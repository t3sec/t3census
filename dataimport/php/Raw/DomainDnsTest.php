<?php
set_error_handler('CliErrorHandler');

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
	while (!feof($fh)) {
		$domain = trim(fgets($fh));

		if (empty($domain))  continue;
		fwrite(STDOUT, PHP_EOL . ++$counter . ': ' . $domain . PHP_EOL);

		$dnsRecords = dns_get_record($domain);
		if (count($dnsRecords) === 0)  $dnsRecords = dns_get_record('www.' . $domain);
		if (count($dnsRecords) === 0)  continue;

		$results = array();
		foreach ($dnsRecords as $dnsRecord) {
			if (array_key_exists('ip', $dnsRecord)) {
				persistServerIp($mysqli, $dnsRecord['ip']);
			}
			if (array_key_exists('type', $dnsRecord) && $dnsRecord['type'] === 'A' && array_key_exists('host', $dnsRecord)) {
				$results[] = 'http://' . $dnsRecord['host'];
				break;
			}
		}

		#print_r($results);

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