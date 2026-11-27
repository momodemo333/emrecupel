<?php
/* Copyright (C) 2026 E-dem SRL <support@e-dem.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    classify.php
 * \ingroup emrecupel
 * \brief   Products to classify: filterable list of products with their
 *          Recupel status and mass assignment.
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
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formother.class.php';
dol_include_once('/emrecupel/class/emrecupeldocumentsync.class.php');
dol_include_once('/emrecupel/class/emrecupelproduct.class.php');
dol_include_once('/emrecupel/lib/emrecupel.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 * @var Form $form
 */

$langs->loadLangs(array('products', 'categories', 'emrecupel@emrecupel'));

if (!isModEnabled('emrecupel') || !$user->hasRight('emrecupel', 'read') || !$user->hasRight('produit', 'lire')) {
	accessforbidden();
}
$canWrite = $user->hasRight('emrecupel', 'write');

$action = GETPOST('action', 'aZ09');
$massaction = GETPOST('massaction', 'aZ09');
$toselect = GETPOST('toselect', 'array:int');
$filterStatus = GETPOST('filter_status', 'aZ09') ?: 'toclassify';
$search = GETPOST('search', 'alphanohtml');
$searchCat = GETPOSTINT('search_cat');
$onlyGoods = GETPOSTISSET('filter_status') ? GETPOSTINT('only_goods') : 1;
$onlySold = GETPOSTINT('only_sold');
$limit = GETPOSTINT('limit') ?: (int) $conf->liste_limit;
$page = max(0, GETPOSTINT('page'));
$offset = $limit * $page;

$catalog = new EmrecupelCatalog($db);
$ep = new EmrecupelProduct($db);
$yearStart = dol_print_date(dol_now(), '%Y') . '-01-01';

// ---------------------------------------------------------------------------
// Mass action
// ---------------------------------------------------------------------------
if ($canWrite && $action === 'apply' && !empty($toselect)) {
	// Keep only products of the entities the user can see.
	$ids = array_map('intval', $toselect);
	$toselect = array();
	$resql = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "product WHERE rowid IN (" . implode(',', $ids) . ") AND entity IN (" . getEntity('product') . ")");
	while ($resql && ($o = $db->fetch_object($resql))) {
		$toselect[] = (int) $o->rowid;
	}
	$ok = 0;
	$ko = array();
	$code = GETPOST('mass_code', 'alphanohtml');
	$units = (float) price2num(GETPOST('mass_units', 'alphanohtml'));
	$units = $units > 0 ? $units : 1;
	$origin = GETPOST('mass_origin', 'aZ09');
	$replace = GETPOSTINT('mass_replace');
	foreach ($toselect as $pid) {
		$pid = (int) $pid;
		$cur = $ep->get($pid, false);
		if ($massaction === 'assign') {
			if ($code === '') {
				$ko[] = $langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('RecupelCode'));
				break;
			}
			$r = $ep->assign($pid, $code, $units, $origin !== 'keep' ? $origin : null, $user, (bool) $replace);
		} elseif ($massaction === 'exempt') {
			$r = $ep->save($pid, EmrecupelProduct::STATUS_EXEMPT, $cur['origin'], array(), $user);
		} elseif ($massaction === 'origin') {
			$r = $cur['classified'] ? $ep->save($pid, $cur['status'], $origin === 'keep' ? $cur['origin'] : $origin, $cur['items'], $user) : 0;
		} elseif ($massaction === 'unclassify') {
			$r = $ep->unclassify($pid);
		} else {
			$r = 0;
		}
		if ($r < 0) {
			$ko[] = $pid . ': ' . $langs->trans($ep->error);
		} elseif ($r > 0) {
			$ok++;
		}
	}
	if ($ok) {
		setEventMessages($langs->trans('RecupelMassDone', $ok), null, 'mesgs');
	}
	if ($ko) {
		setEventMessages($langs->trans('Error'), $ko, 'errors');
	}
	$params = array('filter_status' => $filterStatus, 'search' => $search, 'search_cat' => $searchCat, 'only_goods' => $onlyGoods, 'only_sold' => $onlySold, 'limit' => $limit, 'page' => $page);
	header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($params));
	exit;
}

// ---------------------------------------------------------------------------
// Query
// ---------------------------------------------------------------------------
$fromWhere = " FROM " . MAIN_DB_PREFIX . "product as p";
$fromWhere .= " LEFT JOIN " . MAIN_DB_PREFIX . "emrecupel_product as ep ON ep.fk_product = p.rowid";
if ($searchCat > 0) {
	$fromWhere .= " INNER JOIN " . MAIN_DB_PREFIX . "categorie_product as cp ON cp.fk_product = p.rowid AND cp.fk_categorie = " . (int) $searchCat;
}
$fromWhere .= " WHERE p.entity IN (" . getEntity('product') . ")";
$fromWhere .= " AND p.rowid <> " . getDolGlobalInt('EMRECUPEL_FEE_PRODUCT_ID');
$fromWhere .= " AND NOT EXISTS (SELECT 1 FROM " . MAIN_DB_PREFIX . "product_attribute_combination as pac WHERE pac.fk_product_child = p.rowid)";
if ($onlyGoods) {
	$fromWhere .= " AND p.fk_product_type = 0";
}
if ($filterStatus === 'toclassify') {
	$fromWhere .= " AND ep.rowid IS NULL";
} elseif ($filterStatus === 'subject') {
	$fromWhere .= " AND ep.status = 'subject'";
} elseif ($filterStatus === 'exempt') {
	$fromWhere .= " AND ep.status = 'exempt'";
}
if ($search !== '') {
	$fromWhere .= natural_search(array('p.ref', 'p.label', 'p.barcode'), $search);
}
$soldSql = "SELECT SUM(d.qty) FROM " . MAIN_DB_PREFIX . "facturedet as d INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = d.fk_facture";
$soldSql .= " WHERE d.fk_product = p.rowid AND f.fk_statut > 0 AND f.type IN (0,1) AND f.datef >= '" . $db->escape($yearStart) . "'";
if ($onlySold) {
	$fromWhere .= " AND EXISTS (" . str_replace('SELECT SUM(d.qty)', 'SELECT 1', $soldSql) . ")";
}

$nbtotal = 0;
$resql = $db->query("SELECT COUNT(*) as n" . $fromWhere);
if ($resql) {
	$nbtotal = (int) $db->fetch_object($resql)->n;
}
$sql = "SELECT p.rowid, p.ref, p.label, p.fk_product_type, p.tosell, ep.status, ep.origin, (" . $soldSql . ") as sold" . $fromWhere;
$sql .= " ORDER BY sold DESC, p.ref ASC" . $db->plimit($limit, $offset);
$resql = $db->query($sql);
$rows = array();
while ($resql && ($o = $db->fetch_object($resql))) {
	$rows[] = $o;
}

// Counter: products sold this year and not classified.
$nbSoldUnclassified = 0;
$sqlc = "SELECT COUNT(DISTINCT d.fk_product) as n FROM " . MAIN_DB_PREFIX . "facturedet as d";
$sqlc .= " INNER JOIN " . MAIN_DB_PREFIX . "facture as f ON f.rowid = d.fk_facture";
$sqlc .= " INNER JOIN " . MAIN_DB_PREFIX . "product as p ON p.rowid = d.fk_product AND p.fk_product_type = 0";
$sqlc .= " LEFT JOIN " . MAIN_DB_PREFIX . "emrecupel_product as ep ON ep.fk_product = d.fk_product";
$sqlc .= " WHERE f.entity IN (" . getEntity('invoice') . ") AND f.fk_statut > 0 AND f.datef >= '" . $db->escape($yearStart) . "' AND ep.rowid IS NULL";
$resc = $db->query($sqlc);
if ($resc) {
	$nbSoldUnclassified = (int) $db->fetch_object($resc)->n;
}

// ---------------------------------------------------------------------------
// View
// ---------------------------------------------------------------------------
llxHeader('', $langs->trans('RecupelProductsToClassify'), '', '', 0, 0, '', '', '', 'mod-emrecupel page-classify');
print load_fiche_titre($langs->trans('Recupel'), emrecupel_search_link(), 'fa-recycle');
print dol_get_fiche_head(emrecupel_prepare_head(), 'classify', '', -1, '');

if ($nbSoldUnclassified > 0) {
	print '<div class="warning">' . img_warning() . ' ' . $langs->trans('RecupelSoldUnclassified', $nbSoldUnclassified, dol_print_date(dol_now(), '%Y')) . ' <a href="' . $_SERVER['PHP_SELF'] . '?filter_status=toclassify&only_sold=1&only_goods=1">' . $langs->trans('RecupelShowThem') . '</a></div>';
}
print '<div class="opacitymedium">' . $langs->trans('RecupelClassifyHelp') . '</div><br>';

// Filters.
print '<form method="GET" action="' . $_SERVER['PHP_SELF'] . '">';
print '<div class="divsearchfield">' . $langs->trans('RecupelStatus') . ' <select class="flat" name="filter_status">';
foreach (array('toclassify' => 'RecupelStatusNone', 'subject' => 'RecupelStatusSubject', 'exempt' => 'RecupelStatusExempt', 'all' => 'All') as $k => $lbl) {
	print '<option value="' . $k . '"' . ($filterStatus === $k ? ' selected' : '') . '>' . $langs->trans($lbl) . '</option>';
}
print '</select></div>';
print '<div class="divsearchfield">' . $langs->trans('Search') . ' <input type="text" class="flat" name="search" value="' . dol_escape_htmltag($search) . '"></div>';
if (isModEnabled('category')) {
	$formother = new FormOther($db);
	print '<div class="divsearchfield">' . $langs->trans('Categories') . ' ' . $formother->select_categories('product', $searchCat, 'search_cat', 1) . '</div>';
}
print '<div class="divsearchfield"><label><input type="checkbox" name="only_goods" value="1"' . ($onlyGoods ? ' checked' : '') . '> ' . $langs->trans('RecupelOnlyGoods') . '</label></div>';
print '<div class="divsearchfield"><label><input type="checkbox" name="only_sold" value="1"' . ($onlySold ? ' checked' : '') . '> ' . $langs->trans('RecupelOnlySoldThisYear') . '</label></div>';
print '<div class="divsearchfield"><input type="submit" class="button small" value="' . $langs->trans('Search') . '"></div>';
print '</form><br>';

// List + mass action.
print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="apply">';
foreach (array('filter_status' => $filterStatus, 'search' => $search, 'search_cat' => $searchCat, 'only_goods' => $onlyGoods, 'only_sold' => $onlySold, 'limit' => $limit, 'page' => $page) as $k => $v) {
	print '<input type="hidden" name="' . $k . '" value="' . dol_escape_htmltag((string) $v) . '">';
}

$param = '&filter_status=' . urlencode($filterStatus) . '&search=' . urlencode($search) . '&search_cat=' . $searchCat . '&only_goods=' . $onlyGoods . '&only_sold=' . $onlySold;
print_barre_liste($langs->trans('Products'), $page, $_SERVER['PHP_SELF'], $param, '', '', '', count($rows), $nbtotal, 'product', 0, '', '', $limit, 0, 0, 1);

if ($canWrite) {
	print '<div class="tagtable centpercent paddingtopbottomonly">';
	print '<select class="flat" name="massaction" id="massaction">';
	print '<option value="assign">' . $langs->trans('RecupelMassAssign') . '</option>';
	print '<option value="exempt">' . $langs->trans('RecupelMassExempt') . '</option>';
	print '<option value="origin">' . $langs->trans('RecupelMassOrigin') . '</option>';
	print '<option value="unclassify">' . $langs->trans('RecupelMassUnclassify') . '</option>';
	print '</select> ';
	print emrecupel_select_code($catalog, 'mass_code', '', $langs->defaultlang, true, 'minwidth300 maxwidth400');
	print ' ' . $langs->trans('RecupelUnits') . ' <input type="text" class="flat width40 right" name="mass_units" value="1"> ';
	print '<select class="flat" name="mass_origin"><option value="keep">' . $langs->trans('RecupelOriginKeep') . '</option>';
	foreach (array('' => $langs->trans('RecupelOriginDefaultShort'), 'import' => emrecupel_origin_label('import'), 'local' => emrecupel_origin_label('local'), 'used' => emrecupel_origin_label('used')) as $k => $v) {
		print '<option value="' . $k . '">' . dol_escape_htmltag($v) . '</option>';
	}
	print '</select> ';
	print '<label><input type="checkbox" name="mass_replace" value="1"> ' . $langs->trans('RecupelReplaceCodes') . '</label> ';
	print '<input type="submit" class="button small" value="' . $langs->trans('Apply') . '">';
	print '</div>';
}

print '<div class="div-table-responsive">';
print '<table class="tagtable liste centpercent">';
print '<tr class="liste_titre">';
if ($canWrite) {
	print '<th class="center"><input type="checkbox" id="checkall" onclick="jQuery(\'.emrecupelcheck\').prop(\'checked\', this.checked);"></th>';
}
print '<th>' . $langs->trans('Ref') . '</th><th>' . $langs->trans('Label') . '</th><th>' . $langs->trans('RecupelStatus') . '</th><th>' . $langs->trans('RecupelCodes') . '</th><th>' . $langs->trans('RecupelOrigin') . '</th><th class="right">' . $langs->trans('RecupelSoldThisYear') . '</th>';
print '</tr>';
$prod = new Product($db);
foreach ($rows as $o) {
	$prod->id = (int) $o->rowid;
	$prod->ref = $o->ref;
	$prod->label = $o->label;
	$prod->type = (int) $o->fk_product_type;
	$prod->status = (int) $o->tosell;
	$data = $ep->get((int) $o->rowid, false);
	$codes = array();
	foreach ($data['items'] as $it) {
		$codes[] = '<strong>' . dol_escape_htmltag($it['code']) . '</strong>' . ($it['units'] != 1 ? ' ×' . price2num($it['units']) : '');
	}
	$status = $o->status === null ? '<span class="badge badge-status1">' . $langs->trans('RecupelStatusNone') . '</span>' : ($o->status === 'exempt' ? '<span class="badge badge-status5">' . $langs->trans('RecupelStatusExempt') . '</span>' : '<span class="badge badge-status4">' . $langs->trans('RecupelStatusSubject') . '</span>');
	print '<tr class="oddeven">';
	if ($canWrite) {
		print '<td class="center"><input type="checkbox" class="emrecupelcheck" name="toselect[]" value="' . (int) $o->rowid . '"></td>';
	}
	print '<td class="nowraponall">' . $prod->getNomUrl(1) . ' <a href="' . dol_buildpath('/emrecupel/product.php', 1) . '?id=' . (int) $o->rowid . '">' . img_picto($langs->trans('Recupel'), 'fa-recycle') . '</a></td>';
	print '<td>' . dol_escape_htmltag($o->label) . '</td>';
	print '<td>' . $status . '</td>';
	print '<td>' . implode(', ', $codes) . '</td>';
	print '<td>' . ($o->status === null ? '' : dol_escape_htmltag(emrecupel_origin_label((string) $o->origin))) . '</td>';
	print '<td class="right">' . ($o->sold !== null ? price2num($o->sold) : '') . '</td>';
	print '</tr>';
}
if (empty($rows)) {
	print '<tr class="oddeven"><td colspan="7"><span class="opacitymedium">' . $langs->trans('NoRecordFound') . '</span></td></tr>';
}
print '</table></div>';
print '</form>';

print dol_get_fiche_end();
llxFooter();
$db->close();
