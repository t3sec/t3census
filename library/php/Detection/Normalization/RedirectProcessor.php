<?php
namespace T3census\Detection\Normalization;


$dir = dirname(__FILE__);
$libraryDir = realpath($dir . '/../../../../library/php');
$vendorDir = realpath($dir . '/../../../../vendor');

require_once $libraryDir . '/Detection/AbstractProcessor.php';
require_once $libraryDir . '/Detection/ProcessorInterface.php';
require_once $libraryDir . '/Detection/DomParser.php';
require_once $libraryDir . '/Url/UrlFetcher.php';
require_once $vendorDir . '/autoload.php';


class RedirectProcessor extends \T3census\Detection\AbstractProcessor implements \T3census\Detection\ProcessorInterface {

	protected $allowRedirect = TRUE;


	/**
	 * Class constructor.
	 *
	 * @param  \T3census\Detection\ProcessorInterface|null $successor
	 * @param  bool $allowRedirect
	 */
	public function __construct($successor = NULL, $allowRedirect = TRUE) {
		if (!is_null($successor)) {
			$this->successor = $successor;
		}

		if (!is_bool($allowRedirect)) {
			throw new InvalidArgumentException(
				sprintf('Invalid argument for constructor of %s',
					get_class($this)
				),
				1373924180
			);
		}

		$this->allowRedirect = $allowRedirect;
	}

	/**
	 * Processes context.
	 *
	 * @param  \T3census\Detection\Context $context
	 * @return  void
	 */
	public function process(\T3census\Detection\Context $context) {

		$objOriginUrl = \Purl\Url::parse($context->getUrl());
		$urlOriginHost = $objOriginUrl->get('host');
		$urlOriginScheme = $objOriginUrl->get('scheme');

		$objFetcher = new \T3census\Url\UrlFetcher();
		$objFetcher->setUrl($context->getUrl())->fetchUrl(\T3census\Url\UrlFetcher::HTTP_HEAD, FALSE, $this->allowRedirect);

		if ($objFetcher->getErrno() === 0 && $objFetcher->getNumRedirects() > 0) {
			$objUrl = $objOriginUrl;

			$objProcessedUrl = \Purl\Url::parse($objFetcher->getUrl());
			$objProcessedHost = $objProcessedUrl->get('host');
			$objProcessedScheme = $objProcessedUrl->get('scheme');

			if (0 !== strcmp($urlOriginHost, $objProcessedHost)) {
				$objUrl->set('host', $objProcessedHost)->getUrl();
			}

			if (0 !== strcmp($urlOriginScheme, $objProcessedScheme)) {
				$objUrl->set('scheme', $objProcessedScheme);
			}

			$context->setUrl($objUrl->getUrl());

			unset($objProcessedScheme, $urlOriginScheme, $objProcessedHost, $objProcessedUrl);
		}
		unset($objFetcher, $urlOriginHost, $objOriginUrl);


		if (!is_null($this->successor)) {
			$this->successor->process($context);
		}
	}
}
?>