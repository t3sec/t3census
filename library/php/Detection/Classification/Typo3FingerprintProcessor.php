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
					'typo3/contrib/requirejs/require.js',
					'typo3/contrib/jquery/jquery-1.9.1.min.js',
				)
			),
			2 => array(
				'TYPO3version' => 'TYPO3 6.0 CMS',
				'newFiles' => array(
					'typo3/contrib/jquery/jquery-1.8.2.min.js',
					'typo3/sysext/lang/Resources/Public/Contrib/jquery.dataTables-1.9.4.min.js',
				)
			),
			3 => array(
				'TYPO3version' => 'TYPO3 4.7 CMS',
				'newFiles' => array(
					'typo3/contrib/videojs/video-js/video.js',
					'typo3/contrib/flowplayer/src/javascript/flowplayer.js/flowplayer-3.2.10.js',
				)
			),
			4 => array(
				'TYPO3version' => 'TYPO3 4.6 CMS',
				'newFiles' => array(
					'typo3/contrib/codemirror/contrib/scheme/js/parsescheme.js',
					'typo3/sysext/css_styled_content/static/v4.5/setup.txt',
				)
			),
			5 => array(
				'TYPO3version' => 'TYPO3 4.5 CMS',
				'newFiles' => array(
					'typo3/js/livesearch.js',
					'typo3/contrib/extjs/resources/css/visual/pivotgrid.css',
					'typo3/contrib/modernizr/modernizr.min.js',
					'typo3/contrib/extjs/locale/ext-lang-am.js',
				)
			),
			6 => array(
				'TYPO3version' => 'TYPO3 4.4 CMS',
				'newFiles' => array(
					'typo3/js/pagetreefiltermenu.js',
					't3lib/js/extjs/ux/flashmessages.js',
					'typo3/contrib/extjs/adapter/ext/ext-base-debug-w-comments.js',
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

				unset($objFullPathUrl, $objHostOnlyUrl);

				$objFetcher->setUrl($hostOnlyUrl)->fetchUrl(\T3census\Url\UrlFetcher::HTTP_HEAD, FALSE, FALSE);
				$fetcherErrnoHostOnly = $objFetcher->getErrno();
				$responseHttpCode = $objFetcher->getResponseHttpCode();
				if ($fetcherErrnoHostOnly === 0 && $responseHttpCode === 200) {
					$isClassificationSuccessful = TRUE;
					$TYPO3version = $data['TYPO3version'];
					break;
				}

				if (0 !== strcmp($hostOnlyUrl, $fullPathUrl)) {
					$objFetcher->setUrl($fullPathUrl)->fetchUrl(\T3census\Url\UrlFetcher::HTTP_HEAD, FALSE, FALSE);
					$fetcherErrnoHostOnly = $objFetcher->getErrno();
					$responseHttpCode = $objFetcher->getResponseHttpCode();
					if ($fetcherErrnoHostOnly === 0 && $responseHttpCode === 200) {
						$isClassificationSuccessful = TRUE;
						$TYPO3version = $data['TYPO3version'];
						break;
					}
				}
			}
		}
		unset($fingerprintData, $urlFullPath, $pathString, $path, $urlFullPath, $urlHostOnly, $objUrl, $objFetcher);

		if ($isClassificationSuccessful) {
			$context->setTypo3VersionString($TYPO3version);
		}

		if (!is_null($this->successor) && !$isClassificationSuccessful) {
			$this->successor->process($context);
		}
	}
}