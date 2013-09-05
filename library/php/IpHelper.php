<?php
/**
 * Created by JetBrains PhpStorm.
 * User: marcus
 * Date: 27.06.13
 * Time: 17:59
 * To change this template use File | Settings | File Templates.
 */
class IpHelper {

	/**
	 * Keeps private IP ranges in CIDR notation.
	 *
	 * @var array
	 * @see https://doc.wikimedia.org/mediawiki-core/master/php/html/IP_8php_source.html
	 */
	public static $privateRanges = array(
		'0.0.0.0/8', // this network
		'10.0.0.0/8', // RFC 1918 (private)
		'127.0.0.0/8', // loopback
		'172.16.0.0/12', // RFC 1918 (private)
		'192.168.0.0/16', // RFC 1918 (private)
	);

	public static function getIpRangeByCidr($cidr_address) {
		$first = substr($cidr_address, 0, strpos($cidr_address, "/"));
		$netmask = substr(strstr($cidr_address, "/"), 1);

		$first_bin = str_pad(decbin(ip2long($first)), 32, "0", STR_PAD_LEFT);
		$netmask_bin = str_pad(str_repeat("1", (integer)$netmask), 32, "0", STR_PAD_RIGHT);

		$last_bin = '';
		for ($i = 0; $i < 32; $i++) {
			if ($netmask_bin[$i] == "1")
				$last_bin .= $first_bin[$i];
			else
				$last_bin .= "1";
		}

		$last = long2ip(bindec($last_bin));

		return array($first, $last);
	}

	/**
	 * @param $network
	 * @param $cidr
	 * @return string
	 * @see  https://mebsd.com/coding-snipits/broadcast-from-network-cidr-equation-examples.html
	 */
	public static function getBroadcastIpByCidr($network, $cidr) {
		$broadcast = long2ip(ip2long($network) + pow(2, (32 - $cidr)) - 1);

		return $broadcast;
	}

	public static function isIpInCidr($ip, $net_addr, $net_mask) {
		if ($net_mask <= 0) {
			return FALSE;
		}
		$ip_binary_string = sprintf("%032b", ip2long($ip));
		$net_binary_string = sprintf("%032b", ip2long($net_addr));
		return (substr_compare($ip_binary_string, $net_binary_string, 0, $net_mask) === 0);
	}

	/**
	 * @param $ip
	 * @param $cidr
	 * @return bool
	 * @see  http://php.net/manual/fr/function.ip2long.php
	 */
	public static function isIpInCidr2($ip, $cidr) {
		/* get the base and the bits from the ban in the database */
		list($base, $bits) = explode('/', $cidr);

		if (strpos($bits, '.') !== FALSE) {
			$bits = self::maskToCIDR($bits);
		}

		/* now split it up into it's classes */
		list($a, $b, $c, $d) = explode('.', $base);

		/* now do some bit shfiting/switching to convert to ints */
		$i = ($a << 24) + ($b << 16) + ($c << 8) + $d;
		$mask = $bits == 0 ? 0 : (~0 << (32 - $bits));

		/* here's our lowest int */
		$low = $i & $mask;

		/* here's our highest int */
		$high = $i | (~$mask & 0xFFFFFFFF);

		/* now split the ip were checking against up into classes */
		list($a, $b, $c, $d) = explode('.', $ip);

		/* now convert the ip we're checking against to an int */
		$check = ($a << 24) + ($b << 16) + ($c << 8) + $d;

		/* if the ip is within the range, including
	  highest/lowest values, then it's witin the CIDR range */
		if ($check >= $low && $check <= $high)
			return TRUE;
		else
			return FALSE;
	}

	/**
	 * @param $ip
	 * @param $netMask
	 * @see  http://php.net/manual/fr/function.ip2long.php
	 */
	public static function getNetworkStatistics($ip, $netMask) {
		$ip = ip2long($ip);
		$nm = ip2long($netMask);
		$nw = ($ip & $nm);
		$bc = $nw | (~$nm);

		echo "IP Address:         " . long2ip($ip) . "\n";
		echo "Subnet Mask:        " . long2ip($nm) . "\n";
		echo "Network Address:    " . long2ip($nw) . "\n";
		echo "Broadcast Address:  " . long2ip($bc) . "\n";
		echo "Number of Hosts:    " . ($bc - $nw - 1) . "\n";
		echo "Host Range:         " . long2ip($nw + 1) . " -> " . long2ip($bc - 1) . "\n";
	}

	public static function getIpsFromCidr($ip, $cidr) {

		$ips = array();
		$ip_count = 1 << (32 - $cidr);

		$start = ip2long($ip);
		for ($i = 0; $i < $ip_count; $i++) {
			$ips[] = long2ip($start + $i);
		}

		/*
		$ip = ip2long($ip);
		$nm = ip2long($netMask);
		$nw = ($ip & $nm);
		$bc = $nw | (~$nm);

		echo "Number of Hosts:    " . ($bc - $nw - 1) . PHP_EOL;
		echo "Host Range:         " . long2ip($nw + 1) . " -> " . long2ip($bc - 1) . PHP_EOL;

		for($zm=1;($nw + $zm)<=($bc - 1);$zm++)
		{
			echo long2ip($nw + $zm) . PHP_EOL;
		}*/

		return $ips;
	}

	/**
	 * @param $mask
	 * @return bool
	 */
	public static function isIpMask($mask) {

		$format = '';
		if (preg_match("/[0-9]++\.[0-9]++\.[0-9]++\.[0-9]++/", $mask)) {
			$format = "long";
		} else {
			if ($mask <= 30) {
				$format = "short";
			} else {
				return FALSE;
			}
		}
		switch ($format) {
			case 'long';
				$mask = decbin(ip2long($mask));
				break;
			case 'short':
				$tmp = $mask;
				for ($i = 0; $i < $mask; $i++) {
					$tmp .= 1;
				}
				for ($j = 0; $j < (32 - $mask); $j++) {
					$tmp .= 0;
				}
				$mask = $tmp;
				break;
		}
		if (strlen($mask) <= 32) {
			for ($i = 0; $i <= 32; $i++) {
				$bit = substr($mask, $i, 1);
				if (($bit - substr($mask, $i + 1, 1)) < 0) {
					return FALSE;
				}
			}
		}
		return TRUE;
	}

	/**
	 *  ipv4_in_range
	 * This function takes 2 arguments, an IP address and a "range" in several
	 * different formats.
	 * Network ranges can be specified as:
	 * 1. Wildcard format:     1.2.3.*
	 * 2. CIDR format:         1.2.3/24  OR  1.2.3.4/255.255.255.0
	 * 3. Start-End IP format: 1.2.3.0-1.2.3.255
	 * The function will return true if the supplied IP is within the range.
	 * Note little validation is done on the range inputs - it expects you to
	 * use one of the above 3 formats.
	 *
	 * @param $ip
	 * @param $range
	 * @return bool
	 * @see  http://plugins.svn.wordpress.org/cloudflare/tags/1.3.9/ip_in_range.php
	 */
	function ipv4_in_range($ip, $range) {
		if (strpos($range, '/') !== FALSE) {
			// $range is in IP/NETMASK format
			list($range, $netmask) = explode('/', $range, 2);
			if (strpos($netmask, '.') !== FALSE) {
				// $netmask is a 255.255.0.0 format
				$netmask = str_replace('*', '0', $netmask);
				$netmask_dec = ip2long($netmask);
				return ((ip2long($ip) & $netmask_dec) == (ip2long($range) & $netmask_dec));
			} else {
				// $netmask is a CIDR size block
				// fix the range argument
				$x = explode('.', $range);
				while (count($x) < 4) $x[] = '0';
				list($a, $b, $c, $d) = $x;
				$range = sprintf("%u.%u.%u.%u", empty($a) ? '0' : $a, empty($b) ? '0' : $b, empty($c) ? '0' : $c, empty($d) ? '0' : $d);
				$range_dec = ip2long($range);
				$ip_dec = ip2long($ip);

				# Strategy 1 - Create the netmask with 'netmask' 1s and then fill it to 32 with 0s
				#$netmask_dec = bindec(str_pad('', $netmask, '1') . str_pad('', 32-$netmask, '0'));

				# Strategy 2 - Use math to create it
				$wildcard_dec = pow(2, (32 - $netmask)) - 1;
				$netmask_dec = ~$wildcard_dec;

				return (($ip_dec & $netmask_dec) == ($range_dec & $netmask_dec));
			}
		} else {
			// range might be 255.255.*.* or 1.2.3.0-1.2.3.255
			if (strpos($range, '*') !== FALSE) { // a.b.*.* format
				// Just convert to A-B format by setting * to 0 for A and 255 for B
				$lower = str_replace('*', '0', $range);
				$upper = str_replace('*', '255', $range);
				$range = "$lower-$upper";
			}

			if (strpos($range, '-') !== FALSE) { // A-B format
				list($lower, $upper) = explode('-', $range, 2);
				$lower_dec = (float)sprintf("%u", ip2long($lower));
				$upper_dec = (float)sprintf("%u", ip2long($upper));
				$ip_dec = (float)sprintf("%u", ip2long($ip));
				return (($ip_dec >= $lower_dec) && ($ip_dec <= $upper_dec));
			}
			return FALSE;
		}
	}

	/**
	 * method maskToCIDR.
	 * Return a CIDR block number when given a valid netmask.
	 * Usage:
	 *     CIDR::maskToCIDR('255.255.252.0');
	 * Result:
	 *     int(22)
	 *
	 * @param $netmask String a 1pv4 formatted ip address.
	 * @access public
	 * @static
	 * @return int CIDR number.
	 * @see  https://gist.github.com/jonavon/2028872
	 */
	public static function maskToCIDR($netmask) {
		if (self::validNetMask($netmask)) {
			return self::countSetBits(ip2long($netmask));
		} else {
			throw new Exception('Invalid Netmask');
		}
	}

	/**
	 * method countSetBits.
	 * Return the number of bits that are set in an integer.
	 * Usage:
	 *     CIDR::countSetBits(ip2long('255.255.252.0'));
	 * Result:
	 *     int(22)
	 *
	 * @param $int int a number
	 * @access public
	 * @static
	 * @see  http://stackoverflow.com/questions/109023/best-algorithm-to-co\
	 * unt-the-number-of-set-bits-in-a-32-bit-integer
	 * @return int number of bits set.
	 * @see  https://gist.github.com/jonavon/2028872
	 */
	public static function countSetbits($int) {
		$int = $int - (($int >> 1) & 0x55555555);
		$int = ($int & 0x33333333) + (($int >> 2) & 0x33333333);
		return (($int + ($int >> 4) & 0xF0F0F0F) * 0x1010101) >> 24;
	}

	/**
	 * method validNetMask.
	 * Determine if a string is a valid netmask.
	 * Usage:
	 *     CIDR::validNetMask('255.255.252.0');
	 *     CIDR::validNetMask('127.0.0.1');
	 * Result:
	 *     bool(true)
	 *     bool(false)
	 *
	 * @param $netmask String a 1pv4 formatted ip address.
	 * @see  http://www.actionsnip.com/snippets/tomo_atlacatl/calculate-if-\
	 * a-netmask-is-valid--as2-
	 * @access public
	 * @static
	 * @return bool True if a valid netmask.
	 * @see  https://gist.github.com/jonavon/2028872
	 */
	public static function validNetMask($netmask) {
		$netmask = ip2long($netmask);
		$neg = ((~(int)$netmask) & 0xFFFFFFFF);
		return (($neg + 1) & $neg) === 0;
	}

	/**
	 * method CIDRtoMask
	 * Return a netmask string if given an integer between 0 and 32. I am
	 * not sure how this works on 64 bit machines.
	 * Usage:
	 *     CIDR::CIDRtoMask(22);
	 * Result:
	 *     string(13) "255.255.252.0"
	 *
	 * @param $int int Between 0 and 32.
	 * @access public
	 * @static
	 * @return String Netmask ip address
	 * @see  https://gist.github.com/jonavon/2028872
	 */
	public static function CIDRtoMask($int) {
		return long2ip(-1 << (32 - (int)$int));
	}

	/**
	 * method alignedCIDR.
	 * It takes an ip address and a netmask and returns a valid CIDR
	 * block.
	 * Usage:
	 *     CIDR::alignedCIDR('127.0.0.1','255.255.252.0');
	 * Result:
	 *     string(12) "127.0.0.0/22"
	 *
	 * @param $ipinput String a IPv4 formatted ip address.
	 * @param $netmask String a 1pv4 formatted ip address.
	 * @access public
	 * @static
	 * @return String CIDR block.
	 * @see  https://gist.github.com/jonavon/2028872
	 */
	public static function alignedCIDR($ipinput, $netmask) {
		$alignedIP = long2ip((ip2long($ipinput)) & (ip2long($netmask)));
		return "$alignedIP/" . self::maskToCIDR($netmask);
	}

	/**
	 * method IPisWithinCIDR.
	 * Check whether an IP is within a CIDR block.
	 * Usage:
	 *     CIDR::IPisWithinCIDR('127.0.0.33','127.0.0.1/24');
	 *     CIDR::IPisWithinCIDR('127.0.0.33','127.0.0.1/27');
	 * Result:
	 *     bool(true)
	 *     bool(false)
	 *
	 * @param $ipinput String a IPv4 formatted ip address.
	 * @param $cidr String a IPv4 formatted CIDR block. Block is aligned
	 * during execution.
	 * @access public
	 * @static
	 * @return String CIDR block.
	 * @see  https://gist.github.com/jonavon/2028872
	 */
	public static function IPisWithinCIDR($ipinput, $cidr) {
		$cidr = explode('/', $cidr);
		$cidr = self::alignedCIDR($cidr[0], self::CIDRtoMask((int)$cidr[1]));
		$cidr = explode('/', $cidr);
		$ipinput = (ip2long($ipinput));
		$ip1 = (ip2long($cidr[0]));
		$ip2 = ($ip1 + pow(2, (32 - (int)$cidr[1])) - 1);
		return (($ip1 <= $ipinput) && ($ipinput <= $ip2));
	}

	/**
	 * method maxBlock.
	 * Determines the largest CIDR block that an IP address will fit into.
	 * Used to develop a list of CIDR blocks.
	 * Usage:
	 *     CIDR::maxBlock("127.0.0.1");
	 *     CIDR::maxBlock("127.0.0.0");
	 * Result:
	 *     int(32)
	 *     int(8)
	 *
	 * @param $ipinput String a IPv4 formatted ip address.
	 * @access public
	 * @static
	 * @return int CIDR number.
	 * @see  https://gist.github.com/jonavon/2028872
	 */
	public static function maxBlock($ipinput) {
		return self::maskToCIDR(long2ip(-(ip2long($ipinput) & -(ip2long($ipinput)))));
	}

	/**
	 * method rangeToCIDRList.
	 * Returns an array of CIDR blocks that fit into a specified range of
	 * ip addresses.
	 * Usage:
	 *     CIDR::rangeToCIDRList("127.0.0.1","127.0.0.34");
	 * Result:
	 *     array(7) {
	 *       [0]=> string(12) "127.0.0.1/32"
	 *       [1]=> string(12) "127.0.0.2/31"
	 *       [2]=> string(12) "127.0.0.4/30"
	 *       [3]=> string(12) "127.0.0.8/29"
	 *       [4]=> string(13) "127.0.0.16/28"
	 *       [5]=> string(13) "127.0.0.32/31"
	 *       [6]=> string(13) "127.0.0.34/32"
	 *     }
	 *
	 * @param $startIPinput String a IPv4 formatted ip address.
	 * @param $startIPinput String a IPv4 formatted ip address.
	 * @see  http://null.pp.ru/src/php/Netmask.phps
	 * @return Array CIDR blocks in a numbered array.
	 * @see  https://gist.github.com/jonavon/2028872
	 */
	public static function rangeToCIDRList($startIPinput, $endIPinput = NULL) {
		$start = ip2long($startIPinput);
		$end = (empty($endIPinput)) ? $start : ip2long($endIPinput);
		while ($end >= $start) {
			$maxsize = self::maxBlock(long2ip($start));
			$maxdiff = 32 - intval(log($end - $start + 1) / log(2));
			$size = ($maxsize > $maxdiff) ? $maxsize : $maxdiff;
			$listCIDRs[] = long2ip($start) . "/$size";
			$start += pow(2, (32 - $size));
		}
		return $listCIDRs;
	}

	/**
	 * method cidrToRange.
	 * Returns an array of only two IPv4 addresses that have the lowest ip
	 * address as the first entry. If you need to check to see if an IPv4
	 * address is within range please use the IPisWithinCIDR method above.
	 * Usage:
	 *     CIDR::cidrToRange("127.0.0.128/25");
	 * Result:
	 *     array(2) {
	 *       [0]=> string(11) "127.0.0.128"
	 *       [1]=> string(11) "127.0.0.255"
	 *     }
	 *
	 * @param $cidr string CIDR block
	 * @return Array low end of range then high end of range.
	 * @see  https://gist.github.com/jonavon/2028872
	 */
	public static function cidrToRange($cidr) {
		$range = array();
		$cidr = explode('/', $cidr);
		$range[0] = long2ip((ip2long($cidr[0])) & ((-1 << (32 - (int)$cidr[1]))));
		$range[1] = long2ip((ip2long($cidr[0])) + pow(2, (32 - (int)$cidr[1])) - 1);
		return $range;
	}

}
