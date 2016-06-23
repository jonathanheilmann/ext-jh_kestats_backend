<?php
if (!defined('TYPO3_MODE')) {
	die('Access denied.');
}

if (TYPO3_MODE === 'BE') {

	/**
	 * Registers a Backend Module
	 */
	\TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerModule(
		'Heilmann.' . $_EXTKEY,
		'web',	 // Make module a submodule of 'web'
		'mod1',	// Submodule key
		'after:txkestatsM1',						// Position
		array(
			'Mod1' => 'list,flushCache',
		),
		array(
			'access' => 'user,group',
			'icon'   => 'EXT:' . $_EXTKEY . '/ext_icon.gif',
			'labels' => 'LLL:EXT:' . $_EXTKEY . '/Resources/Private/Language/locallang_mod1.xlf',
		)
	);

	$webModules = $TBE_MODULES['web'];
	$webModules = \TYPO3\CMS\Core\Utility\GeneralUtility::rmFromList('txkestatsM1', $webModules);
	$TBE_MODULES['web'] = $webModules;

}

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addStaticFile($_EXTKEY, 'Configuration/TypoScript', 'ke_stats backend');
