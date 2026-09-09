<?php

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die;

// Register a test-only content type carrying a multi-sheet FlexForm
// (sheet `sDEF` with settings.* fields, sheet `persistence` with
// persistence.storagePid) — mirrors plugins like EXT:powermail whose
// FlexForm spans multiple sheets and non-`settings` subtrees.
ExtensionManagementUtility::addTcaSelectItem(
    'tt_content',
    'CType',
    [
        'label' => 'Test Multi-Sheet FlexForm',
        'value' => 'test_multisheetflex',
        'group' => 'default',
    ]
);

$GLOBALS['TCA']['tt_content']['types']['test_multisheetflex'] = [
    'showitem' => '--palette--;;general, header, pi_flexform',
];

ExtensionManagementUtility::addPiFlexFormValue(
    '*',
    'FILE:EXT:test_flexform/Configuration/FlexForms/multisheet.xml',
    'test_multisheetflex'
);

// A second test-only type whose FlexForm declares a select with NO static
// items: the options arrive at runtime through a DataStructure event, which is
// how ext:form contributes its form definitions. Kept apart from
// test_multisheetflex so tests asserting that type's field list stay stable.
ExtensionManagementUtility::addTcaSelectItem(
    'tt_content',
    'CType',
    [
        'label' => 'Test Dynamic FlexForm',
        'value' => 'test_dynamicflex',
        'group' => 'default',
    ]
);

$GLOBALS['TCA']['tt_content']['types']['test_dynamicflex'] = [
    'showitem' => '--palette--;;general, header, pi_flexform',
];

ExtensionManagementUtility::addPiFlexFormValue(
    '*',
    'FILE:EXT:test_flexform/Configuration/FlexForms/dynamiclist.xml',
    'test_dynamicflex'
);
