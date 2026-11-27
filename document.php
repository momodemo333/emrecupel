<?php
/* Copyright (C) 2026 E-dem SRL <support@e-dem.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    document.php
 * \ingroup emrecupel
 * \brief   Recupel tab of a proposal, order or invoice: analysis of the fees,
 *          "Recalculate" on drafts, declaration rows of validated invoices.
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
dol_include_once('/emrecupel/class/actions_emrecupel.class.php');
dol_include_once('/emrecupel/lib/emrecupel.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('propal', 'orders', 'bills', 'companies', 'emrecupel@emrecupel'));

$element = GETPOST('element', 'aZ09');
$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');

if (!isModEnabled('emrecupel') || !$user->hasRight('emrecupel', 'read') || !isset(EmrecupelDocumentSync::$types[$element])) {
	accessforbidden();
}
$def = EmrecupelDocumentSync::$types[$element];
require_once DOL_DOCUMENT_ROOT . $def['file'];
$class = $def['class'];
$object = new $class($db);
if ($object->fetch($id) <= 0) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}
$object->fetch_thirdparty();
$restrict = array('propal' => array('propal', 'propal'), 'commande' => array('commande', 'commande'), 'facture' => array('facture', 'facture'));
restrictedArea($user, $restrict[$element][0], $object->id, $restrict[$element][1]);

$sync = new EmrecupelDocumentSync($db);
$isDraft = ((int) $object->status === 0);
// Recalculating changes the lines of the document: the user must be allowed
// to edit it, not only to classify products.
$docRights = array('propal' => array('propal', 'creer'), 'commande' => array('commande', 'creer'), 'facture' => array('facture', 'creer'));
$canRecalculate = $isDraft && $user->hasRight('emrecupel', 'write') && $user->hasRight($docRights[$element][0], $docRights[$element][1]);

if ($action === 'recalculate' && $_SERVER['REQUEST_METHOD'] === 'POST' && $canRecalculate) {
	$n = $sync->reconcile($element, $object->id, $user, array('reset_optout' => true));
	if ($n < 0) {
		setEventMessages($langs->trans('RecupelRecalculateError', $sync->error), null, 'errors');
	} else {
		setEventMessages($langs->trans('RecupelRecalculated', $n), null, 'mesgs');
	}
	header('Location: ' . $_SERVER['PHP_SELF'] . '?element=' . $element . '&id=' . $object->id);
	exit;
}

// ---------------------------------------------------------------------------
// View
// ---------------------------------------------------------------------------
llxHeader('', $object->ref . ' - Recupel', '', '', 0, 0, '', '', '', 'mod-emrecupel page-document');

if ($element === 'propal') {
	require_once DOL_DOCUMENT_ROOT . '/core/lib/propal.lib.php';
	$head = propal_prepare_head($object);
	$titletab = $langs->trans('Proposal');
	$picto = 'propal';
	$linkback = '<a href="' . DOL_URL_ROOT . '/comm/propal/list.php?restore_lastsearch_values=1">' . $langs->trans('BackToList') . '</a>';
} elseif ($element === 'commande') {
	require_once DOL_DOCUMENT_ROOT . '/core/lib/order.lib.php';
	$head = commande_prepare_head($object);
	$titletab = $langs->trans('CustomerOrder');
	$picto = 'order';
	$linkback = '<a href="' . DOL_URL_ROOT . '/commande/list.php?restore_lastsearch_values=1">' . $langs->trans('BackToList') . '</a>';
} else {
	require_once DOL_DOCUMENT_ROOT . '/core/lib/invoice.lib.php';
	$head = facture_prepare_head($object);
	$titletab = $langs->trans('InvoiceCustomer');
	$picto = 'bill';
	$linkback = '<a href="' . DOL_URL_ROOT . '/compta/facture/list.php?restore_lastsearch_values=1">' . $langs->trans('BackToList') . '</a>';
}
print dol_get_fiche_head($head, 'emrecupel', $titletab, -1, $picto);
$morehtmlref = '<div class="refidno">' . $object->thirdparty->getNomUrl(1, 'customer') . '</div>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);
print '<div class="fichecenter"><div class="underbanner clearboth"></div>';

$analysis = $sync->analyse($element, $object->id);
$doc = $analysis['doc'];
$cust = $doc['customer'];
$roleLabels = array('enduser' => 'RecupelRoleEnduser', 'reseller' => 'RecupelRoleReseller', 'exempt' => 'RecupelRoleExempt');

print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">' . $langs->trans('RecupelRole') . '</td><td>' . $langs->trans($roleLabels[$cust['role']]) . ($cust['mode'] !== '' ? ' — ' . $langs->trans('RecupelDisplayOverride') . ' : ' . emrecupel_mode_label($cust['mode']) : '') . '</td></tr>';
print '<tr><td>' . $langs->trans('RecupelDeliveryCountry') . '</td><td>' . ($cust['country'] !== '' ? dol_escape_htmltag($cust['country']) : $langs->trans('Unknown')) . ($doc['export'] ? ' — <strong>' . $langs->trans('RecupelExportNoFee') . '</strong>' : '') . '</td></tr>';
print '<tr><td>' . $langs->trans('RecupelScaleDate') . '</td><td>' . dol_print_date(dol_stringtotime($doc['date']), 'day') . '</td></tr>';
print '</table>';

if (!$isDraft) {
	print '<br>' . info_admin($langs->trans('RecupelNotDraft'), 0, 0, 'info');
}

print '<br>';
print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>' . $langs->trans('Product') . '</th>';
print '<th class="right">' . $langs->trans('Qty') . '</th>';
print '<th>' . $langs->trans('RecupelCode') . '</th>';
print '<th>' . $langs->trans('RecupelUse') . '</th>';
print '<th class="right">' . $langs->trans('RecupelUnitRateTTC') . '</th>';
print '<th class="right">' . $langs->trans('RecupelAmountTTC') . '</th>';
print '<th>' . $langs->trans('RecupelDecision') . '</th>';
print '<th>' . $langs->trans('RecupelDisplay') . '</th>';
print '</tr>';
$totLine = 0.0;
$totIncl = 0.0;
$nb = 0;
$feeLinesByKey = array();
foreach ($sync->readLines($element, $object->id) as $l) {
	if ($l['cat'] !== '') {
		$feeLinesByKey[$l['link'] . '|' . $l['cat']] = $l;
	}
}
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
$prodstatic = new Product($db);
foreach ($analysis['lines'] as $l) {
	$prodstatic->fetch($l['fk_product']);
	if (empty($l['fees'])) {
		print '<tr class="oddeven"><td>' . $prodstatic->getNomUrl(1) . '</td><td class="right">' . price2num($l['qty']) . '</td><td colspan="6"><span class="opacitymedium">' . ($l['status'] === 'exempt' ? $langs->trans('RecupelStatusExempt') : $langs->trans('RecupelNoCode')) . '</span></td></tr>';
		continue;
	}
	$first = true;
	foreach ($l['fees'] as $fee) {
		$nb++;
		$amount = $fee['included_ttc'] !== null ? round($fee['included_ttc'] * abs($l['qty']), 2) : null;
		$applied = $fee['decision'] === EmrecupelFeePolicy::APPLY;
		$present = isset($feeLinesByKey[ltrim($l['link'], '-') . '|' . $fee['code']]);
		if ($applied && $amount !== null) {
			if ($fee['mode'] === EmrecupelFeePolicy::MODE_LINE) {
				$totLine += $amount;
			} else {
				$totIncl += $amount;
			}
		}
		print '<tr class="oddeven">';
		print '<td>' . ($first ? $prodstatic->getNomUrl(1) . ' <span class="opacitymedium small">' . dol_escape_htmltag(emrecupel_origin_label($l['origin'])) . '</span>' : '') . '</td>';
		print '<td class="right">' . ($first ? price2num($l['qty']) : '') . '</td>';
		print '<td class="nowraponall"><strong>' . dol_escape_htmltag($fee['code']) . '</strong>' . ($fee['units'] != 1 ? ' × ' . price2num($fee['units']) : '') . ' <span class="opacitymedium small">' . dol_escape_htmltag(dol_trunc($fee['label'], 50)) . '</span></td>';
		print '<td>' . emrecupel_use_label($fee['use_type']) . ' <span class="opacitymedium small">' . emrecupel_feetype_label($fee['fee_type']) . '</span></td>';
		print '<td class="right nowraponall">' . ($fee['rate_ttc'] !== null ? emrecupel_amount($fee['rate_ttc']) : ($fee['rate_ht'] !== null ? emrecupel_amount($fee['rate_ht']) . ' HT' : '')) . '</td>';
		print '<td class="right nowraponall">' . ($amount !== null ? price($amount, 0, $langs, 1, -1, 2, $conf->currency) : '') . '</td>';
		print '<td>' . ($applied ? img_picto('', 'tick', 'class="pictofixedwidth"') : '') . dol_escape_htmltag(emrecupel_decision_label($fee['decision'])) . '</td>';
		$modeTxt = $applied ? emrecupel_mode_label($fee['mode']) : '';
		if ($applied && $fee['mode'] === EmrecupelFeePolicy::MODE_LINE) {
			$modeTxt .= $present ? ' ' . img_picto($langs->trans('RecupelLinePresent'), 'tick') : ' ' . img_warning($langs->trans('RecupelLineMissing'));
		}
		print '<td>' . $modeTxt . '</td>';
		print '</tr>';
		$first = false;
	}
}
if (empty($analysis['lines'])) {
	print '<tr class="oddeven"><td colspan="8"><span class="opacitymedium">' . $langs->trans('RecupelNothingOnDocument') . '</span></td></tr>';
}
print '<tr class="liste_total"><td colspan="5">' . $langs->trans('RecupelTotalAsLines') . '</td><td class="right">' . price($totLine, 0, $langs, 1, -1, 2, $conf->currency) . '</td><td colspan="2"></td></tr>';
print '<tr class="liste_total"><td colspan="5">' . $langs->trans('RecupelTotalIncluded') . '</td><td class="right">' . price($totIncl, 0, $langs, 1, -1, 2, $conf->currency) . '</td><td colspan="2"></td></tr>';
print '</table></div>';

// Ledger of a validated invoice.
if ($element === 'facture' && !$isDraft && $user->hasRight('emrecupel', 'declare')) {
	print '<br>' . load_fiche_titre($langs->trans('RecupelLedgerOfInvoice'), '', '');
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><th>' . $langs->trans('RecupelCode') . '</th><th class="right">' . $langs->trans('RecupelDeclaredUnits') . '</th><th class="right">' . $langs->trans('RecupelWeight') . '</th><th>' . $langs->trans('RecupelOrigin') . '</th><th>' . $langs->trans('RecupelBucket') . '</th><th>' . $langs->trans('RecupelDecision') . '</th></tr>';
	$resql = $db->query("SELECT code, units, weight_kg, origin, bucket, decision FROM " . MAIN_DB_PREFIX . "emrecupel_ledger WHERE fk_facture = " . (int) $object->id . " ORDER BY rowid");
	$n = 0;
	while ($resql && ($o = $db->fetch_object($resql))) {
		$n++;
		print '<tr class="oddeven"><td>' . dol_escape_htmltag($o->code) . '</td><td class="right">' . price2num($o->units) . '</td><td class="right">' . ($o->weight_kg !== null ? price2num($o->weight_kg) . ' kg' : '') . '</td><td>' . dol_escape_htmltag(emrecupel_origin_label($o->origin)) . '</td><td>' . dol_escape_htmltag(emrecupel_bucket_label($o->bucket)) . '</td><td>' . dol_escape_htmltag(emrecupel_decision_label($o->decision)) . '</td></tr>';
	}
	if ($n === 0) {
		print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">' . $langs->trans('RecupelLedgerEmpty') . '</span></td></tr>';
	}
	print '</table>';
}

print '</div>';
print dol_get_fiche_end();

if ($canRecalculate) {
	print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '?element=' . $element . '&id=' . $object->id . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="recalculate">';
	print '<div class="tabsAction"><input type="submit" class="butAction" value="' . dol_escape_htmltag($langs->trans('RecupelRecalculate')) . '"></div>';
	print '</form>';
	print '<div class="opacitymedium small">' . $langs->trans('RecupelRecalculateHelp') . '</div>';
}

llxFooter();
$db->close();
