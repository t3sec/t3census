<?php
set_error_handler('CliErrorHandler');


$gearmanHost = '127.0.0.1';
$gearmanStatus = getGearmanServerStatus($gearmanHost);

if (is_array($gearmanStatus)) {
	$isSuccessful = TRUE;

	$objMysqli = @new mysqli('127.0.0.1', 'X', 'Y', 'Z', 3306);
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


		$selectQuery = sprintf("SELECT host_id, CONCAT(host_scheme, '://', IF(host_subdomain IS NULL, '', CONCAT(host_subdomain, '.')), host_domain) AS host_url FROM host WHERE host_id > %u AND typo3_installed = 1 AND host_path IS NULL AND ((updated IS NULL AND created < '2014-11-15 0:00:00') OR (updated IS NOT NULL AND updated < '2014-11-15 0:00:00')) ORDER BY host_id ASC LIMIT %u",
			'361707',
			'10000');
		$selectResult = $objMysqli->query($selectQuery);
		#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $selectQuery));

		if (isDatabaseQueryResultError($selectResult)) {
			fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $objMysqli->error, $objMysqli->errno));
			$isSuccessful=FALSE;
		}

		if ($isSuccessful) {
			while($objHost = $selectResult->fetch_object()){
				#fwrite(STDOUT, sprintf('DEBUG: url: %s' . PHP_EOL, $objHost->host_url));
				$client->addTask('TYPO3HostDetector', $objHost->host_url, NULL, 'host_'. $objHost->host_id);
				fwrite(STDOUT, sprintf('Last ID: %u' . PHP_EOL, $objHost->host_id));
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
	fwrite(STDOUT, 'COMPLETE: ' . $task->jobHandle() . ', ' . $task->unique(). PHP_EOL);
	#fwrite(STDOUT, 'COMPLETE: ' . $task->jobHandle() . ', ' . $task->unique() . ', '  . $task->data()  . PHP_EOL);

	$detectionResult = json_decode($task->data());
	if (is_object($detectionResult)) {
		if (!is_null($detectionResult->port) && !is_null($detectionResult->ip)) {

			// persist only TYPO3 sites
			if (!is_null($detectionResult->TYPO3) && is_bool($detectionResult->TYPO3) && $detectionResult->TYPO3) {
				#print_r($detectionResult);

				$objRemoteMysqli = @new mysqli('127.0.0.1', 'X', 'Y', 'Z', 3306);
				if ($objRemoteMysqli->connect_errno) {
					fwrite(STDERR, sprintf('ERROR: Database-Server: %s (Errno: %u)' . PHP_EOL, $objRemoteMysqli->connect_error, $objRemoteMysqli->connect_errno));
					die(1);
				}

				persistUpdatedHost($objRemoteMysqli, $detectionResult, $task->unique());

				mysqli_close($objRemoteMysqli);
			}

			fwrite(STDOUT, PHP_EOL);
		}
	}
}

function reverse_fail($task)
{
	fwrite(STDERR, "FAILED: " . $task->jobHandle() . PHP_EOL);
}

function reverse_data($task)
{
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

function persistUpdatedHost($objMysql, $host, $task) {
	$date = new DateTime();

	$updateQuery = sprintf('UPDATE host SET typo3_installed=%u,typo3_versionstring=%s,host_path=%s,updated=\'%s\' WHERE host_id=%u',
		($host->TYPO3 ? 1 : 0),
		($host->TYPO3 && !empty($host->TYPO3version) ? '\'' . mysqli_real_escape_string($objMysql, $host->TYPO3version) . '\'' : 'NULL'),
		(is_null($host->path) ? 'NULL' : '\'' . mysqli_real_escape_string($objMysql, $host->path) . '\''),
		$date->format('Y-m-d H:i:s'),
		filter_var($task, FILTER_SANITIZE_NUMBER_INT)
	);
	$updateResult = $objMysql->query($updateQuery);
	#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));

	if (!is_bool($updateResult) || !$updateResult) {
		fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $objMysql->error, $objMysql->errno));
	}

	#fwrite(STDOUT, 'UPDATE' . PHP_EOL);
}

function CliErrorHandler($errno, $errstr, $errfile, $errline) {
	fwrite(STDERR, $errstr . ' in ' . $errfile . ' on ' . $errline . PHP_EOL);
}

?>