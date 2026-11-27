<?php
/* Copyright (C) 2026 E-dem SRL <support@e-dem.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/emrecupeldocumentsync.class.php
 * \ingroup emrecupel
 * \brief   Computes the Recupel fees of a proposal, order or invoice, keeps the
 *          fee lines of draft documents consistent with their products and
 *          records the appliances of validated invoices for the declaration.
 *
 * Model
 * -----
 * A product line that carries fees and its fee lines share a link key, stored
 * in the line extrafield `emrecupel_link`. Dolibarr copies line extrafields
 * when a document is created from another one (proposal → order → invoice →
 * credit note, clone, replacement invoice, template invoice), so the pair
 * survives every conversion without a mapping table.
 *
 *  - product line : emrecupel_link = key (or "-key" once the user deleted the
 *                   fee by hand)
 *  - fee line     : emrecupel_link = key, emrecupel_cat = Recupel code,
 *                   emrecupel_units = appliances per sold unit
 *
 * A product can carry several codes (luminaire + its lamps, camera kit): one
 * fee line per code.
 *
 * Fee lines are ordinary service lines (special_code 0): every total, PDF
 * model, accounting export and e-invoicing module treats them as a normal
 * line. They are recognised by their extrafields only.
 *
 * Entry points
 * ------------
 *  - analyse()       : what is due for each line of any document (no write);
 *  - reconcile()     : full idempotent pass over a draft document;
 *  - onLine*()       : called by line triggers for an immediate, local update;
 *  - onDocument*()   : called by document triggers (creation, header change);
 *  - writeLedger()   : snapshot of a validated invoice for the declaration.
 *
 * Validated documents are never modified.
 */

dol_include_once('/emrecupel/class/emrecupelfeepolicy.class.php');
dol_include_once('/emrecupel/class/emrecupelcatalog.class.php');
dol_include_once('/emrecupel/class/emrecupelproduct.class.php');

class EmrecupelDocumentSync
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var int Number of lines created/updated/deleted by the last call */
	public $changes = 0;

	/**
	 * True while this class writes lines: triggers fired by our own
	 * addline/updateline/deleteline calls must be ignored.
	 *
	 * @var bool
	 */
	public static $busy = false;

	/** @var array<string,array<int,bool>> element => doc id => true, reconciled later */
	private static $pending = array();

	/** @var array<string,array<int,bool>> element => doc id => true, changed by a deferred reconciliation */
	public static $changedDocs = array();

	/** @var bool */
	private static $shutdownRegistered = false;

	/** @var EmrecupelCatalog */
	public $catalog;

	/** @var EmrecupelProduct */
	public $products;

	/**
	 * Supported document types.
	 *
	 * @var array<string,array<string,string>>
	 */
	public static $types = array(
		'propal' => array(
			'class' => 'Propal',
			'file' => '/comm/propal/class/propal.class.php',
			'table' => 'propal',
			'line_table' => 'propaldet',
			'fk' => 'fk_propal',
			'const' => 'EMRECUPEL_ON_PROPAL',
			'contact_element' => 'propal',
		),
		'commande' => array(
			'class' => 'Commande',
			'file' => '/commande/class/commande.class.php',
			'table' => 'commande',
			'line_table' => 'commandedet',
			'fk' => 'fk_commande',
			'const' => 'EMRECUPEL_ON_ORDER',
			'contact_element' => 'commande',
		),
		'facture' => array(
			'class' => 'Facture',
			'file' => '/compta/facture/class/facture.class.php',
			'table' => 'facture',
			'line_table' => 'facturedet',
			'fk' => 'fk_facture',
			'const' => 'EMRECUPEL_ON_INVOICE',
			'contact_element' => 'facture',
		),
	);

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->catalog = new EmrecupelCatalog($db);
		$this->products = new EmrecupelProduct($db);
	}

	/**
	 * Drop per-request caches.
	 *
	 * @return void
	 */
	public static function clearCache()
	{
		EmrecupelProduct::clearCache();
		EmrecupelCatalog::clearCache();
	}

	/**
	 * Display settings from the setup.
	 *
	 * @return array<string,string>
	 */
	public static function displaySettings()
	{
		$d = EmrecupelFeePolicy::defaultSettings();
		return array(
			'reseller_professional' => getDolGlobalString('EMRECUPEL_MODE_RESELLER_PRO', $d['reseller_professional']),
			'enduser_household' => getDolGlobalString('EMRECUPEL_MODE_ENDUSER_HOUSEHOLD', $d['enduser_household']),
			'enduser_professional' => getDolGlobalString('EMRECUPEL_MODE_ENDUSER_PRO', $d['enduser_professional']),
		);
	}

	/**
	 * Company profile (A reseller, B exporter, C producer/importer).
	 *
	 * @return string
	 */
	public static function profile()
	{
		$p = getDolGlobalString('EMRECUPEL_PROFILE', EmrecupelFeePolicy::PROFILE_RESELLER);
		return in_array($p, array(EmrecupelFeePolicy::PROFILE_RESELLER, EmrecupelFeePolicy::PROFILE_EXPORTER, EmrecupelFeePolicy::PROFILE_PRODUCER), true) ? $p : EmrecupelFeePolicy::PROFILE_RESELLER;
	}

	/**
	 * Default origin of products, set in the setup (derived from the profile).
	 *
	 * @return string
	 */
	public static function defaultOrigin()
	{
		$o = getDolGlobalString('EMRECUPEL_DEFAULT_ORIGIN');
		if (in_array($o, array(EmrecupelFeePolicy::ORIGIN_IMPORT, EmrecupelFeePolicy::ORIGIN_LOCAL), true)) {
			return $o;
		}
		return EmrecupelFeePolicy::profileDefaultOrigin(self::profile());
	}

	// ---------------------------------------------------------------------
	// Trigger entry points
	// ---------------------------------------------------------------------

	/**
	 * Map a line object to its document element and id.
	 *
	 * @param object $line Line object (PropaleLigne, OrderLine, FactureLigne)
	 * @return array{0:string,1:int}|null
	 */
	public static function docOfLine($line)
	{
		if (!is_object($line)) {
			return null;
		}
		foreach (self::$types as $element => $def) {
			$fk = $def['fk'];
			if (!empty($line->$fk)) {
				return array($element, (int) $line->$fk);
			}
		}
		return null;
	}

	/**
	 * Id of a line object.
	 *
	 * @param object $line Line
	 * @return int
	 */
	private static function lineId($line)
	{
		return (int) (!empty($line->id) ? $line->id : (!empty($line->rowid) ? $line->rowid : 0));
	}

	/**
	 * A line was inserted.
	 *
	 * @param object $line Line object
	 * @param User   $user Current user
	 * @return int <0 on error
	 */
	public function onLineInserted($line, $user)
	{
		if (self::$busy) {
			return 0;
		}
		$ref = self::docOfLine($line);
		if ($ref === null) {
			return 0;
		}
		list($element, $docId) = $ref;

		// Copied line (document created from another one): its partner is being
		// copied too, or not. Decide on the complete set of lines (document
		// creation trigger, or end of request) instead of creating a duplicate now.
		if ($this->lineOption($line, 'emrecupel_link') !== '' || $this->lineOption($line, 'emrecupel_cat') !== '') {
			self::schedule($element, $docId);
			return 0;
		}
		// A document whose copied lines are still arriving: wait as well.
		if (isset(self::$pending[$element][$docId])) {
			return 0;
		}

		return $this->reconcile($element, $docId, $user, array('only_line' => self::lineId($line)));
	}

	/**
	 * A line was modified.
	 *
	 * @param object $line Line object
	 * @param User   $user Current user
	 * @return int
	 */
	public function onLineModified($line, $user)
	{
		if (self::$busy) {
			return 0;
		}
		$ref = self::docOfLine($line);
		if ($ref === null) {
			return 0;
		}
		list($element, $docId) = $ref;
		$lineId = self::lineId($line);
		$own = $this->readLineRecupel($element, $lineId);
		// A fee line edited by hand is left alone (the user may have a reason);
		// it is rewritten the next time its product line changes.
		if ($own['cat'] !== '') {
			return 0;
		}
		return $this->reconcile($element, $docId, $user, array('only_line' => $lineId));
	}

	/**
	 * A line is being deleted. The core fires the trigger before removing the
	 * row, so the line is still in the database.
	 *
	 * @param object $line Line object
	 * @param User   $user Current user
	 * @return int
	 */
	public function onLineDeleted($line, $user)
	{
		if (self::$busy) {
			return 0;
		}
		$ref = self::docOfLine($line);
		if ($ref === null) {
			return 0;
		}
		list($element, $docId) = $ref;
		$lineId = self::lineId($line);
		$own = $this->readLineRecupel($element, $lineId);
		$key = $own['link'] !== '' ? $own['link'] : $this->lineOption($line, 'emrecupel_link');
		$cat = $own['cat'] !== '' ? $own['cat'] : $this->lineOption($line, 'emrecupel_cat');
		if ($key === '' || EmrecupelFeePolicy::isOptOutKey($key)) {
			return 0;
		}

		$doc = $this->loadDraft($element, $docId);
		if ($doc === null) {
			return 0;
		}
		$lines = $this->readLines($element, $docId);

		if ($cat !== '') {
			// The user removed one fee by hand: remember that code on the
			// product line (the other codes of the product stay), otherwise the
			// next reconciliation would add it back.
			foreach ($lines as $l) {
				if ($l['id'] !== $lineId && $l['link'] === $key && $l['cat'] === '') {
					$codes = $l['optout'];
					if (!in_array($cat, $codes, true)) {
						$codes[] = $cat;
					}
					$this->setLineOptOut($element, $l['id'], $codes);
				}
			}
			return 1;
		}

		// Product line deleted: its fee lines go with it.
		$res = 0;
		foreach ($lines as $l) {
			if ($l['id'] !== $lineId && $l['link'] === $key && $l['cat'] !== '') {
				$res = $this->deleteLine($doc, $element, $l['id'], $user);
			}
		}
		return $res;
	}

	/**
	 * A document was created (PROPAL_CREATE, ORDER_CREATE, BILL_CREATE). The
	 * core fires it after every copied line is inserted, inside the creation
	 * transaction: the right moment to reconcile a converted document.
	 *
	 * @param string $element Element
	 * @param int    $docId   Document id
	 * @param User   $user    User
	 * @return int
	 */
	public function onDocumentCreated($element, $docId, $user)
	{
		if (self::$busy || !isset(self::$pending[$element][(int) $docId])) {
			return 0;
		}
		unset(self::$pending[$element][(int) $docId]);
		$res = $this->reconcile($element, (int) $docId, $user);
		if ($res > 0) {
			self::$changedDocs[$element][(int) $docId] = true;
		}
		return $res;
	}

	/**
	 * The header of a draft changed (date, customer, delivery contact…): the
	 * rate, the customer role or the country may have changed.
	 *
	 * @param string $element Element
	 * @param int    $docId   Document id
	 * @param User   $user    User
	 * @return int
	 */
	public function onDocumentModified($element, $docId, $user)
	{
		if (self::$busy || isset(self::$pending[$element][(int) $docId])) {
			return 0;
		}
		return $this->reconcile($element, (int) $docId, $user);
	}

	// ---------------------------------------------------------------------
	// Analysis (read only)
	// ---------------------------------------------------------------------

	/**
	 * Compute, for every product line of a document, the Recupel fees due.
	 *
	 * @param string $element propal, commande or facture
	 * @param int    $docId   Document id
	 * @param bool   $forceOptOutReset Ignore fees removed by hand
	 * @return array{doc: array<string,mixed>, lines: array<int,array<string,mixed>>}|null
	 *   lines: product lines with key 'fees' => list of
	 *   [code, label, units, use_type, fee_type, decision, mode, rate_ht, rate_ttc, price, included_ttc, declared_units]
	 */
	public function analyse($element, $docId, $forceOptOutReset = false)
	{
		if (!isset(self::$types[$element]) || $docId <= 0) {
			return null;
		}
		$head = $this->readHeader($element, $docId);
		if ($head === null) {
			return null;
		}
		$customer = $this->customerContext($head['socid'], $element, $docId);
		$doctypeEnabled = (bool) getDolGlobalInt(self::$types[$element]['const'], 1);
		if ($element === 'facture' && !in_array($head['type'], array(0, 1, 2), true)) {
			$doctypeEnabled = false;
		}
		$feeProductId = getDolGlobalInt('EMRECUPEL_FEE_PRODUCT_ID');
		$countryRule = getDolGlobalString('EMRECUPEL_COUNTRY_RULE', EmrecupelFeePolicy::COUNTRY_BE_ONLY);
		$settings = self::displaySettings();
		$preferTtc = getDolGlobalInt('EMRECUPEL_PRICE_BASE_TTC', 1) === 1;
		$isCredit = ($element === 'facture' && (int) $head['type'] === 2);
		$export = EmrecupelFeePolicy::isExport($customer['country'], EmrecupelFeePolicy::COUNTRY_BE_ONLY);

		$lines = $this->readLines($element, $docId);
		$out = array();
		foreach ($lines as $l) {
			if ($l['cat'] !== '') {
				continue;
			}
			$isProduct = ($l['fk_product'] > 0 && $l['fk_product'] != $feeProductId
				&& in_array((int) $l['product_type'], array(0, 1), true)
				&& (int) $l['special_code'] === 0 && (((int) $l['info_bits']) & 2) === 0);
			if (!$isProduct) {
				continue;
			}
			$prod = $this->products->get($l['fk_product']);
			if (!$prod['classified'] && empty($prod['items'])) {
				continue;
			}
			$origin = EmrecupelFeePolicy::effectiveOrigin($prod['origin'], self::defaultOrigin());
			$allOptedOut = !$forceOptOutReset && EmrecupelFeePolicy::isOptOutKey($l['link']);
			$fees = array();
			foreach ($prod['items'] as $item) {
				$optedOut = $allOptedOut || (!$forceOptOutReset && in_array($item['code'], $l['optout'], true));
				$cat = $this->catalog->getCategory($item['code']);
				$rate = ($cat !== null) ? $this->catalog->getRateInfo($item['code'], $head['rate_date']) : null;
				$useType = $cat !== null ? $cat['use_type'] : 'household';
				$feeType = $rate !== null ? $rate['fee_type'] : '';
				$decision = EmrecupelFeePolicy::decide(array(
					'doctype_enabled' => $doctypeEnabled,
					'is_product_line' => true,
					'product_exempt' => $prod['status'] === EmrecupelProduct::STATUS_EXEMPT,
					'opted_out' => $optedOut,
					'qty' => $l['qty'],
					'category_code' => $item['code'],
					'category_active' => ($cat !== null && !empty($cat['active'])),
					'tariff_amount' => $rate !== null ? $rate['ht'] : null,
					'customer_role' => $customer['role'],
					'customer_country' => $customer['country'],
					'country_rule' => $countryRule,
					'origin' => $origin,
				));
				$mode = EmrecupelFeePolicy::displayMode($useType, $feeType, $customer['role'], $settings, $customer['mode']);
				$price = null;
				$included = null;
				if ($rate !== null) {
					$price = EmrecupelFeePolicy::linePrice($rate['ht'], $rate['ttc'], $item['units'], $l['tva_tx'], $preferTtc);
					$included = EmrecupelFeePolicy::includedAmount($rate['ht'], $rate['ttc'], $item['units'], $l['tva_tx']);
				}
				$fees[] = array(
					'code' => $item['code'],
					'label' => $this->catalog->getLabel($item['code'], $customer['lang']),
					'units' => $item['units'],
					'use_type' => $useType,
					'fee_type' => $feeType,
					'decision' => $decision,
					'mode' => $mode,
					'rate_ht' => $rate !== null ? $rate['ht'] : null,
					'rate_ttc' => $rate !== null ? $rate['ttc'] : null,
					'price' => $price,
					'included_ttc' => $included,
					'declared_units' => EmrecupelFeePolicy::declaredUnits($l['qty'], $item['units'], $isCredit),
				);
			}
			$l['origin'] = $origin;
			$l['status'] = $prod['status'];
			$l['fees'] = $fees;
			$out[] = $l;
		}
		return array('doc' => $head + array('customer' => $customer, 'export' => $export, 'is_credit' => $isCredit), 'lines' => $out);
	}

	// ---------------------------------------------------------------------
	// Reconciliation
	// ---------------------------------------------------------------------

	/**
	 * Bring the fee lines of a draft document in line with its product lines.
	 *
	 * Options:
	 *  - only_line (int)     restrict creation/update to this product line
	 *                        (orphan cleanup still runs on the whole document)
	 *  - reset_optout (bool) forget fees removed by hand ("Recalculate")
	 *
	 * @param string              $element propal, commande or facture
	 * @param int                 $docId   Document id
	 * @param User                $user    Current user
	 * @param array<string,mixed> $options Options
	 * @return int Number of changes, <0 on error
	 */
	public function reconcile($element, $docId, $user, array $options = array())
	{
		$this->changes = 0;
		$this->error = '';
		if (!isset(self::$types[$element]) || $docId <= 0) {
			return 0;
		}
		$doc = $this->loadDraft($element, $docId);
		if ($doc === null) {
			return 0;
		}
		$onlyLine = isset($options['only_line']) ? (int) $options['only_line'] : 0;
		$resetOptOut = !empty($options['reset_optout']);
		$feeProductId = getDolGlobalInt('EMRECUPEL_FEE_PRODUCT_ID');

		if ($resetOptOut) {
			foreach ($this->readLines($element, $docId) as $l) {
				if ($l['cat'] === '' && EmrecupelFeePolicy::isOptOutKey($l['link'])) {
					$this->setLineLink($element, $l['id'], ltrim($l['link'], '-'));
				}
				if ($l['cat'] === '' && !empty($l['optout'])) {
					$this->setLineOptOut($element, $l['id'], array());
				}
			}
		}

		$analysis = $this->analyse($element, $docId);
		if ($analysis === null) {
			return 0;
		}
		$customer = $analysis['doc']['customer'];
		$allLines = $this->readLines($element, $docId);

		// Existing fee lines by key then code.
		$fees = array();
		foreach ($allLines as $l) {
			if ($l['cat'] !== '' && $l['link'] !== '') {
				$fees[$l['link']][$l['cat']][] = $l;
			}
		}

		$usedKeys = array();
		$seenKeys = array();
		$error = 0;

		foreach ($analysis['lines'] as $l) {
			$key = $l['link'];
			$plainKey = ltrim($key, '-');
			// Two product lines carrying the same key (a line duplicated by hand
			// with its extrafields): the second one gets its own key.
			if ($plainKey !== '' && isset($seenKeys[$plainKey])) {
				$key = '';
				$plainKey = '';
			}
			if ($plainKey !== '') {
				$seenKeys[$plainKey] = true;
			}
			if ($onlyLine > 0 && $l['id'] !== $onlyLine) {
				if ($plainKey !== '') {
					$usedKeys[$plainKey] = true;
				}
				continue;
			}

			$wanted = array();
			foreach ($l['fees'] as $fee) {
				if ($fee['decision'] === EmrecupelFeePolicy::APPLY && $fee['mode'] === EmrecupelFeePolicy::MODE_LINE) {
					$wanted[$fee['code']] = $fee;
				}
			}

			$existing = ($plainKey !== '' && isset($fees[$plainKey])) ? $fees[$plainKey] : array();
			if (empty($wanted)) {
				foreach ($existing as $list) {
					foreach ($list as $f) {
						$error += ($this->deleteLine($doc, $element, $f['id'], $user) < 0) ? 1 : 0;
					}
				}
				if ($plainKey !== '') {
					$usedKeys[$plainKey] = true;
				}
				continue;
			}

			if ($key === '' || EmrecupelFeePolicy::isOptOutKey($key)) {
				$key = EmrecupelFeePolicy::newLinkKey();
				if ($this->setLineLink($element, $l['id'], $key) < 0) {
					$error++;
					continue;
				}
				$plainKey = $key;
				$existing = array();
			}
			$usedKeys[$plainKey] = true;

			foreach ($wanted as $code => $fee) {
				$expected = array(
					'qty' => $l['qty'],
					'subprice' => $fee['price']['subprice'],
					'base' => $fee['price']['base'],
					'price' => $fee['price']['price'],
					'tva_tx' => $l['tva_tx'],
					'vat_src_code' => $l['vat_src_code'],
					'localtax1_tx' => $l['localtax1_tx'],
					'localtax2_tx' => $l['localtax2_tx'],
					'npr' => ((int) $l['info_bits']) & 1,
					'category_code' => $code,
					'units' => $fee['units'],
					'desc' => $this->feeDescription($code, (float) $fee['rate_ht'], $fee['units'], $customer['lang']),
				);
				$list = isset($existing[$code]) ? $existing[$code] : array();
				$current = array_shift($list);
				foreach ($list as $dup) {
					$error += ($this->deleteLine($doc, $element, $dup['id'], $user) < 0) ? 1 : 0;
				}
				if ($current === null) {
					$error += ($this->addFeeLine($doc, $element, $plainKey, $expected, $feeProductId) < 0) ? 1 : 0;
				} elseif (EmrecupelFeePolicy::feeLineDiffers($current, $expected) || $current['desc'] !== $expected['desc']) {
					$error += ($this->updateFeeLine($doc, $element, $current['id'], $plainKey, $expected) < 0) ? 1 : 0;
				}
				unset($existing[$code]);
			}
			// Codes no longer on the product.
			foreach ($existing as $list) {
				foreach ($list as $f) {
					$error += ($this->deleteLine($doc, $element, $f['id'], $user) < 0) ? 1 : 0;
				}
			}
		}

		// Orphan fee lines: product line gone, or fee copied without it.
		if ($onlyLine === 0 || !empty($fees)) {
			foreach ($fees as $key => $byCode) {
				if (isset($usedKeys[$key])) {
					continue;
				}
				foreach ($byCode as $list) {
					foreach ($list as $f) {
						$error += ($this->deleteLine($doc, $element, $f['id'], $user) < 0) ? 1 : 0;
					}
				}
			}
		}

		if ($this->changes > 0) {
			$this->reorder($doc, $element, $docId);
		}

		return $error ? -$error : $this->changes;
	}

	/**
	 * Reconcile a document later (document creation trigger or end of request).
	 *
	 * Used for lines that arrive through a copy: when an order becomes an
	 * invoice, product lines and fee lines are inserted one by one and only
	 * the complete set tells which fee is missing or orphaned.
	 *
	 * @param string $element Element
	 * @param int    $docId   Document id
	 * @return void
	 */
	public static function schedule($element, $docId)
	{
		self::$pending[$element][(int) $docId] = true;
		if (!self::$shutdownRegistered) {
			self::$shutdownRegistered = true;
			register_shutdown_function(array(__CLASS__, 'runPending'));
		}
	}

	/**
	 * Tell whether a document waits for a deferred reconciliation.
	 *
	 * @param string $element Element
	 * @param int    $docId   Id
	 * @return bool
	 */
	public static function isPending($element, $docId)
	{
		return isset(self::$pending[$element][(int) $docId]);
	}

	/**
	 * Process scheduled documents (shutdown function, also called by hooks
	 * before a document card is rendered).
	 *
	 * @return void
	 */
	public static function runPending()
	{
		global $db, $user;
		if (empty(self::$pending) || !is_object($db)) {
			return;
		}
		if (empty($db->connected)) {
			foreach (self::$pending as $element => $ids) {
				dol_syslog('emrecupel: deferred reconciliation of ' . $element . ' ' . implode(',', array_keys($ids)) . ' lost (database closed); use "Recalculate" on these drafts', LOG_WARNING);
			}
			self::$pending = array();
			return;
		}
		$pending = self::$pending;
		self::$pending = array();
		$sync = new self($db);
		foreach ($pending as $element => $ids) {
			foreach (array_keys($ids) as $id) {
				try {
					if ($sync->reconcile($element, (int) $id, $user) > 0) {
						self::$changedDocs[$element][(int) $id] = true;
					}
				} catch (\Throwable $e) {
					dol_syslog('emrecupel: deferred reconcile of ' . $element . ' ' . $id . ' failed: ' . $e->getMessage(), LOG_ERR);
				}
			}
		}
	}

	// ---------------------------------------------------------------------
	// Declaration ledger
	// ---------------------------------------------------------------------

	/**
	 * Record the appliances of a validated invoice (replaces previous rows).
	 *
	 * Every classified product line is recorded, fee charged or not: an
	 * appliance imported and sold in Belgium is placed on the market even if
	 * the fee line was removed by hand.
	 *
	 * @param int $invoiceId Invoice id
	 * @return int Number of rows written, <0 on error
	 */
	public function writeLedger($invoiceId)
	{
		global $conf;
		$invoiceId = (int) $invoiceId;
		$head = $this->readHeader('facture', $invoiceId);
		if ($head === null || $head['status'] <= 0 || !in_array($head['type'], array(0, 1, 2), true)) {
			return 0;
		}
		$analysis = $this->analyse('facture', $invoiceId);
		if ($analysis === null) {
			return 0;
		}
		$this->db->begin();
		if ($this->deleteLedger($invoiceId) < 0) {
			$this->db->rollback();
			return -1;
		}
		$export = $analysis['doc']['export'];
		$country = $analysis['doc']['customer']['country'];
		$n = 0;
		$now = $this->db->idate(dol_now());
		foreach ($analysis['lines'] as $l) {
			if ($l['status'] === EmrecupelProduct::STATUS_EXEMPT || empty($l['fees'])) {
				continue;
			}
			// Weight of professional appliances: product weight, shared between
			// its professional codes in proportion of their units.
			$weight = $this->products->weightKg($l['fk_product']);
			$proUnits = 0.0;
			foreach ($l['fees'] as $fee) {
				if ($fee['use_type'] === 'professional') {
					$proUnits += $fee['units'];
				}
			}
			foreach ($l['fees'] as $fee) {
				// Only codes known, active and priced at the date are declarable (a
				// parent code 1-6 or a disabled code never reaches the report).
				$catRow = $this->catalog->getCategory($fee['code']);
				if ($fee['rate_ht'] === null || $catRow === null || empty($catRow['active']) || $this->catalog->hasChildren($fee['code'])
					|| in_array($fee['decision'], array(EmrecupelFeePolicy::SKIP_NO_CATEGORY, EmrecupelFeePolicy::SKIP_CATEGORY_INACTIVE, EmrecupelFeePolicy::SKIP_NO_TARIFF, EmrecupelFeePolicy::SKIP_NOT_PRODUCT, EmrecupelFeePolicy::SKIP_ZERO_QTY), true)) {
					continue;
				}
				$units = $fee['declared_units'];
				$w = null;
				if ($fee['use_type'] === 'professional' && $weight !== null && $proUnits > 0) {
					$w = round($weight * $l['qty'] * ($fee['units'] / $proUnits), 6);
					if ($analysis['doc']['is_credit']) {
						$w = -abs($w);
					}
				}
				$bucket = EmrecupelFeePolicy::bucket($l['origin'], $export);
				$rate = $fee['rate_ht'];
				$sql = "INSERT INTO " . MAIN_DB_PREFIX . "emrecupel_ledger (entity, fk_facture, fk_facturedet, fk_product, invoice_type, date_doc, code, use_type, fee_type, units, weight_kg, origin, country_code, export, bucket, rate_ht, amount_ht, decision, display_mode, date_creation) VALUES (";
				$sql .= (int) $head['entity'] . ", " . $invoiceId . ", " . (int) $l['id'] . ", " . (int) $l['fk_product'] . ", " . (int) $head['type'];
				$sql .= ", '" . $this->db->escape($head['date']) . "', '" . $this->db->escape($fee['code']) . "', '" . $this->db->escape($fee['use_type']) . "', '" . $this->db->escape($fee['fee_type'] !== '' ? $fee['fee_type'] : 'allin') . "'";
				$sql .= ", " . price2num($units, 'MU') . ", " . ($w === null ? "NULL" : price2num($w, 'MU'));
				$sql .= ", '" . $this->db->escape($l['origin']) . "', '" . $this->db->escape($country) . "', " . ($export ? 1 : 0) . ", '" . $this->db->escape($bucket) . "'";
				$sql .= ", " . ($rate === null ? "NULL" : price2num($rate, 'MU')) . ", " . ($rate === null ? "NULL" : price2num($rate * $units, 'MT'));
				$sql .= ", '" . $this->db->escape($fee['decision']) . "', '" . $this->db->escape($fee['mode']) . "', '" . $now . "')";
				if (!$this->db->query($sql)) {
					$this->error = $this->db->lasterror();
					dol_syslog('emrecupel: writeLedger of invoice ' . $head['ref'] . ' failed: ' . $this->error, LOG_ERR);
					$this->db->rollback();
					return -1;
				}
				$n++;
			}
		}
		$this->db->commit();
		return $n;
	}

	/**
	 * Remove the ledger rows of an invoice (back to draft, deletion).
	 *
	 * @param int $invoiceId Invoice id
	 * @return int
	 */
	public function deleteLedger($invoiceId)
	{
		if (!$this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "emrecupel_ledger WHERE fk_facture = " . (int) $invoiceId)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Rebuild the ledger of the validated invoices of a period, with the
	 * current product classification (after a late classification, or after
	 * an upgrade from v1).
	 *
	 * @param string $from YYYY-MM-DD
	 * @param string $to   YYYY-MM-DD
	 * @return array{invoices:int,rows:int,errors:int}
	 */
	public function rebuildLedger($from, $to)
	{
		global $conf;
		$res = array('invoices' => 0, 'rows' => 0, 'errors' => 0);
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "facture WHERE entity = " . (int) $conf->entity . " AND fk_statut > 0 AND type IN (0,1,2)";
		$sql .= " AND datef >= '" . $this->db->escape($from) . "' AND datef <= '" . $this->db->escape($to) . "'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$res['errors']++;
			return $res;
		}
		$ids = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$ids[] = (int) $obj->rowid;
		}
		foreach ($ids as $id) {
			$n = $this->writeLedger($id);
			if ($n < 0) {
				$res['errors']++;
			} else {
				$res['invoices']++;
				$res['rows'] += $n;
			}
		}
		return $res;
	}

	// ---------------------------------------------------------------------
	// Context
	// ---------------------------------------------------------------------

	/**
	 * Read the header fields needed for the analysis.
	 *
	 * @param string $element Element
	 * @param int    $docId   Id
	 * @return array{id:int,element:string,entity:int,socid:int,status:int,type:int,date:string,ref:string}|null
	 */
	public function readHeader($element, $docId)
	{
		if ($element === 'propal') {
			$sql = "SELECT rowid, entity, ref, fk_soc, fk_statut as status, 0 as type, datep as docdate FROM " . MAIN_DB_PREFIX . "propal";
		} elseif ($element === 'commande') {
			$sql = "SELECT rowid, entity, ref, fk_soc, fk_statut as status, 0 as type, date_commande as docdate FROM " . MAIN_DB_PREFIX . "commande";
		} elseif ($element === 'facture') {
			$sql = "SELECT f.rowid, f.entity, f.ref, f.fk_soc, f.fk_statut as status, f.type, f.datef as docdate, src.datef as srcdate FROM " . MAIN_DB_PREFIX . "facture as f";
			$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture as src ON src.rowid = f.fk_facture_source AND f.type IN (1, 2)";
		} else {
			return null;
		}
		$sql .= " WHERE " . ($element === 'facture' ? "f." : "") . "rowid = " . (int) $docId;
		$resql = $this->db->query($sql);
		if (!$resql || !($obj = $this->db->fetch_object($resql))) {
			return null;
		}
		$date = !empty($obj->docdate) ? substr((string) $obj->docdate, 0, 10) : dol_print_date(dol_now(), '%Y-%m-%d');
		// A credit note or a replacement invoice gives back / replaces what was
		// invoiced: rate of the source invoice date, not of its own date.
		$rateDate = !empty($obj->srcdate) ? substr((string) $obj->srcdate, 0, 10) : $date;
		return array(
			'id' => (int) $obj->rowid,
			'element' => $element,
			'entity' => (int) $obj->entity,
			'ref' => (string) $obj->ref,
			'socid' => (int) $obj->fk_soc,
			'status' => (int) $obj->status,
			'type' => (int) $obj->type,
			'date' => $date,
			'rate_date' => $rateDate,
		);
	}

	/**
	 * Load a document if it exists, is a draft and can carry fee lines.
	 *
	 * @param string $element Element
	 * @param int    $docId   Id
	 * @return CommonObject|null
	 */
	private function loadDraft($element, $docId)
	{
		$head = $this->readHeader($element, $docId);
		if ($head === null || $head['status'] !== 0) {
			return null;
		}
		// Invoices: standard, replacement and credit note only. Deposit and
		// situation invoices carry percentages of other documents.
		if ($element === 'facture' && !in_array($head['type'], array(0, 1, 2), true)) {
			return null;
		}
		if (!getDolGlobalInt(self::$types[$element]['const'], 1)) {
			return null;
		}
		$def = self::$types[$element];
		require_once DOL_DOCUMENT_ROOT . $def['file'];
		$class = $def['class'];
		$doc = new $class($this->db);
		if ($doc->fetch((int) $docId) <= 0) {
			return null;
		}
		return $doc;
	}

	/**
	 * Customer data needed by the policy.
	 *
	 * The country is the one of the delivery contact of the document when
	 * there is one, otherwise the one of the customer.
	 *
	 * @param int    $socid   Third party id
	 * @param string $element Document element ('' = none)
	 * @param int    $docId   Document id
	 * @return array{role:string,mode:string,country:string,lang:string}
	 */
	public function customerContext($socid, $element = '', $docId = 0)
	{
		global $langs;
		$ctx = array('role' => EmrecupelFeePolicy::ROLE_ENDUSER, 'mode' => '', 'country' => '', 'lang' => '');
		if ($socid > 0) {
			$sql = "SELECT s.default_lang, c.code as country_code, e.emrecupel_role, e.emrecupel_mode";
			$sql .= " FROM " . MAIN_DB_PREFIX . "societe as s";
			$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_country as c ON c.rowid = s.fk_pays";
			$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe_extrafields as e ON e.fk_object = s.rowid";
			$sql .= " WHERE s.rowid = " . (int) $socid;
			$resql = $this->db->query($sql);
			if (!$resql) {
				// Extrafield columns missing on a broken install: retry without them.
				$sql = "SELECT s.default_lang, c.code as country_code FROM " . MAIN_DB_PREFIX . "societe as s";
				$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "c_country as c ON c.rowid = s.fk_pays WHERE s.rowid = " . (int) $socid;
				$resql = $this->db->query($sql);
			}
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				$role = isset($obj->emrecupel_role) ? (string) $obj->emrecupel_role : '';
				if (in_array($role, array(EmrecupelFeePolicy::ROLE_RESELLER, EmrecupelFeePolicy::ROLE_EXEMPT), true)) {
					$ctx['role'] = $role;
				}
				$mode = isset($obj->emrecupel_mode) ? (string) $obj->emrecupel_mode : '';
				if (in_array($mode, EmrecupelFeePolicy::modes(), true)) {
					$ctx['mode'] = $mode;
				}
				$ctx['country'] = (string) $obj->country_code;
				$ctx['lang'] = (string) $obj->default_lang;
			}
		}
		if ($element !== '' && $docId > 0 && isset(self::$types[$element])) {
			$sql = "SELECT c.code FROM " . MAIN_DB_PREFIX . "element_contact as ec";
			$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "c_type_contact as tc ON tc.rowid = ec.fk_c_type_contact";
			$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "socpeople as sp ON sp.rowid = ec.fk_socpeople";
			$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "c_country as c ON c.rowid = sp.fk_pays";
			$sql .= " WHERE ec.element_id = " . (int) $docId . " AND tc.element = '" . $this->db->escape(self::$types[$element]['contact_element']) . "'";
			$sql .= " AND tc.source = 'external' AND tc.code = 'SHIPPING'";
			$sql .= " ORDER BY ec.rowid ASC";
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql)) && !empty($obj->code)) {
				$ctx['country'] = (string) $obj->code;
			}
		}
		if ($ctx['lang'] === '' || $ctx['lang'] === 'auto') {
			$ctx['lang'] = getDolGlobalString('MAIN_LANG_DEFAULT', 'fr_BE');
			if ($ctx['lang'] === 'auto') {
				$ctx['lang'] = is_object($langs) ? $langs->defaultlang : 'fr_BE';
			}
		}
		return $ctx;
	}

	/**
	 * Read the lines of a document with their Recupel extrafields.
	 *
	 * @param string $element Element
	 * @param int    $docId   Id
	 * @return array<int,array<string,mixed>>
	 */
	public function readLines($element, $docId)
	{
		$def = self::$types[$element];
		$lines = array();
		$sql = "SELECT d.rowid, d.fk_product, d.qty, d.subprice, d.tva_tx, d.vat_src_code, d.localtax1_tx, d.localtax2_tx, d.product_type, d.special_code, d.info_bits, d.rang, d.description,";
		$sql .= " d.total_ht, d.total_ttc, e.emrecupel_link, e.emrecupel_cat, e.emrecupel_units, e.emrecupel_optout";
		$sql .= " FROM " . MAIN_DB_PREFIX . $def['line_table'] . " as d";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . $def['line_table'] . "_extrafields as e ON e.fk_object = d.rowid";
		$sql .= " WHERE d." . $def['fk'] . " = " . (int) $docId;
		$sql .= " ORDER BY d.rang ASC, d.rowid ASC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			dol_syslog('emrecupel: readLines ' . $this->error, LOG_ERR);
			return $lines;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$lines[] = array(
				'id' => (int) $obj->rowid,
				'fk_product' => (int) $obj->fk_product,
				'qty' => (float) $obj->qty,
				'subprice' => (float) $obj->subprice,
				'tva_tx' => (float) $obj->tva_tx,
				'vat_src_code' => (string) $obj->vat_src_code,
				'localtax1_tx' => (float) $obj->localtax1_tx,
				'localtax2_tx' => (float) $obj->localtax2_tx,
				'product_type' => (int) $obj->product_type,
				'special_code' => (int) $obj->special_code,
				'info_bits' => (int) $obj->info_bits,
				'rang' => (int) $obj->rang,
				'desc' => (string) $obj->description,
				'total_ht' => (float) $obj->total_ht,
				'total_ttc' => (float) $obj->total_ttc,
				'link' => trim((string) $obj->emrecupel_link),
				'cat' => trim((string) $obj->emrecupel_cat),
				'category_code' => trim((string) $obj->emrecupel_cat),
				'units' => (!empty($obj->emrecupel_units) ? (float) $obj->emrecupel_units : 1.0),
				'optout' => array_values(array_filter(array_map('trim', explode(',', (string) $obj->emrecupel_optout)), 'strlen')),
			);
		}
		return $lines;
	}

	/**
	 * Description of the fee line in the customer language.
	 *
	 * @param string $code     Category code
	 * @param float  $rate     Unit rate excl. VAT
	 * @param float  $units    Appliances per unit
	 * @param string $langcode Customer language
	 * @return string
	 */
	public function feeDescription($code, $rate, $units, $langcode)
	{
		$short = strtoupper(substr((string) $langcode, 0, 2));
		if (!in_array($short, array('FR', 'NL', 'EN'), true)) {
			$short = 'EN';
		}
		$defaults = self::defaultLineTemplates();
		$template = getDolGlobalString('EMRECUPEL_LINE_LABEL_' . $short, $defaults[$short]);
		$label = $this->catalog->getLabel($code, $langcode);
		return EmrecupelFeePolicy::buildDescription($template, $code, $label, price($rate, 0, '', 1, -1, 2), $units);
	}

	/**
	 * Default templates of the fee line description.
	 *
	 * @return array<string,string>
	 */
	public static function defaultLineTemplates()
	{
		// The fee lines carry the RECUPEL service, whose translated label
		// ("Cotisation Recupel", "Recupelbijdrage", "Recupel fee") is printed
		// before the description: the description only names the category.
		return array(
			'FR' => 'Cat. {CODE} – {LABEL}',
			'NL' => 'Cat. {CODE} – {LABEL}',
			'EN' => 'Cat. {CODE} – {LABEL}',
		);
	}

	// ---------------------------------------------------------------------
	// Writes
	// ---------------------------------------------------------------------

	/**
	 * VAT rate string for addline/updateline, with the VAT code.
	 *
	 * @param array<string,mixed> $exp Expected values
	 * @return string|float
	 */
	private static function vatArg(array $exp)
	{
		return $exp['vat_src_code'] !== '' ? $exp['tva_tx'] . ' (' . $exp['vat_src_code'] . ')' : $exp['tva_tx'];
	}

	/**
	 * @param CommonObject        $doc          Document
	 * @param string              $element      Element
	 * @param string              $key          Link key
	 * @param array<string,mixed> $exp          Expected values
	 * @param int                 $feeProductId Service product used for fee lines (0 = free line)
	 * @return int
	 */
	private function addFeeLine($doc, $element, $key, array $exp, $feeProductId)
	{
		$vat = self::vatArg($exp);
		$options = array(
			'options_emrecupel_link' => $key,
			'options_emrecupel_cat' => $exp['category_code'],
			'options_emrecupel_units' => $exp['units'],
		);
		$base = $exp['base'];
		// Quantity keeps its sign on a proposal, order or standard invoice (a
		// returned appliance gives a negative fee); a credit note stores positive
		// quantities and negative prices (forced by Facture::addline).
		$isCredit = ($element === 'facture' && (int) $doc->type === Facture::TYPE_CREDIT_NOTE);
		$pu = abs((float) $exp['price']);
		$qty = $isCredit ? abs((float) $exp['qty']) : (float) $exp['qty'];
		if ($isCredit) {
			$pu = -$pu;
		}
		$puHt = $base === 'HT' ? $pu : 0;
		$puTtc = $base === 'TTC' ? $pu : 0;
		$lt1 = $exp['localtax1_tx'];
		$lt2 = $exp['localtax2_tx'];
		$npr = (int) $exp['npr'];

		self::$busy = true;
		try {
			if ($element === 'propal') {
				$res = $doc->addline($exp['desc'], $puHt, $qty, $vat, $lt1, $lt2, $feeProductId, 0, $base, $puTtc, $npr, 1, -1, 0, 0, 0, 0, '', '', '', $options);
			} elseif ($element === 'commande') {
				$res = $doc->addline($exp['desc'], $puHt, $qty, $vat, $lt1, $lt2, $feeProductId, 0, $npr, 0, $base, $puTtc, '', '', 1, -1, 0, 0, null, 0, '', $options);
			} else {
				$res = $doc->addline($exp['desc'], $puHt, $qty, $vat, $lt1, $lt2, $feeProductId, 0, '', '', 0, $npr, 0, $base, $puTtc, 1, -1, 0, '', 0, 0, null, 0, '', $options);
			}
		} catch (\Throwable $e) {
			$res = -1;
			$this->error = $e->getMessage();
		} finally {
			self::$busy = false;
		}
		if ($res <= 0) {
			$this->error = $this->error ?: $doc->error;
			dol_syslog('emrecupel: addline on ' . $element . ' ' . $doc->id . ' failed: ' . $this->error, LOG_ERR);
			return -1;
		}
		$this->changes++;
		return (int) $res;
	}

	/**
	 * @param CommonObject        $doc     Document
	 * @param string              $element Element
	 * @param int                 $lineId  Fee line id
	 * @param string              $key     Link key
	 * @param array<string,mixed> $exp     Expected values
	 * @return int
	 */
	private function updateFeeLine($doc, $element, $lineId, $key, array $exp)
	{
		$vat = self::vatArg($exp);
		$options = array(
			'options_emrecupel_link' => $key,
			'options_emrecupel_cat' => $exp['category_code'],
			'options_emrecupel_units' => $exp['units'],
		);
		$base = $exp['base'];
		// Same sign rules as addFeeLine(); Facture::updateline() does not force a
		// negative price on a credit note when FACTURE_ENABLE_NEGATIVE_LINES or
		// INVOICE_KEEP_DISCOUNT_LINES_AS_IN_ORIGIN is set.
		$isCredit = ($element === 'facture' && (int) $doc->type === Facture::TYPE_CREDIT_NOTE);
		$pu = abs((float) $exp['price']);
		$qty = $isCredit ? abs((float) $exp['qty']) : (float) $exp['qty'];
		if ($isCredit) {
			$pu = -$pu;
		}
		$lt1 = $exp['localtax1_tx'];
		$lt2 = $exp['localtax2_tx'];
		$npr = (int) $exp['npr'];

		self::$busy = true;
		try {
			if ($element === 'propal') {
				$res = $doc->updateline($lineId, $pu, $qty, 0, $vat, $lt1, $lt2, $exp['desc'], $base, $npr, 0, 0, 0, 0, 0, '', 1, '', '', $options, null, 0, 1);
			} elseif ($element === 'commande') {
				$res = $doc->updateline($lineId, $exp['desc'], $pu, $qty, 0, $vat, $lt1, $lt2, $base, $npr, '', '', 1, 0, 0, null, 0, '', 0, $options, null, 0, 1);
			} else {
				$res = $doc->updateline($lineId, $exp['desc'], $pu, $qty, 0, '', '', $vat, $lt1, $lt2, $base, $npr, 1, 0, 0, null, 0, '', 0, $options, 100, null, 0, 1);
			}
		} catch (\Throwable $e) {
			$res = -1;
			$this->error = $e->getMessage();
		} finally {
			self::$busy = false;
		}
		if ($res < 0) {
			$this->error = $this->error ?: $doc->error;
			dol_syslog('emrecupel: updateline on ' . $element . ' ' . $doc->id . ' failed: ' . $this->error, LOG_ERR);
			return -1;
		}
		$this->changes++;
		return 1;
	}

	/**
	 * @param CommonObject $doc     Document
	 * @param string       $element Element
	 * @param int          $lineId  Line id
	 * @param User         $user    User
	 * @return int
	 */
	private function deleteLine($doc, $element, $lineId, $user)
	{
		self::$busy = true;
		try {
			if ($element === 'commande') {
				$res = $doc->deleteline($user, $lineId);
			} else {
				$res = $doc->deleteline($lineId);
			}
		} catch (\Throwable $e) {
			$res = -1;
			$this->error = $e->getMessage();
		} finally {
			self::$busy = false;
		}
		if ($res < 0) {
			$this->error = $this->error ?: $doc->error;
			dol_syslog('emrecupel: deleteline ' . $lineId . ' on ' . $element . ' failed: ' . $this->error, LOG_ERR);
			return -1;
		}
		$this->changes++;
		return 1;
	}

	/**
	 * Store the link key on a product line (extrafield only, no trigger).
	 *
	 * @param string $element Element
	 * @param int    $lineId  Line id
	 * @param string $key     Key
	 * @return int
	 */
	private function setLineLink($element, $lineId, $key)
	{
		$table = MAIN_DB_PREFIX . self::$types[$element]['line_table'] . '_extrafields';
		$sql = "SELECT rowid FROM " . $table . " WHERE fk_object = " . (int) $lineId;
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		if ($this->db->fetch_object($resql)) {
			$sql = "UPDATE " . $table . " SET emrecupel_link = '" . $this->db->escape($key) . "' WHERE fk_object = " . (int) $lineId;
		} else {
			$sql = "INSERT INTO " . $table . " (fk_object, emrecupel_link) VALUES (" . (int) $lineId . ", '" . $this->db->escape($key) . "')";
		}
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			dol_syslog('emrecupel: setLineLink ' . $this->error, LOG_ERR);
			return -1;
		}
		return 1;
	}

	/**
	 * Store the codes removed by hand on a product line.
	 *
	 * @param string   $element Element
	 * @param int      $lineId  Line id
	 * @param string[] $codes   Codes
	 * @return int
	 */
	private function setLineOptOut($element, $lineId, array $codes)
	{
		$table = MAIN_DB_PREFIX . self::$types[$element]['line_table'] . '_extrafields';
		$value = implode(',', array_unique($codes));
		$sql = "UPDATE " . $table . " SET emrecupel_optout = " . ($value === '' ? "NULL" : "'" . $this->db->escape($value) . "'") . " WHERE fk_object = " . (int) $lineId;
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Recupel extrafields of one line, read from the database.
	 *
	 * @param string $element Element
	 * @param int    $lineId  Line id
	 * @return array{link:string,cat:string}
	 */
	private function readLineRecupel($element, $lineId)
	{
		$res = array('link' => '', 'cat' => '');
		if ($lineId <= 0) {
			return $res;
		}
		$table = MAIN_DB_PREFIX . self::$types[$element]['line_table'] . '_extrafields';
		$resql = $this->db->query("SELECT emrecupel_link, emrecupel_cat FROM " . $table . " WHERE fk_object = " . (int) $lineId);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$res['link'] = trim((string) $obj->emrecupel_link);
			$res['cat'] = trim((string) $obj->emrecupel_cat);
		}
		return $res;
	}

	/**
	 * Put every fee line right after its product line.
	 *
	 * @param CommonObject $doc     Document
	 * @param string       $element Element
	 * @param int          $docId   Id
	 * @return void
	 */
	private function reorder($doc, $element, $docId)
	{
		$lines = $this->readLines($element, $docId);
		$fees = array();
		foreach ($lines as $l) {
			if ($l['cat'] !== '' && $l['link'] !== '') {
				$fees[$l['link']][] = $l;
			}
		}
		$ordered = array();
		$placed = array();
		foreach ($lines as $l) {
			if ($l['cat'] !== '' && $l['link'] !== '') {
				continue;
			}
			$ordered[] = $l;
			$k = ltrim($l['link'], '-');
			if ($k !== '' && isset($fees[$k])) {
				foreach ($fees[$k] as $f) {
					if (!isset($placed[$f['id']])) {
						$ordered[] = $f;
						$placed[$f['id']] = true;
					}
				}
			}
		}
		foreach ($lines as $l) {
			if ($l['cat'] !== '' && $l['link'] !== '' && !isset($placed[$l['id']])) {
				$ordered[] = $l;
			}
		}
		$rank = 0;
		foreach ($ordered as $l) {
			$rank++;
			if ($l['rang'] !== $rank) {
				$doc->updateRangOfLine($l['id'], $rank);
			}
		}
	}

	/**
	 * Read an extrafield value from a line object.
	 *
	 * @param object $line Line
	 * @param string $name Extrafield name without prefix
	 * @return string
	 */
	private function lineOption($line, $name)
	{
		if (!empty($line->array_options) && isset($line->array_options['options_' . $name])) {
			return trim((string) $line->array_options['options_' . $name]);
		}
		return '';
	}
}
