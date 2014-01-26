<?php
set_error_handler('CliErrorHandler');

$gearmanHost = '127.0.0.1';
$gearmanStatus = getGearmanServerStatus($gearmanHost);

if (is_array($gearmanStatus)) {
	$isSuccessful = TRUE;

	$objMysqli = @new mysqli('127.0.0.1', '', '', '', 3306);
	if ($objMysqli->connect_errno) {
		fwrite(STDERR, sprintf('ERROR: Database-Server: %s (Errno: %u)' . PHP_EOL, $objMysqli->connect_error, $objMysqli->connect_errno));
		$isSuccessful = FALSE;
	}


	if ($isSuccessful) {
		// construct a client object
		$client = new GearmanClient();
		// add the default server
		$client->addServer($gearmanHost, 4730);
		# register some callbacks
		$client->setCreatedCallback("reverse_created");
		$client->setDataCallback("reverse_data");
		$client->setStatusCallback("reverse_status");
		$client->setCompleteCallback("reverse_complete");
		$client->setFailCallback("reverse_fail");


		$selectQuery = sprintf('SELECT id,url FROM host WHERE NOT processed ORDER BY RAND() ASC LIMIT %u',
			'5000');
		$selectResult = $objMysqli->query($selectQuery);
		#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $selectQuery));

		if (isDatabaseQueryResultError($selectResult)) {
			fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $objMysqli->error, $objMysqli->errno));
			$isSuccessful=FALSE;
		}

		if ($isSuccessful) {
			while($objHost = $selectResult->fetch_object()){
				$client->addTask('TYPO3HostDetector', $objHost->url, NULL, 'host_'. $objHost->id);
			}
		}

		if (!isDatabaseQueryResultError($selectResult))  $selectResult->close();
	}

	# run the tasks in parallel (assuming multiple workers)
	if ($isSuccessful && !$client->runTasks()) {
		fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $client->error(), $client->getErrno()));
		$isSuccessful = FALSE;
	}

	if (!isDatabaseConnectError($objMysqli))  mysqli_close($objMysqli);
}

if (is_bool($isSuccessful) && $isSuccessful) {
	exit(0);
} else {
	die(1);
}


function reverse_created($task)
{
	#fwrite(STDOUT, "CREATED: " . $task->jobHandle() . PHP_EOL);
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
	#echo "COMPLETE: " . $task->jobHandle() . ", " . $task->data() . "\n";
	fwrite(STDOUT, 'COMPLETE: ' . $task->jobHandle() . ', ' . $task->unique(). PHP_EOL);
	#fwrite(STDOUT, 'COMPLETE: ' . $task->jobHandle() . ', ' . $task->unique() . ', '  . $task->data()  . PHP_EOL);

	$detectionResult = json_decode($task->data());
	if (is_object($detectionResult)) {
		if (!is_null($detectionResult->port) && !is_null($detectionResult->ip)) {

			// persist only TYPO3 sites
			if (!is_null($detectionResult->TYPO3) && is_bool($detectionResult->TYPO3) && $detectionResult->TYPO3) {
				print_r($detectionResult);

				$objRemoteMysqli = @new mysqli('127.0.0.1', '', '', '', 3306);
				if ($objRemoteMysqli->connect_errno) {
					fwrite(STDERR, sprintf('ERROR: Database-Server: %s (Errno: %u)' . PHP_EOL, $objRemoteMysqli->connect_error, $objRemoteMysqli->connect_errno));
					die(1);
				}

				$portId = getPortId($objRemoteMysqli, $detectionResult->port);
				$serverId = getServerId($objRemoteMysqli, $detectionResult->ip);
				persistServerPortMapping($objRemoteMysqli, $serverId, $portId);
				persistHost($objRemoteMysqli, $serverId, $detectionResult);

				mysqli_close($objRemoteMysqli);
			}

			fwrite(STDOUT, PHP_EOL);
		}
	}

	if (substr($task->unique(), 0, 4) === 'host') {
		$objLocalMysqli = @new mysqli('127.0.0.1', '', '', '', 3306);
		if ($objLocalMysqli->connect_errno) {
			fwrite(STDERR, sprintf('ERROR: Database-Server: %s (Errno: %u)' . PHP_EOL, $objLocalMysqli->connect_error, $objLocalMysqli->connect_errno));
		}

		if (!isDatabaseConnectError($objLocalMysqli)) {
			$updateQuery = sprintf('UPDATE host SET processed=1 WHERE id=%u AND NOT processed',
				substr($task->unique(), 5)
			);
			$updateResult = $objLocalMysqli->query($updateQuery);
			#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
			if (!is_bool($updateResult) || !$updateResult) {
				fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $objLocalMysqli->error, $objLocalMysqli->errno));
			}
		}

		if (!isDatabaseConnectError($objLocalMysqli))  mysqli_close($objLocalMysqli);
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

function isDatabaseConnectError($objMysqli) {
	return !is_object($objMysqli) || !property_exists($objMysqli, 'connect_errno') || $objMysqli->connect_errno !== 0;
}

function isDatabaseQueryResultError($queryResult) {
	return !is_object($queryResult) || (! $queryResult instanceof mysqli_result);
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

function CliErrorHandler($errno, $errstr, $errfile, $errline) {
	fwrite(STDERR, $errstr . ' in ' . $errfile . ' on ' . $errline . PHP_EOL);
}

?>