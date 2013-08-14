<?php
namespace T3census\Detection\Classification;

$dir = dirname(__FILE__);
$libraryDir = realpath($dir . '/../../../../library/php');
$vendorDir = realpath($dir . '/../../../../vendor');

require_once $libraryDir . '/Detection/AbstractProcessor.php';
require_once $libraryDir . '/Detection/ProcessorInterface.php';
require_once $libraryDir . '/Detection/DomParser.php';
require_once $libraryDir . '/Url/UrlFetcher.php';
require_once $vendorDir . '/autoload.php';


class Typo3FingerprintProcessor extends \T3census\Detection\AbstractProcessor implements \T3census\Detection\ProcessorInterface {

	/**
	 * Class constructor.
	 *
	 * @param  \T3census\Detection\ProcessorInterface|null  $successor
	 */
	public function __construct($successor = NULL) {
		if (!is_null($successor)) {
			$this->successor = $successor;
		}
	}

	/**
	 * Processes context.
	 *
	 * @param  \T3census\Detection\Context  $context
	 * @return  void
	 */
	public function process(\T3census\Detection\Context $context) {
		$isClassificationSuccessful = FALSE;

		$objFetcher = new \T3census\Url\UrlFetcher();
		#$objFetcher->setUserAgent('Opera/99.0');
		$objUrl = \Purl\Url::parse($context->getUrl());

		$urlHostOnly = $objUrl->get('scheme') . '://' . $objUrl->get('host');
		$urlFullPath = $objUrl->get('scheme') . '://' . $objUrl->get('host');
		$path = $objUrl->path->getData();
		$path = array_reverse($path);
		$pathString = '';
		$i=0;
		foreach ($path as $pathSegment) {
			if (!empty($pathSegment)) {
				if ($i === 0) {
					if (!is_int(strpos($pathSegment, '.'))) {
						$pathString =  $pathString . '/' . $pathSegment;
					}
				} else {
					$pathString = $pathString . '/' . $pathSegment;
				}
			}
			$i++;
		}
		$urlFullPath .= $pathString;

		$fingerprintData = array(
			0 => array(
				'TYPO3version' => 'TYPO3 6.2 CMS',
				'newFiles' => array(
					'typo3/sysext/t3skin/Resources/Public/JavaScript/login.js',
					'typo3/sysext/install/Resources/Public/Javascript/Install.js',
				)
			),
			1 => array(
				'TYPO3version' => 'TYPO3 6.1 CMS',
				'newFiles' => array(
					'typo3/contrib/jquery/jquery-1.9.1.min.js',
					'typo3/contrib/requirejs/require.js',
				)
			),
		);

		$TYPO3version = NULL;

		foreach ($fingerprintData as $data) {
			foreach ($data['newFiles'] as $newFile) {
				$objHostOnlyBackendUrl = new \Purl\Url($urlHostOnly);
				$objFullPathBackendUrl = new \Purl\Url($urlFullPath);
				$objHostOnlyBackendUrl->path = $newFile;
				$hostOnlyBackendUrl = $objHostOnlyBackendUrl->getUrl();

				$pathSegments = explode('/', $newFile);
				foreach($pathSegments as $segment) {
					$objFullPathBackendUrl->path->add($segment);
				}
				$fullPathBackendUrl = $objFullPathBackendUrl->getUrl();

				echo($hostOnlyBackendUrl . PHP_EOL);
				$objFetcher->setUrl($hostOnlyBackendUrl)->fetchUrl(\T3census\Url\UrlFetcher::HTTP_HEAD, FALSE, FALSE);
				$fetcherErrnoHostOnly = $objFetcher->getErrno();
				$responseHttpCode = $objFetcher->getResponseHttpCode();

				if ($fetcherErrnoHostOnly === 0 && $responseHttpCode === 200) {
					var_dump($responseHttpCode);
					echo(PHP_EOL . PHP_EOL);
					$isClassificationSuccessful = TRUE;
					$TYPO3version = $data['TYPO3version'];
					break;
				}

				echo($fullPathBackendUrl . PHP_EOL);
				$objFetcher->setUrl($fullPathBackendUrl)->fetchUrl(\T3census\Url\UrlFetcher::HTTP_HEAD, FALSE, FALSE);
				$fetcherErrnoHostOnly = $objFetcher->getErrno();
				$responseHttpCode = $objFetcher->getResponseHttpCode();
				if ($fetcherErrnoHostOnly === 0 && $responseHttpCode === 200) {
					var_dump($responseHttpCode);
					echo(PHP_EOL . PHP_EOL);
					$isClassificationSuccessful = TRUE;
					$TYPO3version = $data['TYPO3version'];
					break;
				}
			}
		}
		echo($TYPO3version . PHP_EOL);
		unset($objFetcher, $objUrl);

		if (!is_null($this->successor) && !$isClassificationSuccessful) {
			$this->successor->process($context);
		}
	}
}