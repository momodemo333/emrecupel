<?php
/* Copyright (C) 2026 E-dem SRL <support@e-dem.com>
 *
 * Integration tests of emRecupel against a real Dolibarr database.
 *
 * Usage (inside the web container, module enabled):
 *   php custom/emrecupel/tests/Integration/run_integration.php [--keep] [--rollback]
 *
 * --rollback runs everything in one transaction rolled back at the end: the
 * database is left as it was (use it on an instance you must not change).
 *
 * Creates its own fixtures (products TEST-RCP2-*, third parties
 * "TEST-RCP2 …") and documents; drafts are deleted at the end unless --keep.
 * Validated test invoices cannot be deleted by Dolibarr rules and stay in the
 * database (third parties TEST-RCP2 …).
 * Exit code 0 when every check passes.
 */

if (php_sapi_name() !== 'cli') {
	exit(1);
}
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['DOCUMENT_ROOT'] = '/var/www/html';
$_SERVER['REQUEST_URI'] = '';
define('NOSESSION', 1);
define('NOREQUIREMENU', 1);
define('NOLOGIN', 1);
$htdocs = getenv('DOL_HTDOCS') ?: '/var/www/html';
require $htdocs . '/master.inc.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT . '/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture-rec.class.php';
dol_include_once('/emrecupel/class/emrecupeldocumentsync.class.php');
dol_include_once('/emrecupel/class/emrecupelproduct.class.php');
dol_include_once('/emrecupel/class/actions_emrecupel.class.php');

$keep = in_array('--keep', $argv, true);
$rollback = in_array('--rollback', $argv, true);
$user = new User($db);
$res = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "user WHERE admin = 1 AND statut = 1 ORDER BY rowid LIMIT 1");
$user->fetch((int) $db->fetch_object($res)->rowid);
$user->loadRights();
$langs->setDefaultLang('fr_BE');
$langs->loadLangs(array('main', 'bills', 'emrecupel@emrecupel'));

if (!isModEnabled('emrecupel')) {
	echo "Module emrecupel is not enabled\n";
	exit(2);
}

// ---------------------------------------------------------------------------
// Mini assertion framework
// ---------------------------------------------------------------------------
$GLOBALS['nbok'] = 0;
$GLOBALS['fails'] = array();
$GLOBALS['section'] = '';
function section($t)
{
	$GLOBALS['section'] = $t;
	echo "\n== " . $t . "\n";
}
function check($name, $cond, $detail = '')
{
	if ($cond) {
		$GLOBALS['nbok']++;
		echo "  ok   " . $name . "\n";
	} else {
		$GLOBALS['fails'][] = $GLOBALS['section'] . ' / ' . $name . ($detail !== '' ? ' — ' . $detail : '');
		echo "  FAIL " . $name . ($detail !== '' ? ' — ' . $detail : '') . "\n";
	}
}
function approx($a, $b, $eps = 0.005)
{
	return abs((float) $a - (float) $b) <= $eps;
}

/** @var array<int,CommonObject> $created documents to clean */
$created = array();

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------
function fixtureProduct($ref, $label, $vat, $status, $origin, array $items, $weightKg = null)
{
	global $db, $user;
	$p = new Product($db);
	if ($p->fetch(0, $ref) <= 0) {
		$p->ref = $ref;
		$p->label = $label;
		$p->type = Product::TYPE_PRODUCT;
		$p->price = 100;
		$p->price_base_type = 'HT';
		$p->tva_tx = $vat;
		$p->status = 1;
		$p->status_buy = 1;
		if ($weightKg !== null) {
			$p->weight = $weightKg;
			$p->weight_units = 0;
		}
		if ($p->create($user) <= 0) {
			echo "Fixture product $ref: " . $p->error . "\n";
			exit(3);
		}
	}
	$ep = new EmrecupelProduct($db);
	if ($ep->save($p->id, $status, $origin, $items, $user, null) < 0) {
		echo "Fixture classification $ref: " . $ep->error . "\n";
		exit(3);
	}
	return (int) $p->id;
}

function fixtureSoc($name, $countryId, $role, $mode = '')
{
	global $db, $user;
	$s = new Societe($db);
	$res = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "societe WHERE nom = '" . $db->escape($name) . "'");
	if ($res && ($o = $db->fetch_object($res))) {
		$s->fetch((int) $o->rowid);
	} else {
		$s->name = $name;
		$s->client = 1;
		$s->code_client = -1;
		$s->country_id = $countryId;
		$s->tva_assuj = 1;
		if ($s->create($user) <= 0) {
			echo "Fixture soc $name: " . $s->error . "\n";
			exit(3);
		}
		$s->fetch($s->id);
	}
	$s->country_id = $countryId;
	$s->array_options['options_emrecupel_role'] = $role;
	$s->array_options['options_emrecupel_mode'] = $mode;
	$s->update($s->id, $user, 0);
	$s->insertExtraFields();
	return (int) $s->id;
}

function countryId($code)
{
	global $db;
	$r = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "c_country WHERE code = '" . $db->escape($code) . "'");
	return (int) $db->fetch_object($r)->rowid;
}

function d($y, $m, $day)
{
	return dol_mktime(12, 0, 0, $m, $day, $y);
}

if ($rollback) {
	$db->begin();
}
EmrecupelDocumentSync::clearCache();
$BE = countryId('BE');
$FR = countryId('FR');

section('Fixtures');
$P = array();
$P['fridge'] = fixtureProduct('TEST-RCP2-FRIDGE', 'Réfrigérateur test', 21, 'subject', 'import', array(array('code' => '1.1', 'units' => 1)));
$P['laptop'] = fixtureProduct('TEST-RCP2-LAPTOP', 'Portable test', 21, 'subject', 'local', array(array('code' => '2.4', 'units' => 1)));
$P['lumi'] = fixtureProduct('TEST-RCP2-LUMI', 'Luminaire + 4 lampes LED', 21, 'subject', 'local', array(array('code' => '4.6', 'units' => 1), array('code' => '3.2', 'units' => 4)));
$P['pro'] = fixtureProduct('TEST-RCP2-PROMACHINE', 'Machine professionnelle', 21, 'subject', 'import', array(array('code' => '4.62', 'units' => 1)), 50);
$P['used'] = fixtureProduct('TEST-RCP2-USEDTV', 'TV occasion belge', 21, 'subject', 'used', array(array('code' => '2.1', 'units' => 1)));
$P['cable'] = fixtureProduct('TEST-RCP2-CABLE', 'Câble (non soumis)', 21, 'exempt', '', array());
$P['med6'] = fixtureProduct('TEST-RCP2-MED6', 'Dispositif médical TVA 6 %', 6, 'subject', 'import', array(array('code' => '5.9', 'units' => 1)));
$P['plain'] = fixtureProduct('TEST-RCP2-PLAIN', 'Produit non classé', 21, 'subject', '', array());
$db->query("DELETE FROM " . MAIN_DB_PREFIX . "emrecupel_product WHERE fk_product = " . $P['plain']);
EmrecupelProduct::clearCache();

$S = array();
$S['reseller'] = fixtureSoc('TEST-RCP2 Revendeur BE', $BE, 'reseller');
$S['private'] = fixtureSoc('TEST-RCP2 Particulier BE', $BE, 'enduser');
$S['company'] = fixtureSoc('TEST-RCP2 Entreprise utilisatrice BE', $BE, '');
$S['companyline'] = fixtureSoc('TEST-RCP2 Entreprise ligne séparée BE', $BE, 'enduser', 'line');
$S['export'] = fixtureSoc('TEST-RCP2 Client FR', $FR, 'reseller');
$S['exempt'] = fixtureSoc('TEST-RCP2 Exempté', $BE, 'exempt');
check('fixtures created', count($P) === 8 && count($S) === 6);
EmrecupelDocumentSync::clearCache();

$sync = new EmrecupelDocumentSync($db);

/**
 * Fee lines of a document.
 *
 * @return array<int,array<string,mixed>>
 */
function feeLines($element, $id)
{
	global $sync;
	$out = array();
	foreach ($sync->readLines($element, $id) as $l) {
		if ($l['cat'] !== '') {
			$out[] = $l;
		}
	}
	return $out;
}
function feeCodes($element, $id)
{
	$codes = array();
	foreach (feeLines($element, $id) as $l) {
		$codes[] = $l['cat'];
	}
	sort($codes);
	return implode(',', $codes);
}
function lineOf($element, $id, $productId)
{
	global $sync;
	foreach ($sync->readLines($element, $id) as $l) {
		if ($l['fk_product'] === $productId && $l['cat'] === '') {
			return $l;
		}
	}
	return null;
}
function feeOf($element, $id, $code)
{
	foreach (feeLines($element, $id) as $l) {
		if ($l['cat'] === $code) {
			return $l;
		}
	}
	return null;
}
function newInvoice($socid, $date, $type = 0)
{
	global $db, $user, $created;
	$f = new Facture($db);
	$f->socid = $socid;
	$f->date = $date;
	$f->type = $type;
	$id = $f->create($user);
	if ($id <= 0) {
		echo "Invoice creation failed: " . $f->error . "\n";
		exit(4);
	}
	$f->fetch($id);
	$created[] = $f;
	return $f;
}
function refetch($obj)
{
	$obj->fetch($obj->id);
	return $obj;
}

// ---------------------------------------------------------------------------
section('1. Reseller, household appliances: separate lines');
$f = newInvoice($S['reseller'], d(2026, 12, 15));
$f->addline('Frigo', 500, 2, 21, 0, 0, $P['fridge']);
$f->addline('Laptop', 800, 1, 21, 0, 0, $P['laptop']);
$f->addline('Luminaire', 120, 2, 21, 0, 0, $P['lumi']);
$f->addline('Machine pro', 3000, 1, 21, 0, 0, $P['pro']);
$f->addline('TV occasion', 200, 1, 21, 0, 0, $P['used']);
$f->addline('Câble', 5, 3, 21, 0, 0, $P['cable']);
$f->addline('Non classé', 10, 1, 21, 0, 0, $P['plain']);
check('fee lines: 1.1, 2.4, 3.2, 4.6 (none for pro/used/exempt/unclassified)', feeCodes('facture', $f->id) === '1.1,2.4,3.2,4.6', feeCodes('facture', $f->id));
$fee = feeOf('facture', $f->id, '1.1');
check('1.1 qty = product qty (2)', $fee && approx($fee['qty'], 2));
check('1.1 total TTC = 20,00 (round official amount, TTC base)', $fee && approx($fee['total_ttc'], 20.00), $fee ? (string) $fee['total_ttc'] : '');
check('1.1 VAT 21 %', $fee && approx($fee['tva_tx'], 21));
$fee = feeOf('facture', $f->id, '3.2');
check('3.2 (4 lamps per luminaire) total TTC = 2 × 4 × 0,10 = 0,80', $fee && approx($fee['total_ttc'], 0.80), $fee ? (string) $fee['total_ttc'] : '');
$lines = $sync->readLines('facture', $f->id);
$pos = array();
foreach ($lines as $i => $l) {
	$pos[$l['fk_product'] . '|' . $l['cat']] = $i;
}
check('fee line placed right after its product (fridge)', $lines[$pos[$P['fridge'] . '|'] + 1]['cat'] === '1.1');
check('fee line placed right after its product (laptop)', $lines[$pos[$P['laptop'] . '|'] + 1]['cat'] === '2.4');
check('fee lines use the RECUPEL service', feeLines('facture', $f->id)[0]['fk_product'] === getDolGlobalInt('EMRECUPEL_FEE_PRODUCT_ID'));
$desc = feeOf('facture', $f->id, '1.1')['desc'];
check('fee description: category code and official label in the customer language (fr)', strpos($desc, '1.1') !== false && strpos($desc, "Dispositifs d'échange thermique") !== false, $desc);
$feeProd = new Product($db);
$feeProd->fetch(getDolGlobalInt('EMRECUPEL_FEE_PRODUCT_ID'));
check('fee service RECUPEL labelled "Cotisation Recupel"', $feeProd->ref === 'RECUPEL' && $feeProd->label === 'Cotisation Recupel', $feeProd->label);

section('2. Quantity change and manual removal');
$pl = lineOf('facture', $f->id, $P['fridge']);
refetch($f);
$f->updateline($pl['id'], 'Frigo', 500, 3, 0, '', '', 21);
$fee = feeOf('facture', $f->id, '1.1');
check('fee qty follows product qty (3)', $fee && approx($fee['qty'], 3), $fee ? (string) $fee['qty'] : '');
check('still one 1.1 line', substr_count(feeCodes('facture', $f->id), '1.1') === 1);
$fee = feeOf('facture', $f->id, '2.4');
refetch($f);
$f->deleteline($fee['id']);
check('2.4 removed by hand', strpos(feeCodes('facture', $f->id), '2.4') === false);
$pl = lineOf('facture', $f->id, $P['laptop']);
check('product line remembers the code removed by hand (2.4)', $pl['optout'] === array('2.4'), implode(',', $pl['optout']));
refetch($f);
$f->updateline($pl['id'], 'Laptop', 800, 2, 0, '', '', 21);
check('fee not re-added after a change of the product line', strpos(feeCodes('facture', $f->id), '2.4') === false);
$n = $sync->reconcile('facture', $f->id, $user, array('reset_optout' => true));
check('"Recalculate" brings the fee back', strpos(feeCodes('facture', $f->id), '2.4') !== false, 'changes=' . $n);
$n = $sync->reconcile('facture', $f->id, $user);
check('reconcile is idempotent (0 change)', $n === 0, 'changes=' . $n);
$pl = lineOf('facture', $f->id, $P['lumi']);
refetch($f);
$f->deleteline($pl['id']);
check('deleting the luminaire removes its two fee lines', feeCodes('facture', $f->id) === '1.1,2.4', feeCodes('facture', $f->id));
refetch($f);
check('invoice total = sum of lines', approx($f->total_ttc, array_sum(array_map(function ($l) {
	return $l['total_ttc'];
}, $sync->readLines('facture', $f->id)))));

section('3. Proposal → order → invoice, clone: no duplicate');
$p = new Propal($db);
$p->socid = $S['reseller'];
$p->date = d(2026, 12, 10);
$p->duree_validite = 30;
$pid = $p->create($user);
$p->fetch($pid);
$created[] = $p;
$p->addline('Frigo', 500, 1, 21, 0, 0, $P['fridge']);
$p->addline('Luminaire', 120, 3, 21, 0, 0, $P['lumi']);
check('proposal fee lines', feeCodes('propal', $p->id) === '1.1,3.2,4.6', feeCodes('propal', $p->id));
$p->fetch($p->id);
$p->valid($user);
$p->fetch($p->id);
$o = new Commande($db);
$oid = $o->createFromProposal($p, $user);
check('order created', $oid > 0, $o->error);
$o->fetch($oid);
$created[] = $o;
check('order: same fee lines, no duplicate', feeCodes('commande', $oid) === '1.1,3.2,4.6', feeCodes('commande', $oid));
check('order: 5 lines in total', count($sync->readLines('commande', $oid)) === 5, (string) count($sync->readLines('commande', $oid)));
$o->fetch($oid);
$o->date = d(2026, 12, 12);
$o->valid($user);
$o->fetch($oid);
$fi = new Facture($db);
$res = $fi->createFromOrder($o, $user);
$fid = (int) $fi->id;
check('invoice from order created', $res > 0 && $fid > 0, $fi->error);
EmrecupelDocumentSync::runPending();
check('invoice from order: same fee lines, no duplicate', feeCodes('facture', $fid) === '1.1,3.2,4.6', feeCodes('facture', $fid));
check('invoice from order: 5 lines', count($sync->readLines('facture', $fid)) === 5);
$fi->fetch($fid);
$created[] = $fi;
$keyP = lineOf('facture', $fid, $P['fridge'])['link'];
check('link key kept from proposal to invoice', $keyP !== '' && $keyP === lineOf('propal', $p->id, $P['fridge'])['link']);
$clone = new Facture($db);
$cid = $clone->createFromClone($user, $fid);
EmrecupelDocumentSync::runPending();
check('clone: same fee lines, no duplicate', $cid > 0 && feeCodes('facture', $cid) === '1.1,3.2,4.6', feeCodes('facture', $cid));
$clone->fetch($cid);
$created[] = $clone;
$p->fetch($p->id);
$pcid = $p->createFromClone($user, $S['reseller']);
$pc = new Propal($db);
EmrecupelDocumentSync::runPending();
check('proposal clone: no duplicate', $pcid > 0 && feeCodes('propal', $pcid) === '1.1,3.2,4.6', feeCodes('propal', $pcid));
if ($pcid > 0) {
	$pc->fetch($pcid);
	$created[] = $pc;
}

section('4. Customer change on a draft: reseller → private');
$fi->fetch($fid);
$fi->socid = $S['private'];
$fi->update($user);
check('switching to a private customer removes the separate lines', feeCodes('facture', $fid) === '', feeCodes('facture', $fid));
$fi->fetch($fid);
$fi->socid = $S['reseller'];
$fi->update($user);
check('back to the reseller: lines again', feeCodes('facture', $fid) === '1.1,3.2,4.6', feeCodes('facture', $fid));

section('5. Validation, ledger, credit note with lines, replacement');
$fi->fetch($fid);
$res = $fi->validate($user);
check('invoice validated', $res > 0, $fi->error);
$fi->fetch($fid);
$r = $db->query("SELECT code, units, bucket, decision FROM " . MAIN_DB_PREFIX . "emrecupel_ledger WHERE fk_facture = " . $fid . " ORDER BY code");
$ledger = array();
while ($o2 = $db->fetch_object($r)) {
	$ledger[$o2->code] = $o2;
}
check('ledger rows: 1.1, 3.2, 4.6', implode(',', array_keys($ledger)) === '1.1,3.2,4.6', implode(',', array_keys($ledger)));
check('ledger 3.2 counts 12 lamps (3 luminaires × 4)', isset($ledger['3.2']) && approx($ledger['3.2']->units, 12));
check('ledger fridge (imported, BE) → brought to market', isset($ledger['1.1']) && $ledger['1.1']->bucket === 'market');
check('ledger luminaire (bought in BE, BE) → control', isset($ledger['4.6']) && $ledger['4.6']->bucket === 'control');
$before = count($sync->readLines('facture', $fid));
$n = $sync->reconcile('facture', $fid, $user, array('reset_optout' => true));
check('validated invoice never modified', $n === 0 && count($sync->readLines('facture', $fid)) === $before);

// Credit note with the lines of the invoice (same code path as compta/facture/card.php).
$cn = new Facture($db);
$cn->socid = $S['reseller'];
$cn->date = d(2026, 12, 20);
$cn->type = Facture::TYPE_CREDIT_NOTE;
$cn->fk_facture_source = $fid;
$cnid = $cn->create($user);
$cn->fetch($cnid);
$created[] = $cn;
$src = new Facture($db);
$src->fetch($fid);
foreach ($src->lines as $line) {
	$line->fk_facture = $cnid;
	$line->fk_parent_line = 0;
	$line->subprice = -$line->subprice;
	$line->total_ht = -$line->total_ht;
	$line->total_tva = -$line->total_tva;
	$line->total_ttc = -$line->total_ttc;
	$line->multicurrency_subprice = -$line->multicurrency_subprice;
	$line->multicurrency_total_ht = -$line->multicurrency_total_ht;
	$line->multicurrency_total_tva = -$line->multicurrency_total_tva;
	$line->multicurrency_total_ttc = -$line->multicurrency_total_ttc;
	$line->context['createcreditnotefrominvoice'] = 1;
	$line->insert(0, 1);
}
check('credit note: reconciliation deferred while lines arrive', EmrecupelDocumentSync::isPending('facture', $cnid));
EmrecupelDocumentSync::runPending();
check('credit note: same fee lines, no duplicate', feeCodes('facture', $cnid) === '1.1,3.2,4.6', feeCodes('facture', $cnid));
$fee = feeOf('facture', $cnid, '1.1');
check('credit note: fee line negative (-10,00 TTC)', $fee && approx($fee['total_ttc'], -10.00), $fee ? (string) $fee['total_ttc'] : '');
$cn->fetch($cnid);
$cn->update_price(1);
$cn->validate($user);
$r = $db->query("SELECT SUM(units) as u FROM " . MAIN_DB_PREFIX . "emrecupel_ledger WHERE fk_facture = " . $cnid . " AND code = '1.1'");
check('credit note ledger: -1 appliance', approx($db->fetch_object($r)->u, -1));

// Replacement invoice.
$src->fetch($fid);
$rep = new Facture($db);
$rep->fetch($fid);
$rep->fk_facture_source = $fid;
$rep->type = Facture::TYPE_REPLACEMENT;
$repid = $rep->createFromCurrent($user);
EmrecupelDocumentSync::runPending();
check('replacement invoice: same fee lines, no duplicate', $repid > 0 && feeCodes('facture', $repid) === '1.1,3.2,4.6', feeCodes('facture', $repid));
if ($repid > 0) {
	$rep2 = new Facture($db);
	$rep2->fetch($repid);
	$created[] = $rep2;
}

section('6. Template invoice (recurring)');
$tpl = new FactureRec($db);
$tpl->titre = 'TEST-RCP2 modèle ' . dol_print_date(dol_now(), '%H%M%S');
$tpl->title = $tpl->titre;
$tpl->ref = $tpl->titre;
$tpl->frequency = 1;
$tpl->unit_frequency = 'm';
$tpl->date_when = d(2027, 1, 15);
$tpl->cond_reglement_id = 1;
$tpl->mode_reglement_id = 0;
$tplid = $tpl->create($user, $clone->id);
check('template created from the clone', $tplid > 0, $tpl->error);
$r = $db->query("SELECT COUNT(*) as n FROM " . MAIN_DB_PREFIX . "facturedet_rec as d INNER JOIN " . MAIN_DB_PREFIX . "facturedet_rec_extrafields as e ON e.fk_object = d.rowid WHERE d.fk_facture = " . (int) $tplid . " AND e.emrecupel_cat IS NOT NULL AND e.emrecupel_cat <> ''");
check('template keeps the fee line links', (int) $db->fetch_object($r)->n === 3);
$gen = new Facture($db);
$gen->fac_rec = $tplid;
$gen->fk_fac_rec_source = $tplid;
$gen->type = Facture::TYPE_STANDARD;
$gen->socid = $S['reseller'];
$gen->date = d(2027, 1, 15);
$genid = $gen->create($user);
EmrecupelDocumentSync::runPending();
check('invoice generated from template: no duplicate', $genid > 0 && feeCodes('facture', $genid) === '1.1,3.2,4.6', feeCodes('facture', $genid));
$fee = feeOf('facture', $genid, '1.1');
check('generated in 2027: 1.1 at the 2027 rate (13,00 TTC)', $fee && approx($fee['total_ttc'], 13.00), $fee ? (string) $fee['total_ttc'] : '');
if ($genid > 0) {
	$gen->fetch($genid);
	$created[] = $gen;
}

section('7. Scale switch 31/12/2026 → 01/01/2027');
$f7 = newInvoice($S['reseller'], d(2026, 12, 31));
$f7->addline('Frigo', 500, 1, 21, 0, 0, $P['fridge']);
$f7->addline('Laptop', 800, 1, 21, 0, 0, $P['laptop']);
check('31/12/2026: 1.1 = 10,00 TTC', approx(feeOf('facture', $f7->id, '1.1')['total_ttc'], 10.00));
check('31/12/2026: 2.4 = 0,15 TTC', approx(feeOf('facture', $f7->id, '2.4')['total_ttc'], 0.15));
refetch($f7);
$f7->date = d(2027, 1, 1);
$f7->update($user);
check('01/01/2027: 1.1 = 13,00 TTC', approx(feeOf('facture', $f7->id, '1.1')['total_ttc'], 13.00), (string) feeOf('facture', $f7->id, '1.1')['total_ttc']);
check('01/01/2027: 2.4 = 0,05 TTC', approx(feeOf('facture', $f7->id, '2.4')['total_ttc'], 0.05));
check('still 2 fee lines', count(feeLines('facture', $f7->id)) === 2);
$f7b = newInvoice($S['reseller'], d(2027, 1, 1));
$f7b->addline('Machine pro', 3000, 1, 21, 0, 0, $P['pro']);
$an = $sync->analyse('facture', $f7b->id);
check('2027: 4.62 still administrative fee (0,10 TTC), included for a reseller', $an['lines'][0]['fees'][0]['fee_type'] === 'admin' && $an['lines'][0]['fees'][0]['mode'] === 'included' && approx($an['lines'][0]['fees'][0]['rate_ttc'], 0.10));

section('8. End users, override, exempt, export, VAT 6 %');
$f8 = newInvoice($S['private'], d(2026, 12, 15));
$f8->addline('Frigo', 500, 2, 21, 0, 0, $P['fridge']);
$f8->addline('Laptop', 800, 1, 21, 0, 0, $P['laptop']);
$f8->addline('TV occasion', 200, 1, 21, 0, 0, $P['used']);
check('private customer: no fee line (fee included)', feeCodes('facture', $f8->id) === '');
$an = $sync->analyse('facture', $f8->id);
$m = ActionsEmrecupel::buildMentions($an, $langs);
check('private customer: mention under the fridge line', strpos(implode(' ', $m['lines']), '10,00') !== false, implode(' | ', $m['lines']));
check('private customer: footer total 2 × 10,00 + 0,15 = 20,15', strpos($m['footer'], '20,15') !== false, $m['footer']);
check('used appliance: "fee included" mention without amount', strpos($m['footer'], 'occasion') !== false || strpos($m['footer'], 'Recupel') !== false, $m['footer']);
refetch($f8);
$f8->generateDocument('sponge', $langs);
refetch($f8);
check('PDF generated, descriptions restored afterwards', strpos($f8->lines[0]->desc, 'Recupel') === false, $f8->lines[0]->desc);
check('PDF free text restored afterwards', strpos(getDolGlobalString('INVOICE_FREE_TEXT'), 'Recupel') === false);
echo "  PDF: " . $conf->facture->multidir_output[$conf->entity] . '/' . dol_sanitizeFileName($f8->ref) . '/' . dol_sanitizeFileName($f8->ref) . ".pdf\n";
$GLOBALS['pdf_private'] = dol_sanitizeFileName($f8->ref);

$f8b = newInvoice($S['company'], d(2026, 12, 15));
$f8b->addline('Machine pro', 3000, 1, 21, 0, 0, $P['pro']);
$f8b->addline('Frigo', 500, 1, 21, 0, 0, $P['fridge']);
check('company end user (no role set): fee included, no line', feeCodes('facture', $f8b->id) === '');

$f8c = newInvoice($S['companyline'], d(2026, 12, 15));
$f8c->addline('Frigo', 500, 1, 21, 0, 0, $P['fridge']);
$f8c->addline('Machine pro', 3000, 1, 21, 0, 0, $P['pro']);
check('override "separate line" on the customer: lines for both codes', feeCodes('facture', $f8c->id) === '1.1,4.62', feeCodes('facture', $f8c->id));
check('4.62 line = 0,08 TTC (2026 administrative fee)', approx(feeOf('facture', $f8c->id, '4.62')['total_ttc'], 0.08));
refetch($f8c);
$f8c->generateDocument('sponge', $langs);
$GLOBALS['pdf_line'] = dol_sanitizeFileName($f8c->ref);

$f8d = newInvoice($S['exempt'], d(2026, 12, 15));
$f8d->addline('Frigo', 500, 1, 21, 0, 0, $P['fridge']);
check('exempt customer: no fee', feeCodes('facture', $f8d->id) === '');

$f8e = newInvoice($S['export'], d(2026, 12, 15));
$f8e->addline('Frigo', 500, 1, 21, 0, 0, $P['fridge']);
$f8e->addline('Laptop', 800, 4, 21, 0, 0, $P['laptop']);
check('customer outside Belgium: no fee', feeCodes('facture', $f8e->id) === '');
refetch($f8e);
$f8e->validate($user);
$r = $db->query("SELECT code, bucket, units FROM " . MAIN_DB_PREFIX . "emrecupel_ledger WHERE fk_facture = " . $f8e->id . " ORDER BY code");
$lg = array();
while ($o3 = $db->fetch_object($r)) {
	$lg[$o3->code] = $o3;
}
check('export of an imported fridge: not declared', isset($lg['1.1']) && $lg['1.1']->bucket === 'none');
check('export of a laptop bought in BE: Returnable × 4', isset($lg['2.4']) && $lg['2.4']->bucket === 'returnable' && approx($lg['2.4']->units, 4));

// Belgian customer, delivery contact abroad.
$ct = new Contact($db);
$ct->socid = $S['reseller'];
$ct->lastname = 'TEST-RCP2 livraison NL';
$ct->country_id = countryId('NL');
$ctid = $ct->create($user);
$f8f = newInvoice($S['reseller'], d(2026, 12, 15));
$f8f->add_contact($ctid, 'SHIPPING', 'external');
$f8f->addline('Frigo', 500, 1, 21, 0, 0, $P['fridge']);
check('delivery contact in NL: no fee although the customer is Belgian', feeCodes('facture', $f8f->id) === '', feeCodes('facture', $f8f->id));

$f8g = newInvoice($S['reseller'], d(2026, 12, 15));
$f8g->addline('Dispositif médical', 100, 1, 6, 0, 0, $P['med6']);
$fee = feeOf('facture', $f8g->id, '5.9');
check('VAT 6 % product: fee line at 6 %', $fee && approx($fee['tva_tx'], 6));
check('VAT 6 %: HT base 0,0826 × 1', $fee && approx($fee['total_ht'], 0.08), $fee ? (string) $fee['total_ht'] : '');

section('9. Orders and proposals: fee lines too');
$o9 = new Commande($db);
$o9->socid = $S['reseller'];
$o9->date = d(2026, 12, 15);
$o9id = $o9->create($user);
$o9->fetch($o9id);
$created[] = $o9;
$o9->addline('Frigo', 500, 2, 21, 0, 0, $P['fridge']);
check('order: fee line added on the fly', feeCodes('commande', $o9id) === '1.1');
$pl = lineOf('commande', $o9id, $P['fridge']);
$o9->fetch($o9id);
$o9->deleteline($user, $pl['id']);
check('order: deleting the product deletes its fee', feeCodes('commande', $o9id) === '' && count($sync->readLines('commande', $o9id)) === 0);

section('10. Review fixes');
// I4: removing one code of a multi-code product keeps the others.
$f10 = newInvoice($S['reseller'], d(2026, 12, 15));
$f10->addline('Luminaire', 120, 2, 21, 0, 0, $P['lumi']);
check('luminaire: 3.2 and 4.6', feeCodes('facture', $f10->id) === '3.2,4.6');
refetch($f10);
$f10->deleteline(feeOf('facture', $f10->id, '3.2')['id']);
$pl = lineOf('facture', $f10->id, $P['lumi']);
refetch($f10);
$f10->updateline($pl['id'], 'Luminaire', 120, 3, 0, '', '', 21);
check('lamps removed by hand: only 3.2 gone, 4.6 kept and updated (qty 3)', feeCodes('facture', $f10->id) === '4.6' && approx(feeOf('facture', $f10->id, '4.6')['qty'], 3), feeCodes('facture', $f10->id));
$sync->reconcile('facture', $f10->id, $user, array('reset_optout' => true));
check('"Recalculate" restores 3.2', feeCodes('facture', $f10->id) === '3.2,4.6');

// I1: negative quantity on a standard invoice gives a negative fee, stable.
$f11 = newInvoice($S['reseller'], d(2026, 12, 15));
$f11->addline('Reprise frigo', 500, -1, 21, 0, 0, $P['fridge']);
$fee = feeOf('facture', $f11->id, '1.1');
check('returned appliance (qty -1): fee -10,00 TTC', $fee && approx($fee['total_ttc'], -10.00), $fee ? (string) $fee['total_ttc'] : '');
check('negative fee is stable (0 change on reconcile)', $sync->reconcile('facture', $f11->id, $user) === 0);

// I3: credit note dated 2027 on a 2026 invoice refunds the 2026 amount.
$f12 = newInvoice($S['reseller'], d(2026, 12, 15));
$f12->addline('Frigo', 500, 1, 21, 0, 0, $P['fridge']);
refetch($f12);
$f12->validate($user);
$cn2 = new Facture($db);
$cn2->socid = $S['reseller'];
$cn2->date = d(2027, 1, 10);
$cn2->type = Facture::TYPE_CREDIT_NOTE;
$cn2->fk_facture_source = $f12->id;
$cn2id = $cn2->create($user);
$cn2->fetch($cn2id);
$created[] = $cn2;
$src2 = new Facture($db);
$src2->fetch($f12->id);
foreach ($src2->lines as $line) {
	$line->fk_facture = $cn2id;
	$line->subprice = -$line->subprice;
	$line->total_ht = -$line->total_ht;
	$line->total_tva = -$line->total_tva;
	$line->total_ttc = -$line->total_ttc;
	$line->insert(0, 1);
}
EmrecupelDocumentSync::runPending();
$fee = feeOf('facture', $cn2id, '1.1');
check('credit note of 10/01/2027 on a 2026 invoice: -10,00 (2026 rate), not -13,00', $fee && approx($fee['total_ttc'], -10.00), $fee ? (string) $fee['total_ttc'] : '');
$f13 = newInvoice($S['reseller'], d(2027, 1, 10), Facture::TYPE_CREDIT_NOTE);
$f13->addline('Frigo', 500, 1, 21, 0, 0, $P['fridge']);
$fee = feeOf('facture', $f13->id, '1.1');
check('credit note without source in 2027: -13,00', $fee && approx($fee['total_ttc'], -13.00), $fee ? (string) $fee['total_ttc'] : '');
$sync->reconcile('facture', $f13->id, $user);
$fee = feeOf('facture', $f13->id, '1.1');
check('credit note fee stays negative after an update', $fee && $fee['total_ttc'] < 0);

// M4: delivery contact abroad added after the lines.
$f14 = newInvoice($S['reseller'], d(2026, 12, 15));
$f14->addline('Frigo', 500, 1, 21, 0, 0, $P['fridge']);
check('fee before the delivery contact', feeCodes('facture', $f14->id) === '1.1');
refetch($f14);
$f14->add_contact($ctid, 'SHIPPING', 'external');
check('delivery contact NL added afterwards: fee removed', feeCodes('facture', $f14->id) === '', feeCodes('facture', $f14->id));

// C1: template invoice from 1.x with its unlinked Recupel line → no duplicate.
$tpl2 = new FactureRec($db);
$tpl2->titre = 'TEST-RCP2 modèle v1 ' . dol_print_date(dol_now(), '%H%M%S');
$tpl2->title = $tpl2->titre;
$tpl2->ref = $tpl2->titre;
$tpl2->frequency = 1;
$tpl2->unit_frequency = 'm';
$tpl2->cond_reglement_id = 1;
$base = newInvoice($S['reseller'], d(2026, 12, 15));
$tplid2 = $tpl2->create($user, $base->id);
$tpl2->fetch($tplid2);
$tpl2->addline('Frigo', 500, 1, 21, 0, 0, $P['fridge']);
$tpl2->addline('Cotisation Recupel (cat. 1.1)', 8.2645, 1, 21, 0, 0, 0, 0, 'HT', 0, 0, 0, 1);
dol_include_once('/emrecupel/class/emrecupelmigrations.class.php');
$hasV1 = EmrecupelMigrations::tableExists($db, 'emrecupel_category');
if ($hasV1) {
	EmrecupelMigrations::migrateFromV1($db);
	$gen2 = new Facture($db);
	$gen2->fac_rec = $tplid2;
	$gen2->type = Facture::TYPE_STANDARD;
	$gen2->socid = $S['reseller'];
	$gen2->date = d(2026, 12, 20);
	$gen2id = $gen2->create($user);
	EmrecupelDocumentSync::runPending();
	$n11 = 0;
	foreach ($sync->readLines('facture', $gen2id) as $l) {
		if (stripos($l['desc'], 'Recupel') !== false || $l['cat'] !== '') {
			$n11++;
		}
	}
	check('invoice from a 1.x template: exactly one Recupel line (1.x line adopted)', $n11 === 1 && feeCodes('facture', $gen2id) === '1.1', 'recupel lines=' . $n11 . ' codes=' . feeCodes('facture', $gen2id));
	$gen2->fetch($gen2id);
	$created[] = $gen2;
} else {
	echo "  skip 1.x template test (no 1.x tables)\n";
}
$tpl2->delete($user);

// ---------------------------------------------------------------------------
section('Cleanup');
// Keep a copy of the generated PDFs (the drafts and their files are deleted below).
$outdir = DOL_DATA_ROOT . '/emrecupel/temp';
dol_mkdir($outdir);
foreach (array('pdf_private' => 'test-included.pdf', 'pdf_line' => 'test-line.pdf') as $k => $name) {
	if (!empty($GLOBALS[$k])) {
		$srcpdf = $conf->facture->multidir_output[$conf->entity] . '/' . $GLOBALS[$k] . '/' . $GLOBALS[$k] . '.pdf';
		@copy($srcpdf, $outdir . '/' . $name);
		echo "  copied " . $srcpdf . " → " . $outdir . '/' . $name . "\n";
	}
}
if (!$keep && !$rollback) {
	$n = 0;
	foreach (array_reverse($created) as $doc) {
		$doc->fetch($doc->id);
		if ((int) $doc->status === 0) {
			if ($doc->element === 'commande') {
				$doc->delete($user);
			} elseif ($doc->element === 'facture') {
				$doc->delete($user);
			} else {
				$doc->delete($user);
			}
			$n++;
		}
	}
	echo "  drafts deleted: " . $n . "\n";
}

if ($rollback) {
	while ($db->transaction_opened > 0) {
		$db->rollback();
	}
	echo "  transaction rolled back\n";
}
echo "\nRESULT: " . $GLOBALS['nbok'] . " ok, " . count($GLOBALS['fails']) . " failed\n";
foreach ($GLOBALS['fails'] as $f) {
	echo "  - " . $f . "\n";
}
echo "PDF_PRIVATE=" . ($GLOBALS['pdf_private'] ?? '') . "\nPDF_LINE=" . ($GLOBALS['pdf_line'] ?? '') . "\n";
exit(empty($GLOBALS['fails']) ? 0 : 1);
