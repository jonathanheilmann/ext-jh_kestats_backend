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
 * Class MenuUtility
 * @package Heilmann\JhKestatsBackend\Utility
 */
class MenuUtility
{
    const NULL_VALUE = 'EMPTYVALUE';

    /** @var array  */
    protected $arguments = array();

    /** @var array  */
    protected $menuNames = array();

    /**
     * @var \TYPO3\CMS\Backend\Routing\UriBuilder
     * @inject
     */
    protected $uriBuilder = null;

    /** @var array */
    protected $tabmenuPresetValues = array();

    /**
     * @var array
     */
    public $generateTabMenu = array();

    /**
     * @return array
     */
    public function getArguments()
    {
        return $this->arguments;
    }

    /**
     * @param $arguments
     */
    public function setArguments($arguments)
    {
        $this->arguments = $arguments;
    }

    /**
     * Generates a TYPO3 backend module tab menu
     * allowedValues: see getSelectedValue
     *
     * @param mixed $menuArray
     * @param string $menuName
     * @param string $additionalParams
     * @param array $allowedValues
     * @return array
     */
    function generateTabMenu($menuArray, $menuName = 'default', $additionalParams='', $allowedValues = array())
    {        
        $menuItems = array();

        // transform $additionalParams from string to array
        $queryItems = explode('&', $additionalParams);
        $additionalParams = array();
        if (count($queryItems) > 0)
        {
            foreach ($queryItems as $queryItem)
            {
                $param = explode('=', $queryItem);
                $additionalParams[$param[0]] = $param[1];
            }
        }

        foreach ($this->menuNames as $name)
            if ($name != $menuName)
                $additionalParams[$name] = $this->getSelectedValue($name, $allowedValues, 0);

        $selectedValue = $this->getSelectedValue($menuName, $allowedValues);
        foreach ($menuArray as $menuValue => $menuDescription)
        {
            $additionalParams[$menuName] = $menuValue;
            $menuItems[] = array(
                'value' => $menuValue,
                'description' => $menuDescription,
                'isActive' => $selectedValue == $menuValue,
                'arguments' => $additionalParams
            );
        }
        return $menuItems;
    }

    /**
     * generateLinkMenu
     *
     * Generate a menu with simple links
     *
     * this is important for the csv export links:
     * additionalParams are APPENDED so we can use them to overwrite given values
     * adds a "descr"-parameter to the link which contains the linktext
     *
     * @param mixed $menuArray
     * @param string $menuName
     * @param string $additionalParams
     * @param array $allowedValues
     * @return array
     */
    function generateLinkMenu($menuArray, $menuName = 'default', $additionalParams = '', $allowedValues = array())
    {
        $additionalParamsArray = array();
        foreach ($this->menuNames as $name)
            if ($name != $menuName)
                $additionalParamsArray[$name] = $this->getSelectedValue($name, $allowedValues);

        // transform $additionalParams from string to array
        // MUST override generated additionalParams
        $queryItems = GeneralUtility::trimExplode('&', $additionalParams, true);
        if (count($queryItems) > 0)
        {
            foreach ($queryItems as $queryItem)
            {
                $param = explode('=', $queryItem);
                $additionalParamsArray[$param[0]] = $param[1];
            }
        }

        $links = array();
        foreach ($menuArray as $menuValue => $menuDescription)
        {
            $additionalParamsArray[$menuName] = $menuValue;
            $additionalParamsArray['descr'] = $menuDescription;
            $links[] = array(
                'value' => $menuValue,
                'description' => $menuDescription,
                'arguments' => $additionalParamsArray
            );
        }

        return $links;
    }

    /**
     * Generates a TYPO3 backend module dropdown menu
     *
     * @param mixed $menuArray
     * @param string $menuName
     * @param string $additionalParams
     * @return string
     */
    function generateDropDownMenu($menuArray, $menuName = 'default', $additionalParams = '') {
        // transform $additionalParams from string to array
        $queryItems = GeneralUtility::trimExplode('&', $additionalParams, true);
        $additionalParams = array();
        if (count($queryItems) > 0)
        {
            foreach ($queryItems as $queryItem)
            {
                $param = explode('=', $queryItem);
                $additionalParams[$param[0]] = $param[1];
            }
        }

        foreach ($this->menuNames as $name)
            if ($name != $menuName)
                $additionalParams[$name] = $this->getSelectedValue($name, array(), 0);

        // extract the allowed values from the menu-array
        $allowedValues = array();
        foreach ($menuArray as $menuValue => $menuDescription) {
            // transform 0 to NULL_VALUE
            // otherwise 0 would not be a possible value to select in the form
            if (strval($menuValue) === '0')
                $menuValue = self::NULL_VALUE;

            $allowedValues[] = $menuValue;
        }

        // get the selected value for this menu
        $selectedValue = $this->getSelectedValue($menuName, $allowedValues);

        $optionItems = array();
        foreach ($menuArray as $menuValue => $menuDescription)
        {
            $optionItems[] = array(
                'isSelected' => $selectedValue == $menuValue,
                'value' => strval($menuValue) === '0' ? self::NULL_VALUE : $menuValue,
                'description' => $menuDescription
            );
        }

        return array(
            'name' => $menuName,
            'arguments' => $additionalParams,
            'options' => $optionItems
        );
    }

    /**
     * Returns the selected value from a given menu
     * if the allowedValues array is given, the selected value will be checked against its values
     * if the value does not exist in the array, the first value in the array will be selected
     *
     * @param string $menuName
     * @param array $allowedValues
     * @param int $transformNullValue
     * @return string
     */
    function getSelectedValue($menuName = 'default', $allowedValues = array(), $transformNullValue = 1)
    {
        $value = isset($this->arguments[$menuName]) ? $this->arguments[$menuName] : null;
        if (empty($value) && $this->tabmenuPresetValues[$menuName]) $value = $this->tabmenuPresetValues[$menuName];

        // check if the selected value really exists in the options
        // otherwise select the first value
        if (sizeof($allowedValues) > 0)
            if (!in_array($value,$allowedValues))
                $value = $allowedValues[0];

        // transform NULL_VALUE back to 0
        if ($value == self::NULL_VALUE && $transformNullValue)
            $value = 0;

        return $value;
    }

    /**
     * presets values for a given tab menu
     *
     * @param mixed $value
     * @param string $menuName
     * @access public
     * @return void
     */
    function initMenu($menuName = 'default', $value='')
    {
        $this->menuNames[] = $menuName;
        $this->tabmenuPresetValues[$menuName] = $value;
    }
}