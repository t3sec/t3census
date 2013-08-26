<?php
set_error_handler('CliErrorHandler');

$dir = dirname(__FILE__);
$libraryDir = realpath($dir . '/../../library/php');
$vendorDir = realpath($dir . '/../../vendor');

require_once $libraryDir . '/IpHelper.php';


$mysqli = @new mysqli('127.0.0.1', 't3census_dbu', 't3census', 't3census_db', 33006);
if ($mysqli->connect_errno) {
	fwrite(STDERR, sprintf('ERROR: Database-Server: %s (Errno: %u)' . PHP_EOL, $mysqli->connect_error, $mysqli->connect_errno));
	die(1);
}


$isSuccessful = TRUE;


$foo = new IpHelper();

$range = IpHelper::getIpRangeByCidr('87.213.168.32/28');
#'87.213.168.32/28'
#print_r($range);
#print_r(IpHelper::getBroadcastIpByCidr('87.213.168.32', '28'));
#IpHelper::getNetworkStatistics('87.213.168.32', '255.255.255.240');
#var_dump(IpHelper::isIpMask('255.255.255.240'));
#var_dump(IpHelper::isIpInCidr('87.213.168.30', '87.213.168.32', '28'));
#var_dump(IpHelper::isIpInCidr2('87.213.168.30', '87.213.168.32/255.255.255.240'));
#print_r(IpHelper::getIpsFromCidr('87.213.168.32', '28'));


$selectQuery = 'SELECT cidr_id,INET_NTOA(cidr_ip) AS ip, mask_to_cidr(INET_NTOA(cidr_mask)) AS cidr, created '
		. 'FROM cidr '
		. 'WHERE updated IS NULL '
		. 'ORDER BY cidr DESC '
		. 'LIMIT 3;';
#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $selectQuery));
$res = $mysqli->query($selectQuery);


if (is_object($res)) {
	if ($res->num_rows > 0) {
		$date = new DateTime();

		while ($row = $res->fetch_assoc()) {
			print_r($row);
			$ips = IpHelper::getIpsFromCidr($row['ip'], $row['cidr']);
			foreach ($ips as $ipAddress) {
				$insertQuery = sprintf('INSERT INTO server(server_ip,created) VALUES (INET_ATON(\'%s\'),\'%s\');',
					$ipAddress,
					$date->format('Y-m-d H:i:s')
				);
				#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $insertQuery));
				$insertResult = $mysqli->query($insertQuery);
				if (!is_bool($insertResult) || !$insertResult) {
					fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
				}
			}

			$updateQuery = sprintf('UPDATE cidr SET updated=\'%s\' WHERE cidr_id=%u;',
				$date->format('Y-m-d H:i:s'),
				$row['cidr_id']
			);
			#fwrite(STDOUT, sprintf('DEBUG: Query: %s' . PHP_EOL, $updateQuery));
			$updateResult = $mysqli->query($updateQuery);
			if (!is_bool($updateResult) || !$updateResult) {
				fwrite(STDERR, sprintf('ERROR: %s (Errno: %u)' . PHP_EOL, $mysqli->error, $mysqli->errno));
				$isSuccessful = FALSE;
				break;
			}
		}
	}
	$res->close();
}


mysqli_close($mysqli);
fwrite(STDOUT, PHP_EOL);


if (is_bool($isSuccessful) && $isSuccessful) {
	exit(0);
} else {
	die(1);
}


function CliErrorHandler($errno, $errstr, $errfile, $errline) {
	fwrite(STDERR, $errstr . ' in ' . $errfile . ' on ' . $errline . PHP_EOL);
}

?>
