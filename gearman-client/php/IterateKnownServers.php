<?php
set_error_handler('CliErrorHandler');

$dir = dirname(__FILE__);
$libraryDir = realpath($dir . '/../../library/php');
$vendorDir = realpath($dir . '/../../vendor');

require_once $libraryDir . '/Gearman/Serverstatus.php';
require_once $vendorDir . '/autoload.php';


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

// construct a client object
$client = new GearmanClient();
// add the default server
$client->addServer($gearmanHost, $gearmanPort);
$client->setCreatedCallback('reverse_created');
$client->setCompleteCallback('reverse_complete');

$mysqli = new mysqli('127.0.0.1', 'X', 'Y', 'Z', 3306);

$query = 'SELECT s.server_id,INET_NTOA(s.server_ip) AS server_ip,count(h.host_id) AS typo3hosts'
		. ' FROM server s RIGHT JOIN host h ON (s.server_id = h.fk_server_id)'
		. ' WHERE s.updated IS NULL AND h.typo3_installed=1'
		. ' GROUP BY s.server_id'
		. ' HAVING typo3hosts >= 1'
		. ' ORDER BY typo3hosts DESC LIMIT 100;';
$query = 'SELECT updated,server_id,INET_NTOA(server_ip) AS server_ip FROM server WHERE NOT locked AND updated IS NULL ORDER BY RAND() LIMIT 100;';

if ($res = $mysqli->query($query)) {

	$date = new DateTime();

	$numRow = 0;
	while ($row = $res->fetch_assoc()) {
		if (isServerLocked($mysqli, intval($row['server_id'])) || isServerUpdated($mysqli, intval($row['server_id']))) {
			continue;
		} else {
			$updateQuery = sprintf('UPDATE server SET locked=1 WHERE server_id=%u;',
				intval($row['server_id'])
			);
			$updateResult = $mysqli->query($updateQuery);
			#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
			if (!is_bool($updateResult) || !$updateResult) {
				fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
				$isSuccessful = FALSE;
				break;
			}

			$urls = array();

			fwrite(STDOUT, sprintf('%u: ServerId: %u - Server IP: %s', ++$numRow, $row['server_id'], $row['server_ip']) . PHP_EOL);

			$client->addTask('ReverseIpLookup', $row['server_ip'], NULL, 'server_'. $row['server_id']);
		}
	}

	# run the tasks in parallel (assuming multiple workers)
	if ($isSuccessful && !$client->runTasks()) {
		fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $client->error(), $client->getErrno()));
		$isSuccessful = FALSE;
	}
}

mysqli_close($mysqli);
echo(PHP_EOL);



function reverse_created($task) {
	#fwrite(STDOUT, "CREATED: " . $task->jobHandle() . ', ' . $task->unique(). PHP_EOL);
}


function reverse_complete($task) {
	#fwrite(STDOUT, 'COMPLETED: ' . $task->jobHandle() . ', ' . $task->unique(). PHP_EOL);

	$urls = json_decode($task->data());

	if (is_array($urls)) {
		$date = new DateTime();

		$objMysqli = @new mysqli('127.0.0.1', 'X', 'Y', 'Z', 3306);
		if ($objMysqli->connect_errno) {
			fwrite(STDERR, sprintf('ERROR: Database-Server: %s (Errno: %u)' . PHP_EOL, $objMysqli->connect_error, $objMysqli->connect_errno));
			die(1);
		}

		if (count($urls)) {
			fwrite(STDOUT, 'COMPLETED: ' . $task->jobHandle() . ', ' . $task->unique(). ', ' . $task->data() . PHP_EOL);
			$gearmanHost = '93.180.156.236';
			$gearmanPort = 4730;

			// construct a client object
			$client = new GearmanClient();
			// add the default server
			$client->addServer($gearmanHost, $gearmanPort);
			$client->setCreatedCallback('detection_created');
			$client->setCompleteCallback('detection_complete');


			foreach ($urls as $url) {
				$client->addTask('TYPO3HostDetector', $url);
			}

			# run the tasks in parallel (assuming multiple workers)
			if (!$client->runTasks()) {
				fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $client->error(), $client->getErrno()));
				die(1);
			}
		}

		$updateQuery = sprintf('UPDATE server SET locked=0,updated=\'%s\' WHERE server_id=%u;',
			$date->format('Y-m-d H:i:s'),
			intval(substr($task->unique(), 7))
		);
		$updateResult = $objMysqli->query($updateQuery);
		#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
		if (!is_bool($updateResult) || !$updateResult) {
			fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $objMysqli->error, $objMysqli->errno));
			$isSuccessful = FALSE;
			break;
		}

		mysqli_close($objMysqli);
	}
}

function detection_created($task) {
	fwrite(STDOUT, "CREATED detection: " . $task->jobHandle() . ', ' . $task->unique(). PHP_EOL);
}


function detection_complete($task) {
	#fwrite(STDOUT, 'COMPLETED detection: ' . $task->jobHandle() . ', ' . $task->unique(). ', ' . $task->data() . PHP_EOL);
	fwrite(STDOUT, 'COMPLETED detection: ' . $task->jobHandle() . ', ' . $task->unique() . PHP_EOL);

	$detectionResult = json_decode($task->data());

	if (is_object($detectionResult)) {
		$objMysqli = @new mysqli('127.0.0.1', 'X', 'Y', 'Z', 3306);
		if ($objMysqli->connect_errno) {
			fwrite(STDERR, sprintf('ERROR: Database-Server: %s (Errno: %u)' . PHP_EOL, $objMysqli->connect_error, $objMysqli->connect_errno));
			die(1);
		}

		if (!is_null($detectionResult->port) && !is_null($detectionResult->ip)) {
			// persist only TYPO3 sites
			#if (!is_null($detectionResult->TYPO3) && is_bool($detectionResult->TYPO3) && $detectionResult->TYPO3) {

				$portId = getPortId($objMysqli, $detectionResult->port);

				$serverId = getServerId($objMysqli, $detectionResult->ip);

				persistServerPortMapping($objMysqli, $serverId, $portId);

				$portId = getPortId($objMysqli, $detectionResult->port);
				$serverId = getServerId($objMysqli, $detectionResult->ip);
				persistServerPortMapping($objMysqli, $serverId, $portId);
				persistHost($objMysqli, $serverId, $detectionResult);
			#}
		}

		mysqli_close($objMysqli);
	}
}

function isServerLocked($objMysql, $serverId) {
	$isLocked = TRUE;
	$selectQuery = sprintf('SELECT 1 FROM server WHERE server_id=%u AND NOT locked;',
		$serverId
	);
	#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $selectQuery));
	$res = $objMysql->query($selectQuery);
	if (is_object($res = $objMysql->query($selectQuery))) {

		if ($res->num_rows == 1) {
			$isLocked = FALSE;
		}
		$res->close();
	}

	return $isLocked;
}

function isServerUpdated($objMysql, $serverId) {
	$isUpdated = FALSE;
	$selectQuery = sprintf('SELECT 1 FROM server WHERE server_id=%u AND updated IS NOT NULL;',
		$serverId
	);
	$res = $objMysql->query($selectQuery);
	#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $selectQuery));

	if (is_object($res = $objMysql->query($selectQuery))) {

		if ($res->num_rows == 1) {
			$isUpdated = TRUE;
		}
		$res->close();
	}

	return $isUpdated;
}

function extractUrlsFrom($results) {
	$urls = array();
	foreach ($results->d->results as $value) {
		#var_dump($value);
		switch ($value->__metadata->type) {
			case 'WebResult':
				#echo(PHP_EOL . $value->Url);
				$urls[] = $value->Url;
				break;
		}
	}

	return $urls;
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
	$selectQuery = sprintf('SELECT host_id FROM host WHERE fk_server_id=%u AND host_scheme=\'%s\' AND host_subdomain LIKE %s AND host_domain LIKE \'%s\' LIMIT 1',
		$serverId,
		mysqli_real_escape_string($objMysql, $host->scheme),
		(is_null($host->subdomain) ? 'NULL' : '\'' . mysqli_real_escape_string($objMysql, $host->subdomain) . '\''),
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
			#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $insertQuery));
			$insertResult = $objMysql->query($insertQuery);
			if (!is_bool($insertResult) || !$insertResult) {
				fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $objMysql->error, $objMysql->errno));
			}
		} else {
			$row = $selectRes->fetch_assoc();

			$updateQuery = sprintf('UPDATE host SET typo3_installed=%u,typo3_versionstring=%s,host_path=%s,updated=\'%s\' WHERE created IS NULL AND host_id=%u',
				($host->TYPO3 ? 1 : 0),
				($host->TYPO3 && !empty($host->TYPO3version) ? '\'' . mysqli_real_escape_string($objMysql, $host->TYPO3version) . '\'' : 'NULL'),
				(is_null($host->path) ? 'NULL' : '\'' . mysqli_real_escape_string($objMysql, $host->path) . '\''),
				$date->format('Y-m-d H:i:s'),
				$row['host_id']
			);
			#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
			$updateResult = $objMysql->query($updateQuery);
			if (!is_bool($updateResult) || !$updateResult) {
				fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $objMysql->error, $objMysql->errno));
			}
		}
		$selectRes->close();
	}
}

function CliErrorHandler($errno, $errstr, $errfile, $errline) {
	fwrite(STDERR, $errstr . ' in ' . $errfile . ' on ' . $errline . PHP_EOL);
}

?>