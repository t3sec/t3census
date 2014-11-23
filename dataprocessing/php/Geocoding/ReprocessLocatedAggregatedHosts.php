<?php
set_error_handler('CliErrorHandler');

$dir = dirname(__FILE__);
$libraryDir = realpath($dir . '/../../../library/php');
$vendorDir = realpath($dir . '/../../../vendor');

require_once $vendorDir. '/autoload.php';
require_once $libraryDir . '/IpHelper.php';


$mysqli = @new mysqli('127.0.0.1', 'X', 'Y', 'Z', 3306);
if ($mysqli->connect_errno) {
	fwrite(STDERR, sprintf('ERROR: Database-Server: %s (Errno: %u)' . PHP_EOL, $mysqli->connect_error, $mysqli->connect_errno));
	die(1);
}

$selectQuery = 'SELECT domain_id,domain_name,location '
			.  'FROM reg_domain '
			.  'WHERE skipped=1 AND latitude IS NULL AND longitude IS NULL AND location LIKE \'O:27%\' '
			.  'ORDER BY domain_id ASC;';
$res = $mysqli->query($selectQuery);
fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $selectQuery));

if (is_object($res = $mysqli->query($selectQuery))) {
	$isSuccessful = TRUE;

	$objParser = new \Novutec\WhoisParser\Parser();

	while ($row = $res->fetch_assoc()) {
		#print_r($row);
		#$location = unserialize($row['location']);
		#print_r($location);

		if (!isSerialized($row['location'])) {
			$updateQuery = sprintf('UPDATE reg_domain SET skipped=0,location=NULL WHERE domain_id=%u;',
				$row['domain_id']);
			fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
			$updateResult = $mysqli->query($updateQuery);
			if (!is_bool($updateResult) || !$updateResult) {
				fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
				$isSuccessful = FALSE;
				break;
			}
		} else {
			$unserializedLocation = unserialize($row['location']);
			#print_r($unserializedLocation);

			if (is_object($unserializedLocation) && $unserializedLocation instanceOf \Novutec\WhoisParser\Contact) {
				$location = array();
				$owner =& $unserializedLocation;

				if (!empty($owner->address)) {
					$location['address'] = $owner->address;
					if (is_array($location['address']))  $location['address'] = join(' ,', $location['address']);
				}
				if (!empty($owner->zipcode)) {
					$location['zipcode'] = $owner->zipcode;
				}
				if (!empty($owner->city)) {
					$location['city'] = $owner->city;
				}
				if (!empty($owner->country)) {
					$location['country'] = $owner->country;
				}

				if (!empty($location)) {
					#print_r($location);
					$locationString = join(', ', $location);
					fwrite(STDOUT, $locationString . PHP_EOL);

					$updateQuery = sprintf('UPDATE reg_domain SET skipped=0,location=\'%s\' WHERE domain_id=%u;',
						mysqli_real_escape_string($mysqli, $locationString),
						$row['domain_id']);
					#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
					$updateResult = $mysqli->query($updateQuery);
					if (!is_bool($updateResult) || !$updateResult) {
						fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
						$isSuccessful = FALSE;
						break;
					}
				}
			}
		}
	}

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

function isSerialized($str) {
	return ($str == serialize(false) || @unserialize($str) !== false);
}

function CliErrorHandler($errno, $errstr, $errfile, $errline) {
	fwrite(STDERR, $errstr . ' in ' . $errfile . ' on ' . $errline . PHP_EOL);
}

?>