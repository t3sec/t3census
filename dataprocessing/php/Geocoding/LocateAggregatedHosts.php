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

$selectQuery = 'SELECT domain_id,domain_name,skipped '
    .  'FROM reg_domain '
    .  'WHERE skipped=0 AND (domain_id % 2 = 0) AND latitude IS NULL AND longitude IS NULL AND location IS NULL AND domain_suffix NOT LIKE \'de\' AND domain_suffix NOT LIKE \'fr\' AND domain_suffix NOT LIKE \'at\' '
    .  'ORDER BY domain_id ASC LIMIT 1000;';
$res = $mysqli->query($selectQuery);
fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $selectQuery));

if (is_object($res = $mysqli->query($selectQuery))) {
    $isSuccessful = TRUE;

    $objParser = new \Novutec\WhoisParser\Parser();

    $counter = 0;
    while ($row = $res->fetch_assoc()) {
        $counter++;
        if ($counter % 20 === 0)  sleep(30);
        print_r($row);

        $record = $objParser->lookup($row['domain_name']);

        if (is_object($record) && $record instanceOf \Novutec\WhoisParser\Result) {

            $location = array();

            if (!property_exists($record, 'contacts') || !property_exists($record->contacts, 'owner')) {
                $updateQuery = sprintf('UPDATE reg_domain SET skipped=1 WHERE domain_id=%u;',
                    $row['domain_id']);
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
            if (is_array($location['address']))  $location['address'] = join(', ', $location['address']);
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
                $updateQuery = sprintf('UPDATE reg_domain SET location=\'%s\' WHERE domain_id=%u;',
                    mysqli_real_escape_string($mysqli, $locationString),
                    $row['domain_id']);
                fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
                $updateResult = $mysqli->query($updateQuery);
                if (!is_bool($updateResult) || !$updateResult) {
                    fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
                    $isSuccessful = FALSE;
                    break;
                }
            } else {
                $updateQuery = sprintf('UPDATE reg_domain SET skipped=1,location=\'%s\' WHERE domain_id=%u;',
                    mysqli_real_escape_string($mysqli, serialize($owner)),
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