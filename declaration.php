<?php
/* Copyright (C) 2026 E-dem SRL <support@e-dem.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    declaration.php
 * \ingroup emrecupel
 * \brief   Recupel declaration support: appliances brought to market and
 *          "Returnable" per code, household and professional, for a period,
 *          from the validated invoices (ledger). Control statement for a
 *          reseller who does not declare.
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

require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
dol_include_once('/emrecupel/class/emrecupeldocumentsync.class.php');
dol_include_once('/emrecupel/lib/emrecupel.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('bills', 'companies', 'emrecupel@emrecupel'));

if (!isModEnabled('emrecupel') || !$user->hasRight('emrecupel', 'declare')) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$ptype = GETPOST('ptype', 'aZ09') ?: 'quarter';
$year = GETPOSTINT('year') ?: (int) dol_print_date(dol_now(), '%Y');
$curMonth = (int) dol_print_date(dol_now(), '%m');
$index = GETPOSTISSET('pindex') ? GETPOSTINT('pindex') : ($ptype === 'month' ? $curMonth : (int) ceil($curMonth / 3));
list($from, $to) = EmrecupelFeePolicy::periodBounds($ptype, $year, $index);
$detailCode = GETPOST('detail', 'alphanohtml');
$detailBucket = GETPOST('bucket', 'aZ09');

$profile = EmrecupelDocumentSync::profile();
$declared = EmrecupelFeePolicy::declaredBuckets($profile);
$catalog = new EmrecupelCatalog($db);
$sync = new EmrecupelDocumentSync($db);

if ($action === 'rebuild' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($user->hasRight('emrecupel', 'setup') || $user->hasRight('emrecupel', 'write'))) {
	$r = $sync->rebuildLedger($from, $to);
	setEventMessages($langs->trans('RecupelLedgerRebuilt', $r['invoices'], $r['rows']), null, $r['errors'] ? 'warnings' : 'mesgs');
	header('Location: ' . $_SERVER['PHP_SELF'] . '?ptype=' . $ptype . '&year=' . $year . '&pindex=' . $index);
	exit;
}

/**
 * SQL filter of the invoices that count: validated, paid, or abandoned for
 * another reason than a replacement (the replacement invoice counts instead).
 */
$invoiceFilter = " AND f.entity IN (" . getEntity('invoice') . ") AND (f.fk_statut IN (1,2) OR (f.fk_statut = 3 AND (f.close_code IS NULL OR f.close_code <> 'replaced')))";
$base = " FROM " . MAIN_DB_PREFIX . "emrecupel_ledger as l INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = l.fk_facture";
$base .= " WHERE l.date_doc >= '" . $db->escape($from) . "' AND l.date_doc <= '" . $db->escape($to) . "'" . $invoiceFilter;

// Aggregation per code and bucket.
$agg = array();
$sql = "SELECT l.code, l.use_type, l.fee_type, l.bucket, SUM(l.units) as units, SUM(l.weight_kg) as weight, SUM(l.amount_ht) as amount, COUNT(l.weight_kg) as nbw, COUNT(*) as nb" . $base;
$sql .= " GROUP BY l.code, l.use_type, l.fee_type, l.bucket";
$resql = $db->query($sql);
while ($resql && ($o = $db->fetch_object($resql))) {
	// One row per code and fee type: 1.50 changes from administrative to
	// all-in in 2027, a period across the switch shows both.
	$k = $o->code . '|' . $o->fee_type;
	if (!isset($agg[$k])) {
		$agg[$k] = array('code' => $o->code, 'use_type' => $o->use_type, 'fee_type' => $o->fee_type, 'market' => 0.0, 'market_w' => 0.0, 'market_w_missing' => false, 'returnable' => 0.0, 'control' => 0.0, 'none' => 0.0, 'amount_market' => 0.0, 'amount_returnable' => 0.0, 'amount_control' => 0.0);
	}
	$b = $o->bucket;
	$agg[$k][$b] = ($agg[$k][$b] ?? 0) + (float) $o->units;
	if ($b === 'market') {
		$agg[$k]['market_w'] += (float) $o->weight;
		if ($o->use_type === 'professional' && (int) $o->nbw < (int) $o->nb) {
			$agg[$k]['market_w_missing'] = true;
		}
	}
	// Only all-in fees can be refunded by Recupel: the administrative fee of a
	// professional appliance is never given back, even when it is exported.
	if (in_array($b, array('market', 'control'), true) || ($b === 'returnable' && $o->fee_type !== 'admin')) {
		$agg[$k]['amount_' . $b] += (float) $o->amount;
	}
}
uksort($agg, function ($a, $b) {
	return EmrecupelCatalog::compareCodes(explode('|', $a)[0], explode('|', $b)[0]);
});

// Yearly administrative fees (cap of 10 000 EUR per member).
$adminYear = 0.0;
$resql = $db->query("SELECT SUM(l.amount_ht) as a FROM " . MAIN_DB_PREFIX . "emrecupel_ledger as l INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = l.fk_facture WHERE l.bucket = 'market' AND l.fee_type = 'admin' AND l.date_doc >= '" . $year . "-01-01' AND l.date_doc <= '" . $year . "-12-31'" . $invoiceFilter);
if ($resql) {
	$adminYear = (float) $db->fetch_object($resql)->a;
}

// Products sold in the period but not classified (hypothesis to show).
$nbUnclassified = 0;
$sqlu = "SELECT COUNT(DISTINCT d.fk_product) as n FROM " . MAIN_DB_PREFIX . "facturedet as d INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = d.fk_facture";
$sqlu .= " INNER JOIN " . MAIN_DB_PREFIX . "product as p ON p.rowid = d.fk_product AND p.fk_product_type = 0";
$sqlu .= " LEFT JOIN " . MAIN_DB_PREFIX . "emrecupel_product as ep ON ep.fk_product = d.fk_product";
$sqlu .= " WHERE ep.rowid IS NULL AND f.datef >= '" . $db->escape($from) . "' AND f.datef <= '" . $db->escape($to) . "'" . $invoiceFilter;
$resu = $db->query($sqlu);
if ($resu) {
	$nbUnclassified = (int) $db->fetch_object($resu)->n;
}

// ---------------------------------------------------------------------------
// CSV export
// ---------------------------------------------------------------------------
if ($action === 'exportcsv') {
	$out = "period_start;period_end;profile;code;label;use;fee_type;brought_to_market;weight_kg;returnable;sold_be_fee_passed_on;estimated_due_ht;estimated_refund_ht\n";
	foreach ($agg as $a) {
		$code = $a['code'];
		$out .= $from . ';' . $to . ';' . $profile . ';' . $code . ';' . EmrecupelProduct::csvField($catalog->getLabel($code, $langs->defaultlang)) . ';' . $a['use_type'] . ';' . $a['fee_type'];
		$out .= ';' . price2num($a['market']) . ';' . ($a['use_type'] === 'professional' ? price2num($a['market_w'], 'MS') : '') . ';' . price2num($a['returnable']) . ';' . price2num($a['control']);
		$out .= ';' . price2num($a['amount_market'], 'MT') . ';' . price2num($a['amount_returnable'], 'MT') . "\n";
	}
	header('Content-Type: text/csv; charset=UTF-8');
	header('Content-Disposition: attachment; filename="recupel-declaration-' . $from . '-' . $to . '.csv"');
	print "\xEF\xBB\xBF" . $out;
	exit;
}

// ---------------------------------------------------------------------------
// View
// ---------------------------------------------------------------------------
llxHeader('', $langs->trans('RecupelDeclaration'), '', '', 0, 0, '', '', '', 'mod-emrecupel page-declaration');
print load_fiche_titre($langs->trans('RecupelDeclaration'), '', 'fa-recycle');

print '<form method="GET" action="' . $_SERVER['PHP_SELF'] . '">';
print '<select class="flat" name="ptype" onchange="this.form.submit()">';
foreach (array('month' => 'Month', 'quarter' => 'RecupelQuarter', 'year' => 'Year') as $k => $lbl) {
	print '<option value="' . $k . '"' . ($ptype === $k ? ' selected' : '') . '>' . $langs->trans($lbl) . '</option>';
}
print '</select> ';
if ($ptype === 'month') {
	print '<select class="flat" name="pindex">';
	for ($m = 1; $m <= 12; $m++) {
		print '<option value="' . $m . '"' . ($index === $m ? ' selected' : '') . '>' . dol_escape_htmltag($langs->trans('Month' . sprintf('%02d', $m))) . '</option>';
	}
	print '</select> ';
} elseif ($ptype === 'quarter') {
	print '<select class="flat" name="pindex">';
	for ($q = 1; $q <= 4; $q++) {
		print '<option value="' . $q . '"' . ($index === $q ? ' selected' : '') . '>T' . $q . '</option>';
	}
	print '</select> ';
}
print '<input type="number" class="flat width75" name="year" value="' . $year . '"> ';
print '<input type="submit" class="button small" value="' . $langs->trans('Show') . '">';
print '</form><br>';

$profileLabels = array('reseller' => 'RecupelProfileReseller', 'exporter' => 'RecupelProfileExporter', 'producer' => 'RecupelProfileProducer');
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">' . $langs->trans('Period') . '</td><td>' . dol_print_date(dol_stringtotime($from), 'day') . ' – ' . dol_print_date(dol_stringtotime($to), 'day') . '</td></tr>';
print '<tr><td>' . $langs->trans('RecupelProfile') . '</td><td>' . $langs->trans($profileLabels[$profile]) . '</td></tr>';
if (!empty($declared)) {
	print '<tr><td>' . $langs->trans('RecupelDeadline') . '</td><td><strong>' . dol_print_date(dol_stringtotime(EmrecupelFeePolicy::declarationDeadline($to)), 'day') . '</strong> <span class="opacitymedium">(' . $langs->trans('RecupelDeadlineHelp') . ')</span></td></tr>';
}
print '</table><br>';

print emrecupel_disclaimer();
if ($nbUnclassified > 0) {
	print '<div class="warning">' . img_warning() . ' ' . $langs->trans('RecupelReportUnclassified', $nbUnclassified) . ' <a href="' . dol_buildpath('/emrecupel/classify.php', 1) . '?filter_status=toclassify&only_sold=1&only_goods=1">' . $langs->trans('RecupelShowThem') . '</a></div>';
}
if ($adminYear > EmrecupelFeePolicy::ADMIN_FEE_CAP) {
	print '<div class="warning">' . img_warning() . ' ' . $langs->trans('RecupelAdminCapReached', price($adminYear, 0, $langs, 1, -1, 2, 'EUR'), $year) . '</div>';
}
print '<div class="opacitymedium small">' . $langs->trans('RecupelReportHypotheses') . '</div><br>';

if ($detailCode !== '') {
	// Detail down to the invoices.
	print load_fiche_titre($langs->trans('RecupelDetailOf', $detailCode, emrecupel_bucket_label($detailBucket)), '<a href="' . $_SERVER['PHP_SELF'] . '?ptype=' . $ptype . '&year=' . $year . '&pindex=' . $index . '">' . $langs->trans('RecupelBack') . '</a>', '');
	$sql = "SELECT l.fk_facture, f.ref, f.type, l.date_doc, s.nom, s.rowid as socid, l.fk_product, p.ref as pref, l.units, l.weight_kg, l.country_code, l.amount_ht, l.decision" . $base;
	$sql = str_replace(" FROM " . MAIN_DB_PREFIX . "emrecupel_ledger as l INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = l.fk_facture", " FROM " . MAIN_DB_PREFIX . "emrecupel_ledger as l INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = l.fk_facture LEFT JOIN " . MAIN_DB_PREFIX . "societe as s ON s.rowid = f.fk_soc LEFT JOIN " . MAIN_DB_PREFIX . "product as p ON p.rowid = l.fk_product", $sql);
	$sql .= " AND l.code = '" . $db->escape($detailCode) . "'" . ($detailBucket !== '' ? " AND l.bucket = '" . $db->escape($detailBucket) . "'" : '') . " ORDER BY l.date_doc, f.ref";
	$resql = $db->query($sql);
	$fac = new Facture($db);
	print '<table class="noborder centpercent"><tr class="liste_titre"><th>' . $langs->trans('Invoice') . '</th><th>' . $langs->trans('Date') . '</th><th>' . $langs->trans('ThirdParty') . '</th><th>' . $langs->trans('Product') . '</th><th>' . $langs->trans('Country') . '</th><th class="right">' . $langs->trans('RecupelDeclaredUnits') . '</th><th class="right">' . $langs->trans('RecupelWeight') . '</th><th class="right">' . $langs->trans('RecupelEstimatedHT') . '</th></tr>';
	$tu = 0.0;
	while ($resql && ($o = $db->fetch_object($resql))) {
		$fac->id = (int) $o->fk_facture;
		$fac->ref = $o->ref;
		$fac->type = (int) $o->type;
		$tu += (float) $o->units;
		print '<tr class="oddeven"><td>' . $fac->getNomUrl(1) . '</td><td>' . dol_print_date($db->jdate($o->date_doc), 'day') . '</td><td>' . dol_escape_htmltag($o->nom) . '</td><td>' . dol_escape_htmltag($o->pref) . '</td><td>' . dol_escape_htmltag($o->country_code) . '</td>';
		print '<td class="right">' . price2num($o->units) . '</td><td class="right">' . ($o->weight_kg !== null ? price2num($o->weight_kg, 'MS') . ' kg' : '') . '</td><td class="right">' . ($o->amount_ht !== null ? price($o->amount_ht, 0, $langs, 1, 2, 4) : '') . '</td></tr>';
	}
	print '<tr class="liste_total"><td colspan="5">' . $langs->trans('Total') . '</td><td class="right">' . price2num($tu) . '</td><td colspan="2"></td></tr>';
	print '</table>';
} else {
	$link = function ($code, $bucket, $value) use ($ptype, $year, $index) {
		if (abs($value) < 0.0000001) {
			return '<span class="opacitymedium">0</span>';
		}
		return '<a href="' . $_SERVER['PHP_SELF'] . '?ptype=' . $ptype . '&year=' . $year . '&pindex=' . $index . '&detail=' . urlencode($code) . '&bucket=' . $bucket . '">' . price2num($value) . '</a>';
	};
	$totalDue = 0.0;
	$totalRefund = 0.0;
	foreach (array('household', 'professional') as $use) {
		$rows = array_filter($agg, function ($a) use ($use) {
			return $a['use_type'] === $use;
		});
		print load_fiche_titre($use === 'household' ? $langs->trans('RecupelBlockHousehold') : $langs->trans('RecupelBlockProfessional'), '', '');
		print '<div class="div-table-responsive"><table class="noborder centpercent">';
		print '<tr class="liste_titre"><th>' . $langs->trans('RecupelCode') . '</th><th>' . $langs->trans('Label') . '</th>';
		if (in_array('market', $declared, true)) {
			print '<th class="right">' . $langs->trans('RecupelBroughtToMarket') . '</th>';
			if ($use === 'professional') {
				print '<th class="right">' . $langs->trans('RecupelWeightKg') . '</th>';
			}
		}
		if (in_array('returnable', $declared, true)) {
			print '<th class="right">' . $langs->trans('RecupelReturnable') . '</th>';
		}
		print '<th class="right">' . $form->textwithpicto($langs->trans('RecupelSoldBePassedOn'), $langs->trans('RecupelSoldBePassedOnHelp')) . '</th>';
		if (!empty($declared)) {
			print '<th class="right">' . $langs->trans('RecupelEstimatedHT') . '</th>';
		} else {
			print '<th class="right">' . $langs->trans('RecupelFeesPassedOnHT') . '</th>';
		}
		print '</tr>';
		if (empty($rows)) {
			print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">' . $langs->trans('NoRecordFound') . '</span></td></tr>';
		}
		foreach ($rows as $a) {
			$code = $a['code'];
			$due = (in_array('market', $declared, true) ? $a['amount_market'] : 0) - (in_array('returnable', $declared, true) ? $a['amount_returnable'] : 0);
			$totalDue += in_array('market', $declared, true) ? $a['amount_market'] : 0;
			$totalRefund += in_array('returnable', $declared, true) ? $a['amount_returnable'] : 0;
			print '<tr class="oddeven"><td class="nowraponall"><strong>' . dol_escape_htmltag($code) . '</strong></td><td>' . dol_escape_htmltag(dol_trunc($catalog->getLabel($code, $langs->defaultlang), 70)) . '</td>';
			if (in_array('market', $declared, true)) {
				print '<td class="right">' . $link($code, 'market', $a['market']) . '</td>';
				if ($use === 'professional') {
					print '<td class="right">' . price2num($a['market_w'], 'MS') . ($a['market_w_missing'] ? ' ' . img_warning($langs->trans('RecupelWeightMissing')) : '') . '</td>';
				}
			}
			if (in_array('returnable', $declared, true)) {
				print '<td class="right">' . $link($code, 'returnable', $a['returnable']) . '</td>';
			}
			print '<td class="right">' . $link($code, 'control', $a['control']) . '</td>';
			print '<td class="right">' . price(!empty($declared) ? $due : $a['amount_control'], 0, $langs, 1, -1, 2, 'EUR') . '</td>';
			print '</tr>';
		}
		print '</table></div><br>';
	}
	if (!empty($declared)) {
		print '<table class="border centpercent tableforfield">';
		print '<tr><td class="titlefield">' . $langs->trans('RecupelTotalDue') . '</td><td class="right">' . price($totalDue, 0, $langs, 1, -1, 2, 'EUR') . '</td></tr>';
		print '<tr><td>' . $langs->trans('RecupelTotalRefund') . '</td><td class="right">' . price($totalRefund, 0, $langs, 1, -1, 2, 'EUR') . '</td></tr>';
		print '<tr class="liste_total"><td>' . $langs->trans('RecupelNetEstimate') . '</td><td class="right"><strong>' . price($totalDue - $totalRefund, 0, $langs, 1, -1, 2, 'EUR') . '</strong></td></tr>';
		print '</table>';
	} else {
		print '<div class="info">' . $langs->trans('RecupelResellerNoDeclaration') . '</div>';
	}

	print '<div class="tabsAction">';
	print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?ptype=' . $ptype . '&year=' . $year . '&pindex=' . $index . '&action=exportcsv&token=' . newToken() . '">' . $langs->trans('RecupelExportCsv') . '</a>';
	print '</div>';
	if ($user->hasRight('emrecupel', 'setup') || $user->hasRight('emrecupel', 'write')) {
		print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '?ptype=' . $ptype . '&year=' . $year . '&pindex=' . $index . '">';
		print '<input type="hidden" name="token" value="' . newToken() . '"><input type="hidden" name="action" value="rebuild">';
		print '<div class="tabsAction"><input type="submit" class="butAction" value="' . dol_escape_htmltag($langs->trans('RecupelRebuildLedger')) . '"></div></form>';
	}
	print '<div class="opacitymedium small">' . $langs->trans('RecupelRebuildLedgerHelp') . '</div>';
	print '<div class="opacitymedium small">' . $langs->trans('RecupelPortalHelp') . ' <a href="https://declarations.recupel.be" target="_blank" rel="noopener noreferrer">declarations.recupel.be</a></div>';
}

llxFooter();
$db->close();
