<?php
/* Copyright (C) 2026 E-dem SRL <support@e-dem.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/setup.php
 * \ingroup emrecupel
 * \brief   Setup of emRecupel: company profile, display rules, documents,
 *          line templates, fee service, database diagnostic.
 */

$res = 0;
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
dol_include_once('/emrecupel/class/emrecupeldocumentsync.class.php');
dol_include_once('/emrecupel/class/emrecupelmigrations.class.php');
dol_include_once('/emrecupel/lib/emrecupel.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 * @var Form $form
 */

$langs->loadLangs(array('admin', 'products', 'emrecupel@emrecupel'));

if (!$user->admin && !$user->hasRight('emrecupel', 'setup')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$modes = EmrecupelFeePolicy::modes();

if ($action === 'save') {
	$error = 0;
	$profile = GETPOST('EMRECUPEL_PROFILE', 'aZ09');
	if (in_array($profile, array('reseller', 'exporter', 'producer'), true)) {
		$error += dolibarr_set_const($db, 'EMRECUPEL_PROFILE', $profile, 'chaine', 0, '', $conf->entity) < 0 ? 1 : 0;
	}
	$origin = GETPOST('EMRECUPEL_DEFAULT_ORIGIN', 'aZ09');
	if (in_array($origin, array('', 'import', 'local'), true)) {
		$error += dolibarr_set_const($db, 'EMRECUPEL_DEFAULT_ORIGIN', $origin, 'chaine', 0, '', $conf->entity) < 0 ? 1 : 0;
	}
	foreach (array('EMRECUPEL_MODE_RESELLER_PRO', 'EMRECUPEL_MODE_ENDUSER_HOUSEHOLD', 'EMRECUPEL_MODE_ENDUSER_PRO') as $c) {
		$v = GETPOST($c, 'aZ09');
		if (in_array($v, $modes, true)) {
			$error += dolibarr_set_const($db, $c, $v, 'chaine', 0, '', $conf->entity) < 0 ? 1 : 0;
		}
	}
	foreach (array('EMRECUPEL_PRICE_BASE_TTC', 'EMRECUPEL_ON_PROPAL', 'EMRECUPEL_ON_ORDER', 'EMRECUPEL_ON_INVOICE') as $c) {
		$error += dolibarr_set_const($db, $c, GETPOSTINT($c) ? '1' : '0', 'chaine', 0, '', $conf->entity) < 0 ? 1 : 0;
	}
	$rule = GETPOST('EMRECUPEL_COUNTRY_RULE', 'aZ09');
	if (in_array($rule, array('BE', 'ALL'), true)) {
		$error += dolibarr_set_const($db, 'EMRECUPEL_COUNTRY_RULE', $rule, 'chaine', 0, '', $conf->entity) < 0 ? 1 : 0;
	}
	foreach (array('FR', 'NL', 'EN') as $l) {
		$v = trim(GETPOST('EMRECUPEL_LINE_LABEL_' . $l, 'alphanohtml'));
		if ($v === '') {
			dolibarr_del_const($db, 'EMRECUPEL_LINE_LABEL_' . $l, $conf->entity);
		} else {
			$error += dolibarr_set_const($db, 'EMRECUPEL_LINE_LABEL_' . $l, $v, 'chaine', 0, '', $conf->entity) < 0 ? 1 : 0;
		}
	}
	$pid = GETPOSTINT('EMRECUPEL_FEE_PRODUCT_ID');
	if ($pid > 0) {
		$error += dolibarr_set_const($db, 'EMRECUPEL_FEE_PRODUCT_ID', (string) $pid, 'chaine', 0, '', $conf->entity) < 0 ? 1 : 0;
	}
	setEventMessages($error ? $langs->trans('Error') : $langs->trans('SetupSaved'), null, $error ? 'errors' : 'mesgs');
	header('Location: ' . $_SERVER['PHP_SELF']);
	exit;
}
if ($action === 'repair' && $_SERVER['REQUEST_METHOD'] === 'POST') {
	dolibarr_del_const($db, 'EMRECUPEL_MIGRATION_FAILED', $conf->entity);
	$res = EmrecupelMigrations::createHelper($db)->forceRunAll();
	if (empty($res['errors'])) {
		setEventMessages($langs->trans('RecupelRepairDone', $res['executed']), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('RecupelRepairErrors'), $res['errors'], 'errors');
	}
	header('Location: ' . $_SERVER['PHP_SELF']);
	exit;
}

// ---------------------------------------------------------------------------
// View
// ---------------------------------------------------------------------------
llxHeader('', $langs->trans('EmrecupelSetup'), '', '', 0, 0, '', '', '', 'mod-emrecupel page-admin-setup');
$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans('BackToModuleList') . '</a>';
print load_fiche_titre($langs->trans('EmrecupelSetup'), $linkback, 'title_setup');
print dol_get_fiche_head(emrecupel_admin_prepare_head(), 'settings', '', -1, '');

print emrecupel_disclaimer();

/**
 * @param string $name     Constant
 * @param string $selected Value
 * @return string
 */
function emrecupel_select_mode($name, $selected)
{
	global $langs;
	$out = '<select class="flat" name="' . $name . '">';
	foreach (EmrecupelFeePolicy::modes() as $m) {
		$out .= '<option value="' . $m . '"' . ($m === $selected ? ' selected' : '') . '>' . dol_escape_htmltag(emrecupel_mode_label($m)) . '</option>';
	}
	return $out . '</select>';
}

$settings = EmrecupelDocumentSync::displaySettings();
$profile = EmrecupelDocumentSync::profile();

print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="save">';

// Profile.
print load_fiche_titre($langs->trans('RecupelProfile'), '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>' . $langs->trans('Parameter') . '</th><th>' . $langs->trans('Value') . '</th></tr>';
print '<tr class="oddeven"><td class="titlefieldcreate">' . $langs->trans('RecupelProfile') . '</td><td>';
foreach (array('reseller' => 'RecupelProfileReseller', 'exporter' => 'RecupelProfileExporter', 'producer' => 'RecupelProfileProducer') as $k => $lbl) {
	print '<label class="block paddingtopbottomonly"><input type="radio" name="EMRECUPEL_PROFILE" value="' . $k . '"' . ($profile === $k ? ' checked' : '') . '> <strong>' . $langs->trans($lbl) . '</strong> — <span class="opacitymedium">' . $langs->trans($lbl . 'Help') . '</span></label>';
}
print '</td></tr>';
$defOrigin = getDolGlobalString('EMRECUPEL_DEFAULT_ORIGIN');
print '<tr class="oddeven"><td>' . $form->textwithpicto($langs->trans('RecupelDefaultOrigin'), $langs->trans('RecupelDefaultOriginHelp')) . '</td><td><select class="flat" name="EMRECUPEL_DEFAULT_ORIGIN">';
print '<option value=""' . ($defOrigin === '' ? ' selected' : '') . '>' . $langs->trans('RecupelFromProfile', emrecupel_origin_label(EmrecupelFeePolicy::profileDefaultOrigin($profile))) . '</option>';
foreach (array('import', 'local') as $o) {
	print '<option value="' . $o . '"' . ($defOrigin === $o ? ' selected' : '') . '>' . dol_escape_htmltag(emrecupel_origin_label($o)) . '</option>';
}
print '</select></td></tr>';
print '</table><br>';

// Display matrix.
print load_fiche_titre($langs->trans('RecupelDisplayRules'), '', '');
print '<div class="opacitymedium">' . $langs->trans('RecupelDisplayRulesHelp') . '</div>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>' . $langs->trans('RecupelCustomer') . '</th><th>' . $langs->trans('RecupelUseHousehold') . '</th><th>' . $langs->trans('RecupelUsePro') . '</th></tr>';
print '<tr class="oddeven"><td>' . $langs->trans('RecupelRoleReseller') . '</td><td>' . emrecupel_mode_label('line') . ' <span class="badge badge-status4">' . $langs->trans('RecupelLegalObligation') . '</span></td><td>' . emrecupel_select_mode('EMRECUPEL_MODE_RESELLER_PRO', $settings['reseller_professional']) . '</td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans('RecupelRoleEnduser') . '</td><td>' . emrecupel_select_mode('EMRECUPEL_MODE_ENDUSER_HOUSEHOLD', $settings['enduser_household']) . '</td><td>' . emrecupel_select_mode('EMRECUPEL_MODE_ENDUSER_PRO', $settings['enduser_professional']) . '</td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans('RecupelOutsideBelgium') . '</td><td colspan="2">' . $langs->trans('RecupelNoFee') . '</td></tr>';
print '</table>';
print '<div class="opacitymedium small">' . $langs->trans('RecupelModesExplained') . '</div><br>';

// Documents and computation.
print load_fiche_titre($langs->trans('RecupelDocumentsAndComputation'), '', '');
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>' . $langs->trans('Parameter') . '</th><th>' . $langs->trans('Value') . '</th></tr>';
foreach (array('EMRECUPEL_ON_PROPAL' => 'RecupelOnPropal', 'EMRECUPEL_ON_ORDER' => 'RecupelOnOrder', 'EMRECUPEL_ON_INVOICE' => 'RecupelOnInvoice') as $c => $lbl) {
	print '<tr class="oddeven"><td class="titlefieldcreate">' . $langs->trans($lbl) . '</td><td><input type="checkbox" name="' . $c . '" value="1"' . (getDolGlobalInt($c, 1) ? ' checked' : '') . '></td></tr>';
}
print '<tr class="oddeven"><td>' . $form->textwithpicto($langs->trans('RecupelPriceBaseTTC'), $langs->trans('RecupelPriceBaseTTCHelp')) . '</td><td><input type="checkbox" name="EMRECUPEL_PRICE_BASE_TTC" value="1"' . (getDolGlobalInt('EMRECUPEL_PRICE_BASE_TTC', 1) ? ' checked' : '') . '></td></tr>';
$rule = getDolGlobalString('EMRECUPEL_COUNTRY_RULE', 'BE');
print '<tr class="oddeven"><td>' . $form->textwithpicto($langs->trans('RecupelCountryRule'), $langs->trans('RecupelCountryRuleHelp')) . '</td><td><select class="flat" name="EMRECUPEL_COUNTRY_RULE"><option value="BE"' . ($rule === 'BE' ? ' selected' : '') . '>' . $langs->trans('RecupelCountryRuleBE') . '</option><option value="ALL"' . ($rule === 'ALL' ? ' selected' : '') . '>' . $langs->trans('RecupelCountryRuleAll') . '</option></select></td></tr>';
$pid = getDolGlobalInt('EMRECUPEL_FEE_PRODUCT_ID');
print '<tr class="oddeven"><td>' . $form->textwithpicto($langs->trans('RecupelFeeProduct'), $langs->trans('RecupelFeeProductHelp')) . '</td><td>';
print $form->select_produits($pid, 'EMRECUPEL_FEE_PRODUCT_ID', 1, 0, 0, -1, 2, '', 0, array(), 0, 1, 0, 'minwidth300', 1);
if ($pid > 0) {
	$p = new Product($db);
	if ($p->fetch($pid) > 0) {
		print ' ' . $p->getNomUrl(1) . ' <span class="opacitymedium small">' . $langs->trans('RecupelFeeProductAccounting') . '</span>';
	}
}
print '</td></tr>';
$defaults = EmrecupelDocumentSync::defaultLineTemplates();
foreach (array('FR', 'NL', 'EN') as $l) {
	print '<tr class="oddeven"><td>' . $form->textwithpicto($langs->trans('RecupelLineTemplate', $l), $langs->trans('RecupelLineTemplateHelp')) . '</td><td><input type="text" class="flat minwidth400" name="EMRECUPEL_LINE_LABEL_' . $l . '" value="' . dol_escape_htmltag(getDolGlobalString('EMRECUPEL_LINE_LABEL_' . $l)) . '" placeholder="' . dol_escape_htmltag($defaults[$l]) . '"></td></tr>';
}
print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="' . $langs->trans('Save') . '"></div>';
print '</form><br>';

// Diagnostic.
print load_fiche_titre($langs->trans('RecupelDiagnostic'), '', '');
$helper = EmrecupelMigrations::createHelper($db);
$check = $helper->verifySchema(EmrecupelMigrations::getExpectedSchema());
$catalog = new EmrecupelCatalog($db);
print '<table class="noborder centpercent">';
print '<tr class="oddeven"><td class="titlefieldcreate">' . $langs->trans('RecupelDbVersion') . '</td><td>' . dol_escape_htmltag($helper->getStoredVersion()) . ' / ' . EmrecupelMigrations::MODULE_VERSION . '</td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans('RecupelSchema') . '</td><td>' . ($check['ok'] ? img_picto('', 'tick') . ' ' . $langs->trans('RecupelSchemaOk') : img_warning() . ' ' . dol_escape_htmltag(implode(', ', array_merge($check['missing_tables'], array_map(function ($t, $cols) {
	return $t . ' (' . implode(', ', $cols) . ')';
}, array_keys($check['missing_columns']), $check['missing_columns']))))) . '</td></tr>';
print '<tr class="oddeven"><td>' . $langs->trans('RecupelScalesLoaded') . '</td><td>' . dol_escape_htmltag(implode(', ', array_map(function ($d) {
	return dol_print_date(dol_stringtotime($d), 'day');
}, $catalog->getScaleDates()))) . '</td></tr>';
print '</table>';
if (getDolGlobalString('EMRECUPEL_MIGRATION_FAILED') !== '') {
	print '<div class="error">' . $langs->trans('RecupelMigrationFailed', dol_escape_htmltag(getDolGlobalString('EMRECUPEL_MIGRATION_FAILED'))) . '</div>';
}
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '"><input type="hidden" name="token" value="' . newToken() . '"><input type="hidden" name="action" value="repair">';
print '<div class="tabsAction"><input type="submit" class="butAction" value="' . dol_escape_htmltag($langs->trans('RecupelRepair')) . '"></div></form>';

print dol_get_fiche_end();
llxFooter();
$db->close();
