<?php
set_error_handler('CliErrorHandler');

$dir = dirname(__FILE__);
$libraryDir = realpath($dir . '/../../../library/php');
$vendorDir = realpath($dir . '/../../../vendor');

require_once $vendorDir. '/autoload.php';
require_once $libraryDir . '/Url/UrlFetcher.php';


$mysqli = @new mysqli('127.0.0.1', 'X', 'Y', 'Z', 3306);
if ($mysqli->connect_errno) {
    fwrite(STDERR, sprintf('ERROR: Database-Server: %s (Errno: %u)' . PHP_EOL, $mysqli->connect_error, $mysqli->connect_errno));
    die(1);
}

$mysqli->set_charset('utf8');
$selectQuery = 'SELECT domain_id,domain_name,extractedText '
    .  'FROM reg_domain '
    .  'WHERE skipped=0 AND location IS NULL AND latitude IS NULL AND longitude IS NULL AND extractedText IS NOT NULL '
    .  'ORDER BY domain_id ASC '
    .  ';';
$res = $mysqli->query($selectQuery);
fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $selectQuery));

if (is_object($res = $mysqli->query($selectQuery))) {
    $isSuccessful = TRUE;

    $objUrlFetcher = new \T3census\Url\UrlFetcher();
    $yql_base_url = 'http://query.yahooapis.com/v1/public/yql';

    $yql_query = 'SELECT * FROM geo.placemaker WHERE documentContent = "%s" AND documentType="text/plain"';

    while ($row = $res->fetch_assoc()) {
        if (1 !== preg_match('/\b\d{5,5}\b\s+\w/', $row['extractedText'])) {
            continue;
        }

        $arrExtractedText = explode(PHP_EOL, $row['extractedText']);

        $addressString = '';

        foreach ($arrExtractedText as $line => $content) {

            if (1 === preg_match('/\b\d{5,5}\b\s+\w/', $content)) {

                    $addressString = $arrExtractedText[$line];
                    if (array_key_exists(intval($line - 1), $arrExtractedText)) {
                        $addressString = $arrExtractedText[$line - 1] . ', ' . $addressString;
                    }

                    $yql_query_url = $yql_base_url . "?format=json&q=" . urlencode(sprintf($yql_query, $addressString));

                    $objUrlFetcher->setUrl($yql_query_url)->fetchUrl(\T3census\Url\UrlFetcher::HTTP_GET);
                    $json = $objUrlFetcher->getBody();

                    if (!empty($json)) {
                        $phpObj =  json_decode(trim($json));
                        if (!is_null($phpObj->query->results) && !is_null($phpObj->query->results->matches)) {
                            print_r($arrExtractedText);
                            $updateQuery = sprintf('UPDATE reg_domain SET skipped=0,extractedText=NULL,location=\'%s\' WHERE domain_id=%u;',
                                mysqli_real_escape_string($mysqli, $addressString . ', DE'),
                                $row['domain_id']);
                            fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
                            $updateResult = $mysqli->query($updateQuery);
                            if (!is_bool($updateResult) || !$updateResult) {
                                fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
                                $isSuccessful = FALSE;
                                break 2;
                            }
                        } else {
                            #echo((check_utf8($addressString) ? 'IS UTF-8' : 'NO UTF-8') . PHP_EOL);
                            if (check_utf8($addressString)) {
                                $addressString = \ForceUTF8\Encoding::fixUTF8($addressString);
                                echo($addressString . PHP_EOL);

                                $yql_query_url = $yql_base_url . "?format=json&q=" . urlencode(sprintf($yql_query, $addressString));

                                $objUrlFetcher->setUrl($yql_query_url)->fetchUrl(\T3census\Url\UrlFetcher::HTTP_GET);
                                $json = $objUrlFetcher->getBody();

                                if (!empty($json)) {
                                    $phpObj =  json_decode(trim($json));
                                    if (!is_null($phpObj->query->results) && !is_null($phpObj->query->results->matches)) {
                                        print_r($arrExtractedText);
                                        $updateQuery = sprintf('UPDATE reg_domain SET skipped=0,extractedText=NULL,location=\'%s\' WHERE domain_id=%u;',
                                            mysqli_real_escape_string($mysqli, $addressString . ', DE'),
                                            $row['domain_id']);
                                        fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
                                        $updateResult = $mysqli->query($updateQuery);
                                        if (!is_bool($updateResult) || !$updateResult) {
                                            fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
                                            $isSuccessful = FALSE;
                                            break 2;
                                        }
                                    } /* else {
                                        $updateQuery = sprintf('UPDATE reg_domain SET skipped=0,extractedText=NULL,location=\'%s\' WHERE domain_id=%u;',
                                            mysqli_real_escape_string($mysqli, $addressString . ', DE'),
                                            $row['domain_id']);
                                        fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
                                        $updateResult = $mysqli->query($updateQuery);
                                        if (!is_bool($updateResult) || !$updateResult) {
                                            fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
                                            $isSuccessful = FALSE;
                                            break 2;
                                        }
                                    } */
                                }
                            }
                        }
                    }
                #}
                break;
            }
        }

        #echo($yql_query_url . PHP_EOL);
        #echo($addressString . PHP_EOL);

        #break;
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

function check_utf8($str) {
    $len = strlen($str);
    for($i = 0; $i < $len; $i++){
        $c = ord($str[$i]);
        if ($c > 128) {
            if (($c > 247)) return false;
            elseif ($c > 239) $bytes = 4;
            elseif ($c > 223) $bytes = 3;
            elseif ($c > 191) $bytes = 2;
            else return false;
            if (($i + $bytes) > $len) return false;
            while ($bytes > 1) {
                $i++;
                $b = ord($str[$i]);
                if ($b < 128 || $b > 191) return false;
                $bytes--;
            }
        }
    }
    return true;
}

function CliErrorHandler($errno, $errstr, $errfile, $errline) {
    fwrite(STDERR, $errstr . ' in ' . $errfile . ' on ' . $errline . PHP_EOL);
}

?>