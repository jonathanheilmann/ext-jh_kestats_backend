<?php
namespace Heilmann\JhKestatsBackend\Controller;

/***************************************************************
 *
 *  Copyright notice
 *
 *  (c) 2016 Jonathan Heilmann <mail@jonathan-heilmann.de>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Extbase\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Mod1Controller
 */
class Mod1Controller extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController
{

    /** @var string  */
    protected $extensionKey = 'jh_kestats_backend';

    protected $tablename = 'tx_kestats_statdata';
    protected $tablenameCache = 'tx_kestats_cache';
    protected $tablenameQueue = 'tx_kestats_queue';
    protected $maxLengthURLs = 80;
    protected $maxLengthTableContent = 80;
    protected $showTrackingResultNumbers = array(10 => '10', 50 => '50', 100 => '100', 200 => '200');
    protected $currentRowNumber = 0;
    protected $currentColNumber = 0;

    protected $csvDateFormat = 'd.m.Y';
    protected $decimalChar = ',';

    /**
     * @var \tx_kestats_lib
     * @inject
     */
    protected $kestatslib = null;

    /**
     * @var \Heilmann\JhKestatsBackend\Utility\KestatsUtility
     * @inject
     */
    protected $kestatsUtility = null;

    /**
     * @var \Heilmann\JhKestatsBackend\Utility\MenuUtility
     * @inject
     */
    protected $menuUtility = null;

    /** @var int  */
    protected $id = 0;

    /**
     * ke_stats extension configuration
     *
     * @var array
     */
    protected $extConf = array();

    /** @var array  */
    protected $dropDownMenus = array();

    /** @var array  */
    protected $csvContent = array();

    /** @var bool  */
    protected $csvOutput = false;

    /** @var array  */
    protected $allowedYears = array();

    /** @var array  */
    protected $allowedMonths = array();

    /** @var string  */
    protected $subpages_query = '';

    /** @var array  */
    protected $elementLanguagesArray = array();

    /** @var array  */
    protected $elementTypesArray = array();

    /** @var array  */
    protected $allowedExtensionTypes = array();

    public function __construct()
    {
        parent::__construct();

        $this->id = GeneralUtility::_GP('id') ? intval(GeneralUtility::_GP('id')) : 0;

        GeneralUtility::requireOnce(ExtensionManagementUtility::extPath('ke_stats', 'inc/constants.inc.php'));

        // Including locallang.xlf manually is required, as \tx_kestats_lib uses $GLOBALS['LANG'] for month translations
        $GLOBALS['LANG']->includeLLFile('EXT:jh_kestats_backend/Resources/Private/Language/locallang.xlf');
    }

    /**
     * initializeObject
     */
    public function initializeObject()
    {
        // introduce the backend module to the shared library
        $this->kestatslib->backendModule_obj = $this;

        // get the subpages list
        if ($this->id)
        {
            $this->kestatslib->pagelist = strval($this->id);
            $this->kestatslib->getSubPages($this->id);
        }

        // the query to filter the elements based on the selected page in the pagetree
        // extension elements are filtered by their pid
        if (strlen($this->kestatslib->pagelist) > 0)
        {
            if ($this->menuUtility->getSelectedValue('type') == STAT_TYPE_EXTENSION)
            {
                $this->subpages_query = ' AND '.$this->tablename.'.element_pid IN ('.$this->kestatslib->pagelist.')';
            } else
            {
                $this->subpages_query = ' AND '.$this->tablename.'.element_uid IN ('.$this->kestatslib->pagelist.')';
            }
        } else {
            $this->subpages_query = '';
        }

        // get the extension-manager configuration
        $this->extConf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['ke_stats']);
        $this->extConf['enableIpLogging'] = $this->extConf['enableIpLogging'] ? 1 : 0;
        $this->extConf['enableTracking'] = $this->extConf['enableTracking'] ? 1 : 0;
        $this->extConf['ignoreBackendUsers'] = $this->extConf['ignoreBackendUsers'] ? 1 : 0;
        $this->extConf['asynchronousDataRefreshing'] = $this->extConf['asynchronousDataRefreshing'] ? 1 : 0;

        // load the frontend TSconfig
        $this->loadFrontendTSconfig($this->id, 'tx_kestats_pi1');
    }

    /**
     * initialize action list
     */
    public function initializeListAction()
    {
        // init menuUtility
        $this->menuUtility->setArguments($this->request->getArguments());

        // init the first csv-content row
        $this->csvContent[0] = array();

        // check, if we should render a csv-table
        $this->csvOutput = $this->request->hasArgument('format') && $this->request->getArgument('format') == 'csv' ? true : false;
    }

    /**
     * action list
     */
    public function listAction()
    {
        $access = $this->checkAccess();
        if ($access)
        {
            $tags = array('pageId_' . $this->id);
            $cacheIdentifier = md5(serialize($this->request->getArguments()));
            if (($entry = GeneralUtility::makeInstance(CacheManager::class)->getCache($this->extensionKey)->get($cacheIdentifier)) === false)
            {
                if ($this->id != 0)
                {
                    // Get all required data
                    $tabMenus = $this->getTabMenus();

                    // Get pageTitle
                    $row = BackendUtility::getRecord('pages', $this->id);
                    $pageTitle = BackendUtility::getRecordTitle('pages', $row, 1);
                    $this->addCsvCol(LocalizationUtility::translate('statistics_for', $this->extensionName).' '.$pageTitle.' '.LocalizationUtility::translate('and_subpages', $this->extensionName));
                    // Get description
                    $this->addCsvCol($this->getDescription());
                    $this->addCsvRow();

                    // Get module content
                    if ($this->menuUtility->getSelectedValue('type') != 'overview')
                        $this->getModuleContent();

                    if (!$this->csvOutput)
                    {
                        $entry = array(
                            'pageTitle' => $pageTitle,
                            'tabMenus' => $tabMenus,
                            'type' => $this->menuUtility->getSelectedValue('type'),
                            'overviewPageData' => $this->getOverviewPage(),
                            'csvDownloadMenu' => $this->getCsvDownloadMenu(),
                            'dropDownMenus'=> $this->dropDownMenus,
                            'csvContent' => $this->csvContent,
                            'updateInformation' => $this->getUpdateInformation()
                        );
                    } else
                    {
                        // Download csv file
                        $this->downloadCsvFile();
                    }
                } else
                {
                    // Get overview for complete pagetree (pageId is 0)
                    $this->menuUtility->initMenu('type','overview');

                    $this->addCsvCol(LocalizationUtility::translate('statistics_for', $this->extensionName).' '.$GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'].' '.LocalizationUtility::translate('and_subpages', $this->extensionName));
                    // Get description
                    $this->addCsvCol($this->getDescription());
                    $this->addCsvRow();

                    $entry = array(
                        'pageTitle' => $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'],
                        'tabMenus' => array(),
                        'type' => 'overview',
                        'overviewPageData' => $this->getOverviewPage(),
                        'csvDownloadMenu' => array(),
                        'dropDownMenus'=> array(),
                        'csvContent' => $this->csvContent,
                        'updateInformation' => $this->getUpdateInformation()
                    );
                }

                $cacheType = 'nonPermanent';
                if ($this->request->hasArgument('year') && $this->request->hasArgument('month') && $this->request->getArgument('month') != -1)
                {
                    $date = new \DateTime();
                    $date->setDate($this->request->getArgument('year'), $this->request->getArgument('month'), 1)->setTime(23, 59, 59)->modify('+1 month')->modify('-1 day');
                    if ($date < new \DateTime())
                        $cacheType = 'permanent';
                }
                $tags[] = 'pageId_' . $this->id . '_' . $cacheType;

                GeneralUtility::makeInstance(CacheManager::class)
                    ->getCache($this->extensionKey)
                    ->set($cacheIdentifier, $entry, $tags);
            }
            $this->view->assignMultiple($entry);
        } else
        {
            // No access: Show info
            $this->addFlashMessage(LocalizationUtility::translate('please_select_page', $this->extensionName), '', AbstractMessage::INFO);
        }
    }

    /**
     * @param string $cacheType
     * @throws \TYPO3\CMS\Extbase\Mvc\Exception\UnsupportedRequestTypeException
     */
    public function flushCacheAction($cacheType = 'this')
    {
        /** @var \TYPO3\CMS\Core\Cache\Frontend\FrontendInterface $cache */
        $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache($this->extensionKey);

        switch ($cacheType)
        {
            case 'all':
                $cache->flush();
                break;
            case 'subpages':
                $pageList = GeneralUtility::trimExplode(',', $this->kestatslib->pagelist, true);
                if (count($pageList) > 0)
                    foreach ($pageList as $id)
                        $cache->flushByTag('pageId_' . $id . '_nonPermanent');
                break;
            default:
                $cache->flushByTag('pageId_' . $this->id . '_nonPermanent');
        }

        $arguments = $this->request->getArguments();
        unset($arguments['action']);
        unset($arguments['cacheType']);

        $this->redirect('list', null, null, $arguments);
    }

    /**
     * Check access of be user for selected page
     *
     * @return bool
     */
    protected function checkAccess()
    {
        // Access check!
        // The page will show only if there is a valid page and if this page may be viewed by the user
        $pageinfo = BackendUtility::readPageAccess($this->id, $GLOBALS['BE_USER']->getPagePermsClause(1));
        $access = is_array($pageinfo) ? 1 : 0;

        return $access;
    }

    /**
     * get tabMenus
     *
     * @return array
     */
    protected function getTabMenus()
    {
        $tabMenus = array();

        // Init tab menus
        $this->menuUtility->initMenu('type','overview');
        $now = time();
        $this->menuUtility->initMenu('month',date('m',$now));
        $this->menuUtility->initMenu('year',date('Y',$now));
        $this->menuUtility->initMenu('element_language',-1);
        $this->menuUtility->initMenu('element_type',-1);

        // find out what types we have statistics for
        // extension elements are filtered by their pid
        //
        // C. B., 11.Jul.2008:
        // this is very slow, so we assume having every type available here
        /*
        $typesArray = array();
        $where = '('.$this->tablename.'.type=\'extension\' AND '.$this->tablename.'.element_pid IN ('.$this->kestatslib->pagelist.')'. ')';
        $where .= ' OR ('.$this->tablename.'.type!=\'extension\' AND '.$this->tablename.'.element_uid IN ('.$this->kestatslib->pagelist.')'. ')';
        $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery('type',$this->tablename,$where,'type');

        if ($GLOBALS['TYPO3_DB']->sql_num_rows($res) > 0) {
            while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
                $typesArray[$row['type']] = LocalizationUtility::translate('type_'.$row['type'], $this->extensionName) ? LocalizationUtility::translate('type_'.$row['type'], $this->extensionName) : $row['type'];
            }
        }

            // put "extensions" to the end of the array
            // (just for optical reasons)
        if ($typesArray['extension']) {
            $value = $typesArray['extension'];
            unset($typesArray['extension']);
            $typesArray['extension'] = $value;
        }
        */

        // this is a lot faster, it just means that you get an empty table
        // on pages where you click on "extension" and there are no
        // elements
        $typesArray = array(
            'overview' => LocalizationUtility::translate('overview', $this->extensionName),
            STAT_TYPE_PAGES => LocalizationUtility::translate('type_' . STAT_TYPE_PAGES, $this->extensionName),
            STAT_TYPE_EXTENSION => LocalizationUtility::translate('type_' . STAT_TYPE_EXTENSION, $this->extensionName),
            'csvdownload' => LocalizationUtility::translate('csvdownload', $this->extensionName)
        );

        // Put "Tracking" tab at the end display it only if tracking is activated
        if ($this->extConf['enableTracking'])
            $typesArray[STAT_TYPE_TRACKING] = LocalizationUtility::translate('type_' . STAT_TYPE_TRACKING, $this->extensionName);

        // render tab menu: types
        $tabMenus['type'] = $this->menuUtility->generateTabMenu($typesArray,'type');

        // Render menus only if we are not in the csvdownload-section
        if ($this->menuUtility->getSelectedValue('type') != 'overview' && $this->menuUtility->getSelectedValue('type') != 'csvdownload' && !$this->csvOutput)
        {

            if ($this->menuUtility->getSelectedValue('type') == STAT_TYPE_PAGES)
            {

                // Init tab menus
                $this->menuUtility->initMenu('list_type','list_monthly_process');
                $this->menuUtility->initMenu('list_type_category','category_pages');
                $this->menuUtility->initMenu('list_type_category_monthly','category_monthly_pages');
                $this->menuUtility->initMenu('category_pages',CATEGORY_PAGES);
                $this->menuUtility->initMenu('category_referers',CATEGORY_REFERERS_EXTERNAL_WEBSITES);
                $this->menuUtility->initMenu('category_time_type','category_time_hits');
                $this->menuUtility->initMenu('category_time_hits',CATEGORY_PAGES_OVERALL_DAY_OF_MONTH);
                $this->menuUtility->initMenu('category_time_visits',CATEGORY_VISITS_OVERALL_DAY_OF_MONTH);
                $this->menuUtility->initMenu('category_time_visits_feusers',CATEGORY_VISITS_OVERALL_FEUSERS_DAY_OF_MONTH);
                $this->menuUtility->initMenu('category_user_agents',CATEGORY_BROWSERS);
                $this->menuUtility->initMenu('category_other',CATEGORY_OPERATING_SYSTEMS);

                // render tab menu: monthly or details of one month
                $tabMenus['list_type'] = $this->menuUtility->generateTabMenu(array(
                    'list_monthly_process' => LocalizationUtility::translate('list_full', $this->extensionName),
                    'list_details_of_a_month' => LocalizationUtility::translate('list_details', $this->extensionName),
                ),'list_type');

                if ($this->menuUtility->getSelectedValue('list_type') == 'list_monthly_process') {
                    // render tab menu: category
                    $tabMenus['list_type_category_monthly'] = $this->menuUtility->generateTabMenu(array(
                        'category_monthly_pages' => LocalizationUtility::translate('category_monthly_pages', $this->extensionName),
                        'category_monthly_pages_fe_users' => LocalizationUtility::translate('category_monthly_pages_fe_users', $this->extensionName),
                        'category_monthly_visits' => LocalizationUtility::translate('category_monthly_visits', $this->extensionName),
                        'category_monthly_visits_fe_users' => LocalizationUtility::translate('category_monthly_visits_fe_users', $this->extensionName)
                    ),'list_type_category_monthly');
                } else if ($this->menuUtility->getSelectedValue('list_type') == 'list_details_of_a_month') {
                    // render tab menu: category
                    $tabMenus['list_type_category'] = $this->menuUtility->generateTabMenu(array(
                        'category_pages' => LocalizationUtility::translate('category_pages', $this->extensionName),
                        'category_time' => LocalizationUtility::translate('category_time', $this->extensionName),
                        'category_referers' => LocalizationUtility::translate('category_referers', $this->extensionName),
                        'category_user_agents' => LocalizationUtility::translate('category_user_agents', $this->extensionName),
                        'category_other' => LocalizationUtility::translate('category_other', $this->extensionName)
                    ),'list_type_category');
                    if ($this->menuUtility->getSelectedValue('list_type_category') == 'category_pages') {
                        // render tab menu: pages
                        $tabMenus['category_pages'] = $this->menuUtility->generateTabMenu(array(
                            CATEGORY_PAGES => LocalizationUtility::translate('category_pages_all', $this->extensionName),
                            CATEGORY_PAGES_FEUSERS => LocalizationUtility::translate('category_pages_feusers', $this->extensionName)
                        ),'category_pages');
                    }
                    if ($this->menuUtility->getSelectedValue('list_type_category') == 'category_referers') {
                        // render tab menu: referers
                        $tabMenus['category_referers'] = $this->menuUtility->generateTabMenu(array(
                            CATEGORY_REFERERS_EXTERNAL_WEBSITES => LocalizationUtility::translate('category_referers_websites', $this->extensionName),
                            CATEGORY_REFERERS_SEARCHENGINES => LocalizationUtility::translate('category_referers_search_engines', $this->extensionName),
                            CATEGORY_SEARCH_STRINGS => LocalizationUtility::translate('category_search_strings', $this->extensionName)
                        ),'category_referers');
                    }
                    if ($this->menuUtility->getSelectedValue('list_type_category') == 'category_time') {
                        // render tab menu: time
                        $tabMenus['category_time_type'] = $this->menuUtility->generateTabMenu(array(
                            'category_time_hits' => LocalizationUtility::translate('category_time_hits', $this->extensionName),
                            'category_time_visits' => LocalizationUtility::translate('category_time_visits', $this->extensionName),
                            'category_time_visits_feusers' => LocalizationUtility::translate('category_time_visits_feusers', $this->extensionName),
                        ),'category_time_type');
                        if ($this->menuUtility->getSelectedValue('category_time_type') == 'category_time_hits') {
                            // render tab menu: time hits
                            $tabMenus['category_time_hits'] = $this->menuUtility->generateTabMenu(array(
                                CATEGORY_PAGES_OVERALL_DAY_OF_MONTH => LocalizationUtility::translate('category_day_of_month', $this->extensionName),
                                CATEGORY_PAGES_OVERALL_DAY_OF_WEEK => LocalizationUtility::translate('category_day_of_week', $this->extensionName),
                                CATEGORY_PAGES_OVERALL_HOUR_OF_DAY => LocalizationUtility::translate('category_hour_of_day', $this->extensionName),
                            ),'category_time_hits');
                        } else if ($this->menuUtility->getSelectedValue('category_time_type') == 'category_time_visits') {
                            // render tab menu: time visits
                            $tabMenus['category_time_visits'] = $this->menuUtility->generateTabMenu(array(
                                CATEGORY_VISITS_OVERALL_DAY_OF_MONTH => LocalizationUtility::translate('category_visits_day_of_month', $this->extensionName),
                                CATEGORY_VISITS_OVERALL_DAY_OF_WEEK => LocalizationUtility::translate('category_visits_day_of_week', $this->extensionName),
                                CATEGORY_VISITS_OVERALL_HOUR_OF_DAY => LocalizationUtility::translate('category_visits_hour_of_day', $this->extensionName),
                            ),'category_time_visits');
                        } else if ($this->menuUtility->getSelectedValue('category_time_type') == 'category_time_visits_feusers') {
                            // render tab menu: time visits logged-in
                            $tabMenus['category_time_visits_feusers'] = $this->menuUtility->generateTabMenu(array(
                                CATEGORY_VISITS_OVERALL_FEUSERS_DAY_OF_MONTH => LocalizationUtility::translate('category_visits_day_of_month_feusers', $this->extensionName),
                                CATEGORY_VISITS_OVERALL_FEUSERS_DAY_OF_WEEK => LocalizationUtility::translate('category_visits_day_of_week_feusers', $this->extensionName),
                                CATEGORY_VISITS_OVERALL_FEUSERS_HOUR_OF_DAY => LocalizationUtility::translate('category_visits_hour_of_day_feusers', $this->extensionName),
                            ),'category_time_visits_feusers');
                        }
                    }
                    if ($this->menuUtility->getSelectedValue('list_type_category') == 'category_user_agents') {
                        // render tab menu: user agents
                        $tabMenus['category_user_agents'] = $this->menuUtility->generateTabMenu(array(
                            CATEGORY_BROWSERS => LocalizationUtility::translate('category_browsers', $this->extensionName),
                            CATEGORY_ROBOTS => LocalizationUtility::translate('category_robots', $this->extensionName),
                            CATEGORY_UNKNOWN_USER_AGENTS => LocalizationUtility::translate('category_unknown_user_agents', $this->extensionName),
                        ),'category_user_agents');
                    }
                    if ($this->menuUtility->getSelectedValue('list_type_category') == 'category_other') {
                        // render tab menu: other
                        $tabMenus['category_other'] = $this->menuUtility->generateTabMenu(array(
                            CATEGORY_OPERATING_SYSTEMS => LocalizationUtility::translate('category_operating_systems', $this->extensionName),
                            CATEGORY_IP_ADRESSES => LocalizationUtility::translate('category_ip_addresses', $this->extensionName),
                            'category_hosts' => LocalizationUtility::translate('category_hosts', $this->extensionName)
                        ),'category_other');
                    }
                }
            } else if ($this->menuUtility->getSelectedValue('type') == STAT_TYPE_EXTENSION)
            {
                // render tabs for the different extensions
                // find out what extensions we have statistics for (db field "category")
                $extensionTypesArray = array();
                $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery('category',$this->tablename,'type=\''.STAT_TYPE_EXTENSION.'\''.$this->subpages_query,'category');
                if ($GLOBALS['TYPO3_DB']->sql_num_rows($res) > 0) {
                    while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
                        // get the tabname for the extension from page TSconfig
                        // if it is not set, get it from Locallang or from the database itself
                        $tabname = LocalizationUtility::translate('extension_'.$row['category'], $this->extensionName) ? LocalizationUtility::translate('extension_'.$row['category'], $this->extensionName) : $row['category'];
                        $extensionTypesArray[$row['category']] = $tabname;
                        $this->allowedExtensionTypes[] = $row['category'];
                    }
                }

                // Init tab menus
                $this->menuUtility->initMenu('extension_type','');

                // render the extension types tabs
                $tabMenus['extension_type'] = $this->menuUtility->generateTabMenu($extensionTypesArray,'extension_type');
            }
        }

        return $tabMenus;
    }

    /**
     * get description
     */
    protected function getDescription()
    {
        // description of the statistic type
        $description = $this->request->hasArgument('descr') ? $this->request->getArgument('descr') : null;

        if ($this->menuUtility->getSelectedValue('list_type') == 'list_details_of_a_month')
        {
            if (!empty($description))
                $description .= ' - ';
            $description .= LocalizationUtility::translate('csvdownload_statistics_for_month', $this->extensionName) . LocalizationUtility::translate('month_'.$this->menuUtility->getSelectedValue('month'), $this->extensionName) . ' ' . $this->menuUtility->getSelectedValue('year');
            $description .= ', ' . LocalizationUtility::translate('csvdownload_generated_on', $this->extensionName) . ' ' . date($this->csvDateFormat);
        }

        return $description;
    }

    /**
     * getOverviewPage
     *
     * $overviewPageData is required to render javascript for graph
     *
     * Renders the overview for the current page.
     * Wrapper for the function in kestatslib.
     *
     * @return array
     */
    protected function getOverviewPage() {
        $overviewPageData = null;
        
        if ($this->menuUtility->getSelectedValue('type') == 'overview')
        {
            // get the for the overview page data
            $overviewPageData = $this->kestatsUtility->refreshOverviewPageData($this->id);

            // monthly progress, combined table
            $this->getTable(LocalizationUtility::translate('overview_pageviews_and_visits_monthly', $this->extensionName), 'element_title,pageviews,visits,pages_per_visit', $overviewPageData['pageviews_and_visits'], 'no_line_numbers', '', '');

            // for future versions:
            /*
            // pageviews of current month, top 10
            $this->renderTable(LocalizationUtility::translate('overview_pageviews_current_month'), 'element_title,element_uid,counter', $overviewPageData['pageviews_current_month'], '', '', '', 10);

            // referers, external websites, top 10
            $this->renderTable(LocalizationUtility::translate('overview_referers_external_websites'), 'element_title,counter', $overviewPageData['referers_external_websites'], 'url', '', '', 10);

            // search words, top 10
            $this->renderTable(LocalizationUtility::translate('overview_search_words'), 'element_title,counter', $overviewPageData['search_words'], '', '', '', 10);
            */
        }

        return $overviewPageData;
    }

    /**
     * Returns a selectorboxes for month/year/language/type for the given data
     *
     * @param array $statType
     * @param array $statCategory
     */
    protected function renderSelectorMenu($statType,$statCategory) {
        $fromToArray = $this->getFirstAndLastEntries($statType,$statCategory);

        // generate the year and the month-array
        // generate a list of allowed values for the years an the months

        // render all years for which data exists
        $yearArray = array();
        $this->allowedYears = array();
        for ($year = $fromToArray['from_year']; $year<=$fromToArray['to_year']; $year++) {
            $yearArray[$year] = $year;
            $this->allowedYears[] = $year;
        }

        // render only months for which data exists
        $monthArray = array();
        // todo: remove hardcoded label
        $monthArray[-1] = 'Alle Monate';
        $this->allowedMonths = array();
        $this->allowedMonths[] = -1;
        for ($month = 1; $month<=12; $month++) {
            if ($this->menuUtility->getSelectedValue('year') == $fromToArray['from_year'] && $fromToArray['from_year']== $fromToArray['to_year']) {
                if ($month >= $fromToArray['from_month'] && $month <= $fromToArray['to_month']) {
                    $monthArray[$month] = LocalizationUtility::translate('month_'.$month, $this->extensionName);
                    $this->allowedMonths[] = $month;
                }
            } else if ($this->menuUtility->getSelectedValue('year') == $fromToArray['from_year']) {
                if ($month >= $fromToArray['from_month']) {
                    $monthArray[$month] = LocalizationUtility::translate('month_'.$month, $this->extensionName);
                    $this->allowedMonths[] = $month;
                }
            } else if ($this->menuUtility->getSelectedValue('year') == $fromToArray['to_year']) {
                if ($month <= $fromToArray['to_month']) {
                    $monthArray[$month] = LocalizationUtility::translate('month_'.$month, $this->extensionName);
                    $this->allowedMonths[] = $month;
                }
            } else {
                // we are in a year in-between, so we display all months
                $monthArray[$month] = LocalizationUtility::translate('month_'.$month, $this->extensionName);
                $this->allowedMonths[] = $month;
            }
        }

        // is there more than one element type?
        $where_clause = 'type=\''.$statType.'\'';
        $where_clause .= ' AND category=\''.$statCategory.'\'';
        $where_clause .= $this->subpages_query;
        $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery('element_type',$this->tablename,$where_clause,'element_type');
        if ($GLOBALS['TYPO3_DB']->sql_num_rows($res) > 1) {
            $this->elementTypesArray[-1] = LocalizationUtility::translate('selector_type_all', $this->extensionName);
            while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
                $this->elementTypesArray[$row['element_type']] = LocalizationUtility::translate('selector_type', $this->extensionName).' '.$row['element_type'];
            }
        }

        // is there more than one element language?
        $where_clause = 'type=\''.$statType.'\'';
        $where_clause .= ' AND category=\''.$statCategory.'\'';
        $where_clause .= $this->subpages_query;
        $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery('element_language',$this->tablename,$where_clause,'element_language');
        if ($GLOBALS['TYPO3_DB']->sql_num_rows($res) > 1) {
            $this->elementLanguagesArray[-1] = LocalizationUtility::translate('selector_language_all', $this->extensionName);
            while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
                $this->elementLanguagesArray[$row['element_language']] = $this->getLanguageName($row['element_language']);
            }
        }

        // do the menu rendering
        $this->dropDownMenus['year'] = $this->menuUtility->generateDropDownMenu($yearArray,'year');
        $this->dropDownMenus['month'] = $this->menuUtility->generateDropDownMenu($monthArray,'month');
        if (is_array($this->elementTypesArray) && count($this->elementTypesArray) > 0)
            $this->dropDownMenus['element_type'] = $this->menuUtility->generateDropDownMenu($this->elementTypesArray,'element_type');
        if (is_array($this->elementLanguagesArray) && count($this->elementLanguagesArray) > 0)
            $this->dropDownMenus['element_language'] = $this->menuUtility->generateDropDownMenu($this->elementLanguagesArray,'element_language');

        // todo: Signal do modify tabMenu
    }

    /**
     * returns the cleartext name of a language uid
     *
     * @param integer $sys_language_uid
     * @return string
     */
    protected function getLanguageName($sys_language_uid) {
        // get the language name from sys_language
        if ($sys_language_uid == 0) {
            return LocalizationUtility::translate('language_default', $this->extensionName);
        } else {
            $resLanguage = $GLOBALS['TYPO3_DB']->exec_SELECTquery('title','sys_language','hidden=0 AND uid='.$sys_language_uid);
            $rowLanguage = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($resLanguage);
            return $rowLanguage['title'];
        }
    }

    /**
     * Generates the main content (renders the statistics)
     */
    protected function getModuleContent() {
        /** @noinspection PhpVoidFunctionResultUsedInspection */
        switch($this->menuUtility->getSelectedValue('type')) {

            // the overview page
            case 'overview':
                // content will be fetched later
                break;

            // default statistics for pages
            case STAT_TYPE_PAGES:
                /** @noinspection PhpVoidFunctionResultUsedInspection */
                switch($this->menuUtility->getSelectedValue('list_type')) {
                    case 'list_details_of_a_month':
                        /** @noinspection PhpVoidFunctionResultUsedInspection */
                        switch($this->menuUtility->getSelectedValue('list_type_category')) {
                            case 'category_pages':
                                /** @noinspection PhpVoidFunctionResultUsedInspection */
                                switch($this->menuUtility->getSelectedValue('category_pages')) {
                                    case CATEGORY_PAGES:
                                        $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_PAGES);
                                        $columns = 'element_title,element_uid,counter';
                                        $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_PAGES,$columns);
                                        $this->getTable(LocalizationUtility::translate('type_pages', $this->extensionName),$columns,$resultArray);
                                        break;
                                    case CATEGORY_PAGES_FEUSERS:
                                        $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_PAGES_FEUSERS);
                                        $columns = 'element_title,element_uid,counter';
                                        $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_PAGES_FEUSERS,$columns);
                                        $this->getTable(LocalizationUtility::translate('type_pages', $this->extensionName),$columns,$resultArray);
                                        break;
                                }
                                break;
                            case 'category_time':
                                /** @noinspection PhpVoidFunctionResultUsedInspection */
                                switch($this->menuUtility->getSelectedValue('category_time_type')) {
                                    // PAGEVIEWS
                                    case 'category_time_hits':
                                        /** @noinspection PhpVoidFunctionResultUsedInspection */
                                        switch($this->menuUtility->getSelectedValue('category_time_hits')) {
                                            case CATEGORY_PAGES_OVERALL_DAY_OF_MONTH:
                                                $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_PAGES_OVERALL_DAY_OF_MONTH);
                                                $columns = 'element_title,counter';
                                                $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_PAGES_OVERALL_DAY_OF_MONTH,$columns,STAT_COMPLETE_LIST,'element_title');
                                                $this->getTable(LocalizationUtility::translate('type_pages_day_of_month', $this->extensionName),$columns,$resultArray,'no_line_numbers');
                                                break;
                                            case CATEGORY_PAGES_OVERALL_DAY_OF_WEEK:
                                                $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_PAGES_OVERALL_DAY_OF_WEEK);
                                                $columns = 'element_title,counter';
                                                $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_PAGES_OVERALL_DAY_OF_WEEK,$columns,STAT_COMPLETE_LIST,'element_title');
                                                $this->getTable(LocalizationUtility::translate('type_pages_day_of_week', $this->extensionName),$columns,$resultArray,'no_line_numbers,day_of_week');
                                                break;
                                            case CATEGORY_PAGES_OVERALL_HOUR_OF_DAY:
                                                $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_PAGES_OVERALL_HOUR_OF_DAY);
                                                $columns = 'element_title,counter';
                                                $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_PAGES_OVERALL_HOUR_OF_DAY,$columns,STAT_COMPLETE_LIST,'element_title');
                                                $this->getTable(LocalizationUtility::translate('type_pages_hour_of_day', $this->extensionName),$columns,$resultArray,'no_line_numbers,hour_of_day');
                                                break;
                                        }
                                        break;
                                    // VISITS
                                    case 'category_time_visits':
                                        /** @noinspection PhpVoidFunctionResultUsedInspection */
                                        switch($this->menuUtility->getSelectedValue('category_time_visits')) {
                                            case CATEGORY_VISITS_OVERALL_DAY_OF_MONTH:
                                                $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_VISITS_OVERALL_DAY_OF_MONTH);
                                                $columns = 'element_title,counter';
                                                $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_VISITS_OVERALL_DAY_OF_MONTH,$columns,STAT_COMPLETE_LIST,'element_title');
                                                $this->getTable(LocalizationUtility::translate('type_visits_day_of_month', $this->extensionName),$columns,$resultArray,'no_line_numbers');
                                                break;
                                            case CATEGORY_VISITS_OVERALL_DAY_OF_WEEK:
                                                $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_VISITS_OVERALL_DAY_OF_WEEK);
                                                $columns = 'element_title,counter';
                                                $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_VISITS_OVERALL_DAY_OF_WEEK,$columns,STAT_COMPLETE_LIST,'element_title');
                                                $this->getTable(LocalizationUtility::translate('type_visits_day_of_week', $this->extensionName),$columns,$resultArray,'no_line_numbers,day_of_week');
                                                break;
                                            case CATEGORY_VISITS_OVERALL_HOUR_OF_DAY:
                                                $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_VISITS_OVERALL_HOUR_OF_DAY);
                                                $columns = 'element_title,counter';
                                                $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_VISITS_OVERALL_HOUR_OF_DAY,$columns,STAT_COMPLETE_LIST,'element_title');
                                                $this->getTable(LocalizationUtility::translate('type_visits_hour_of_day', $this->extensionName),$columns,$resultArray,'no_line_numbers,hour_of_day');
                                                break;
                                        }
                                        break;
                                    // VISITS OF LOGGED-IN USERS
                                    case 'category_time_visits_feusers':
                                        /** @noinspection PhpVoidFunctionResultUsedInspection */
                                        switch($this->menuUtility->getSelectedValue('category_time_visits_feusers')) {
                                            case CATEGORY_VISITS_OVERALL_FEUSERS_DAY_OF_MONTH:
                                                $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_VISITS_OVERALL_FEUSERS_DAY_OF_MONTH);
                                                $columns = 'element_title,counter';
                                                $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_VISITS_OVERALL_FEUSERS_DAY_OF_MONTH,$columns,STAT_COMPLETE_LIST,'element_title');
                                                $this->getTable(LocalizationUtility::translate('type_visits_day_of_month_feusers', $this->extensionName),$columns,$resultArray,'no_line_numbers');
                                                break;
                                            case CATEGORY_VISITS_OVERALL_FEUSERS_DAY_OF_WEEK:
                                                $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_VISITS_OVERALL_FEUSERS_DAY_OF_WEEK);
                                                $columns = 'element_title,counter';
                                                $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_VISITS_OVERALL_FEUSERS_DAY_OF_WEEK,$columns,STAT_COMPLETE_LIST,'element_title');
                                                $this->getTable(LocalizationUtility::translate('type_visits_day_of_week_feusers', $this->extensionName),$columns,$resultArray,'no_line_numbers,day_of_week');
                                                break;
                                            case CATEGORY_VISITS_OVERALL_FEUSERS_HOUR_OF_DAY:
                                                $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_VISITS_OVERALL_FEUSERS_HOUR_OF_DAY);
                                                $columns = 'element_title,counter';
                                                $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_VISITS_OVERALL_FEUSERS_HOUR_OF_DAY,$columns,STAT_COMPLETE_LIST,'element_title');
                                                $this->getTable(LocalizationUtility::translate('type_visits_hour_of_day_feusers', $this->extensionName),$columns,$resultArray,'no_line_numbers,hour_of_day');
                                                break;
                                        }
                                        break;
                                }
                                break;
                            case 'category_referers':
                                /** @noinspection PhpVoidFunctionResultUsedInspection */
                                switch($this->menuUtility->getSelectedValue('category_referers')) {
                                    case CATEGORY_REFERERS_EXTERNAL_WEBSITES:
                                        $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_REFERERS_EXTERNAL_WEBSITES);
                                        $columns = 'element_title,counter';
                                        $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_REFERERS_EXTERNAL_WEBSITES,$columns,STAT_COMPLETE_LIST);
                                        $this->getTable(LocalizationUtility::translate('type_pages_referers_websites', $this->extensionName),$columns,$resultArray,'url');
                                        break;
                                    case CATEGORY_REFERERS_SEARCHENGINES:
                                        $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_REFERERS_SEARCHENGINES);
                                        $columns = 'element_title,counter';
                                        $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_REFERERS_SEARCHENGINES,$columns,STAT_COMPLETE_LIST);
                                        $this->getTable(LocalizationUtility::translate('type_pages_referers_search_engines', $this->extensionName),$columns,$resultArray);
                                        break;
                                    case CATEGORY_SEARCH_STRINGS:
                                        $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_SEARCH_STRINGS);
                                        $columns = 'element_title,counter';
                                        $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_SEARCH_STRINGS,$columns,STAT_COMPLETE_LIST,'counter DESC');
                                        $this->getTable(LocalizationUtility::translate('type_pages_referers_searchwords', $this->extensionName),$columns,$resultArray,'none');
                                        break;
                                }
                                break;
                            case 'category_user_agents':
                                /** @noinspection PhpVoidFunctionResultUsedInspection */
                                switch($this->menuUtility->getSelectedValue('category_user_agents')) {
                                    case CATEGORY_BROWSERS:
                                        $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_BROWSERS);
                                        $columns = 'element_title,counter';
                                        $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_BROWSERS,$columns,STAT_COMPLETE_LIST);
                                        $this->getTable(LocalizationUtility::translate('type_pages_user_agents_browsers', $this->extensionName),$columns,$resultArray);
                                        break;
                                    case CATEGORY_ROBOTS:
                                        $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_ROBOTS);
                                        $columns = 'element_title,counter';
                                        $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_ROBOTS,$columns,STAT_COMPLETE_LIST);
                                        $this->getTable(LocalizationUtility::translate('type_pages_user_agents_robots', $this->extensionName),$columns,$resultArray);
                                        break;
                                    case CATEGORY_UNKNOWN_USER_AGENTS:
                                        $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_UNKNOWN_USER_AGENTS);
                                        $columns = 'element_title,counter';
                                        $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_UNKNOWN_USER_AGENTS,$columns,STAT_COMPLETE_LIST);
                                        $this->getTable(LocalizationUtility::translate('type_pages_user_agents_unknown', $this->extensionName),$columns,$resultArray);
                                        break;
                                }
                                break;
                            case 'category_other':
                                /** @noinspection PhpVoidFunctionResultUsedInspection */
                                switch($this->menuUtility->getSelectedValue('category_other')) {
                                    case CATEGORY_OPERATING_SYSTEMS:
                                        $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_OPERATING_SYSTEMS);
                                        $columns = 'element_title,counter';
                                        $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_OPERATING_SYSTEMS,$columns,STAT_COMPLETE_LIST);
                                        $this->getTable(LocalizationUtility::translate('type_pages_operating_systems', $this->extensionName),$columns,$resultArray);
                                        break;
                                    case CATEGORY_IP_ADRESSES:
                                        // display note, if ip-logging is disabled
                                        if (!$this->extConf['enableIpLogging'])
                                            $this->addFlashMessage(LocalizationUtility::translate('iplogging_is_disabled', $this->extensionName), '', AbstractMessage::WARNING);

                                        $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_IP_ADRESSES);
                                        $columns = 'element_title,counter';
                                        $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_IP_ADRESSES,$columns,STAT_COMPLETE_LIST);
                                        $this->getTable(LocalizationUtility::translate('type_pages_ip_addresses', $this->extensionName),$columns,$resultArray);
                                        break;
                                    case 'category_hosts':
                                        // display note, if ip-logging is disabled
                                        if (!$this->extConf['enableIpLogging'])
                                            $this->addFlashMessage(LocalizationUtility::translate('iplogging_is_disabled', $this->extensionName), '', AbstractMessage::WARNING);

                                        $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_IP_ADRESSES);
                                        $columns = 'element_title,counter';
                                        $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_IP_ADRESSES,$columns,STAT_COMPLETE_LIST);
                                        $this->getTable(LocalizationUtility::translate('type_pages_hosts', $this->extensionName),$columns,$resultArray,'hosts');
                                        break;
                                }
                                break;
                        }
                        break;
                    default:
                    case 'list_monthly_process':
                        /** @noinspection PhpVoidFunctionResultUsedInspection */
                        switch($this->menuUtility->getSelectedValue('list_type_category_monthly')) {
                            case 'category_monthly_pages':
                                $columns = 'element_title,counter';
                                $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_PAGES,$columns,STAT_ONLY_SUM,'element_title');
                                $this->getTable(LocalizationUtility::translate('type_pages_monthly', $this->extensionName),$columns,$resultArray,'no_line_numbers','counter','');
                                break;
                            case 'category_monthly_pages_fe_users':
                                $columns = 'element_title,counter';
                                $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_PAGES_FEUSERS,$columns,STAT_ONLY_SUM,'element_title');
                                $this->getTable(LocalizationUtility::translate('type_pages_monthly_fe_users', $this->extensionName),$columns,$resultArray,'no_line_numbers','counter','');
                                break;
                            case 'category_monthly_visits':
                                $columns = 'element_title,counter';
                                $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_VISITS_OVERALL,$columns,STAT_ONLY_SUM,'element_title');
                                $this->getTable(LocalizationUtility::translate('type_pages_visits_monthly', $this->extensionName),$columns,$resultArray,'no_line_numbers','counter','');
                                break;
                            case 'category_monthly_visits_fe_users':
                                $columns = 'element_title,counter';
                                $resultArray = $this->getStatResults(STAT_TYPE_PAGES,CATEGORY_VISITS_OVERALL_FEUSERS,$columns,STAT_ONLY_SUM,'element_title');
                                $this->getTable(LocalizationUtility::translate('type_pages_visits_monthly_fe_users', $this->extensionName),$columns,$resultArray,'no_line_numbers','counter','');
                                break;
                        }
                }
                break;
            // user tracking statistics
            case STAT_TYPE_TRACKING:
                // init tab menus
                $this->menuUtility->initMenu('tracking_results_number',10);

                // render the selector menu
                foreach ($this->showTrackingResultNumbers as $key => $value)
                    $this->showTrackingResultNumbers[$key] = $value.' '.LocalizationUtility::translate('show_entries_number', $this->extensionName);

                // display note, if tracking is disabled
                if (!$this->extConf['enableTracking']) {
                    $this->addFlashMessage(LocalizationUtility::translate('iplogging_is_disabled', $this->extensionName), '', AbstractMessage::WARNING);
                } else {
                    $this->dropDownMenus['tracking_results_number'] = $this->menuUtility->generateDropDownMenu($this->showTrackingResultNumbers,'tracking_results_number');

                    // render the refresh link
                    // todo: where to add reload-link if required?
                    $content = '<a href="JavaScript:location.reload(true);" class="buttonlink">'.LocalizationUtility::translate('refresh', $this->extensionName).'</a>';

                    // get the initial entries
                    $where_clause = 'type='.$GLOBALS['TYPO3_DB']->fullQuoteStr(STAT_TYPE_TRACKING, $this->tablename);
                    $where_clause .= ' AND category='.$GLOBALS['TYPO3_DB']->fullQuoteStr(CATEGORY_TRACKING_INITIAL, $this->tablename);
                    $where_clause .= $this->subpages_query;

                    // get the number of entries to display
                    /** @noinspection PhpVoidFunctionResultUsedInspection */
                    $number_of_entries = $this->menuUtility->getSelectedValue('tracking_results_number') ? $this->menuUtility->getSelectedValue('tracking_results_number') : 10;

                    // Todo: make the time format string configurable
                    $time_format_date = "%d.%m.%y";
                    $time_format_time = "%R";

                    // get the initial entries from the database
                    $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*',$this->tablename,$where_clause,'','tstamp DESC',$number_of_entries);

                    // loop through the entries and display the details
                    while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
                        $startTime = $row['crdate'];

                        // compile the header
                        $tableHeader = '';

                        $headerRowCounter = 0;
                        $tableInfoList = array(
                            CATEGORY_TRACKING_BROWSER,
                            CATEGORY_TRACKING_OPERATING_SYSTEM,
                            CATEGORY_TRACKING_IP_ADRESS,
                            CATEGORY_TRACKING_REFERER,
                            CATEGORY_TRACKING_SEARCH_STRING
                        );

                        foreach ($tableInfoList as $category) {
                            $where_clause = 'type=' . $GLOBALS['TYPO3_DB']->fullQuoteStr(STAT_TYPE_TRACKING, $this->tablename);
                            $where_clause .= ' AND category=' . $GLOBALS['TYPO3_DB']->fullQuoteStr($category, $this->tablename);
                            $where_clause .= ' AND parent_uid='.$row['uid'];
                            $resDetail = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*',$this->tablename,$where_clause);
                            $rowDetail = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($resDetail);
                            $headerRowCounter++;
                            if ($rowDetail['element_title']) {
                                if ($headerRowCounter > 1 && $headerRowCounter < 4) {
                                    $tableHeader .= ' / ';
                                } else if ($headerRowCounter >= 4) {
                                    $tableHeader .= ' <br /> ';
                                }
                                switch ($category) {
                                    case CATEGORY_TRACKING_REFERER:
                                        $tableHeader .= LocalizationUtility::translate('referer', $this->extensionName).': ';
                                        break;
                                    case CATEGORY_TRACKING_SEARCH_STRING:
                                        $tableHeader .= LocalizationUtility::translate('searchstring', $this->extensionName).': ';
                                        break;
                                }
                                $tableHeader .= $rowDetail['element_title'];
                            }
                        }

                        // get the details
                        $where_clause = 'type=' . $GLOBALS['TYPO3_DB']->fullQuoteStr(STAT_TYPE_TRACKING, $this->tablename);
                        $where_clause .= ' AND category=' . $GLOBALS['TYPO3_DB']->fullQuoteStr(CATEGORY_TRACKING_PAGES, $this->tablename);
                        $where_clause .= ' AND parent_uid=' . $GLOBALS['TYPO3_DB']->fullQuoteStr($row['uid'], $this->tablename);
                        $resDetail = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*',$this->tablename,$where_clause,'','crdate');

                        $printRows = array();
                        $lastRow = array();
                        while ($rowDetail = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($resDetail)) {
                            // compile the data which will be printed
                            $printRow['date'] = strftime($time_format_date, $rowDetail['crdate']);
                            $printRow['time'] = strftime($time_format_time, $rowDetail['crdate']);
                            $printRow['duration'] = $rowDetail['crdate'] - $startTime;
                            $printRow['element_title'] = $rowDetail['element_title'];
                            $printRow['element_uid'] = $rowDetail['element_uid'];
                            $printRow['element_language'] = $this->getLanguageName($rowDetail['element_language']);

                            // do some formating for the printRow
                            if ($printRow['duration'] > 60) {
                                $printRow['duration'] = round($printRow['duration']/60).' '.LocalizationUtility::translate('min', $this->extensionName);
                            } else {
                                $printRow['duration'] = $printRow['duration'].' '.LocalizationUtility::translate('sec', $this->extensionName);
                            }
                            if (strlen($printRow['element_title']) > $this->maxLengthTableContent) {
                                $printRow['element_title'] = substr($printRow['element_title'],0,$this->maxLengthTableContent).'...';
                            }

                            // print some values only if they differ from the last values
                            $cleanUpFiels = 'element_language,element_uid,date';
                            if (sizeof($lastRow)==0) {
                                $lastRow = $printRow;
                            } else {
                                foreach (explode(',',$cleanUpFiels) as $key) {
                                    if ($printRow[$key] == $lastRow[$key]) {
                                        $printRow[$key] = '';
                                    } else {
                                        $lastRow[$key] = $printRow[$key];
                                    }
                                }
                            }

                            // add this row to the result
                            $printRows[] = $printRow;
                        }
                        $this->getTable($tableHeader,'date,time,duration,element_title,element_uid,element_language',$printRows,'no_line_numbers','counter','');
                        unset($printRows);
                        unset($lastRow);
                    }
                }
                break;
            // display extension statistics
            // works more or less like the normal page statistics
            case STAT_TYPE_EXTENSION:
                /** @noinspection PhpVoidFunctionResultUsedInspection */
                /** @var array $category */
                $category = $this->menuUtility->getSelectedValue('extension_type',$this->allowedExtensionTypes);
                $this->renderSelectorMenu(STAT_TYPE_EXTENSION,$category);
                $columns = 'element_title,element_uid,counter';
                $resultArray = $this->getStatResults(STAT_TYPE_EXTENSION,$category,$columns);
                $this->addContentAboveTable('extension', $category);
                $this->getTable(LocalizationUtility::translate('type_extension', $this->extensionName),$columns,$resultArray,$category);
                $this->addContentBelowTable('extension', $category);
                break;
        }
    }

    /**
     * Get csv download menu for type csvdownload
     * 
     * @return array|null
     */
    protected function getCsvDownloadMenu()
    {
        if ($this->menuUtility->getSelectedValue('type') != 'csvdownload')
            return null;

        $sections = array(
            'list_full_csv' => array(
                'titleOnly' => true
            )
        );
        
        $sections['list_full_csv-content'] = array(
            'noTitle' => true,
            'links' => $this->menuUtility->generateLinkMenu(
                array(
                    'category_monthly_pages' => LocalizationUtility::translate('category_monthly_pages', $this->extensionName),
                    'category_monthly_pages_fe_users' => LocalizationUtility::translate('category_monthly_pages_fe_users', $this->extensionName),
                    'category_monthly_visits' => LocalizationUtility::translate('category_monthly_visits', $this->extensionName),
                    'category_monthly_visits_fe_users' => LocalizationUtility::translate('category_monthly_visits_fe_users', $this->extensionName)
                ),
                'list_type_category_monthly',
                '&type=pages&format=csv&list_type=list_monthly_process'
            )
        );
        /*
        $content .= '<a ';
        $content .= 'href="index.php?id='.$this->id.'&type=pages&list_type_category_monthly=category_monthly_pages&type=pages&format=csv';
        $content .= '<h2>' . LocalizationUtility::translate('list_details_csv') . '</h2>';
        $content .= '">';
        $content .= '</a>';
        */

        $sections['list_details_csv'] = array(
            'titleOnly' => true,
            'appendDropDownMenus' => true
        );

        // Render the dropdown for selecting month and year
        // we use STAT_TYPE_PAGES here, which is certainly not correct for all statistic types, but will do the job
        $this->content .= $this->renderSelectorMenu(STAT_TYPE_PAGES,CATEGORY_PAGES);
        $this->content .= '<div style="clear:both;">&nbsp;</div>';

        // render menu: pages
        $defaultParams = '&type=pages&format=csv&list_type=list_details_of_a_month';
        $sections['csvdownload_pages'] = array(
            'links' => $this->menuUtility->generateLinkMenu(
                array(
                    CATEGORY_PAGES => LocalizationUtility::translate('category_pages_all', $this->extensionName),
                    CATEGORY_PAGES_FEUSERS => LocalizationUtility::translate('category_pages_feusers', $this->extensionName)
                ),
                'category_pages',
                $defaultParams . '&list_type_category=category_pages'
            )
        );

        // render tab menu: referers
        $sections['csvdownload_referer'] = array(
            'links' => $this->menuUtility->generateLinkMenu(
                array(
                    CATEGORY_REFERERS_EXTERNAL_WEBSITES => LocalizationUtility::translate('category_referers_websites', $this->extensionName),
                    CATEGORY_REFERERS_SEARCHENGINES => LocalizationUtility::translate('category_referers_search_engines', $this->extensionName),
                    CATEGORY_SEARCH_STRINGS => LocalizationUtility::translate('category_search_strings', $this->extensionName)
                ),
                'category_referers',
                $defaultParams . '&list_type_category=category_referers'
            )
        );

        // render tab menu: time hits
        $sections['csvdownload_list_time_hits'] = array(
            'links' => $this->menuUtility->generateLinkMenu(
                array(
                    CATEGORY_PAGES_OVERALL_DAY_OF_MONTH =>  LocalizationUtility::translate('category_day_of_month', $this->extensionName),
                    CATEGORY_PAGES_OVERALL_DAY_OF_WEEK => LocalizationUtility::translate('category_day_of_week', $this->extensionName),
                    CATEGORY_PAGES_OVERALL_HOUR_OF_DAY => LocalizationUtility::translate('category_hour_of_day', $this->extensionName),
                ),
                'category_time_hits',
                $defaultParams . '&list_type_category=category_time&category_time_type=category_time_hits'
            )
        );

        // render tab menu: time visits
        $sections['csvdownload_list_time_visits'] = array(
            'links' => $this->menuUtility->generateLinkMenu(
                array(
                    CATEGORY_VISITS_OVERALL_DAY_OF_MONTH => LocalizationUtility::translate('category_visits_day_of_month', $this->extensionName),
                    CATEGORY_VISITS_OVERALL_DAY_OF_WEEK => LocalizationUtility::translate('category_visits_day_of_week', $this->extensionName),
                    CATEGORY_VISITS_OVERALL_HOUR_OF_DAY => LocalizationUtility::translate('category_visits_hour_of_day', $this->extensionName),
                ),
                'category_time_visits',
                $defaultParams . '&list_type_category=category_time&category_time_type=category_time_visits'
            )
        );

        // render tab menu: time visits logged-in
        $sections['csvdownload_list_time_visits_feusers'] = array(
            'links' => $this->menuUtility->generateLinkMenu(
                array(
                    CATEGORY_VISITS_OVERALL_FEUSERS_DAY_OF_MONTH => LocalizationUtility::translate('category_visits_day_of_month_feusers', $this->extensionName),
                    CATEGORY_VISITS_OVERALL_FEUSERS_DAY_OF_WEEK => LocalizationUtility::translate('category_visits_day_of_week_feusers', $this->extensionName),
                    CATEGORY_VISITS_OVERALL_FEUSERS_HOUR_OF_DAY => LocalizationUtility::translate('category_visits_hour_of_day_feusers', $this->extensionName),
                ),
                'category_time_visits_feusers',
                $defaultParams . '&list_type_category=category_time&category_time_type=category_time_visits_feusers'
            )
        );

        // render tab menu: user agents
        $sections['csvdownload_user_agents'] = array(
            'links' => $this->menuUtility->generateLinkMenu(
                array(
                    CATEGORY_BROWSERS => LocalizationUtility::translate('category_browsers', $this->extensionName),
                    CATEGORY_ROBOTS => LocalizationUtility::translate('category_robots', $this->extensionName),
                    CATEGORY_UNKNOWN_USER_AGENTS => LocalizationUtility::translate('category_unknown_user_agents', $this->extensionName),
                ),
                'category_user_agents',
                $defaultParams . '&list_type_category=category_user_agents'
            )
        );

        // render tab menu: other

        // display ip related options only if ip-logging is enabled
        $linkArray = array( CATEGORY_OPERATING_SYSTEMS => LocalizationUtility::translate('category_operating_systems', $this->extensionName));
        if ($this->extConf['enableIpLogging']) {
            $linkArray[CATEGORY_IP_ADRESSES ] = LocalizationUtility::translate('category_ip_addresses', $this->extensionName);
            $linkArray['category_hosts'] = LocalizationUtility::translate('category_hosts', $this->extensionName);
        }
        $sections['csvdownload_more_statistics'] = array(
            'links' => $this->menuUtility->generateLinkMenu(
                $linkArray,
                'category_other',
                $defaultParams . '&list_type_category=category_other'
            )
        );

        return $sections;
    }

    /**
     * Get update information
     *
     * Get information about to what time the update has been made
     *
     * @return bool|string
     */
    protected function getUpdateInformation()
    {
        $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery('tstamp', 'tx_kestats_statdata', '1=1', '', 'tstamp DESC', '1');
        if ($GLOBALS['TYPO3_DB']->sql_num_rows($res) > 0) {
            $row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res);
            return $row['tstamp'];
        }

        return false;
    }

    /**
     * Returns an array with statistical data of a certain time period.
     *
     * @param string $statType : type of the statistic. default ist pages, but may also be for example an extension key.
     * @param string $statCategory : category, used to determine further differences with in the statistic type
     * @param string $columns : fields to display in the list
     * @param int $onlySum : display only the sum of each month or the whole list for a certain time period (which is normally a single month)?
     * @param string $orderBy
     * @param string $groupBy : group fields (commalist of database field names)
     * @param int $encode_title_to_utf8 : set to 1 if the title in the result table has to be encoded to utf-8. The function checks for itself, if the backend is set to utf-8 and only then encodes the value.
     * @param array $fromToArray : contains the time period for which the statistical data shoud be generated (year and month from and to). If empty, it will be populated automatically within the function.
     * @return array
     */
    protected function getStatResults($statType = 'pages', $statCategory, $columns, $onlySum = 0, $orderBy = 'counter DESC', $groupBy = '', $encode_title_to_utf8 = 0, $fromToArray = array())
    {
        $columns = $this->addTypeAndLanguageToColumns($columns);

        // find out the time period, if it is not given as a parameter
        if (!count($fromToArray))
        {
            if ($onlySum)
            {
                // the whole time period, for which data exits
                $fromToArray = $this->getFirstAndLastEntries($statType,$statCategory);
            } else
            {
                // only the month given in the parameters
                /** @noinspection PhpVoidFunctionResultUsedInspection */
                $fromToArray['from_year'] = $this->menuUtility->getSelectedValue('year',$this->allowedYears);
                /** @noinspection PhpVoidFunctionResultUsedInspection */
                $fromToArray['to_year'] = $this->menuUtility->getSelectedValue('year',$this->allowedYears);
                /** @noinspection PhpVoidFunctionResultUsedInspection */
                $fromToArray['from_month'] = $this->menuUtility->getSelectedValue('month',$this->allowedMonths);
                /** @noinspection PhpVoidFunctionResultUsedInspection */
                $fromToArray['to_month'] = $this->menuUtility->getSelectedValue('month',$this->allowedMonths);
            }
        }

        /** @noinspection PhpVoidFunctionResultUsedInspection */
        $element_language = intval($this->menuUtility->getSelectedValue('element_language'));
        /** @noinspection PhpVoidFunctionResultUsedInspection */
        $element_type = intval($this->menuUtility->getSelectedValue('element_type'));

        return $this->kestatslib->getStatResults($statType, $statCategory, $columns, $onlySum, $orderBy, $groupBy, $encode_title_to_utf8, $fromToArray, $element_language, $element_type);
    }

    /**
     * addTypeAndLanguageToColumns
     * Add a column for the type and the language, if more than one type
     * (language) exists and none is yet selected
     *
     * @param string $columns
     * @return string
     */
    protected function addTypeAndLanguageToColumns($columns = '')
    {
        if (sizeof($this->elementTypesArray)>0 && $this->menuUtility->getSelectedValue('element_type')==-1)
            $columns = str_replace('element_title','element_title,element_type',$columns);

        if (sizeof($this->elementLanguagesArray)>0 && $this->menuUtility->getSelectedValue('element_language')==-1)
            $columns = str_replace('element_title','element_title,element_language',$columns);

        return $columns;
    }

    /**
     * returns year and month of the first and the last entry of given statistic types / categories
     *
     * @param string $statType
     * @param string $statCategory
     * @return array
     */
    protected function getFirstAndLastEntries($statType, $statCategory)
    {
        $fromToArray = array();
        $fromToArray['from_month'] = 0;
        $fromToArray['from_year'] = 0;
        $fromToArray['to_month'] = 0;
        $fromToArray['to_year'] = 0;

        $where_clause = 'type=\''.$statType.'\'';
        $where_clause .= ' AND category=\''.$statCategory.'\'';
        // ignore faulty entries
        $where_clause .= ' AND year>0';
        $where_clause .= $this->subpages_query;

        // get first entry
        $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*',$this->tablename,$where_clause,'','uid','1');
        if ($GLOBALS['TYPO3_DB']->sql_num_rows($res) > 0)
        {
            $row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res);
            $fromToArray['from_month'] = $row['month'];
            $fromToArray['from_year'] = $row['year'];
        } else
        {
            return $fromToArray;
        }

        // get last entry
        $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery('*',$this->tablename,$where_clause,'','uid DESC','1');
        if ($GLOBALS['TYPO3_DB']->sql_num_rows($res) > 0)
        {
            $row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res);
            $fromToArray['to_month'] = $row['month'];
            $fromToArray['to_year'] = $row['year'];
        } else
        {
            return $fromToArray;
        }

        return $fromToArray;
    }

    /**
     * Gets a table, rendered from the array $dataRows.
     * $dataRows must contains one row for each row in the table.
     * Each row is an array associative containing the data for the row.
     *
     * Result is finally written to $this->csvContent
     *
     * @param string $caption: Table-caption
     * @param string $columns: comma-separated list of column-names used in the table (corrsponding to the array-keys in each row)
     * @param array $dataRows: data array
     * @param string $special: special rendering options
     * @param string $columnWithSum: name of the column for which a sum shall be calculated
     * @param string $columnWithPercent: name of the column for which a sum shall be calculated
     * @param int $maxrows: Max. rows to render. 0 --> render all rows.
     */
    protected function getTable($caption = 'Table', $columns = 'element_title,element_uid,counter', $dataRows = array(), $special = '', $columnWithSum = 'counter', $columnWithPercent = 'counter', $maxrows = 0)
    {
        $columns = $this->addTypeAndLanguageToColumns($columns);
        $columnsArray = explode(',',$columns);

        // is there a language column and which one is it?
        $language_column = -1;
        $i = 0;
        foreach ($columnsArray as $column)
        {
            if ($column == 'element_language')
                $language_column = $i;
            $i++;
        }

        // Kick out every field from the dataRows which should not be rendered
        if (count($dataRows) > 0)
            foreach ($dataRows as $label => $dataRow)
                foreach ($dataRow as $column_name => $data)
                    if (!in_array($column_name, $columnsArray))
                        unset($dataRows[$label][$column_name]);

        // first we calculate the sum for each column
        $sumRow = array();
        if (count($dataRows) > 0)
        {
            foreach ($dataRows as $label => $dataRow)
            {
                $column_number = 0;
                foreach ($dataRow as $data)
                {
                    if (!isset($sumRow[$column_number]))
                        $sumRow[$column_number] = 0;
                    $sumRow[$column_number] += intval($data);
                    $column_number++;
                }
            }
        }

        // how many data columns will we have?
        if (count($dataRows) > 0)
        {
            reset($dataRows);
            $numberOfDataColumns = sizeof(current($dataRows));
            // add one for the percentage column
            if (!empty($columnWithPercent))
                $numberOfDataColumns += sizeof($columnWithPercent);
        }

        // hack: we do have at least two colums!
        if (!isset($numberOfDataColumns) || $numberOfDataColumns < 2)
            $numberOfDataColumns = 2;

        // first we render a line number column
        if (!strstr($special,'no_line_numbers'))
            $this->addCsvCol(LocalizationUtility::translate('header_line_number', $this->extensionName));

        // render a header column for each data column
        foreach ($columnsArray as $data)
            $this->addCsvCol(LocalizationUtility::translate('header_'.$data, $this->extensionName));

        if (!empty($columnWithPercent))
            for ($column_number=0; $column_number<$numberOfDataColumns; $column_number++)
                if ($columnsArray[$column_number-1] == $columnWithPercent)
                    $this->addCsvCol(LocalizationUtility::translate('header_percent', $this->extensionName));

        $rowCount = 0;

        // print the data rows
        if (count($dataRows) > 0)
        {
            foreach ($dataRows as $key => $dataRow)
            {

                // skip empty rows with empty title and emtpy uid
                $skipRow = false;
                if (empty($dataRow['element_title']) && empty($dataRow['element_uid']))
                {
                    $skipRow = true;
                } else
                {
                    $rowCount++;
                }

                // render row if we not reached the limit $maxrows
                if (!$maxrows || $rowCount <= $maxrows && !$skipRow)
                {
                    $column_number = 0;

                    // start a new csv row
                    $this->addCsvRow();

                    // print the line number (which is the key in the data array)
                    if (!strstr($special,'no_line_numbers'))
                        $this->addCsvCol($key);

                    foreach ($dataRow as $data)
                    {
                        // print the label of this row
                        if ($column_number == 0) {
                            if (strstr($special,'day_of_week')) {
                                $formatted_data = LocalizationUtility::translate('weekday_'.$data, $this->extensionName);
                            } else if (strstr($special,'hosts')) {
                                $formatted_data = gethostbyaddr($data);
                            } else if (strstr($special,'url')) {
                                $formatted_data = $data;
                                if (substr($formatted_data,0,strlen('http://')) == 'http://') {
                                    $formatted_data = substr($formatted_data,strlen('http://'));
                                }
                                if (strlen($formatted_data) > $this->maxLengthURLs) {
                                    $formatted_data = substr($formatted_data,0,$this->maxLengthURLs).'...';
                                }
                                // sanitize the output
                                // since 5.5.2008 data is already sanitized in the frontend
                                // plugin, but maybe there are older values in the
                                // databases that need to be sanitized
                                $formatted_data = $this->csvOutput ? rawurlencode($data) : '<a target="_blank" href="'.htmlspecialchars($data, ENT_QUOTES).'">'.htmlspecialchars($formatted_data, ENT_QUOTES).'</a>';
                            } else if (strstr($special,'naw_securedl')) {
                                // Data from extension "naw_securedl"
                                $formatted_data = $this->csvOutput ? rawurlencode($data) : '<a title="'.htmlspecialchars($data, ENT_QUOTES).'" alt="'.htmlspecialchars($data, ENT_QUOTES).'">'.basename(htmlspecialchars($data, ENT_QUOTES)).'</a>';
                            } else if (strstr($special,'none')) {
                                $formatted_data = $data;
                                $formatted_data = htmlspecialchars($formatted_data, ENT_QUOTES);
                            } else if (strstr($special,'hour_of_day')) {
                                $formatted_data = $data.' - '.sprintf('%02d',intval($data+1));
                            } else {
                                $formatted_data = $data;
                                if (strlen($formatted_data) > $this->maxLengthTableContent) {
                                    $formatted_data = substr($formatted_data,0,$this->maxLengthTableContent).'...';
                                }
                            }
                            // todo: Signal for individual modifications of the description col
                            $this->addCsvCol($formatted_data);
                        } else {
                            // print the data
                            // if this the row with the language, print the cleartext language name
                            if ($column_number == $language_column) {
                                $formatted_data = $this->getLanguageName($data);
                            } else {
                                // do some formatting
                                switch ($special) {
                                    default:
                                        $formatted_data = $data;
                                        // number format for integer fields
                                        if (strval(intval($formatted_data)) == $formatted_data) {
                                            $formatted_data = number_format(intval($formatted_data),0,'.','');
                                        }
                                        break;
                                }
                            }
                            $this->addCsvCol($formatted_data);
                        }

                        // render the percent column
                        if ($columnsArray[$column_number] == $columnWithPercent) {
                            if (!empty($sumRow[$column_number])) {
                                //$percent = round(100 * intval($data) / $sumRow[$column_number],2);
                                $percent = 100 * intval($data) / $sumRow[$column_number];
                                $percent = number_format($percent, 2, $this->decimalChar, ' ');
                            } else {
                                $percent = '-';
                            }
                            $this->addCsvCol($percent . ' %');
                        }
                        $column_number++;
                    }
                }
            }

            // start a new csv row
            $this->addCsvRow();

            // make the sum row
            if (strlen($columnWithSum) > 0) {
                // This columns normally contais the line number, so wie have to disable it, if we have no line numbers
                if (!strstr($special,'no_line_numbers')) {
                    $this->addCsvCol(LocalizationUtility::translate('sum', $this->extensionName));
                }
                for ($column_number=0; $column_number<$numberOfDataColumns; $column_number++) {
                    if ($columnsArray[$column_number] == $columnWithSum) {
                        $this->addCsvCol($sumRow[$column_number]);
                    } else {
                        if ($column_number>0 && $columnsArray[$column_number-1] == $columnWithPercent) {
                            $this->addCsvCol('100 %');
                        } else {
                            $this->addCsvCol('');
                        }
                    }
                }
            }
        }

        // add caption to csv
        $this->addCsvRow();
        $this->addCsvCol($caption);
    }

    /**
     * method which calls a signal to add some content above table
     *
     * @param string $type
     * @param string $category
     */
    protected function addContentAboveTable($type, $category)
    {
        // todo: Signal for additional content above table
    }

    /**
     * method which calls a signal to add some content below table
     *
     * @param string $type
     * @param string $category
     */
    protected function addContentBelowTable($type, $category)
    {
        // todo: Signal for additional content above table
    }

    /**
     * addCsvCol
     *
     * @param string $content
     * @api
     */
    public function addCsvCol($content = '')
    {
        $this->csvContent[$this->currentRowNumber][$this->currentColNumber] = $content;
        $this->currentColNumber++;
    }

    /**
     * addCsvRow
     *
     * @api
     */
    public function addCsvRow()
    {
        $this->currentRowNumber++;
        $this->currentColNumber = 0;
        $this->csvContent[$this->currentRowNumber] = array();
    }

    /**
     * outputCSV
     */
    protected function downloadCsvFile()
    {
        // Set Excel as default application
        header('Pragma: private');
        header('Cache-control: private, must-revalidate');
        header("Content-Type: application/vnd.ms-excel");

        // Set file name
        header('Content-Disposition: attachment; filename="' . str_replace('###DATE###', date('Y-m-d-H-i'), LocalizationUtility::translate('csvdownload_filename', $this->extensionName) . '"'));

        $content = '';
        foreach ($this->csvContent as $row)
            $content .= GeneralUtility::csvValues($row) . "\n";

        // I'm not sure if this is necessary for all programs you are importing to, tested with OpenOffice.org
        if ($GLOBALS['LANG']->charSet == 'utf-8')
            $content = utf8_decode($content);

        echo $content;
        exit();
    }


    /**
     * loadFrontendTSconfig
     *
     * gives access to the frontend TSconfig
     * loads the TSconfig for the given page-uid
     * and the plugin-TSconfig on this page for $plugin_name
     *
     * @param mixed $pageUid
     * @param string $plugin_name
     */
    protected function loadFrontendTSconfig($pageUid = 0, $plugin_name = '')
    {
        if ($pageUid > 0)
        {
            $sysPageObj = $this->objectManager->get('TYPO3\\CMS\\Frontend\\Page\\PageRepository');
            $rootLine = $sysPageObj->getRootLine($pageUid);
            $TSObj = $this->objectManager->get('TYPO3\\CMS\\Core\\TypoScript\\ExtendedTemplateService');
            $TSObj->tt_track = 0;
            $TSObj->init();
            $TSObj->runThroughTemplates($rootLine);
            $TSObj->generateConfig();
            //$this->conf = $TSObj->setup;
            if (!empty($plugin_name))
                $this->extConf = ArrayUtility::arrayMergeRecursiveOverrule($this->extConf, $TSObj->setup['plugin.'][$plugin_name.'.']);
        }
    }

}