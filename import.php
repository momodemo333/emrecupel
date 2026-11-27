<?php
/* Copyright (C) 2026 E-dem SRL <support@e-dem.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    import.php
 * \ingroup emrecupel
 * \brief   Import / export of the product classification (CSV ref;code;quantity;origin).
 */

$res = 0;
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

dol_include_once('/emrecupel/class/emrecupeldocumentsync.class.php');
dol_include_once('/emrecupel/class/emrecupelproduct.class.php');
dol_include_once('/emrecupel/lib/emrecupel.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('products', 'emrecupel@emrecupel'));

if (!isModEnabled('emrecupel') || !$user->hasRight('emrecupel', 'read')) {
	accessforbidden();
}
$action = GETPOST('action', 'aZ09');
$ep = new EmrecupelProduct($db);

if ($action === 'export') {
	$csv = $ep->exportClassification();
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="recupel-products-' . dol_print_date(dol_now(), '%Y%m%d') . '.csv"');
	print "\xEF\xBB\xBF" . $csv;
	exit;
}

if (!$user->hasRight('emrecupel', 'write')) {
	accessforbidden();
}

$content = '';
$parsed = null;
$result = null;
if (in_array($action, array('check', 'import'), true)) {
	if (!empty($_FILES['csvfile']['tmp_name']) && is_uploaded_file($_FILES['csvfile']['tmp_name'])) {
		$content = (string) file_get_contents($_FILES['csvfile']['tmp_name']);
	} else {
		$content = GETPOST('csvcontent', 'none');
	}
	$parsed = EmrecupelProduct::parseClassificationCsv($content);
	if ($action === 'import' && empty($parsed['errors'])) {
		$result = $ep->importClassification($parsed['rows'], $user, false);
		if (empty($result['errors'])) {
			setEventMessages($langs->trans('RecupelImportDone', $result['products']), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('RecupelImportPartial', $result['products']), $result['errors'], 'warnings');
		}
	} elseif (!empty($parsed['rows'])) {
		$result = $ep->importClassification($parsed['rows'], $user, true);
	}
}

llxHeader('', $langs->trans('RecupelImportClassification'), '', '', 0, 0, '', '', '', 'mod-emrecupel page-import');
print load_fiche_titre($langs->trans('Recupel'), emrecupel_search_link(), 'fa-recycle');
print dol_get_fiche_head(emrecupel_prepare_head(), 'import', '', -1, '');

print '<div class="opacitymedium">' . $langs->trans('RecupelImportHelp') . '</div>';
print '<pre class="small">ref;code;quantity;origin
LAPTOP-14;2.4;1;local
FRIGO-A;1.1;1;import
SPOT-KIT;5.6;1;import
SPOT-KIT;3.2;3;import
CABLE-USB;exempt;;</pre>';

print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<table class="border centpercent">';
print '<tr><td class="titlefield">' . $langs->trans('RecupelCsvFile') . '</td><td><input type="file" name="csvfile" accept=".csv,text/csv,text/plain"></td></tr>';
print '<tr><td>' . $langs->trans('RecupelCsvPaste') . '</td><td><textarea name="csvcontent" class="quatrevingtpercent" rows="8">' . dol_escape_htmltag($content) . '</textarea></td></tr>';
print '</table>';
print '<div class="center"><button type="submit" class="button" name="action" value="check">' . $langs->trans('RecupelCheckFile') . '</button> ';
print '<button type="submit" class="button button-save" name="action" value="import">' . $langs->trans('Import') . '</button></div>';
print '</form>';

if ($parsed !== null) {
	print '<br>' . load_fiche_titre($langs->trans('RecupelCheckResult'), '', '');
	print '<div>' . $langs->trans('RecupelCheckLines', count($parsed['rows'])) . '</div>';
	$errs = array_merge($parsed['errors'], $result !== null ? $result['errors'] : array());
	if (!empty($errs)) {
		print '<div class="error"><ul><li>' . implode('</li><li>', array_map('dol_escape_htmltag', $errs)) . '</li></ul></div>';
	} elseif ($action === 'check') {
		print '<div class="ok">' . $langs->trans('RecupelCheckOk', $result !== null ? $result['products'] : 0) . '</div>';
	}
}

print '<br>' . load_fiche_titre($langs->trans('Export'), '', '');
print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?action=export&token=' . newToken() . '">' . $langs->trans('RecupelExportClassification') . '</a>';

print dol_get_fiche_end();
llxFooter();
$db->close();
