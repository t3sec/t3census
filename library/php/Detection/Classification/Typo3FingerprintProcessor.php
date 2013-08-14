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
				$objHostOnlyUrl = new \Purl\Url($urlHostOnly);
				$objFullPathUrl = new \Purl\Url($urlFullPath);
				$objHostOnlyUrl->path = $newFile;
				$hostOnlyUrl = $objHostOnlyUrl->getUrl();

				$pathSegments = explode('/', $newFile);
				foreach($pathSegments as $segment) {
					$objFullPathUrl->path->add($segment);
				}
				$fullPathUrl = $objFullPathUrl->getUrl();

				echo($hostOnlyUrl . PHP_EOL);
				$objFetcher->setUrl($hostOnlyUrl)->fetchUrl(\T3census\Url\UrlFetcher::HTTP_HEAD, FALSE, FALSE);
				$fetcherErrnoHostOnly = $objFetcher->getErrno();
				$responseHttpCode = $objFetcher->getResponseHttpCode();
				if ($fetcherErrnoHostOnly === 0 && $responseHttpCode === 200) {
					var_dump($responseHttpCode);
					echo(PHP_EOL . PHP_EOL);
					$isClassificationSuccessful = TRUE;
					$TYPO3version = $data['TYPO3version'];
					break;
				}

				if (0 !== strcmp($hostOnlyUrl, $fullPathUrl)) {
					echo($fullPathUrl . PHP_EOL);
					$objFetcher->setUrl($fullPathUrl)->fetchUrl(\T3census\Url\UrlFetcher::HTTP_HEAD, FALSE, FALSE);
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
		}
		unset($fingerprintData, $urlFullPath, $pathString, $path, $urlFullPath, $urlHostOnly, $objUrl);

		if ($isClassificationSuccessful) {
			$context->setTypo3VersionString($TYPO3version);
		}

		if (!is_null($this->successor) && !$isClassificationSuccessful) {
			$this->successor->process($context);
		}
	}
}