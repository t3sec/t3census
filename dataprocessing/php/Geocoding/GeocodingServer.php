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

$selectQuery = 'SELECT s.server_id, INET_NTOA(s.server_ip) AS server_ip, COUNT(h.host_id) AS typo3hosts '
			.  'FROM server s RIGHT JOIN host h ON (s.server_id = h.fk_server_id) '
			.  'WHERE s.latitude IS NULL AND s.longitude IS NULL AND h.typo3_installed=1 '
			.  'GROUP BY s.server_id '
			.  'HAVING typo3hosts >= 1 '
			.  'ORDER BY typo3hosts DESC;';
$res = $mysqli->query($selectQuery);
fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $selectQuery));

if (is_object($res = $mysqli->query($selectQuery))) {
	$isSuccessful = TRUE;

	$geocoder = new \Geocoder\Geocoder();
	$adapter  = new \Geocoder\HttpAdapter\CurlHttpAdapter();
	$geocoder->registerProviders(array(
		new \Geocoder\Provider\GoogleMapsProvider($adapter),
		new \Geocoder\Provider\FreeGeoIpProvider($adapter),

	));

	while ($row = $res->fetch_assoc()) {
#		print_r($row);
		try {
			$geotools = new \League\Geotools\Geotools();
			$geocode = $geocoder->using('free_geo_ip')->geocode($row['server_ip']);
#			var_dump($geocode);

			if (is_object($geocode) && $geocode instanceOf \Geocoder\Result\ResultInterface && in_array('Geocoder\Result\ResultInterface', class_implements($geocode))) {
				$updateQuery = sprintf('UPDATE server SET latitude=%F,longitude=%F WHERE server_id=%u;',
					$geocode->getLatitude(),
					$geocode->getLongitude(),
					$row['server_id']);
				fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
				$updateResult = $mysqli->query($updateQuery);
				if (!is_bool($updateResult) || !$updateResult) {
					fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
					$isSuccessful = FALSE;
					break;
				}

				#$location = $geocoder->using('google_maps')->reverse($geocode->getLatitude(), $geocode->getLongitude());
				#var_dump($location);
			}
		} catch (Exception $e) {
			echo $e->getMessage();
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


function CliErrorHandler($errno, $errstr, $errfile, $errline) {
	fwrite(STDERR, $errstr . ' in ' . $errfile . ' on ' . $errline . PHP_EOL);
}

?>