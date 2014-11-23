<?php
set_error_handler('CliErrorHandler');

$dir = dirname(__FILE__);
$libraryDir = realpath($dir . '/../../library/php');
$vendorDir = realpath($dir . '/../../vendor');

require_once $vendorDir. '/autoload.php';
require_once $libraryDir . '/IpHelper.php';


$mysqli = @new mysqli('127.0.0.1', 'X', 'Y', 'Z', 3306);
if ($mysqli->connect_errno) {
	fwrite(STDERR, sprintf('ERROR: Database-Server: %s (Errno: %u)' . PHP_EOL, $mysqli->connect_error, $mysqli->connect_errno));
	die(1);
}

$selectHostQuery = 'SELECT host_id,host_domain FROM aggregated_host WHERE sfk_domain_id IS NULL;';

if (is_object($resHost = $mysqli->query($selectHostQuery))) {
	fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $selectHostQuery));
	$isSuccessful = TRUE;


	while ($rowHost = $resHost->fetch_assoc()) {
		#print_r($rowHost);

		$selectDomainQuery = sprintf('SELECT domain_id,domain_name FROM reg_domain WHERE domain_name LIKE \'%s\' LIMIT 1;',
			$rowHost['host_domain']
		);
		$resDomain = $mysqli->query($selectDomainQuery);
		if (is_object($resDomain)) {
			fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $selectDomainQuery));

			if ($resDomain->num_rows === 1) {
				$domain = $resDomain->fetch_assoc();
				#print_r($domain);

				$updateHostQuery = sprintf('UPDATE aggregated_host SET sfk_domain_id=%u WHERE host_id=%u;',
					$domain['domain_id'],
					$rowHost['host_id']);
				fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateHostQuery));
				$resUpdateHost = $mysqli->query($updateHostQuery);
				if (!is_bool($resUpdateHost) || !$resUpdateHost) {
					fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
					$isSuccessful = FALSE;
					break;
				}
			}
			$resDomain->free_result();
		} else {
			printf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno);
			$isSuccessful = FALSE;
			break;
		}

		/*
		if (is_object($record) && $record instanceOf \Novutec\WhoisParser\Result) {

			$location = array();

			if (!property_exists($record, 'contacts') || !property_exists($record->contacts, 'owner')) {
				$updateQuery = sprintf('UPDATE aggregated_host SET skipped=1 WHERE host_id=%u;',
					$row['host_id']);
				fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
				$updateResult = $mysqli->query($updateQuery);
				if (!is_bool($updateResult) || !$updateResult) {
					fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
					$isSuccessful = FALSE;
					break;
				}
				continue;
			}

			$owner = $record->contacts->owner;
			if (is_array($owner) && array_key_exists(0, $owner)) {
				$owner = array_shift($owner);
			} else continue;

			$location['address'] = $owner->address;
			if (is_array($location['address']))  $location['address'] = join(' ,', $location['address']);
			$location['zipcode'] = $owner->zipcode;
			$location['city'] = $owner->city;
			$location['country'] = $owner->country;

			if (!empty($location['address'])
					&& !empty($location['zipcode'])
					&& !empty($location['city'])
					&& !empty($location['country'])) {
				$locationString = sprintf('%s, %s %s, %s',
					$location['address'],
					$location['zipcode'],
					$location['city'],
					$location['country']);
				$updateQuery = sprintf('UPDATE aggregated_host SET location=\'%s\' WHERE host_id=%u;',
					mysqli_real_escape_string($mysqli, $locationString),
					$row['host_id']);
				fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
				$updateResult = $mysqli->query($updateQuery);
				if (!is_bool($updateResult) || !$updateResult) {
					fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
					$isSuccessful = FALSE;
					break;
				}
			} else {
				$updateQuery = sprintf('UPDATE aggregated_host SET skipped=1,location=\'%s\' WHERE host_id=%u;',
					mysqli_real_escape_string($mysqli, serialize($owner)),
					$row['host_id']);
				fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
				$updateResult = $mysqli->query($updateQuery);
				if (!is_bool($updateResult) || !$updateResult) {
					fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
					$isSuccessful = FALSE;
					break;
				}
			}
		}
		*/
	}
	$resHost->free_result();
	mysqli_close($mysqli);
	echo(PHP_EOL);
} else {
	printf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno);
	$isSuccessful = FALSE;
}


if (is_bool($isSuccessful) && $isSuccessful) {
	exit(0);
} else {
	die(1);
}


function CliErrorHandler($errno, $errstr, $errfile, $errline) {
	fwrite(STDERR, $errstr . ' in ' . $errfile . ' on ' . $errline . PHP_EOL);
}
?>