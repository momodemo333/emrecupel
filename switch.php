<?php
/* Copyright (C) 2026 E-dem SRL <support@e-dem.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    switch.php
 * \ingroup emrecupel
 * \brief   Yearly switch assistant: what changes with the next scale for the
 *          products of the catalogue and for the open proposals and orders.
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

require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
dol_include_once('/emrecupel/class/emrecupeldocumentsync.class.php');
dol_include_once('/emrecupel/class/emrecupelproduct.class.php');
dol_include_once('/emrecupel/lib/emrecupel.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('products', 'propal', 'orders', 'emrecupel@emrecupel'));

if (!isModEnabled('emrecupel') || !$user->hasRight('emrecupel', 'read')) {
	accessforbidden();
}

$catalog = new EmrecupelCatalog($db);
$ep = new EmrecupelProduct($db);
$today = dol_print_date(dol_now(), '%Y-%m-%d');
$dates = $catalog->getScaleDates();
$newDate = GETPOST('newdate', 'alphanohtml');
if (!in_array($newDate, $dates, true)) {
	$newDate = $catalog->getNextScaleDate($today);
	if ($newDate === '' && !empty($dates)) {
		$newDate = $dates[0];
	}
}
$oldDate = $newDate !== '' ? EmrecupelCatalog::dayBefore($newDate) : $today;

llxHeader('', $langs->trans('RecupelSwitchAssistant'), '', '', 0, 0, '', '', '', 'mod-emrecupel page-switch');
print load_fiche_titre($langs->trans('Recupel'), emrecupel_search_link(), 'fa-recycle');
print dol_get_fiche_head(emrecupel_prepare_head(), 'switch', '', -1, '');

print '<form method="GET" action="' . $_SERVER['PHP_SELF'] . '">' . $langs->trans('RecupelSwitchTo') . ' <select class="flat" name="newdate">';
foreach ($dates as $d) {
	print '<option value="' . $d . '"' . ($d === $newDate ? ' selected' : '') . '>' . dol_print_date(dol_stringtotime($d), 'day') . '</option>';
}
print '</select> <input type="submit" class="button small" value="' . $langs->trans('Show') . '"></form><br>';

if ($newDate === '') {
	print '<div class="opacitymedium">' . $langs->trans('RecupelNoScale') . '</div>';
	print dol_get_fiche_end();
	llxFooter();
	$db->close();
	exit;
}

$cmp = $catalog->compareScales($oldDate, $newDate, $langs->defaultlang);
$counts = array('same' => 0, 'changed' => 0, 'new' => 0, 'removed' => 0);
foreach ($cmp as $row) {
	$counts[$row['status']]++;
}
print '<div class="info">' . $langs->trans('RecupelSwitchSummary', dol_print_date(dol_stringtotime($newDate), 'day'), $counts['changed'], $counts['same'], $counts['new'] + $counts['removed']) . '</div>';
print '<div class="warning">' . $langs->trans('RecupelSwitchMovedCodes') . '</div>';

// 1. Codes that change.
print load_fiche_titre($langs->trans('RecupelSwitchCodes'), '', '');
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>' . $langs->trans('RecupelCode') . '</th><th>' . $langs->trans('Label') . '</th><th class="right">' . dol_print_date(dol_stringtotime($oldDate), 'day') . ' (' . $langs->trans('TTC') . ')</th><th class="right">' . dol_print_date(dol_stringtotime($newDate), 'day') . ' (' . $langs->trans('TTC') . ')</th><th>' . $langs->trans('RecupelFeeType') . '</th></tr>';
foreach ($cmp as $c => $row) {
	if ($row['status'] === 'same') {
		continue;
	}
	$typeChange = $row['old_fee_type'] !== $row['new_fee_type'] ? emrecupel_feetype_label($row['old_fee_type']) . ' → <strong>' . emrecupel_feetype_label($row['new_fee_type']) . '</strong>' : emrecupel_feetype_label($row['new_fee_type']);
	print '<tr class="oddeven"><td>' . dol_escape_htmltag($c) . '</td><td>' . dol_escape_htmltag(dol_trunc($row['label'], 80)) . '</td>';
	print '<td class="right">' . emrecupel_amount($row['old_ttc']) . '</td><td class="right"><strong>' . emrecupel_amount($row['new_ttc']) . '</strong></td><td>' . $typeChange . '</td></tr>';
}
print '</table></div>';

// 2. Products of the catalogue whose fee changes.
$changedCodes = array();
foreach ($cmp as $c => $row) {
	if ($row['status'] !== 'same') {
		$changedCodes[] = "'" . $db->escape($c) . "'";
	}
}
print '<br>' . load_fiche_titre($langs->trans('RecupelSwitchProducts'), '', '');
$productIds = array();
if (!empty($changedCodes)) {
	$sql = "SELECT DISTINCT pc.fk_product FROM " . MAIN_DB_PREFIX . "emrecupel_product_code as pc INNER JOIN " . MAIN_DB_PREFIX . "product as p ON p.rowid = pc.fk_product";
	$sql .= " WHERE p.entity IN (" . getEntity('product') . ") AND pc.code IN (" . implode(',', $changedCodes) . ") ORDER BY pc.fk_product";
	$resql = $db->query($sql);
	while ($resql && ($o = $db->fetch_object($resql))) {
		$productIds[] = (int) $o->fk_product;
	}
}
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>' . $langs->trans('Product') . '</th><th>' . $langs->trans('RecupelCodes') . '</th><th class="right">' . $langs->trans('RecupelFeePerUnitAt', dol_print_date(dol_stringtotime($oldDate), 'day')) . '</th><th class="right">' . $langs->trans('RecupelFeePerUnitAt', dol_print_date(dol_stringtotime($newDate), 'day')) . '</th><th class="right">' . $langs->trans('RecupelDifference') . '</th></tr>';
$prod = new Product($db);
$feeOld = array();
$feeNew = array();
foreach ($productIds as $pid) {
	$data = $ep->get($pid, false);
	$o1 = 0.0;
	$n1 = 0.0;
	$codes = array();
	foreach ($data['items'] as $it) {
		$r1 = $catalog->getRateInfo($it['code'], $oldDate);
		$r2 = $catalog->getRateInfo($it['code'], $newDate);
		$o1 += $r1 ? (($r1['ttc'] !== null ? $r1['ttc'] : $r1['ht'] * 1.21) * $it['units']) : 0;
		$n1 += $r2 ? (($r2['ttc'] !== null ? $r2['ttc'] : $r2['ht'] * 1.21) * $it['units']) : 0;
		$codes[] = $it['code'] . ($it['units'] != 1 ? ' ×' . price2num($it['units']) : '') . ($it['code'] === '4.1.2' ? ' ' . img_warning($langs->trans('RecupelCheckDishwasher')) : '');
	}
	$feeOld[$pid] = $o1;
	$feeNew[$pid] = $n1;
	$prod->fetch($pid);
	print '<tr class="oddeven"><td>' . $prod->getNomUrl(1) . ' ' . dol_escape_htmltag(dol_trunc($prod->label, 40)) . '</td><td>' . implode(', ', $codes) . '</td>';
	print '<td class="right">' . price($o1, 0, $langs, 1, -1, 2, $conf->currency) . '</td><td class="right"><strong>' . price($n1, 0, $langs, 1, -1, 2, $conf->currency) . '</strong></td><td class="right">' . ($n1 - $o1 >= 0 ? '+' : '') . price($n1 - $o1, 0, $langs, 1, -1, 2, $conf->currency) . '</td></tr>';
}
if (empty($productIds)) {
	print '<tr class="oddeven"><td colspan="5"><span class="opacitymedium">' . $langs->trans('RecupelSwitchNoProduct') . '</span></td></tr>';
}
print '</table></div>';

// 3. Open proposals and orders dated before the switch that contain those products.
print '<br>' . load_fiche_titre($langs->trans('RecupelSwitchDocuments'), '', '');
print '<div class="opacitymedium">' . $langs->trans('RecupelSwitchDocumentsHelp') . '</div>';
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><th>' . $langs->trans('Ref') . '</th><th>' . $langs->trans('ThirdParty') . '</th><th>' . $langs->trans('Date') . '</th><th>' . $langs->trans('Status') . '</th><th class="right">' . $langs->trans('RecupelFeeAtDocDate') . '</th><th class="right">' . $langs->trans('RecupelFeeAtSwitch') . '</th></tr>';
$nbdoc = 0;
if (!empty($productIds)) {
	$in = implode(',', $productIds);
	$queries = array(
		'propal' => "SELECT DISTINCT p.rowid, p.ref, p.fk_statut as status, p.datep as d, s.nom FROM " . MAIN_DB_PREFIX . "propal as p INNER JOIN " . MAIN_DB_PREFIX . "propaldet as l ON l.fk_propal = p.rowid INNER JOIN " . MAIN_DB_PREFIX . "societe as s ON s.rowid = p.fk_soc WHERE p.entity IN (" . getEntity('propal') . ") AND p.fk_statut IN (0,1) AND l.fk_product IN (" . $in . ") AND p.datep < '" . $db->escape($newDate) . "'",
		'commande' => "SELECT DISTINCT c.rowid, c.ref, c.fk_statut as status, c.date_commande as d, s.nom FROM " . MAIN_DB_PREFIX . "commande as c INNER JOIN " . MAIN_DB_PREFIX . "commandedet as l ON l.fk_commande = c.rowid INNER JOIN " . MAIN_DB_PREFIX . "societe as s ON s.rowid = c.fk_soc WHERE c.entity IN (" . getEntity('commande') . ") AND c.fk_statut IN (0,1,2) AND c.facture = 0 AND l.fk_product IN (" . $in . ") AND c.date_commande < '" . $db->escape($newDate) . "'",
	);
	$urls = array('propal' => '/comm/propal/card.php?id=', 'commande' => '/commande/card.php?id=');
	foreach ($queries as $el => $sql) {
		// Only documents the user may read; an external user only sees his company.
		if (($el === 'propal' && !$user->hasRight('propal', 'lire')) || ($el === 'commande' && !$user->hasRight('commande', 'lire'))) {
			continue;
		}
		if (!empty($user->socid)) {
			$sql .= " AND s.rowid = " . (int) $user->socid;
		}
		$resql = $db->query($sql);
		while ($resql && ($o = $db->fetch_object($resql))) {
			$nbdoc++;
			$old = 0.0;
			$new = 0.0;
			$resl = $db->query("SELECT fk_product, qty FROM " . MAIN_DB_PREFIX . ($el === 'propal' ? 'propaldet WHERE fk_propal' : 'commandedet WHERE fk_commande') . " = " . (int) $o->rowid . " AND fk_product IN (" . $in . ")");
			while ($resl && ($l = $db->fetch_object($resl))) {
				$old += $feeOld[(int) $l->fk_product] * (float) $l->qty;
				$new += $feeNew[(int) $l->fk_product] * (float) $l->qty;
			}
			print '<tr class="oddeven"><td><a href="' . DOL_URL_ROOT . $urls[$el] . (int) $o->rowid . '">' . img_picto('', $el === 'propal' ? 'propal' : 'order', 'class="pictofixedwidth"') . dol_escape_htmltag($o->ref) . '</a></td>';
			print '<td>' . dol_escape_htmltag($o->nom) . '</td><td>' . dol_print_date($db->jdate($o->d), 'day') . '</td><td>' . ((int) $o->status === 0 ? $langs->trans('Draft') : $langs->trans('Validated')) . '</td>';
			print '<td class="right">' . price($old, 0, $langs, 1, -1, 2, $conf->currency) . '</td><td class="right"><strong>' . price($new, 0, $langs, 1, -1, 2, $conf->currency) . '</strong></td></tr>';
		}
	}
}
if ($nbdoc === 0) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">' . $langs->trans('NoRecordFound') . '</span></td></tr>';
}
print '</table></div>';

print dol_get_fiche_end();
llxFooter();
$db->close();
