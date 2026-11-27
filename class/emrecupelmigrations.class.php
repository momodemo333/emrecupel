<?php
/* Copyright (C) 2026 E-dem SRL <support@e-dem.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/emrecupelmigrations.class.php
 * \ingroup emrecupel
 * \brief   Versioned, idempotent setup of everything emRecupel needs in the
 *          database (tables, extrafields, fee product, bundled scale, data of
 *          the 1.x prototype).
 *
 * Dolibarr does not replay init() when module files are updated, so this is
 * also run automatically from the printCommonFooter hook when the stored
 * EMRECUPEL_DB_VERSION differs from MODULE_VERSION.
 *
 * MODULE_VERSION must be bumped together with $this->version of the descriptor.
 */

dol_include_once('/emrecupel/class/emrecupelmigrationhelper.class.php');

class EmrecupelMigrations
{
	/** Keep in sync with modEmrecupel::$version */
	const MODULE_VERSION = '2.0.0';

	/** Tables of the module, in creation order */
	const TABLES = array('emrecupel_cat', 'emrecupel_rate', 'emrecupel_product', 'emrecupel_product_code', 'emrecupel_ledger');

	/** Line tables that carry the link extrafields */
	const LINE_ELEMENTS = array('propaldet', 'commandedet', 'facturedet', 'facturedet_rec');

	/**
	 * @param DoliDB $db Database handler
	 * @return EmrecupelMigrationHelper
	 */
	public static function createHelper($db)
	{
		$helper = new EmrecupelMigrationHelper($db, 'emrecupel', self::MODULE_VERSION);

		$helper->addCallbackMigration('2.0.0', function ($db) {
			return self::check(self::ensureTables($db), 'tables');
		});
		$helper->addCallbackMigration('2.0.0', function ($db) {
			return self::check(self::ensureExtrafields($db), 'extrafields');
		});
		$helper->addCallbackMigration('2.0.0', function ($db) {
			return self::check(self::ensureFeeProduct($db) > 0, 'fee product');
		});
		$helper->addCallbackMigration('2.0.0', function ($db) {
			return self::check(self::importBundledScales($db), 'bundled scales');
		});
		$helper->addCallbackMigration('2.0.0', function ($db) {
			return self::check(self::migrateFromV1($db), 'data of version 1.x');
		});

		return $helper;
	}

	/**
	 * Turn a failed step into an exception, so that the helper reports it and
	 * does not store the new version (the step is retried on the next page).
	 *
	 * @param bool   $ok   Result
	 * @param string $what Step
	 * @return bool
	 * @throws Exception
	 */
	private static function check($ok, $what)
	{
		if (!$ok) {
			throw new Exception('emrecupel migration step failed: ' . $what);
		}
		return true;
	}

	/**
	 * Run pending migrations once per request.
	 *
	 * @param DoliDB $db Database handler
	 * @return void
	 */
	public static function runIfNeeded($db)
	{
		static $done = false;
		if ($done) {
			return;
		}
		$done = true;
		if (getDolGlobalString('EMRECUPEL_DB_VERSION') === self::MODULE_VERSION) {
			return;
		}
		// A step that keeps failing must not replay every migration on every
		// page: stop the automatic attempts after 3 failures for this version
		// (the setup page shows it and "Check and repair" retries by hand).
		$failKey = 'EMRECUPEL_MIGRATION_FAILED';
		$fails = getDolGlobalString($failKey);
		$count = (strpos($fails, self::MODULE_VERSION . ':') === 0) ? (int) substr($fails, strlen(self::MODULE_VERSION) + 1) : 0;
		if ($count >= 3) {
			return;
		}
		$result = self::createHelper($db)->migrateIfNeeded();
		if (!function_exists('dolibarr_set_const')) {
			require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
		}
		global $conf;
		if (!empty($result['errors'])) {
			dol_syslog('emrecupel: auto-migration errors: ' . implode(' | ', $result['errors']), LOG_ERR);
			dolibarr_set_const($db, $failKey, self::MODULE_VERSION . ':' . ($count + 1), 'chaine', 0, '', $conf->entity);
		} elseif ($count > 0) {
			dolibarr_del_const($db, $failKey, $conf->entity);
		}
	}

	/**
	 * Expected schema, for the diagnostic of the setup page.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function getExpectedSchema()
	{
		$schema = array(
			'emrecupel_cat' => array('code' => 'varchar', 'parent_code' => 'varchar', 'label_fr' => 'varchar', 'label_nl' => 'varchar', 'label_en' => 'varchar', 'use_type' => 'varchar', 'active' => 'tinyint', 'selectable' => 'tinyint'),
			'emrecupel_rate' => array('category_code' => 'varchar', 'amount_ht' => 'double', 'amount_ttc' => 'double', 'fee_type' => 'varchar', 'date_start' => 'date', 'date_end' => 'date'),
			'emrecupel_product' => array('fk_product' => 'int', 'status' => 'varchar', 'origin' => 'varchar', 'weight_kg' => 'double'),
			'emrecupel_product_code' => array('fk_product' => 'int', 'code' => 'varchar', 'units' => 'double'),
			'emrecupel_ledger' => array('fk_facture' => 'int', 'fk_facturedet' => 'int', 'code' => 'varchar', 'units' => 'double', 'bucket' => 'varchar', 'date_doc' => 'date'),
			'societe_extrafields' => array('emrecupel_role' => 'varchar', 'emrecupel_mode' => 'varchar'),
		);
		foreach (self::LINE_ELEMENTS as $el) {
			$schema[$el . '_extrafields'] = array('emrecupel_link' => 'varchar', 'emrecupel_cat' => 'varchar', 'emrecupel_units' => 'double', 'emrecupel_optout' => 'varchar');
		}
		return $schema;
	}

	/**
	 * Tell whether a table exists.
	 *
	 * @param DoliDB $db    Database handler
	 * @param string $table Table name without prefix
	 * @return bool
	 */
	public static function tableExists($db, $table)
	{
		$resql = $db->query("SHOW TABLES LIKE '" . $db->escape(MAIN_DB_PREFIX . $table) . "'");
		return $resql && $db->num_rows($resql) > 0;
	}

	/**
	 * Tell whether a column exists.
	 *
	 * @param DoliDB $db     Database handler
	 * @param string $table  Table name without prefix
	 * @param string $column Column
	 * @return bool
	 */
	public static function columnExists($db, $table, $column)
	{
		$resql = $db->query("SHOW COLUMNS FROM " . MAIN_DB_PREFIX . $table . " LIKE '" . $db->escape($column) . "'");
		return $resql && $db->num_rows($resql) > 0;
	}

	/**
	 * Create module tables when missing (file-only upgrade path).
	 *
	 * @param DoliDB $db Database handler
	 * @return bool
	 */
	public static function ensureTables($db)
	{
		$dir = dol_buildpath('/emrecupel/sql/', 0);
		$ok = true;
		foreach (self::TABLES as $table) {
			if (self::tableExists($db, $table)) {
				continue;
			}
			foreach (array($dir . 'llx_' . $table . '.sql', $dir . 'llx_' . $table . '.key.sql') as $file) {
				$content = (string) @file_get_contents($file);
				$content = preg_replace('/^--.*$/m', '', $content);
				foreach (preg_split('/;\s*(\r?\n|$)/', $content) as $stmt) {
					$stmt = trim($stmt);
					if ($stmt === '') {
						continue;
					}
					$stmt = str_replace('llx_', MAIN_DB_PREFIX, $stmt);
					if (!$db->query($stmt)) {
						dol_syslog('emrecupel: ensureTables ' . $db->lasterror(), LOG_ERR);
						$ok = false;
					}
				}
			}
		}
		return $ok;
	}

	/**
	 * Create (or repair) every extrafield used by the module.
	 *
	 * @param DoliDB $db Database handler
	 * @return bool
	 */
	public static function ensureExtrafields($db)
	{
		require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
		$ef = new ExtraFields($db);
		$ok = true;
		$enabled = "isModEnabled('emrecupel')";

		// Third party: role towards Recupel and display override.
		$ok = self::addField($ef, 'emrecupel_role', 'RecupelRole', 'select', 900, '16', 'societe', '', array('options' => array('enduser' => 'RecupelRoleEnduser', 'reseller' => 'RecupelRoleReseller', 'exempt' => 'RecupelRoleExempt')), '1', 'RecupelRoleHelp', $enabled) && $ok;
		$ok = self::addField($ef, 'emrecupel_mode', 'RecupelDisplayOverride', 'select', 901, '16', 'societe', '', array('options' => array('line' => 'RecupelModeLine', 'included' => 'RecupelModeIncluded', 'footer' => 'RecupelModeFooter')), '1', 'RecupelDisplayOverrideHelp', $enabled) && $ok;

		// Lines: link key + category snapshot of fee lines. Never shown in forms.
		foreach (self::LINE_ELEMENTS as $lineType) {
			$ok = self::addField($ef, 'emrecupel_link', 'RecupelLink', 'varchar', 900, '16', $lineType, '', '', '0', '', $enabled) && $ok;
			$ok = self::addField($ef, 'emrecupel_cat', 'RecupelCategory', 'varchar', 901, '16', $lineType, '', '', '0', '', $enabled) && $ok;
			$ok = self::addField($ef, 'emrecupel_units', 'RecupelUnits', 'double', 902, '24,8', $lineType, '', '', '0', '', $enabled) && $ok;
			$ok = self::addField($ef, 'emrecupel_optout', 'RecupelOptOut', 'varchar', 903, '255', $lineType, '', '', '0', '', $enabled) && $ok;
		}
		return $ok;
	}

	/**
	 * Add one extrafield if it does not exist yet (all entities).
	 *
	 * @param ExtraFields  $ef       Extrafields handler
	 * @param string       $name     Attribute name
	 * @param string       $label    Label (translation key)
	 * @param string       $type     Type
	 * @param int          $pos      Position
	 * @param string       $size     Size
	 * @param string       $element  Element type
	 * @param string       $default  Default value
	 * @param array|string $param    Param
	 * @param string       $list     Visibility
	 * @param string       $help     Help (translation key)
	 * @param string       $enabled  Enabled condition
	 * @return bool
	 */
	private static function addField($ef, $name, $label, $type, $pos, $size, $element, $default, $param, $list, $help, $enabled)
	{
		global $db;
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "extrafields WHERE name = '" . $ef->db->escape($name) . "' AND elementtype = '" . $ef->db->escape($element) . "'";
		$resql = $ef->db->query($sql);
		if ($resql && $ef->db->num_rows($resql) > 0) {
			// Declared already: make sure the column exists too (restored database…).
			$table = $element . '_extrafields';
			if (self::tableExists($ef->db, $table) && !self::columnExists($ef->db, $table, $name)) {
				$colType = ($type === 'double') ? 'double(24,8)' : 'varchar(' . (int) $size . ')';
				return (bool) $ef->db->query("ALTER TABLE " . MAIN_DB_PREFIX . $table . " ADD COLUMN " . $name . " " . $colType . " NULL");
			}
			return true;
		}
		$res = $ef->addExtraField($name, $label, $type, $pos, $size, $element, 0, 0, $default, $param, 1, '', $list, $help, '', '0', 'emrecupel@emrecupel', $enabled, 0, 0);
		if ($res <= 0) {
			dol_syslog('emrecupel: addExtraField ' . $element . '.' . $name . ' failed: ' . $ef->error, LOG_ERR);
			return false;
		}
		return true;
	}

	/**
	 * Create the service product used on fee lines (accounting account,
	 * translated label), or return the configured one.
	 *
	 * @param DoliDB $db Database handler
	 * @return int Product id, <0 on error
	 */
	public static function ensureFeeProduct($db)
	{
		global $conf, $user;
		require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
		if (!function_exists('dolibarr_set_const')) {
			require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
		}

		$id = getDolGlobalInt('EMRECUPEL_FEE_PRODUCT_ID');
		if ($id > 0) {
			$p = new Product($db);
			if ($p->fetch($id) > 0) {
				return $id;
			}
		}
		$p = new Product($db);
		if ($p->fetch(0, 'RECUPEL') > 0) {
			$id = (int) $p->id;
		} else {
			$actor = $user;
			if (!is_object($actor) || empty($actor->id)) {
				require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
				$actor = new User($db);
				$resql = $db->query("SELECT rowid FROM " . MAIN_DB_PREFIX . "user WHERE admin = 1 AND statut = 1 ORDER BY rowid ASC LIMIT 1");
				if ($resql && ($o = $db->fetch_object($resql))) {
					$actor->fetch((int) $o->rowid);
				}
			}
			$p->ref = 'RECUPEL';
			$p->label = 'Cotisation Recupel';
			$p->description = '';
			$p->type = Product::TYPE_SERVICE;
			$p->status = 1;
			$p->status_buy = 0;
			$p->price = 0;
			$p->price_base_type = 'HT';
			$p->tva_tx = 21;
			$p->note_private = 'Created by emRecupel. Used on automatic Recupel fee lines: set its accounting account here (CNC advice 2009/9: sub-account of 70).';
			$id = $p->create($actor);
			if ($id <= 0) {
				dol_syslog('emrecupel: fee product creation failed: ' . $p->error . ' ' . implode(',', (array) $p->errors), LOG_ERR);
				return -1;
			}
			// Translated labels, used on documents when MAIN_MULTILANGS is on.
			$p->fetch($id);
			$p->multilangs = array(
				'fr_FR' => array('label' => 'Cotisation Recupel', 'description' => '', 'note' => '', 'other' => ''),
				'fr_BE' => array('label' => 'Cotisation Recupel', 'description' => '', 'note' => '', 'other' => ''),
				'nl_BE' => array('label' => 'Recupelbijdrage', 'description' => '', 'note' => '', 'other' => ''),
				'nl_NL' => array('label' => 'Recupelbijdrage', 'description' => '', 'note' => '', 'other' => ''),
				'en_US' => array('label' => 'Recupel fee', 'description' => '', 'note' => '', 'other' => ''),
			);
			$p->setMultiLangs($actor);
		}
		dolibarr_set_const($db, 'EMRECUPEL_FEE_PRODUCT_ID', (string) $id, 'chaine', 0, 'Service product used on Recupel fee lines', $conf->entity);
		$conf->global->EMRECUPEL_FEE_PRODUCT_ID = (string) $id;
		return $id;
	}

	/**
	 * Load the scale files shipped in data/ (idempotent).
	 *
	 * @param DoliDB $db Database handler
	 * @return bool
	 */
	public static function importBundledScales($db)
	{
		dol_include_once('/emrecupel/class/emrecupelcatalog.class.php');
		$catalog = new EmrecupelCatalog($db);
		$res = $catalog->importBundledScales();
		if (!empty($res['errors'])) {
			dol_syslog('emrecupel: bundled scale import: ' . implode(' | ', $res['errors']), LOG_ERR);
			return false;
		}
		return true;
	}

	/**
	 * Take over the data of the 1.x prototype (non destructive).
	 *
	 *  - product classification (extrafields emrecupel_category and
	 *    emrecupel_exempt) → llx_emrecupel_product(_code), for products not
	 *    classified yet; the old product extrafields are then disabled
	 *    (column and values kept);
	 *  - Recupel lines created by 1.x (llx_emrecupel_invoice_log) get the
	 *    link extrafields, so that 2.x adopts them instead of adding a second
	 *    fee line on draft invoices;
	 *  - EMRECUPEL_PROVIDER_MEMBER = 1 → profile "producer" if no profile yet.
	 * The 1.x tables (category, tariff, invoice_log) are left in place.
	 *
	 * @param DoliDB $db Database handler
	 * @return bool
	 */
	public static function migrateFromV1($db)
	{
		global $conf;
		if (!self::tableExists($db, 'emrecupel_category')) {
			return true;
		}
		$ok = true;

		// 1. Product classification.
		if (self::columnExists($db, 'product_extrafields', 'emrecupel_category')) {
			$hasExempt = self::columnExists($db, 'product_extrafields', 'emrecupel_exempt');
			$sql = "SELECT pe.fk_object, c.code" . ($hasExempt ? ", pe.emrecupel_exempt" : ", 0 as emrecupel_exempt");
			$sql .= " FROM " . MAIN_DB_PREFIX . "product_extrafields as pe";
			$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "emrecupel_category as c ON c.rowid = pe.emrecupel_category";
			$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "emrecupel_product as ep ON ep.fk_product = pe.fk_object";
			$sql .= " WHERE ep.rowid IS NULL AND ((pe.emrecupel_category IS NOT NULL AND pe.emrecupel_category <> '' AND pe.emrecupel_category <> '0')" . ($hasExempt ? " OR pe.emrecupel_exempt = 1" : "") . ")";
			$resql = $db->query($sql);
			if ($resql) {
				$rows = array();
				while ($obj = $db->fetch_object($resql)) {
					$rows[] = $obj;
				}
				$now = $db->idate(dol_now());
				dol_include_once('/emrecupel/class/emrecupelcatalog.class.php');
				EmrecupelCatalog::clearCache();
				$catalog = new EmrecupelCatalog($db);
				foreach ($rows as $obj) {
					$exempt = !empty($obj->emrecupel_exempt);
					$code = (string) $obj->code;
					// A 1.x code that is not an assignable leaf of the 2.x scale
					// (parent categories 1 to 6) is not taken over: the product
					// stays "to classify" instead of silently paying nothing.
					if (!$exempt && ($code === '' || !$catalog->isAssignable($code))) {
						continue;
					}
					$sqli = "INSERT INTO " . MAIN_DB_PREFIX . "emrecupel_product (entity, fk_product, status, origin, date_creation) VALUES (" . (int) $conf->entity . ", " . (int) $obj->fk_object . ", '" . ($exempt ? 'exempt' : 'subject') . "', '', '" . $now . "')";
					if (!$db->query($sqli)) {
						$ok = false;
						continue;
					}
					if (!$exempt) {
						$ok = $db->query("INSERT INTO " . MAIN_DB_PREFIX . "emrecupel_product_code (fk_product, code, units, position) VALUES (" . (int) $obj->fk_object . ", '" . $db->escape($code) . "', 1, 10)") && $ok;
					}
				}
			} else {
				$ok = false;
			}
			$db->query("UPDATE " . MAIN_DB_PREFIX . "extrafields SET enabled = '0' WHERE elementtype = 'product' AND name IN ('emrecupel_category', 'emrecupel_use_type', 'emrecupel_exempt')");
		}

		// 2. Adopt the fee lines written by 1.x.
		if (self::tableExists($db, 'emrecupel_invoice_log')) {
			$sql = "SELECT l.fk_facturedet, l.fk_facturedet_source, l.category_code, l.qty, src.qty as srcqty";
			$sql .= " FROM " . MAIN_DB_PREFIX . "emrecupel_invoice_log as l";
			$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "facturedet as fee ON fee.rowid = l.fk_facturedet";
			$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "facturedet as src ON src.rowid = l.fk_facturedet_source";
			$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "facturedet_extrafields as e ON e.fk_object = fee.rowid";
			$sql .= " WHERE (e.emrecupel_cat IS NULL OR e.emrecupel_cat = '')";
			$resql = $db->query($sql);
			if ($resql) {
				$rows = array();
				while ($obj = $db->fetch_object($resql)) {
					$rows[] = $obj;
				}
				foreach ($rows as $obj) {
					$key = 'v1' . substr(md5('emrecupel' . $obj->fk_facturedet_source), 0, 11);
					$ok = self::setLineExtrafields($db, (int) $obj->fk_facturedet_source, array('emrecupel_link' => $key)) && $ok;
					$ok = self::setLineExtrafields($db, (int) $obj->fk_facturedet, array('emrecupel_link' => $key, 'emrecupel_cat' => (string) $obj->category_code, 'emrecupel_units' => 1)) && $ok;
				}
			}
		}

		// 2b. 1.x fee lines that are not in the log: copies of 1.x lines in
		//     invoices and in template invoices (facturedet_rec). 1.x lines are
		//     free lines (no product) whose description follows a 1.x template;
		//     they are attached to the product line right above them.
		$ok = self::adoptV1Lines($db, 'facturedet', 'fk_facture') && $ok;
		$ok = self::adoptV1Lines($db, 'facturedet_rec', 'fk_facture') && $ok;

		// 3. Line templates left at their 1.x default: drop them so that the
		//    2.x defaults apply (a template changed by the user is kept).
		$v1defaults = array(
			'EMRECUPEL_LINE_LABEL_FR' => 'Cotisation Recupel (cat. {CODE})',
			'EMRECUPEL_LINE_LABEL_EN' => 'Recupel contribution (cat. {CODE})',
			'EMRECUPEL_LINE_LABEL_NL' => 'Recupel-bijdrage (cat. {CODE})',
		);
		foreach ($v1defaults as $name => $value) {
			if (getDolGlobalString($name) === $value) {
				if (!function_exists('dolibarr_del_const')) {
					require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
				}
				dolibarr_del_const($db, $name, $conf->entity);
				unset($conf->global->$name);
			}
		}

		// 4. Profile.
		if (getDolGlobalString('EMRECUPEL_PROVIDER_MEMBER') === '1' && getDolGlobalString('EMRECUPEL_PROFILE') === '') {
			if (!function_exists('dolibarr_set_const')) {
				require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
			}
			dolibarr_set_const($db, 'EMRECUPEL_PROFILE', 'producer', 'chaine', 0, '', $conf->entity);
		}
		return $ok;
	}

	/**
	 * Regular expression matching the description of a 1.x fee line (default
	 * templates in FR/EN/NL plus the templates customised by the user).
	 *
	 * @return string
	 */
	public static function v1LineRegex()
	{
		$templates = array('Cotisation Recupel (cat. {CODE})', 'Recupel contribution (cat. {CODE})', 'Recupel-bijdrage (cat. {CODE})');
		foreach (array('FR', 'EN', 'NL') as $l) {
			$t = getDolGlobalString('EMRECUPEL_LINE_LABEL_' . $l);
			if ($t !== '' && strpos($t, '{CODE}') !== false) {
				$templates[] = $t;
			}
		}
		$parts = array();
		foreach (array_unique($templates) as $t) {
			$q = preg_quote($t, '/');
			$q = str_replace(preg_quote('{CODE}', '/'), '(?P<code>\\d(?:\\.\\d{1,2}){0,2})', $q);
			$parts[] = str_replace(array(preg_quote('{LABEL}', '/'), preg_quote('{AMOUNT}', '/'), preg_quote('{UNITS}', '/')), '.*?', $q);
		}
		return '/(?J)^\\s*(?:' . implode('|', $parts) . ')/u';
	}

	/**
	 * Attach unlinked 1.x fee lines of a line table to the product line above them.
	 *
	 * @param DoliDB $db    Database handler
	 * @param string $table facturedet or facturedet_rec
	 * @param string $fk    Column of the document id
	 * @return bool
	 */
	private static function adoptV1Lines($db, $table, $fk)
	{
		if (!self::tableExists($db, $table . '_extrafields') || !self::columnExists($db, $table . '_extrafields', 'emrecupel_cat')) {
			return true;
		}
		$regex = self::v1LineRegex();
		$sql = "SELECT d.rowid, d." . $fk . " as docid, d.rang, d.description FROM " . MAIN_DB_PREFIX . $table . " as d";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . $table . "_extrafields as e ON e.fk_object = d.rowid";
		$sql .= " WHERE (d.fk_product IS NULL OR d.fk_product = 0) AND (e.emrecupel_cat IS NULL OR e.emrecupel_cat = '')";
		$sql .= " AND (d.description LIKE '%Recupel%')";
		$resql = $db->query($sql);
		if (!$resql) {
			return false;
		}
		$rows = array();
		while ($obj = $db->fetch_object($resql)) {
			$rows[] = $obj;
		}
		$ok = true;
		foreach ($rows as $obj) {
			if (!preg_match($regex, (string) $obj->description, $m) || empty($m['code'])) {
				continue;
			}
			$sqlp = "SELECT d.rowid, e.emrecupel_link FROM " . MAIN_DB_PREFIX . $table . " as d LEFT JOIN " . MAIN_DB_PREFIX . $table . "_extrafields as e ON e.fk_object = d.rowid";
			$sqlp .= " WHERE d." . $fk . " = " . (int) $obj->docid . " AND d.fk_product > 0 AND d.rang < " . (int) $obj->rang . " ORDER BY d.rang DESC, d.rowid DESC";
			$sqlp .= $db->plimit(1);
			$resp = $db->query($sqlp);
			$src = $resp ? $db->fetch_object($resp) : null;
			if (!$src) {
				continue;
			}
			$key = !empty($src->emrecupel_link) ? ltrim((string) $src->emrecupel_link, '-') : 'v1' . substr(md5('emrecupel' . $table . $src->rowid), 0, 11);
			$ok = self::setLineExtrafields($db, (int) $src->rowid, array('emrecupel_link' => $key), $table) && $ok;
			$ok = self::setLineExtrafields($db, (int) $obj->rowid, array('emrecupel_link' => $key, 'emrecupel_cat' => (string) $m['code'], 'emrecupel_units' => 1), $table) && $ok;
		}
		return $ok;
	}

	/**
	 * Write values in the extrafields row of an invoice line.
	 *
	 * @param DoliDB               $db     Database handler
	 * @param int                  $lineId Line id
	 * @param array<string,mixed>  $values Column => value
	 * @return bool
	 */
	private static function setLineExtrafields($db, $lineId, array $values, $lineTable = 'facturedet')
	{
		$table = MAIN_DB_PREFIX . $lineTable . '_extrafields';
		$resql = $db->query("SELECT rowid FROM " . $table . " WHERE fk_object = " . (int) $lineId);
		$set = array();
		foreach ($values as $col => $val) {
			$set[$col] = is_int($val) || is_float($val) ? (string) $val : "'" . $db->escape((string) $val) . "'";
		}
		if ($resql && $db->fetch_object($resql)) {
			$parts = array();
			foreach ($set as $col => $val) {
				$parts[] = $col . " = " . $val;
			}
			return (bool) $db->query("UPDATE " . $table . " SET " . implode(', ', $parts) . " WHERE fk_object = " . (int) $lineId);
		}
		return (bool) $db->query("INSERT INTO " . $table . " (fk_object, " . implode(', ', array_keys($set)) . ") VALUES (" . (int) $lineId . ", " . implode(', ', $set) . ")");
	}
}
