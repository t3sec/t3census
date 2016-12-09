<?php
set_error_handler('CliErrorHandler');

$dir = dirname(__FILE__);
$libraryDir = realpath($dir . '/../../library/php');
$vendorDir = realpath($dir . '/../../vendor');

require_once $libraryDir . '/Gearman/Serverstatus.php';
require_once $vendorDir . '/autoload.php';

use Monolog\Logger;
use Monolog\Handler\StreamHandler;


$gearmanHost = '127.0.0.1';
$gearmanPort = 4730;
$gearmanFunction = 'TYPO3HostDetector';
$numHostsToProcess = 10000;
$complete = 0;
$logfile = __DIR__ . '/../../reprocess-typo3-hosts.log';

$dateString = 'last month';
$dt=date_create($dateString);
$datetimeCheckMaximum = $dt->format('Y-m-d H:i:s');


// create a log channel
$logger = new Logger('reprocess-typo3-hosts');
$logger->pushHandler(new StreamHandler($logfile, Logger::INFO));
//TODO graylog


$mysqli = @new mysqli('', '', '', '', 3306);
if ($mysqli->connect_errno) {
	fwrite(STDERR, sprintf('ERROR: Database-Server: %s (Errno: %u)' . PHP_EOL, $mysqli->connect_error, $mysqli->connect_errno));
	$logger->addError('Database error', array('error_message' => $mysqli->connect_error, 'error_number' => $mysqli->connect_errno));
	die(1);
}


try {
	$gearmanStatus = new T3census\Gearman\Serverstatus();
	$gearmanStatus->setHost($gearmanHost)->setPort($gearmanPort);
	$gearmanStatus->poll();

	if (!$gearmanStatus->hasFunction($gearmanFunction)) {
		fwrite(STDERR, sprintf('ERROR: Job-Server: Requested function %s not available (Errno: %u)' . PHP_EOL, $gearmanFunction, 1373751780));
		$logger->addError('Gearman function not available', array('function' => $gearmanFunction, 'error_number' => 1373751780));
		die(1);
	}
	if (!$gearmanStatus->getNumberOfWorkersByFunction($gearmanFunction) > 0) {
		fwrite(STDERR, sprintf('ERROR: Job-Server: No workers for function %s available (Errno: %u)' . PHP_EOL, $gearmanFunction, 1373751783));
		$logger->addError('Gearman workers for function not available', array('function' => $gearmanFunction, 'error_number' => 1373751783));
		die(1);
	}
	$logger->addInfo('Gearman server available');
} catch (GearmanException $e) {
	fwrite(STDERR, sprintf('ERROR: Job-Server: %s (Errno: %u)' . PHP_EOL, $e->getMessage(), $e->getCode()));
	die(1);
}
unset($gearmanStatus);


// construct a client object
$client = new GearmanClient();
// add the default server
$client->addServer($gearmanHost, $gearmanPort);
$client->setCreatedCallback(function(GearmanTask $task) use ($gearmanFunction, $logger) {
	#fwrite(STDOUT, "CREATED: " . $task->jobHandle() . ', ' . $task->unique(). PHP_EOL);
});
$client->setCompleteCallback(function(GearmanTask $task) use (&$complete, $gearmanFunction, $logger, $numHostsToProcess) {
	$date = new DateTime();
	$detectionResult = json_decode($task->data());
	$hostId = intval(substr($task->unique(), 5));

	//fwrite(STDOUT, 'COMPLETED: ' . $task->jobHandle() . ', ' . $task->unique(). ', ' . $task->data() . PHP_EOL);
	//fwrite(STDOUT, intval(substr($task->unique(), 5)) . PHP_EOL);


	if (is_object($detectionResult)) {
		$mysqli = @new mysqli('127.0.0.1', 't3census_dbu', 'typo3', 't3census_db', 3306);
		if ($mysqli->connect_errno) {
			fwrite(STDERR, sprintf('ERROR: Database-Server: %s (Errno: %u)' . PHP_EOL, $mysqli->connect_error, $mysqli->connect_errno));
			$logger->addError('Database error', array('error_message' => $mysqli->connect_error, 'error_number' => $mysqli->connect_errno));
			die(1);
		}
	
		if (property_exists($detectionResult, 'TYPO3') && ((is_bool($detectionResult->TYPO3) && !$detectionResult->TYPO3) || empty($detectionResult->TYPO3))) {
			//fwrite(STDOUT, 'no longer TYPO3' . PHP_EOL);
			$logger->addDebug('Host is no longer using TYPO3', array('host_id' => $hostId));
			$updateQuery = sprintf('UPDATE host SET typo3_installed=0,typo3_versionstring=NULL,host_name=NULL,host_scheme=\'%s\',host_subdomain=%s,host_domain=\'%s\',host_suffix=%s,host_path=%s,updated=\'%s\' WHERE host_id=%u;',
					mysqli_real_escape_string($mysqli, $detectionResult->scheme),
					(is_null($detectionResult->subdomain) ? 'NULL' : '\'' . mysqli_real_escape_string($mysqli, $detectionResult->subdomain) . '\''),
					$detectionResult->registerableDomain,
					(is_null($detectionResult->publicSuffix) ? 'NULL' : '\'' . mysqli_real_escape_string($mysqli, $detectionResult->publicSuffix) . '\''),
					(is_null($detectionResult->path) ? 'NULL' : '\'' . mysqli_real_escape_string($mysqli, $detectionResult->path) . '\''),
					$date->format('Y-m-d H:i:s'),
					$hostId
					);
			//fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
			$updateResult = $mysqli->query($updateQuery);
			if (!is_bool($updateResult) || !$updateResult) {
				fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
				print_r($detectionResult);
				$isSuccessful = FALSE;
				die(1);
			}
		}
		
		if (property_exists($detectionResult, 'TYPO3') && is_bool($detectionResult->TYPO3) && $detectionResult->TYPO3 && !is_string($detectionResult->TYPO3version)) {
			//fwrite(STDOUT, 'TYPO3 identification only' . PHP_EOL);
			$logger->addDebug('Host is using TYPO3, classification not possible', array('host_id' => $hostId));
			$updateQuery = sprintf('UPDATE host SET host_name=NULL,host_scheme=\'%s\',host_subdomain=%s,host_domain=\'%s\',host_suffix=%s,host_path=%s,updated=\'%s\' WHERE host_id=%u;',
					mysqli_real_escape_string($mysqli, $detectionResult->scheme),
					(is_null($detectionResult->subdomain) ? 'NULL' : '\'' . mysqli_real_escape_string($mysqli, $detectionResult->subdomain) . '\''),
					$detectionResult->registerableDomain,
					(is_null($detectionResult->publicSuffix) ? 'NULL' : '\'' . mysqli_real_escape_string($mysqli, $detectionResult->publicSuffix) . '\''),
					(is_null($detectionResult->path) ? 'NULL' : '\'' . mysqli_real_escape_string($mysqli, $detectionResult->path) . '\''),
					$date->format('Y-m-d H:i:s'),
					$hostId
					);
			//fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
			$updateResult = $mysqli->query($updateQuery);
			if (!is_bool($updateResult) || !$updateResult) {
				fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
				print_r($detectionResult);
				$isSuccessful = FALSE;
				die(1);
			}
		}
		
		if (property_exists($detectionResult, 'TYPO3version') && is_string($detectionResult->TYPO3version)) {
			fwrite(STDOUT, 'TYPO3 identification + classification: ' . $detectionResult->TYPO3version . ' ' . PHP_EOL);
			$logger->addDebug('Host is still using TYPO3', array('host_id' => $hostId, 'typo3_version' => $detectionResult->TYPO3version));
			$updateQuery = sprintf('UPDATE host SET typo3_versionstring=%s,host_name=NULL,host_scheme=\'%s\',host_subdomain=%s,host_domain=\'%s\',host_suffix=%s,host_path=%s,updated=\'%s\' WHERE host_id=%u;',
					(is_null($detectionResult->TYPO3version) ? 'NULL' : '\'' . mysqli_real_escape_string($mysqli, $detectionResult->TYPO3version) . '\''),
					mysqli_real_escape_string($mysqli, $detectionResult->scheme),
					(is_null($detectionResult->subdomain) ? 'NULL' : '\'' . mysqli_real_escape_string($mysqli, $detectionResult->subdomain) . '\''),
					$detectionResult->registerableDomain,
					(is_null($detectionResult->publicSuffix) ? 'NULL' : '\'' . mysqli_real_escape_string($mysqli, $detectionResult->publicSuffix) . '\''),
					(is_null($detectionResult->path) ? 'NULL' : '\'' . mysqli_real_escape_string($mysqli, $detectionResult->path) . '\''),
					$date->format('Y-m-d H:i:s'),
					$hostId
					);
			//fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
			$updateResult = $mysqli->query($updateQuery);
			if (!is_bool($updateResult) || !$updateResult) {
				fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
				print_r($detectionResult);
				$isSuccessful = FALSE;
				die(1);
			}
		}

		mysqli_close($mysqli);
	}
	
	$complete++;
	
	if (($complete % intval(floor($numHostsToProcess/10))) == 0) {
		$logger->addInfo('Processed hosts', array('total' => $numHostsToProcess, 'count' => $complete));
	}
	
	
});
$client->setFailCallback(function(GearmanTask $task) use ($gearmanFunction, $logger) {
	fwrite(STDERR, "Error in task: " . $task->jobHandle() . ', ' . $task->unique(). PHP_EOL);
	print_r($task->data());
	$logger->addError('Failed task run', array('function' => $gearmanFunction, 'data' => $task->data()));
	die(1);
});

$client->setExceptionCallback(function(GearmanTask $task) use ($gearmanFunction, $logger) {
	fwrite(STDERR, "Exception in task: " . $task->jobHandle() . ', ' . $task->unique(). PHP_EOL);
	$logger->addError(
			'Exception in task run',
			array(
				'function' => $gearmanFunction, 
				'job' => $task->jobHandle(),
				'host_id' => intval(substr($task->unique(), 5)),
				'error_message' => $task->data())
			);
	die(1);
});



$selectQuery = sprintf('SELECT * FROM host WHERE typo3_installed=1 AND typo3_versionstring IS NOT NULL AND host_id > 0 AND ((updated IS NULL AND created < "%s") OR (updated IS NOT NULL AND updated < "%s")) ORDER BY host_id LIMIT %u;', mysqli_real_escape_string($mysqli, $datetimeCheckMaximum), mysqli_real_escape_string($mysqli, $datetimeCheckMaximum), $numHostsToProcess);
//$selectQuery = sprintf('SELECT * FROM host WHERE typo3_installed=1 AND typo3_versionstring IS NOT NULL AND host_id > 0 AND (updated IS NULL AND created < "%s") ORDER BY host_id LIMIT %u;', mysqli_real_escape_string($mysqli, $datetimeCheckMaximum), $numHostsToProcess);
//$selectQuery = sprintf('SELECT * FROM host WHERE typo3_installed=1 AND typo3_versionstring IS NOT NULL AND host_id > 0 AND (updated IS NOT NULL AND updated < "%s") ORDER BY host_id LIMIT %u;', mysqli_real_escape_string($mysqli, $datetimeCheckMaximum), $numHostsToProcess);
fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $selectQuery));


if ($res = $mysqli->query($selectQuery)) {
	//fwrite(STDOUT, sprintf('Processing %u rows' . PHP_EOL, $res->num_rows));
	$logger->addInfo('Processing hosts', array('total' => $res->num_rows, 'dateBefore' => $datetimeCheckMaximum));
	
	while ($row = $res->fetch_assoc()) {
		$url = '';
		$url .= $row['host_scheme'] . '://';
		$url .= (is_null($row['host_subdomain']) ? '' : $row['host_subdomain'] . '.');
		$url .= $row['host_domain'];
		$url .= (is_null($row['host_path']) ? '' : '/' . ltrim($row['host_path'], '/'));
		
		//fwrite(STDOUT, 'URL: ' . $url . ' UID:'. $row['host_id'] . PHP_EOL);
		
		//$client->addTask('ReverseIpLookup', $row['server_ip'], NULL, 'server_'. $row['server_id']);
		$client->addTask($gearmanFunction, $url, NULL, 'host_'. $row['host_id']);
	}
	$res->close();

	if (!$client->runTasks()) {
		fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $client->error(), $client->getErrno()));
		$logger->addError('Gearman tasks not runable', array('function' => $gearmanFunction, 'error_message' => $client->error(), 'error_number' => $client->getErrno()));
		$isSuccessful = FALSE;
	}
}

mysqli_close($mysqli);
echo(PHP_EOL);












function CliErrorHandler($errno, $errstr, $errfile, $errline) {
	fwrite(STDERR, $errstr . ' in ' . $errfile . ' on ' . $errline . PHP_EOL);
}

?>