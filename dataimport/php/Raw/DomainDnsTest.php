<?php
set_error_handler('CliErrorHandler');


$dir = dirname(__FILE__);
$libraryDir = realpath($dir . '/../../../library/php');
$vendorDir = realpath($dir . '/../../../vendor');
require_once $vendorDir. '/autoload.php';


$filename = 'DomainDnsTest.txt';
if (!file_exists(realpath(dirname(__FILE__) . '/' . $filename)) || !is_readable(realpath(dirname(__FILE__) . '/' . $filename))) {
	fwrite(STDERR, sprintf('ERROR: File %s not accessible' . PHP_EOL, $filename));
	die(1);
}


if ($fh = fopen(realpath(dirname(__FILE__) . '/' . $filename), 'r')) {

	$mysqli = @new mysqli('127.0.0.1', '', '', '', 3306);
	if ($mysqli->connect_errno) {
		fwrite(STDERR, sprintf('ERROR: Database-Server: %s (Errno: %u)' . PHP_EOL, $mysqli->connect_error, $mysqli->connect_errno));
		die(1);
	}

	$counter = 0;
	$objResolver = new \Net_DNS2_Resolver(NULL);
	while (!feof($fh)) {
		$domain = trim(fgets($fh));

		if (empty($domain))  continue;
		fwrite(STDOUT, PHP_EOL . ++$counter . ': ' . $domain . PHP_EOL);

		try {
			$objResponse = $objResolver->query($domain);
			$dnsRecords = $objResponse->answer;

			unset($objResponse);
		} catch(Net_DNS2_Exception $e) {
			unset($objResponse);
			if ($e->getCode() !== 2  && $e->getCode() !== 3)  {
				fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $e->getMessage(), $e->getCode()));
			}
		}

		if (!isset($dnsRecords)) {
			try {
				$objResponse = $objResolver->query($domain);
				$dnsRecords = $objResponse->answer;

				unset($objResponse);
			} catch(Net_DNS2_Exception $e) {
				unset($objResponse);
				if ($e->getCode() !== 2  && $e->getCode() !== 3)  {
					fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $e->getMessage(), $e->getCode()));
				}
			}
		}

		if (!isset($dnsRecords))  continue;

		$results = array();
		foreach ($dnsRecords as $dnsRecord) {
			if (property_exists($dnsRecord, 'address')) {
				persistServerIp($mysqli, $dnsRecord->address);
			}
			if (property_exists($dnsRecord, 'type') && $dnsRecord->type === 'A' && property_exists($dnsRecord, 'name')) {
				$results[] = 'http://' .$dnsRecord->name;
				break;
			}
		}
		unset($dnsRecords);

		foreach ($results as $url) {
			persistHost($mysqli, $url);
		}
	}
	mysqli_close($mysqli);
	fclose($fh);
}

function persistServerIp($objMysql, $ip) {
	$insertQuery = sprintf('INSERT INTO server(ip) VALUES (INET_ATON(\'%s\'));',
		mysqli_real_escape_string($objMysql, $ip)
	);
	$insertResult = $objMysql->query($insertQuery);
	#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $insertQuery));
	if (!is_bool($insertResult) || !$insertResult) {
		fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $objMysql->error, $objMysql->errno));
	}
}

function persistHost($objMysql, $url) {
	$insertQuery = sprintf('INSERT INTO host(url) ' .
		'VALUES(\'%s\');',
		mysqli_real_escape_string($objMysql, $url)
	);
	$insertResult = $objMysql->query($insertQuery);
	#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $insertQuery));
	if (!is_bool($insertResult) || !$insertResult) {
		fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $objMysql->error, $objMysql->errno));
	}
}

function CliErrorHandler($errno, $errstr, $errfile, $errline) {
	fwrite(STDERR, $errstr . ' in ' . $errfile . ' on ' . $errline . PHP_EOL);
}

?>