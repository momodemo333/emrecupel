<?php
/* Copyright (C) 2026 E-dem SRL <support@e-dem.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    scale.php
 * \ingroup emrecupel
 * \brief   Recupel scale: rates valid at a date, history of a code, manual
 *          import of a new scale (CSV).
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
dol_include_once('/emrecupel/lib/emrecupel.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'emrecupel@emrecupel'));

if (!isModEnabled('emrecupel') || !$user->hasRight('emrecupel', 'read')) {
	accessforbidden();
}
$action = GETPOST('action', 'aZ09');
$catalog = new EmrecupelCatalog($db);
$today = dol_print_date(dol_now(), '%Y-%m-%d');
$date = GETPOST('date', 'alphanohtml');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
	$date = $today;
}
$code = GETPOST('code', 'alphanohtml');
// The scale is shared by every company (entity 1): only the setup of the main
// entity may import one.
$canSetup = $user->hasRight('emrecupel', 'setup') && (int) $conf->entity === 1;

// ---------------------------------------------------------------------------
// Import of a scale
// ---------------------------------------------------------------------------
$preview = null;
$csv = '';
if ($canSetup && in_array($action, array('checkscale', 'importscale'), true)) {
	if (!empty($_FILES['scalefile']['tmp_name']) && is_uploaded_file($_FILES['scalefile']['tmp_name'])) {
		$csv = (string) file_get_contents($_FILES['scalefile']['tmp_name']);
	} else {
		$csv = (string) GETPOST('scalecsv', 'none');
	}
	$parsed = EmrecupelCatalog::parseScaleCsv($csv);
	if (!empty($parsed['errors'])) {
		setEventMessages($langs->trans('RecupelScaleFileErrors'), $parsed['errors'], 'errors');
	} elseif (empty($parsed['rows'])) {
		setEventMessages($langs->trans('RecupelScaleFileEmpty'), null, 'errors');
	} elseif ($action === 'importscale') {
		$res = $catalog->importScale($parsed['rows']);
		if (empty($res['errors'])) {
			setEventMessages($langs->trans('RecupelScaleImported', $res['categories'], $res['rates']), null, 'mesgs');
			header('Location: ' . $_SERVER['PHP_SELF']);
			exit;
		}
		setEventMessages($langs->trans('RecupelScaleImportFailed'), $res['errors'], 'errors');
	} else {
		// Preview: compare with the scale in force the day before the new one.
		$starts = array();
		$byCode = array();
		foreach ($parsed['rows'] as $r) {
			if ($r['amount_ht'] !== '') {
				$starts[$r['date_start']] = true;
				$byCode[$r['code']] = $r;
			}
		}
		$preview = array('starts' => array_keys($starts), 'new' => array(), 'removed' => array(), 'changed' => array(), 'big' => array(), 'count' => count($byCode));
		$ref = count($starts) === 1 ? EmrecupelCatalog::dayBefore(key($starts)) : $today;
		$current = $catalog->getScale($ref, $langs->defaultlang);
		foreach ($byCode as $c => $r) {
			if (!isset($current[$c]) || $current[$c]['ht'] === null) {
				$preview['new'][] = $c;
				continue;
			}
			$old = (float) $current[$c]['ht'];
			$new = (float) $r['amount_ht'];
			if (abs($old - $new) > 0.000001) {
				$preview['changed'][] = $c;
				if ($old > 0 && abs($new - $old) / $old > 0.5) {
					$preview['big'][] = $c . ' (' . price($old, 0, '', 1, 4, 4) . ' → ' . price($new, 0, '', 1, 4, 4) . ')';
				}
			}
		}
		foreach ($current as $c => $row) {
			if ($row['ht'] !== null && !isset($byCode[$c])) {
				$preview['removed'][] = $c;
			}
		}
	}
}

// ---------------------------------------------------------------------------
// View
// ---------------------------------------------------------------------------
llxHeader('', $langs->trans('RecupelScale'), '', '', 0, 0, '', '', '', 'mod-emrecupel page-scale');
print load_fiche_titre($langs->trans('Recupel'), emrecupel_search_link(), 'fa-recycle');
print dol_get_fiche_head(emrecupel_prepare_head(), 'scale', '', -1, '');

$dates = $catalog->getScaleDates();
print '<form method="GET" action="' . $_SERVER['PHP_SELF'] . '">';
print $langs->trans('RecupelScaleValidAt') . ' <input type="date" name="date" value="' . dol_escape_htmltag($date) . '"> ';
print '<input type="submit" class="button small" value="' . $langs->trans('Show') . '"> &nbsp; ';
foreach ($dates as $d) {
	print '<a class="marginleftonly" href="' . $_SERVER['PHP_SELF'] . '?date=' . $d . '">' . $langs->trans('RecupelScaleOf', dol_print_date(dol_stringtotime($d), 'day')) . '</a>';
}
print '</form>';
print '<div class="opacitymedium small">' . $langs->trans('RecupelScaleSources') . ' <a href="https://www.recupel.be/en/appliance-list" target="_blank" rel="noopener noreferrer">recupel.be/appliance-list</a></div><br>';

// History of one code.
if ($code !== '' && $catalog->getCategory($code) !== null) {
	print load_fiche_titre($langs->trans('RecupelHistoryOf', $code . ' – ' . $catalog->getLabel($code, $langs->defaultlang)), '<a href="' . $_SERVER['PHP_SELF'] . '?date=' . $date . '">' . $langs->trans('Close') . '</a>', '');
	print '<table class="noborder centpercent"><tr class="liste_titre"><th>' . $langs->trans('DateStart') . '</th><th>' . $langs->trans('DateEnd') . '</th><th>' . $langs->trans('RecupelFeeType') . '</th><th class="right">' . $langs->trans('HT') . '</th><th class="right">' . $langs->trans('TTC') . '</th><th>' . $langs->trans('Source') . '</th></tr>';
	foreach ($catalog->getRateHistory($code) as $h) {
		print '<tr class="oddeven"><td>' . dol_print_date(dol_stringtotime($h['date_start']), 'day') . '</td><td>' . ($h['date_end'] !== '' ? dol_print_date(dol_stringtotime($h['date_end']), 'day') : '') . '</td><td>' . emrecupel_feetype_label($h['fee_type']) . '</td><td class="right">' . emrecupel_amount($h['amount_ht']) . '</td><td class="right">' . emrecupel_amount($h['amount_ttc']) . '</td><td class="small">' . dol_escape_htmltag($h['source']) . '</td></tr>';
	}
	print '</table><br>';
}

$scale = $catalog->getScale($date, $langs->defaultlang);
$prevDate = '';
foreach ($dates as $d) {
	if ($d <= $date) {
		$prevDate = EmrecupelCatalog::dayBefore($d);
		break;
	}
}
$prev = $prevDate !== '' ? $catalog->getScale($prevDate, $langs->defaultlang) : array();
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th>' . $langs->trans('RecupelCode') . '</th><th>' . $langs->trans('Label') . '</th><th>' . $langs->trans('RecupelUse') . '</th><th>' . $langs->trans('RecupelFeeType') . '</th><th class="right">' . $langs->trans('HT') . '</th><th class="right">' . $langs->trans('TTC') . '</th><th class="right">' . $langs->trans('RecupelPreviousScale') . '</th></tr>';
$parentLabel = '';
foreach ($scale as $c => $row) {
	if ($row['parent_code'] !== $parentLabel) {
		$parentLabel = $row['parent_code'];
		print '<tr class="liste_titre_sel"><td colspan="7"><strong>' . dol_escape_htmltag($parentLabel . '. ' . $catalog->getLabel($parentLabel, $langs->defaultlang)) . '</strong></td></tr>';
	}
	$p = isset($prev[$c]) ? $prev[$c] : null;
	$changed = ($p !== null && $p['ht'] !== null && $row['ht'] !== null && abs($p['ht'] - $row['ht']) > 0.000001);
	print '<tr class="oddeven' . (empty($row['active']) ? ' opacitymedium' : '') . '">';
	print '<td class="nowraponall"><a href="' . $_SERVER['PHP_SELF'] . '?date=' . $date . '&code=' . urlencode($c) . '">' . dol_escape_htmltag($c) . '</a></td>';
	print '<td>' . dol_escape_htmltag($row['label']) . '</td>';
	print '<td>' . emrecupel_use_label($row['use_type']) . '</td>';
	print '<td>' . emrecupel_feetype_label($row['fee_type']) . '</td>';
	print '<td class="right nowraponall">' . emrecupel_amount($row['ht']) . '</td>';
	print '<td class="right nowraponall"><strong>' . emrecupel_amount($row['ttc']) . '</strong></td>';
	print '<td class="right nowraponall' . ($changed ? ' warning' : ' opacitymedium') . '">' . ($p !== null && $p['ttc'] !== null ? emrecupel_amount($p['ttc']) : '') . '</td>';
	print '</tr>';
}
print '</table></div>';

// Import.
if ($canSetup) {
	print '<br>' . load_fiche_titre($langs->trans('RecupelImportScale'), '', '');
	print '<div class="opacitymedium">' . $langs->trans('RecupelImportScaleHelp') . '</div>';
	print '<pre class="small">code;parent_code;use_type;fee_type;label_fr;label_nl;label_en;amount_ht;amount_ttc;date_start;date_end;source
1.1;1;household;allin;Dispositifs d\'échange thermique…;Temperatuuruitwisselingsapparaten…;Temperature exchange equipment…;10.7438;13.0000;2027-01-01;;Recupel 2027</pre>';
	print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '" enctype="multipart/form-data">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="file" name="scalefile" accept=".csv,text/csv,text/plain"> ';
	print '<input type="hidden" name="scalecsv" value="' . dol_escape_htmltag($csv) . '">';
	print '<button type="submit" class="button" name="action" value="checkscale">' . $langs->trans('RecupelCheckFile') . '</button>';
	if ($preview !== null) {
		print ' <button type="submit" class="button button-save" name="action" value="importscale">' . $langs->trans('RecupelApplyScale') . '</button>';
	}
	print '</form>';
	if ($preview !== null) {
		print '<br><table class="border centpercent">';
		print '<tr><td class="titlefield">' . $langs->trans('RecupelScaleStart') . '</td><td>' . dol_escape_htmltag(implode(', ', $preview['starts'])) . '</td></tr>';
		print '<tr><td>' . $langs->trans('RecupelScaleCodes') . '</td><td>' . $preview['count'] . ($preview['count'] !== 92 ? ' ' . img_warning() . ' ' . $langs->trans('RecupelScaleExpected92') : '') . '</td></tr>';
		print '<tr><td>' . $langs->trans('RecupelScaleNewCodes') . '</td><td>' . dol_escape_htmltag(implode(', ', $preview['new'])) . '</td></tr>';
		print '<tr><td>' . $langs->trans('RecupelScaleRemovedCodes') . '</td><td>' . dol_escape_htmltag(implode(', ', $preview['removed'])) . '</td></tr>';
		print '<tr><td>' . $langs->trans('RecupelScaleChangedCodes') . '</td><td>' . count($preview['changed']) . '</td></tr>';
		print '<tr><td>' . $langs->trans('RecupelScaleBigChanges') . '</td><td>' . dol_escape_htmltag(implode(' ; ', $preview['big'])) . '</td></tr>';
		print '</table>';
	}
}

print dol_get_fiche_end();
llxFooter();
$db->close();
