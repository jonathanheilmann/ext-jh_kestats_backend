<?php
namespace Heilmann\JhKestatsBackend\Utility;

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
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class KestatsUtility
 * @package Heilmann\JhKestatsBackend\Utility
 */
class KestatsUtility extends \tx_kestats_lib
{

    /**
     * refreshOverviewPageData
     *
     * In future versions there will be an overview page with more data. This
     * page will then stay in a cache and will be updated by a cron-cli-script.
     * Right now, there are only visitors and pageviews in order to keep the
     * rendering time fast.
     *
     * @param int $pageUid
     * @return array
     */
    public function refreshOverviewPageData($pageUid=0) {
        $overviewPageData = array();

        // all languages and types will be shown in the overview page
        $element_language = -1;
        $element_type = -1;

        // get the subpages list
        $this->pagelist = strval($pageUid);
        $this->getSubPages($pageUid);

        $startTime = GeneralUtility::milliseconds();

        // in the overview page we display 13 month
        $fromToArray['from_year'] = date('Y') - 1;
        $fromToArray['to_year'] = date('Y');
        $fromToArray['from_month'] = date('n');
        $fromToArray['to_month'] = date('n');

        // monthly process of pageviews
        $columns = 'element_title,counter';
        $pageviews = $this->getStatResults(STAT_TYPE_PAGES, CATEGORY_PAGES, $columns, STAT_ONLY_SUM, 'counter DESC', '', 0, $fromToArray, $element_language, $element_type);
        //$content .= $this->renderTable($GLOBALS['LANG']->getLL('type_pages_monthly'),$columns,$resultArray,'no_line_numbers','counter','');
        // monthly process of visitors
        $visits = $this->getStatResults(STAT_TYPE_PAGES, CATEGORY_VISITS_OVERALL, $columns, STAT_ONLY_SUM, 'counter DESC', '', 0, $fromToArray, $element_language, $element_type);

        // combine visits and pageviews
        $resultArray = array();
        for ($i = 0; $i < 13; $i++) {
            $pages_per_visit = $visits[$i]['counter'] ? round(floatval($pageviews[$i]['counter'] / $visits[$i]['counter']), 1) : '';
            $resultArray[$i] = array(
                'element_title' => $pageviews[$i]['element_title'],
                'pageviews' => $pageviews[$i]['counter'],
                'visits' => $visits[$i]['counter'],
                'pages_per_visit' => $pages_per_visit
            );
        }

        $overviewPageData['pageviews_and_visits'] = $resultArray;
        unset($pageviews);
        unset($visits);
        unset($resultArray);

        // For future versions:
        /*

          // in the overview page we display the current month for the detailed listing
          $fromToArray['from_year'] = date('Y');
          $fromToArray['to_year'] = date('Y');
          $fromToArray['from_month'] = date('n');
          $fromToArray['to_month'] = date('n');

          // pageviews of the current month
          $columns = 'element_title,element_uid,counter';
          $overviewPageData['pageviews_current_month'] = $this->getStatResults(STAT_TYPE_PAGES, CATEGORY_PAGES, $columns, 0, 'counter DESC', '', 0, $fromToArray, $element_language, $element_type);

          // referers, external websites
          $columns = 'element_title,counter';
          $overviewPageData['referers_external_websites'] = $this->getStatResults(STAT_TYPE_PAGES, CATEGORY_REFERERS_EXTERNAL_WEBSITES, $columns, 0, 'counter DESC', '', 0, $fromToArray, $element_language, $element_type);

          // search words
          $columns = 'element_title,counter';
          $overviewPageData['search_words'] = $this->getStatResults(STAT_TYPE_PAGES, CATEGORY_SEARCH_STRINGS, $columns, 0, 'counter DESC', '', 0, $fromToArray, $element_language, $element_type);

          // extensions
          // get extension types
          $extensionTypes= array();
          $res = $GLOBALS['TYPO3_DB']->exec_SELECTquery('category','tx_kestats_statdata','type=\''.STAT_TYPE_EXTENSION.'\' AND year='. date('Y') . ' AND month=' . date('m'), 'category');
          if ($GLOBALS['TYPO3_DB']->sql_num_rows($res) > 0) {
          while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res)) {
          $extensionTypes[] = $row['category'];
          }
          }

          // save extension list
          $overviewPageData['extensionlist'] = implode(',', $extensionTypes);

          // get extension data
          $columns = 'element_title,counter';
          foreach ($extensionTypes as $extensionType) {
          //$overviewPageData['extension_' . $extensionType] = $this->getStatResults(STAT_TYPE_EXTENSION, $extensionType, $columns, 0, 'counter DESC', '', 0, $fromToArray, $element_language, $element_type);
          }

         */

        // some time information ...
        $runningTime = round((GeneralUtility::milliseconds() - $startTime) / 1000, 1);
        // $overviewPageData['info'] = '<p class="update_information">' . $GLOBALS['LANG']->getLL('last_update') . date(UPDATED_UNTIL_DATEFORMAT);
        // $overviewPageData['info'] .= ' in ' . $runningTime . ' s.<p>';
        $overviewPageData['tstamp'] = time();

                return $overviewPageData;
    }

}