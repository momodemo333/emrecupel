<?php
/* Copyright (C) 2026 E-dem SRL <support@e-dem.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    product.php
 * \ingroup emrecupel
 * \brief   Recupel tab of a product: status, origin, weight and Recupel codes.
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
require_once DOL_DOCUMENT_ROOT . '/core/lib/product.lib.php';
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

$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');

if (!isModEnabled('emrecupel') || !$user->hasRight('emrecupel', 'read')) {
	accessforbidden();
}
$object = new Product($db);
if ($object->fetch($id, $ref) <= 0) {
	accessforbidden($langs->trans('ErrorRecordNotFound'));
}
$result = restrictedArea($user, 'produit|service', $object->id, 'product&product', '', '', 'rowid');
$canWrite = $user->hasRight('emrecupel', 'write');

$catalog = new EmrecupelCatalog($db);
$ep = new EmrecupelProduct($db);

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
if ($canWrite && in_array($action, array('savestatus', 'addcode', 'delcode', 'unclassify'), true)) {
	$cur = $ep->get($object->id, false);
	$res = 0;
	if ($action === 'savestatus') {
		$status = GETPOST('status', 'aZ09');
		if ($status === 'none') {
			$res = $ep->unclassify($object->id);
		} else {
			$weight = GETPOST('weight_kg', 'alphanohtml');
			$weight = ($weight === '' ? null : (float) price2num($weight));
			$res = $ep->save($object->id, $status === 'exempt' ? EmrecupelProduct::STATUS_EXEMPT : EmrecupelProduct::STATUS_SUBJECT, GETPOST('origin', 'aZ09'), $cur['items'], $user, $weight);
		}
	} elseif ($action === 'addcode') {
		$code = GETPOST('code', 'alphanohtml');
		$units = (float) price2num(GETPOST('units', 'alphanohtml'));
		if ($code === '') {
			setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('RecupelCode')), null, 'errors');
			$res = 0;
		} else {
			$res = $ep->assign($object->id, $code, $units > 0 ? $units : 1, null, $user);
		}
	} elseif ($action === 'delcode') {
		$code = GETPOST('code', 'alphanohtml');
		$items = array();
		foreach ($cur['items'] as $it) {
			if ($it['code'] !== $code) {
				$items[] = $it;
			}
		}
		// Last code removed: back to "to classify" rather than "subject" without code.
		$res = empty($items) ? $ep->unclassify($object->id) : $ep->save($object->id, EmrecupelProduct::STATUS_SUBJECT, $cur['origin'], $items, $user);
	}
	if ($res < 0) {
		setEventMessages($langs->trans('Error') . ' ' . $langs->trans($ep->error), null, 'errors');
	} elseif ($res > 0) {
		setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $object->id);
		exit;
	}
}

// ---------------------------------------------------------------------------
// View
// ---------------------------------------------------------------------------
$title = $langs->trans('ProductServiceCard') . ' - Recupel';
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-emrecupel page-product');

$head = product_prepare_head($object);
print dol_get_fiche_head($head, 'emrecupel', $langs->trans('Product'), -1, $object->picto);
$linkback = '<a href="' . DOL_URL_ROOT . '/product/list.php?restore_lastsearch_values=1">' . $langs->trans('BackToList') . '</a>';
dol_banner_tab($object, 'ref', $linkback, ($user->socid ? 0 : 1), 'ref');
print '<div class="fichecenter"><div class="underbanner clearboth"></div>';

$data = $ep->get($object->id);
$own = $ep->get($object->id, false);
$today = dol_print_date(dol_now(), '%Y-%m-%d');
$next = $catalog->getNextScaleDate($today);
$state = !$data['classified'] ? 'none' : ($data['status'] === EmrecupelProduct::STATUS_EXEMPT ? 'exempt' : 'subject');

if ($data['inherited']) {
	print info_admin($langs->trans('RecupelInheritedFromParent'), 0, 0, 'info');
}
if ($object->type == Product::TYPE_SERVICE) {
	print info_admin($langs->trans('RecupelServiceNotice'), 0, 0, 'info');
}

// Status, origin, weight.
print load_fiche_titre($langs->trans('RecupelStatus'), '', '');
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="savestatus">';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">' . $langs->trans('RecupelStatus') . '</td><td>';
foreach (array('none' => 'RecupelStatusNone', 'subject' => 'RecupelStatusSubject', 'exempt' => 'RecupelStatusExempt') as $k => $lbl) {
	print '<label class="marginrightonly"><input type="radio" name="status" value="' . $k . '"' . ($state === $k ? ' checked' : '') . ($canWrite ? '' : ' disabled') . '> ' . $langs->trans($lbl) . '</label> ';
}
print '<br><span class="opacitymedium small">' . $langs->trans('RecupelStatusHelp') . '</span>';
print '</td></tr>';
print '<tr><td>' . $form->textwithpicto($langs->trans('RecupelOrigin'), $langs->trans('RecupelOriginHelp')) . '</td><td>';
print $canWrite ? emrecupel_select_origin('origin', $own['origin']) : dol_escape_htmltag(emrecupel_origin_label($data['origin']));
print '</td></tr>';
$nativeWeight = ($object->weight !== '' && $object->weight !== null && (float) $object->weight > 0) ? $object->weight . ' ' . measuringUnitString(0, 'weight', $object->weight_units) : $langs->trans('None');
print '<tr><td>' . $form->textwithpicto($langs->trans('RecupelWeight'), $langs->trans('RecupelWeightHelp')) . '</td><td>';
if ($canWrite) {
	print '<input type="text" class="flat width75 right" name="weight_kg" value="' . ($own['weight_kg'] === null ? '' : dol_escape_htmltag(price2num($own['weight_kg']))) . '"> kg';
} else {
	print $own['weight_kg'] === null ? '' : dol_escape_htmltag(price2num($own['weight_kg'])) . ' kg';
}
print ' <span class="opacitymedium">(' . $langs->trans('RecupelWeightProduct') . ' : ' . dol_escape_htmltag($nativeWeight) . ')</span>';
print '</td></tr>';
print '</table>';
if ($canWrite) {
	print '<div class="center"><input type="submit" class="button button-save" value="' . $langs->trans('Save') . '"></div>';
}
print '</form>';

// Codes.
print '<br>';
print load_fiche_titre($langs->trans('RecupelCodes'), emrecupel_search_link(), '');
if ($state === 'exempt') {
	print '<div class="opacitymedium">' . $langs->trans('RecupelExemptNoCode') . '</div>';
} else {
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre">';
	print '<th>' . $langs->trans('RecupelCode') . '</th>';
	print '<th>' . $langs->trans('Label') . '</th>';
	print '<th>' . $langs->trans('RecupelUse') . '</th>';
	print '<th class="right">' . $form->textwithpicto($langs->trans('RecupelUnits'), $langs->trans('RecupelUnitsHelp')) . '</th>';
	print '<th class="right">' . $langs->trans('RecupelRateAt', dol_print_date(dol_stringtotime($today), 'day')) . '</th>';
	if ($next !== '') {
		print '<th class="right">' . $langs->trans('RecupelRateAt', dol_print_date(dol_stringtotime($next), 'day')) . '</th>';
	}
	print '<th></th>';
	print '</tr>';
	if (empty($data['items'])) {
		print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">' . $langs->trans('RecupelNoCode') . '</span></td></tr>';
	}
	foreach ($data['items'] as $it) {
		$cat = $catalog->getCategory($it['code']);
		$r1 = $catalog->getRateInfo($it['code'], $today);
		print '<tr class="oddeven">';
		print '<td class="nowraponall"><strong>' . dol_escape_htmltag($it['code']) . '</strong></td>';
		print '<td>' . dol_escape_htmltag($catalog->getLabel($it['code'], $langs->defaultlang)) . '</td>';
		print '<td>' . ($cat ? emrecupel_use_label($cat['use_type']) : '') . '</td>';
		print '<td class="right">' . price2num($it['units']) . '</td>';
		print '<td class="right nowraponall">' . ($r1 ? emrecupel_amount($r1['ttc'] !== null ? $r1['ttc'] : $r1['ht']) . ' <span class="opacitymedium small">' . ($r1['ttc'] !== null ? $langs->trans('TTC') : $langs->trans('HT')) . ' · ' . emrecupel_feetype_label($r1['fee_type']) . '</span>' : '') . '</td>';
		if ($next !== '') {
			$r2 = $catalog->getRateInfo($it['code'], $next);
			$changed = ($r1 && $r2 && abs($r1['ht'] - $r2['ht']) > 0.000001);
			print '<td class="right nowraponall' . ($changed ? ' warning' : '') . '">' . ($r2 ? emrecupel_amount($r2['ttc'] !== null ? $r2['ttc'] : $r2['ht']) . ' <span class="opacitymedium small">' . ($r2['ttc'] !== null ? $langs->trans('TTC') : $langs->trans('HT')) . ' · ' . emrecupel_feetype_label($r2['fee_type']) . '</span>' : '') . '</td>';
		}
		print '<td class="right">';
		if ($canWrite && !$data['inherited']) {
			print '<a class="reposition" href="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '&action=delcode&code=' . urlencode($it['code']) . '&token=' . newToken() . '">' . img_delete() . '</a>';
		}
		print '</td>';
		print '</tr>';
	}
	print '</table></div>';

	if ($canWrite) {
		print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '?id=' . $object->id . '">';
		print '<input type="hidden" name="token" value="' . newToken() . '">';
		print '<input type="hidden" name="action" value="addcode">';
		print '<div class="tagtable paddingtopbottomonly">';
		print $langs->trans('RecupelAddCode') . ' ' . emrecupel_select_code($catalog, 'code', '', $langs->defaultlang);
		print ' &nbsp; ' . $langs->trans('RecupelUnits') . ' <input type="text" class="flat width50 right" name="units" value="1">';
		print ' <input type="submit" class="button smallpaddingimp" value="' . $langs->trans('Add') . '">';
		print '</div>';
		print '<div class="opacitymedium small">' . $langs->trans('RecupelKitHelp') . '</div>';
		print '</form>';
	}
}

print '</div>';
print dol_get_fiche_end();

llxFooter();
$db->close();
