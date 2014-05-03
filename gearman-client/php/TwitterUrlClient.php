<?php
set_error_handler('CliErrorHandler');

$dir = dirname(__FILE__);
$libraryDir = realpath($dir . '/../../library/php');
$vendorDir = realpath($dir . '/../../vendor');

require_once $libraryDir . '/Gearman/Serverstatus.php';
require_once $vendorDir . '/autoload.php';

$mysqli = @new mysqli('127.0.0.1', 'X', 'Y', 'Z', 3306);
if ($mysqli->connect_errno) {
	fwrite(STDERR, sprintf('ERROR: Database-Server: %s (Errno: %u)' . PHP_EOL, $mysqli->connect_error, $mysqli->connect_errno));
	die(1);
}

$gearmanHost = '127.0.0.1';
$gearmanPort = 4730;
$gearmanFunction = 'TYPO3HostDetector';
try {
	$gearmanStatus = new T3census\Gearman\Serverstatus();
	$gearmanStatus->setHost($gearmanHost)->setPort($gearmanPort);
	$gearmanStatus->poll();

	if (!$gearmanStatus->hasFunction($gearmanFunction)) {
		fwrite(STDERR, sprintf('ERROR: Job-Server: Requested function %s not available (Errno: %u)' . PHP_EOL, $gearmanFunction, 1373751780));
		die(1);
	}
	if (!$gearmanStatus->getNumberOfWorkersByFunction($gearmanFunction) > 0) {
		fwrite(STDERR, sprintf('ERROR: Job-Server: No workers for function %s available (Errno: %u)' . PHP_EOL, $gearmanFunction, 1373751783));
		die(1);
	}
} catch (GearmanException $e) {
	fwrite(STDERR, sprintf('ERROR: Job-Server: %s (Errno: %u)' . PHP_EOL, $e->getMessage(), $e->getCode()));
	die(1);
}
unset($gearmanStatus);
$isSuccessful = TRUE;


$client = new GearmanClient();
$client->addServer($gearmanHost, $gearmanPort);
$client->setCreatedCallback('reverse_created');
$client->setDataCallback('reverse_data');
$client->setStatusCallback('reverse_status');
$client->setCompleteCallback('reverse_complete');
$client->setFailCallback('reverse_fail');

$selectQuery = 'SELECT t.tweet_id,t.created,t.tweet_processed,u.url_text '
			 . 'FROM twitter_tweet t JOIN twitter_url u ON (t.tweet_id = u.fk_tweet_id) '
			 . 'WHERE NOT t.tweet_processed '
			 . 'ORDER BY t.created ASC LIMIT 1000;';
$res = $mysqli->query($selectQuery);
if (is_object($res)) {
	$numRow = 0;
	while ($row = $res->fetch_assoc()) {
		fwrite(STDOUT, sprintf('%u: %s', ++$numRow, $row['url_text']) . PHP_EOL);

		$objUrl = \Purl\Url::parse($row['url_text']);
		$result = array();

		if (!isShortenerServiceHost($objUrl->get('host'))) {
			$selectQuery = sprintf('SELECT 1 '
				. 'FROM host '
				. 'WHERE created IS NOT NULL AND host_scheme LIKE \'%s\' AND host_subdomain %s AND host_domain LIKE \'%s\' '
				. 'LIMIT 1;',
				$objUrl->get('scheme'),
				(is_null($objUrl->get('subdomain')) ? 'IS NULL' : 'LIKE \'' . mysqli_real_escape_string($mysqli, $objUrl->get('subdomain')) . '\''),
				$objUrl->get('registerableDomain')
			);
			$selectRes = $mysqli->query($selectQuery);
			#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $selectQuery));

			if (is_object($selectRes)) {
				if ($selectRes->num_rows > 0) {
					echo('PASS' . PHP_EOL);
					$updateQuery = sprintf('UPDATE twitter_tweet SET tweet_processed = 1 WHERE tweet_id=%u;',
						$row['tweet_id']
					);
					$updateResult = $mysqli->query($updateQuery);
					#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
					if (!is_bool($updateResult) || !$updateResult) {
						fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
						$isSuccessful = FALSE;
						break;
					}
					continue;
				}
				$selectRes->close();
			} else {
				fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
				$isSuccessful = FALSE;
				break;
			}
		}


		$client->addTask('TYPO3HostDetector', $row['url_text'], NULL, 'tweet_'. $row['tweet_id']);
	}

	# run the tasks in parallel (assuming multiple workers)
	if ($isSuccessful && !$client->runTasks()) {
		fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $client->error(), $client->getErrno()));
		$isSuccessful = FALSE;
	}

	$res->close();
} else {
	fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
	$isSuccessful = FALSE;
}

mysqli_close($mysqli);

if (is_bool($isSuccessful) && $isSuccessful) {
	exit(0);
} else {
	die(1);
}

function reverse_created($task)
{
	#fwrite(STDOUT, "CREATED: " . $task->jobHandle() . ', ' . $task->unique(). ', ' . $task->data() . PHP_EOL);
}

function reverse_status($task)
{
	/*
		fwrite(STDOUT, "STATUS: " . $task->jobHandle() . " - " . $task->taskNumerator() .
			"/" . $task->taskDenominator()  . PHP_EOL);
	*/
}

function reverse_complete($task)
{
	fwrite(STDOUT, 'COMPLETED: ' . $task->jobHandle() . ', ' . $task->unique(). PHP_EOL);
	#fwrite(STDOUT, 'Detection result: ' . $task->data(). PHP_EOL);

	$detectionResult = json_decode($task->data());
	if (is_object($detectionResult)) {
		if (substr($task->unique(), 0, 5) === 'tweet') {
			$objMysqli = @new mysqli('127.0.0.1', 'X', 'Y', 'Z', 3306);
			if ($objMysqli->connect_errno) {
				fwrite(STDERR, sprintf('ERROR: Database-Server: %s (Errno: %u)' . PHP_EOL, $objMysqli->connect_error, $objMysqli->connect_errno));
				die(1);
			}

			if (!is_null($detectionResult->port) && !is_null($detectionResult->ip)) {
					// persist only TYPO3 sites
				if (!is_null($detectionResult->TYPO3) && is_bool($detectionResult->TYPO3) && $detectionResult->TYPO3) {

					$portId = getPortId($objMysqli, $detectionResult->port);

					$serverId = getServerId($objMysqli, $detectionResult->ip);

					persistServerPortMapping($objMysqli, $serverId, $portId);

					$portId = getPortId($objMysqli, $detectionResult->port);
					$serverId = getServerId($objMysqli, $detectionResult->ip);
					persistServerPortMapping($objMysqli, $serverId, $portId);
					persistHost($objMysqli, $serverId, $detectionResult);
				}
			}


			$updateQuery = sprintf('UPDATE twitter_tweet SET tweet_processed = 1 WHERE tweet_id=%u;',
				substr($task->unique(), 6)
			);
			$updateResult = $objMysqli->query($updateQuery);
			#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
			if (!is_bool($updateResult) || !$updateResult) {
				fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $objMysqli->error, $objMysqli->errno));
				$isSuccessful = FALSE;
				die(1);
			}

			mysqli_close($objMysqli);
		}
	}
}

function reverse_fail($task)
{
	#echo "FAILED: " . $task->jobHandle() . "\n";
	fwrite(STDERR, "FAILED: " . $task->jobHandle() . PHP_EOL);
}

function reverse_data($task)
{
	#echo "DATA: " . $task->data() . "\n";
	fwrite(STDOUT, "DATA: " . $task->data() . PHP_EOL);
}

function isShortenerServiceHost($host) {
	$shortenerServices = array(
		'b-gat.es',
		'base24.eu',
		'bit.ly',
		'buff.ly',
		'csc0.ly',
		'eepurl.com',
		'fb.me',
		'dlvr.it',
		'goo.gl',
		'indu.st',
		'is.gd',
		'j.mp',
		'kck.st',
		'krz.ch',
		'lnkr.ch',
		'lgsh.ch',
		'moreti.me',
		'myurl.to',
		'npub.li',
		'nkirch.de',
		'nkor.de',
		'opnstre.am',
		'ow.ly',
		'rlmk.me',
		'shar.es',
		't3n.me',
		'tinyurl.com',
		'ur1.ca',
		'xing.com',
		'zite.to',
	);

	return (in_array($host, $shortenerServices, TRUE));
}

function persistHost($objMysql, $serverId, $host) {

	$selectQuery = sprintf('SELECT host_id '
		. 'FROM host '
		. 'WHERE fk_server_id=%u AND host_scheme LIKE \'%s\' AND host_subdomain %s AND host_domain LIKE \'%s\';',
		$serverId,
		$host->scheme,
		(is_null($host->subdomain) ? 'IS NULL' : 'LIKE \'' . mysqli_real_escape_string($objMysql, $host->subdomain) . '\''),
		$host->registerableDomain
	);
	$selectRes = $objMysql->query($selectQuery);
	#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $selectQuery));

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
			#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $insertQuery));
			$insertResult = $objMysql->query($insertQuery);
			if (!is_bool($insertResult) || !$insertResult) {
				fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $objMysql->error, $objMysql->errno));
				return;
			}
		} else {
			$row = $selectRes->fetch_assoc();

			$updateQuery = sprintf('UPDATE host '
				. 'SET host_path=%s,typo3_installed=%u,typo3_versionstring=%s,updated=\'%s\' '
				. 'WHERE host_id=%u;',
				(is_null($host->path) ? 'NULL' : '\'' . mysqli_real_escape_string($objMysql, $host->path) . '\''),
				($host->TYPO3 ? 1 : 0),
				($host->TYPO3 && !empty($host->TYPO3version) ? '\'' . mysqli_real_escape_string($objMysql, $host->TYPO3version) . '\'' : 'NULL'),
				$date->format('Y-m-d H:i:s'),
				$row['host_id']);
			#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
			$updateResult = $objMysql->query($updateQuery);
			if (!is_bool($updateResult) || !$updateResult) {
				fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $objMysql->error, $objMysql->errno));
				return;
			}
		}

		$selectRes->close();
	} else {
		fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $objMysql->error, $objMysql->errno));
	}
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


function CliErrorHandler($errno, $errstr, $errfile, $errline) {
	fwrite(STDERR, $errstr . ' in ' . $errfile . ' on ' . $errline . PHP_EOL);
}

?>