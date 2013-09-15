<?php
set_error_handler('CliErrorHandler');

$dir = dirname(__FILE__);
$libraryDir = realpath($dir . '/../../../library/php');
$vendorDir = realpath($dir . '/../../../vendor');

require_once $libraryDir . '/Bing/Api/ReverseIpLookup.php';
require_once $libraryDir . '/Bing/Scraper/ReverseIpLookup.php';
require_once $libraryDir . '/Url/UrlFetcher.php';

require_once $vendorDir. '/autoload.php';


$mysqli = @new mysqli('127.0.0.1', 'X', 'Y', 'Z', 3306);
if ($mysqli->connect_errno) {
	fwrite(STDERR, sprintf('ERROR: Database-Server: %s (Errno: %u)' . PHP_EOL, $mysqli->connect_error, $mysqli->connect_errno));
	die(1);
}

$selectQuery = 'SELECT domain_id,domain_name,skipped '
			.  'FROM reg_domain '
			.  'WHERE skipped=0 AND latitude IS NULL AND longitude IS NULL AND location IS NULL AND extractedText IS NULL AND domain_suffix LIKE \'de\' '
			.  'ORDER BY domain_id ASC LIMIT 200;';
$res = $mysqli->query($selectQuery);
fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $selectQuery));

if (is_object($res = $mysqli->query($selectQuery))) {
	$isSuccessful = TRUE;

	$objUrlFetcher = new \T3census\Url\UrlFetcher();

	while ($row = $res->fetch_assoc()) {

		#print_r($row);

		/**try {
			$objLookup = new T3census\Bing\Api\ReverseIpLookup();
			$objLookup->setAccountKey('euXsokQXoAKcvyoHjuY8OOf5Zs5lHfNpSCmheWOzkr8')->setEndpoint('https://api.datamarket.azure.com/Bing/Search');
			$results = $objLookup->setQuery('instreamset:(title):impressum site:' . $row['domain_name'])->setOffset(0)->setMaxResults(1)->getResults(FALSE);
			unset($objLookup);
		} catch (\T3census\Bing\Api\Exception\ApiConsumeException $e) {*/
			$objLookup = new \T3census\Bing\Scraper\ReverseIpLookup();
			$objLookup->setEndpoint('http://www.bing.com/search');
			$results = $objLookup->setQuery('instreamset:(title):impressum site:' . $row['domain_name'])->setOffset(0)->setMaxResults(1)->getResults(FALSE);
			unset($objLookup);
		#}

		#print_r($results);

		if (is_array($results) && !empty($results)) {
			print_r($row);
			#$results = array_reverse($results);

			$imprintUrl = array_pop($results);
			fwrite(STDOUT, $imprintUrl . PHP_EOL);

			$url = sprintf('http://www.diffbot.com/api/article?timeout=%u&token=%s&url=%s',
				5000,
				'c8723c4c7c25739e2df831b2e8c6db8f',
				urlencode($imprintUrl)
			);
			$objUrlFetcher->setUrl($url)->fetchUrl(\T3census\Url\UrlFetcher::HTTP_GET);
			$body = $objUrlFetcher->getBody();

			$apiResult = json_decode($body);
			if (is_object($apiResult) && property_exists($apiResult, 'text')) {
				fwrite(STDOUT, $apiResult->text . PHP_EOL);

				$updateQuery = sprintf('UPDATE reg_domain SET skipped=0,extractedText=\'%s\' WHERE domain_id=%u;',
					mysqli_real_escape_string($mysqli, $apiResult->text),
					$row['domain_id']);
				#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
				$updateResult = $mysqli->query($updateQuery);
				if (!is_bool($updateResult) || !$updateResult) {
					fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
					$isSuccessful = FALSE;
					break;
				}
			} else {
                $updateQuery = sprintf('UPDATE reg_domain SET skipped=1 WHERE domain_id=%u;',
                    $row['domain_id']);
                #fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
                $updateResult = $mysqli->query($updateQuery);
                if (!is_bool($updateResult) || !$updateResult) {
                    fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
                    $isSuccessful = FALSE;
                    break;
                }
            }
		} else {
			$updateQuery = sprintf('UPDATE reg_domain SET skipped=1 WHERE domain_id=%u;',
				$row['domain_id']);
			#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
			$updateResult = $mysqli->query($updateQuery);
			if (!is_bool($updateResult) || !$updateResult) {
				fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
				$isSuccessful = FALSE;
				break;
			}
		}

		sleep(3);
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