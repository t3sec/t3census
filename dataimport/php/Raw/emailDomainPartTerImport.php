<?php
set_error_handler('CliErrorHandler');


$dir = dirname(__FILE__);
$libraryDir = realpath($dir . '/../../../library/php');
$vendorDir = realpath($dir . '/../../../vendor');

require_once $libraryDir . '/Bing/Api/ReverseIpLookup.php';
require_once $libraryDir . '/Bing/Scraper/ReverseIpLookup.php';
require_once $vendorDir . '/autoload.php';


$gearmanHost = '127.0.0.1';
$gearmanStatus = getGearmanServerStatus($gearmanHost);


$filename = 'emailDomainPartTer.txt';
if (!file_exists(realpath(dirname(__FILE__) . '/' . $filename)) || !is_readable(realpath(dirname(__FILE__) . '/' . $filename))) {
	fwrite(STDERR, sprintf('ERROR: File %s not accessible' . PHP_EOL, $filename));
	die(1);
}

$mysqli = @new mysqli('127.0.0.1', '', '', '', 3306);
if ($mysqli->connect_errno) {
	fwrite(STDERR, sprintf('ERROR: Database-Server: %s (Errno: %u)' . PHP_EOL, $mysqli->connect_error, $mysqli->connect_errno));
	die(1);
}

if (is_array($gearmanStatus)) {
	$isSuccessful = TRUE;

	if ($fh = fopen(realpath(dirname(__FILE__) . '/' . $filename), 'r')) {

		// construct a client object
		$client = new GearmanClient();
		// add the default server
		$client->addServer($gearmanHost, 4730);

		$date = new DateTime();

		while (!feof($fh)) {
			$domain = trim(fgets($fh));

			if (empty($domain))  continue;
			fwrite(STDOUT, PHP_EOL . $domain . PHP_EOL);

			$results = array();
			try {
				$objLookup = new T3census\Bing\Api\ReverseIpLookup();
				$objLookup->setAccountKey('')->setEndpoint('https://api.datamarket.azure.com/Bing/Search');
				$results = $objLookup->setQuery('site:' . $domain)->setOffset(0)->setMaxResults(1500)->getResults();
				unset($objLookup);
			} catch (\T3census\Bing\Api\Exception\ApiConsumeException $e) {
				$objLookup = new \T3census\Bing\Scraper\ReverseIpLookup();
				$objLookup->setEndpoint('http://www.bing.com/search');
				$results = $objLookup->setQuery('site:' . $domain)->setOffset(0)->setMaxResults(1500)->getResults();
				unset($objLookup);
			}

			foreach ($results as $url) {
				if (method_exists($client, 'doNormal')) {
					$detectionResult = json_decode($client->doNormal("TYPO3HostDetector", $url));
				} else {
					$detectionResult = json_decode($client->do("TYPO3HostDetector", $url));
				}

				if (is_object($detectionResult)) {
					if (is_null($detectionResult->port) || is_null($detectionResult->ip)) continue;

					$portId = getPortId($mysqli, $detectionResult->port);
					$serverId = getServerId($mysqli, $detectionResult->ip);
					persistServerPortMapping($mysqli, $serverId, $portId);

					// persist only TYPO3 sites
					if (!is_null($detectionResult->TYPO3) && is_bool($detectionResult->TYPO3) && $detectionResult->TYPO3) {
						print_r($detectionResult);
						persistHost($mysqli, $serverId, $detectionResult);
					}
				}
			}
		}
		fclose($fh);
	}
}

mysqli_close($mysqli);
fwrite(STDOUT, PHP_EOL);


if (is_bool($isSuccessful) && $isSuccessful) {
	exit(0);
} else {
	die(1);
}

function getServerId($mysqli, $server) {
	$serverId = NULL;
	/* Select queries return a resultset */
	if ($result = $mysqli->query("SELECT server_id FROM server WHERE server_ip = INET_ATON('" . mysqli_real_escape_string($mysqli, $server) . "');")) {

		if ($result->num_rows == 0) {
			$date = new DateTime();
			$foo = $mysqli->query("INSERT INTO server(server_ip,created) VALUES (INET_ATON('" . mysqli_real_escape_string($mysqli, $server) . "'), '" . $date->format('Y-m-d H:i:s') . "')");
			if (!$foo) echo "error-2: (" . $mysqli->errno . ") " . $mysqli->error;
			$serverId = $mysqli->insert_id;
		} else {
			$row = $result->fetch_assoc();
			$serverId = intval($row['server_id']);
		}

		/* free result set */
		$result->close();
	}

	return $serverId;
}

function getPortId($mysqli, $port) {
	$portId = NULL;
	/* Select queries return a resultset */
	if ($result = $mysqli->query("SELECT port_id FROM port WHERE port_number=" . intval($port) . " LIMIT 1")) {

		if ($result->num_rows == 0) {
			$foo = $mysqli->query("INSERT INTO port(port_number) VALUES (" . intval($port) . ")");
			if (!$foo) echo "error-1: (" . $mysqli->errno . ") " . $mysqli->error;
			$portId = $mysqli->insert_id;
		} else {
			$row = $result->fetch_assoc();
			$portId = intval($row['port_id']);
		}

		/* free result set */
		$result->close();
	}

	return $portId;
}

function persistServerPortMapping($mysqli, $serverId, $portId) {
	if ($result = $mysqli->query("SELECT fk_port_id FROM server_port WHERE fk_port_id = " . intval($portId) . " AND fk_server_id = " . intval($serverId))) {

		if ($result->num_rows == 0) {
			$foo = $mysqli->query("INSERT INTO server_port(fk_port_id,fk_server_id) VALUES (" . intval($portId) . ", " . intval($serverId) . ")");
			if (!$foo) echo "error-3: (" . $mysqli->errno . ") " . $mysqli->error;
		}

		/* free result set */
		$result->close();
	}
}

function persistHost($objMysql, $serverId, $host) {
	$selectQuery = sprintf('SELECT host_id FROM host WHERE fk_server_id=%u AND host_scheme LIKE \'%s\' AND host_subdomain %s AND host_domain LIKE \'%s\' LIMIT 1',
		$serverId,
		$host->scheme,
		(is_null($host->subdomain) ? 'IS NULL' : 'LIKE \'' . mysqli_real_escape_string($objMysql, $host->subdomain) . '\''),
		$host->registerableDomain
	);
	#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $selectQuery));
	$selectRes = $objMysql->query($selectQuery);

	if (is_object($selectRes)) {
		$date = new DateTime();

		if ($selectRes->num_rows == 0) {
			$insertQuery = sprintf('INSERT INTO host(typo3_installed,typo3_versionstring,host_name,host_scheme,host_subdomain,host_domain,host_suffix,host_path,created,fk_server_id) ' .
				'VALUES(%u,%s,NULL,\'%s\',%s,\'%s\',%s,%s,\'%s\',%u);',
				($host->TYPO3 ? 1 : 0),
				($host->TYPO3 && !empty($host->TYPO3version) ? '\'' . mysqli_real_escape_string($objMysql, $host->TYPO3version) . '\'' : 'NULL'),
				mysqli_real_escape_string($objMysql, $host->scheme),
				(is_null($host->subdomain) ? 'NULL' : '\'' . mysqli_real_escape_string($objMysql, $host->subdomain) . '\''),
				$host->registerableDomain,
				(is_null($host->publicSuffix) ? 'NULL' : '\'' . mysqli_real_escape_string($objMysql, $host->publicSuffix) . '\''),
				(is_null($host->path) ? 'NULL' : '\'' . mysqli_real_escape_string($objMysql, $host->path) . '\''),
				$date->format('Y-m-d H:i:s'),
				$serverId
			);
			$insertResult = $objMysql->query($insertQuery);
			#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $insertQuery));
			if (!is_bool($insertResult) || !$insertResult) {
				fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $objMysql->error, $objMysql->errno));
			}
			echo('INSERT' . PHP_EOL);
		} else {
			$row = $selectRes->fetch_assoc();

			$updateQuery = sprintf('UPDATE host SET typo3_installed=%u,typo3_versionstring=%s,host_path=%s,updated=\'%s\' WHERE host_id=%u',
				($host->TYPO3 ? 1 : 0),
				($host->TYPO3 && !empty($host->TYPO3version) ? '\'' . mysqli_real_escape_string($objMysql, $host->TYPO3version) . '\'' : 'NULL'),
				(is_null($host->path) ? 'NULL' : '\'' . mysqli_real_escape_string($objMysql, $host->path) . '\''),
				$date->format('Y-m-d H:i:s'),
				$row['host_id']
			);
			$updateResult = $objMysql->query($updateQuery);
			#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
			if (!is_bool($updateResult) || !$updateResult) {
				fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $objMysql->error, $objMysql->errno));
			}
			echo('UPDATE' . PHP_EOL);
		}
		$selectRes->close();
	}
}

function getGearmanServerStatus($host = '127.0.0.1', $port = 4730) {
	$status = NULL;

	$handle = fsockopen($host, $port, $errorNumber, $errorString, 30);
	if ($handle != NULL) {
		fwrite($handle, "status\n");
		while (!feof($handle)) {
			$line = fgets($handle, 4096);
			if ($line == ".\n") {
				break;
			}
			if (preg_match("~^(.*)[ \t](\d+)[ \t](\d+)[ \t](\d+)~", $line, $matches)) {
				$function = $matches[1];
				$status['operations'][$function] = array(
					'function' => $function,
					'total' => $matches[2],
					'running' => $matches[3],
					'connectedWorkers' => $matches[4],
				);
			}
		}
		fwrite($handle, "workers\n");
		while (!feof($handle)) {
			$line = fgets($handle, 4096);
			if ($line == ".\n") {
				break;
			}
			// FD IP-ADDRESS CLIENT-ID : FUNCTION
			if (preg_match("~^(\d+)[ \t](.*?)[ \t](.*?) : ?(.*)~", $line, $matches)) {
				$fd = $matches[1];
				$status['connections'][$fd] = array(
					'fd' => $fd,
					'ip' => $matches[2],
					'id' => $matches[3],
					'function' => $matches[4],
				);
			}
		}
		fclose($handle);
	}

	return $status;
}

function CliErrorHandler($errno, $errstr, $errfile, $errline) {
	fwrite(STDERR, $errstr . ' in ' . $errfile . ' on ' . $errline . PHP_EOL);
}

?>