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

$mysqli->set_charset('utf8');
$selectQuery = 'SELECT domain_id,domain_name,location '
			.  'FROM reg_domain '
			.  'WHERE skipped=0 AND location IS NOT NULL AND latitude IS NULL AND longitude IS NULL '
            #.  'AND location LIKE \'%hamburg%\' '
			.  'ORDER BY domain_id ASC LIMIT 50;';
$res = $mysqli->query($selectQuery);
fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $selectQuery));

if (is_object($res = $mysqli->query($selectQuery))) {
	$isSuccessful = TRUE;

	$geocoder = new \Geocoder\Geocoder();
	$adapter  = new \Geocoder\HttpAdapter\CurlHttpAdapter();
	$geocoder->registerProviders(array(
		#new \Geocoder\Provider\OpenStreetMapsProvider($adapter),
		new \Geocoder\Provider\GoogleMapsProvider($adapter),
		#new \Geocoder\Provider\YandexProvider($adapter),
	));

	while ($row = $res->fetch_assoc()) {
		print_r($row);
		try {
			$geotools = new \League\Geotools\Geotools();
			$cache    = new \League\Geotools\Cache\Memcached();

			$batchResults = $geotools->batch($geocoder)->setCache($cache)->geocode($row['location'])->parallel();

			#var_dump($batchResults);
			$geocode = NULL;
			foreach ($batchResults as $result) {
                #print_r($result);
				$exception = $result->getExceptionMessage();
				if (empty($exception)) {
					$geocode = $result;
					break;
				}
				/**if (strtolower($result->getProviderName()) === 'openstreetmaps') {
					$geocode = $result;
					break;
				}*/
			}

			#$geocode = $geocoder->using('openstreetmaps')->geocode($row['location']); // yandex only fallback!
			#var_dump($geocode);

			if (is_object($geocode) && $geocode instanceOf \Geocoder\Result\ResultInterface && in_array('Geocoder\Result\ResultInterface', class_implements($geocode))) {
				$updateQuery = sprintf('UPDATE reg_domain SET latitude=%F,longitude=%F WHERE domain_id=%u;',
					$geocode->getLatitude(),
					$geocode->getLongitude(),
					$row['domain_id']);
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
			echo $e->getMessage() . PHP_EOL;
			$updateQuery = sprintf('UPDATE reg_domain SET latitude=NULL,longitude=NULL WHERE domain_id=%u;',
				$row['domain_id']);
			fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
			$updateResult = $mysqli->query($updateQuery);
			if (!is_bool($updateResult) || !$updateResult) {
				fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
				$isSuccessful = FALSE;
				break;
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


function CliErrorHandler($errno, $errstr, $errfile, $errline) {
	fwrite(STDERR, $errstr . ' in ' . $errfile . ' on ' . $errline . PHP_EOL);
}

?>