<?php
$dir = dirname(__FILE__);
$libraryDir = realpath($dir . '/../../library/php');
$vendorDir = realpath($dir . '/../../vendor');

require_once $libraryDir . '/Detection/Context.php';
require_once $libraryDir . '/Detection/Request.php';
require_once $libraryDir . '/Detection/Normalization/RedirectProcessor.php';
require_once $libraryDir . '/Detection/Identification/HostOnlyProcessor.php';
require_once $libraryDir . '/Detection/Identification/FullPathProcessor.php';
require_once $libraryDir . '/Detection/Normalization/ShortenerRedirectOnlyProcessor.php';
require_once $libraryDir . '/Detection/Identification/Typo3ArtefactsProcessor.php';
require_once $libraryDir . '/Detection/Classification/ExistingRequestsProcessor.php';
require_once $libraryDir . '/Detection/Classification/HostOnlyProcessor.php';
require_once $libraryDir . '/Detection/Classification/FullPathProcessor.php';
require_once $libraryDir . '/Detection/Classification/Typo3ArtefactsProcessor.php';
require_once $libraryDir . '/Detection/Classification/Typo3FingerprintProcessor.php';

/*
$foo = new \T3census\Detection\Context();
$bar = new \T3census\Detection\Request();
$foo->addRequest($bar);
print_r($foo->getRequest());
*/
$context = new \T3census\Detection\Context();
$context->setUrl('http://www.typovision.de/de/agentur/aktivitaeten/');
$context->setUrl('http://kamerakind.net/');
$context->setUrl('http://bit.ly/nYswn2');
$context->setUrl('http://bit.ly/nYswn2');
$context->setUrl('http://www.bayernkurier.de/');
$context->setUrl('http://www.bergkristall.it//');
$context->setUrl('http://www.walthelm-gruppe.com/unternehmen/standorte/');
$context->setUrl('http://www.barsa.by');
$context->setUrl('http://www.colleen-rae-holmes.com/index.php');

$context->setUrl('http://www.maasdamgroep.nl/foo');
$context->setUrl('http://www.foreverknowledge.info');



$objTypo3Artefacts = new \T3census\Detection\Identification\Typo3ArtefactsProcessor(NULL, TRUE);
$objPathRedirect = new \T3census\Detection\Identification\FullPathProcessor($objTypo3Artefacts, TRUE);
$objPathNoRedirect = new \T3census\Detection\Identification\FullPathProcessor($objPathRedirect, FALSE);
$objHostRedirect = new \T3census\Detection\Identification\HostOnlyProcessor($objPathNoRedirect, TRUE);
$objHostNoRedirect = new \T3census\Detection\Identification\HostOnlyProcessor($objHostRedirect, FALSE);
$objRedirect = new \T3census\Detection\Normalization\RedirectProcessor($objHostNoRedirect);
$objShortener = new \T3census\Detection\Normalization\ShortenerRedirectOnlyProcessor($objRedirect);
$objShortener->process($context);


//$objRedirect = new \T3census\Detection\Normalization\RedirectProcessor(NULL);
//$objRedirect->process($context);

print_r($context);


if (TRUE || is_bool($context->getIsTypo3Cms()) && $context->getIsTypo3Cms()) {
	/*
		$objArtefacts = new \T3census\Detection\Classification\Typo3ArtefactsProcessor();
		$objFullPath = new \T3census\Detection\Classification\FullPathProcessor($objArtefacts);
		$objHost = new \T3census\Detection\Classification\HostOnlyProcessor($objFullPath);
		$objRequest = new \T3census\Detection\Classification\ExistingRequestsProcessor($objHost);
		$objRequest->process($context);
	*/
	$objFingerprint = new \T3census\Detection\Classification\Typo3FingerprintProcessor();
	$objFingerprint->process($context);
	unset($objRequest);
}


/*
$objShortener = new \T3census\Detection\Identification\ShortenerRedirectOnlyProcessor();
$objShortener->process($context);
*/
print_r($context);
?>