<?php
/* Copyright (C) 2026 E-dem SRL <support@e-dem.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    lib/emrecupel.lib.php
 * \ingroup emrecupel
 * \brief   UI helpers of emRecupel (tabs, selectors, labels).
 */

dol_include_once('/emrecupel/class/emrecupelfeepolicy.class.php');
dol_include_once('/emrecupel/class/emrecupelcatalog.class.php');

/**
 * Tabs of the setup pages.
 *
 * @return array<int,array<int,string>>
 */
function emrecupel_admin_prepare_head()
{
	global $langs;
	$langs->load('emrecupel@emrecupel');
	$h = 0;
	$head = array();
	$head[$h] = array(dol_buildpath('/emrecupel/admin/setup.php', 1), $langs->trans('Settings'), 'settings');
	$h++;
	$head[$h] = array(dol_buildpath('/emrecupel/admin/about.php', 1), $langs->trans('About'), 'about');
	$h++;
	return $head;
}

/**
 * Tabs of the Recupel pages of the Products menu.
 *
 * @return array<int,array<int,string>>
 */
function emrecupel_prepare_head()
{
	global $langs, $user;
	$langs->load('emrecupel@emrecupel');
	$h = 0;
	$head = array();
	$head[$h] = array(dol_buildpath('/emrecupel/classify.php', 1), $langs->trans('RecupelProductsToClassify'), 'classify');
	$h++;
	if ($user->hasRight('emrecupel', 'write')) {
		$head[$h] = array(dol_buildpath('/emrecupel/import.php', 1), $langs->trans('RecupelImportClassification'), 'import');
		$h++;
	}
	$head[$h] = array(dol_buildpath('/emrecupel/scale.php', 1), $langs->trans('RecupelScale'), 'scale');
	$h++;
	$head[$h] = array(dol_buildpath('/emrecupel/switch.php', 1), $langs->trans('RecupelSwitchAssistant'), 'switch');
	$h++;
	return $head;
}

/**
 * HTML select of the assignable Recupel codes, grouped by main category.
 *
 * @param EmrecupelCatalog $catalog  Catalog
 * @param string           $htmlname Name of the field
 * @param string           $selected Selected code
 * @param string           $langcode Language of the labels
 * @param bool             $empty    Add an empty choice
 * @param string           $morecss  CSS class
 * @return string
 */
function emrecupel_select_code($catalog, $htmlname, $selected = '', $langcode = '', $empty = true, $morecss = 'minwidth300 maxwidth500')
{
	global $langs;
	$langcode = $langcode ?: $langs->defaultlang;
	$all = $catalog->getAllCategories();
	$parents = array();
	$leaves = array();
	foreach ($all as $code => $cat) {
		if ($cat['parent_code'] === '') {
			$parents[$code] = $catalog->getLabel($code, $langcode);
		} elseif ($catalog->isAssignable($code)) {
			$leaves[$cat['parent_code']][$code] = $catalog->getLabel($code, $langcode) . ($cat['use_type'] === 'professional' ? ' [' . $langs->trans('RecupelUsePro') . ']' : '');
		}
	}
	uksort($parents, array('EmrecupelCatalog', 'compareCodes'));
	$out = '<select class="flat ' . $morecss . '" name="' . dol_escape_htmltag($htmlname) . '" id="' . dol_escape_htmltag($htmlname) . '">';
	if ($empty) {
		$out .= '<option value="">&nbsp;</option>';
	}
	foreach ($parents as $pcode => $plabel) {
		if (empty($leaves[$pcode])) {
			continue;
		}
		uksort($leaves[$pcode], array('EmrecupelCatalog', 'compareCodes'));
		$out .= '<optgroup label="' . dol_escape_htmltag($pcode . '. ' . $plabel) . '">';
		foreach ($leaves[$pcode] as $code => $label) {
			$out .= '<option value="' . dol_escape_htmltag($code) . '"' . ((string) $selected === (string) $code ? ' selected' : '') . ' data-html="' . dol_escape_htmltag('<strong>' . dol_escape_htmltag($code) . '</strong> ' . dol_escape_htmltag($label)) . '">' . dol_escape_htmltag($code . ' – ' . $label) . '</option>';
		}
		$out .= '</optgroup>';
	}
	$out .= '</select>';
	include_once DOL_DOCUMENT_ROOT . '/core/lib/ajax.lib.php';
	$out .= ajax_combobox($htmlname);
	return $out;
}

/**
 * HTML select of an origin.
 *
 * @param string $htmlname Field name
 * @param string $selected Selected value
 * @param bool   $withDefault Add "company default"
 * @return string
 */
function emrecupel_select_origin($htmlname, $selected = '', $withDefault = true)
{
	global $langs;
	$opts = array();
	if ($withDefault) {
		$opts[''] = $langs->transnoentitiesnoconv('RecupelOriginDefault', emrecupel_origin_label(EmrecupelDocumentSync::defaultOrigin()));
	}
	foreach (array(EmrecupelFeePolicy::ORIGIN_IMPORT, EmrecupelFeePolicy::ORIGIN_LOCAL, EmrecupelFeePolicy::ORIGIN_USED) as $o) {
		$opts[$o] = emrecupel_origin_label($o);
	}
	$out = '<select class="flat" name="' . dol_escape_htmltag($htmlname) . '">';
	foreach ($opts as $k => $v) {
		$out .= '<option value="' . dol_escape_htmltag($k) . '"' . ((string) $selected === (string) $k ? ' selected' : '') . '>' . dol_escape_htmltag($v) . '</option>';
	}
	$out .= '</select>';
	return $out;
}

/**
 * Label helpers below return plain text (no HTML entities): escape them
 * once with dol_escape_htmltag() when printed, or pass them to trans().
 *
 * @param string $origin ORIGIN_*
 * @return string
 */
function emrecupel_origin_label($origin)
{
	global $langs;
	$map = array('import' => 'RecupelOriginImport', 'local' => 'RecupelOriginLocal', 'used' => 'RecupelOriginUsed');
	return isset($map[$origin]) ? $langs->transnoentitiesnoconv($map[$origin]) : $langs->transnoentitiesnoconv('RecupelOriginDefaultShort');
}

/**
 * @param string $mode MODE_*
 * @return string
 */
function emrecupel_mode_label($mode)
{
	global $langs;
	$map = array('line' => 'RecupelModeLine', 'included' => 'RecupelModeIncluded', 'footer' => 'RecupelModeFooter');
	return isset($map[$mode]) ? $langs->transnoentitiesnoconv($map[$mode]) : $mode;
}

/**
 * @param string $decision APPLY or SKIP_*
 * @return string
 */
function emrecupel_decision_label($decision)
{
	global $langs;
	return $langs->transnoentitiesnoconv('RecupelDecision_' . $decision);
}

/**
 * @param string $bucket BUCKET_*
 * @return string
 */
function emrecupel_bucket_label($bucket)
{
	global $langs;
	return $langs->transnoentitiesnoconv('RecupelBucket_' . $bucket);
}

/**
 * @param string $useType household or professional
 * @return string
 */
function emrecupel_use_label($useType)
{
	global $langs;
	return $useType === 'professional' ? $langs->transnoentitiesnoconv('RecupelUsePro') : $langs->transnoentitiesnoconv('RecupelUseHousehold');
}

/**
 * @param string $feeType allin or admin
 * @return string
 */
function emrecupel_feetype_label($feeType)
{
	global $langs;
	if ($feeType === '') {
		return '';
	}
	return $feeType === 'admin' ? $langs->transnoentitiesnoconv('RecupelFeeAdmin') : $langs->transnoentitiesnoconv('RecupelFeeAllin');
}

/**
 * Amount of the scale (4 decimals, as published by Recupel).
 *
 * @param float|null $amount Amount
 * @return string
 */
function emrecupel_amount($amount)
{
	if ($amount === null) {
		return '';
	}
	return price((float) $amount, 0, '', 1, 4, 4) . ' €';
}

/**
 * Link to the official appliance search of Recupel.
 *
 * @return string
 */
function emrecupel_search_link()
{
	global $langs;
	$lang = substr($langs->defaultlang, 0, 2);
	$lang = in_array($lang, array('fr', 'nl', 'en'), true) ? $lang : 'en';
	return '<a href="https://www.recupel.be/' . $lang . '/appliance-list-search-appliance" target="_blank" rel="noopener noreferrer">' . img_picto('', 'globe', 'class="pictofixedwidth"') . $langs->trans('RecupelOfficialSearch') . '</a>';
}

/**
 * Disclaimer printed on the report and the setup.
 *
 * @return string
 */
function emrecupel_disclaimer()
{
	global $langs;
	return '<div class="warning">' . img_warning() . ' ' . $langs->trans('RecupelDisclaimer') . '</div>';
}
