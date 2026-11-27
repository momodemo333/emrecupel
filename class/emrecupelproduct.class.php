<?php
/* Copyright (C) 2026 E-dem SRL <support@e-dem.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/emrecupelproduct.class.php
 * \ingroup emrecupel
 * \brief   Recupel classification of products: status, origin, weight and
 *          Recupel codes (several codes per product, with a quantity).
 *
 * A product is in one of three states:
 *  - not classified : no row in llx_emrecupel_product;
 *  - subject        : status 'subject' and at least one code;
 *  - not subject    : status 'exempt' (accessory sold with an appliance,
 *                     component, battery, solar panel, service…).
 */

dol_include_once('/emrecupel/class/emrecupelfeepolicy.class.php');
dol_include_once('/emrecupel/class/emrecupelcatalog.class.php');

class EmrecupelProduct
{
	const STATUS_SUBJECT = 'subject';
	const STATUS_EXEMPT = 'exempt';

	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var array<int,array<string,mixed>> */
	private static $cache = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @return void
	 */
	public static function clearCache()
	{
		self::$cache = array();
	}

	/**
	 * Recupel data of a product.
	 *
	 * A variant without its own classification inherits the classification
	 * of its parent product.
	 *
	 * @param int  $productId Product id
	 * @param bool $inherit   Inherit from the parent of a variant
	 * @return array{classified:bool,status:string,origin:string,weight_kg:float|null,inherited:bool,items:array<int,array{code:string,units:float}>}
	 */
	public function get($productId, $inherit = true)
	{
		$productId = (int) $productId;
		$ck = $productId . ($inherit ? 'i' : '');
		if (isset(self::$cache[$ck])) {
			return self::$cache[$ck];
		}
		$data = array('classified' => false, 'status' => '', 'origin' => '', 'weight_kg' => null, 'inherited' => false, 'items' => array());
		if ($productId <= 0) {
			return $data;
		}
		$sql = "SELECT status, origin, weight_kg FROM " . MAIN_DB_PREFIX . "emrecupel_product WHERE fk_product = " . $productId;
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$data['classified'] = true;
			$data['status'] = (string) $obj->status;
			$data['origin'] = (string) $obj->origin;
			$data['weight_kg'] = ($obj->weight_kg === null || $obj->weight_kg === '') ? null : (float) $obj->weight_kg;
		}
		$sql = "SELECT code, units FROM " . MAIN_DB_PREFIX . "emrecupel_product_code WHERE fk_product = " . $productId . " ORDER BY position ASC, rowid ASC";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$data['items'][] = array('code' => (string) $obj->code, 'units' => ((float) $obj->units > 0 ? (float) $obj->units : 1.0));
			}
		}
		if ($inherit && !$data['classified'] && empty($data['items'])) {
			$sql = "SELECT fk_product_parent FROM " . MAIN_DB_PREFIX . "product_attribute_combination WHERE fk_product_child = " . $productId;
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql)) && (int) $obj->fk_product_parent > 0 && (int) $obj->fk_product_parent !== $productId) {
				$parent = $this->get((int) $obj->fk_product_parent, false);
				if ($parent['classified']) {
					$parent['inherited'] = true;
					$data = $parent;
				}
			}
		}
		if ($data['status'] === self::STATUS_EXEMPT) {
			$data['items'] = array();
		}
		self::$cache[$ck] = $data;
		return $data;
	}

	/**
	 * Declarable net weight of one sold unit, in kg: the override of the
	 * module, otherwise the Dolibarr weight of the product converted to kg.
	 *
	 * @param int $productId Product id
	 * @return float|null
	 */
	public function weightKg($productId)
	{
		$data = $this->get($productId);
		if ($data['weight_kg'] !== null) {
			return $data['weight_kg'];
		}
		$sql = "SELECT weight, weight_units FROM " . MAIN_DB_PREFIX . "product WHERE rowid = " . (int) $productId;
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql)) && $obj->weight !== null && $obj->weight !== '' && (float) $obj->weight > 0) {
			// weight_units is a power of ten relative to the kg (0 = kg, -3 = g, 3 = t);
			// 99 is the pound in Dolibarr.
			$unit = (int) $obj->weight_units;
			if ($unit === 99) {
				return round((float) $obj->weight * 0.45359237, 6);
			}
			return round((float) $obj->weight * pow(10, $unit), 6);
		}
		return null;
	}

	/**
	 * Save the classification of a product (status, origin, weight, codes).
	 *
	 * @param int                                        $productId Product id
	 * @param string                                     $status    STATUS_SUBJECT or STATUS_EXEMPT
	 * @param string                                     $origin    ORIGIN_* or '' (company default)
	 * @param array<int,array{code:string,units:float}>  $items     Codes (ignored for STATUS_EXEMPT)
	 * @param User                                       $user      User
	 * @param float|null|false                           $weightKg  Weight override, null to clear, false to keep
	 * @return int >0 OK, <0 error
	 */
	public function save($productId, $status, $origin, array $items, $user, $weightKg = false)
	{
		$productId = (int) $productId;
		$this->error = '';
		if ($productId <= 0) {
			$this->error = 'BadProduct';
			return -1;
		}
		if (!in_array($status, array(self::STATUS_SUBJECT, self::STATUS_EXEMPT), true)) {
			$this->error = 'BadStatus';
			return -1;
		}
		if ($origin !== '' && !in_array($origin, array(EmrecupelFeePolicy::ORIGIN_IMPORT, EmrecupelFeePolicy::ORIGIN_LOCAL, EmrecupelFeePolicy::ORIGIN_USED), true)) {
			$this->error = 'BadOrigin';
			return -1;
		}
		$catalog = new EmrecupelCatalog($this->db);
		$clean = array();
		if ($status === self::STATUS_SUBJECT) {
			foreach ($items as $item) {
				$code = trim((string) $item['code']);
				if ($code === '') {
					continue;
				}
				if (!$catalog->isAssignable($code)) {
					$this->error = 'UnknownRecupelCode ' . $code;
					return -1;
				}
				$units = (float) price2num($item['units'] ?? 1);
				$clean[$code] = $units > 0 ? $units : 1.0;
			}
		}

		$this->db->begin();
		$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "emrecupel_product WHERE fk_product = " . $productId;
		$resql = $this->db->query($sql);
		$exists = $resql && $this->db->fetch_object($resql);
		$weightSql = '';
		if ($weightKg !== false) {
			$weightSql = ($weightKg === null || $weightKg === '') ? "NULL" : (string) price2num((float) $weightKg, 'MU');
		}
		if ($exists) {
			$sql = "UPDATE " . MAIN_DB_PREFIX . "emrecupel_product SET status = '" . $this->db->escape($status) . "', origin = '" . $this->db->escape($origin) . "'";
			if ($weightSql !== '') {
				$sql .= ", weight_kg = " . $weightSql;
			}
			$sql .= ", fk_user_modif = " . (is_object($user) ? (int) $user->id : 0) . " WHERE fk_product = " . $productId;
		} else {
			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "emrecupel_product (entity, fk_product, status, origin, weight_kg, date_creation, fk_user_modif) VALUES (";
			$sql .= (isset($GLOBALS['conf']->entity) ? (int) $GLOBALS['conf']->entity : 1);
			$sql .= ", " . $productId . ", '" . $this->db->escape($status) . "', '" . $this->db->escape($origin) . "', " . ($weightSql !== '' ? $weightSql : "NULL");
			$sql .= ", '" . $this->db->idate(dol_now()) . "', " . (is_object($user) ? (int) $user->id : 0) . ")";
		}
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		if (!$this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "emrecupel_product_code WHERE fk_product = " . $productId)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$pos = 0;
		foreach ($clean as $code => $units) {
			$pos += 10;
			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "emrecupel_product_code (fk_product, code, units, position) VALUES (" . $productId . ", '" . $this->db->escape($code) . "', " . price2num($units, 'MU') . ", " . $pos . ")";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				$this->db->rollback();
				return -1;
			}
		}
		$this->db->commit();
		self::clearCache();
		return 1;
	}

	/**
	 * Add one code to a product (keeps the other codes), classify it as subject.
	 *
	 * @param int    $productId Product id
	 * @param string $code      Recupel code
	 * @param float  $units     Appliances per sold unit
	 * @param string $origin    Origin, null to keep the current one
	 * @param User   $user      User
	 * @param bool   $replace   Replace the existing codes instead of adding
	 * @return int
	 */
	public function assign($productId, $code, $units, $origin, $user, $replace = false)
	{
		$cur = $this->get($productId, false);
		$items = $replace ? array() : $cur['items'];
		$found = false;
		foreach ($items as $k => $it) {
			if ($it['code'] === $code) {
				$items[$k]['units'] = $units;
				$found = true;
			}
		}
		if (!$found) {
			$items[] = array('code' => $code, 'units' => $units);
		}
		return $this->save($productId, self::STATUS_SUBJECT, $origin === null ? $cur['origin'] : $origin, $items, $user);
	}

	/**
	 * Forget the classification of a product (back to "to classify").
	 *
	 * @param int $productId Product id
	 * @return int
	 */
	public function unclassify($productId)
	{
		$productId = (int) $productId;
		$ok = $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "emrecupel_product_code WHERE fk_product = " . $productId)
			&& $this->db->query("DELETE FROM " . MAIN_DB_PREFIX . "emrecupel_product WHERE fk_product = " . $productId);
		self::clearCache();
		if (!$ok) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Remove the module rows of a deleted product.
	 *
	 * @param int $productId Product id
	 * @return void
	 */
	public function onProductDeleted($productId)
	{
		$this->unclassify($productId);
	}

	/**
	 * Parse a product classification file.
	 *
	 * Format: CSV separated by ';' (',' accepted), UTF-8, optional header
	 * line. Columns: ref;code;quantity;origin
	 *  - code: Recupel code, or "exempt" / "non soumis" / "-" for a product
	 *    not subject to Recupel;
	 *  - quantity: appliances per sold unit (default 1);
	 *  - origin: import, local, used (French and Dutch words accepted), empty =
	 *    company default.
	 * Several lines with the same ref give several codes.
	 *
	 * @param string $content File content
	 * @return array{rows: array<int,array{line:int,ref:string,code:string,units:float,origin:string,exempt:bool}>, errors: string[]}
	 */
	public static function parseClassificationCsv($content)
	{
		$rows = array();
		$errors = array();
		$content = preg_replace('/^\xEF\xBB\xBF/', '', (string) $content);
		$lines = preg_split('/\r\n|\r|\n/', $content);
		$originMap = array(
			'' => '', 'import' => 'import', 'imported' => 'import', 'importe' => 'import', 'importé' => 'import', 'produit' => 'import', 'produced' => 'import', 'geimporteerd' => 'import', 'geïmporteerd' => 'import', 'geproduceerd' => 'import',
			'local' => 'local', 'be' => 'local', 'belgique' => 'local', 'belgium' => 'local', 'belgie' => 'local', 'belgië' => 'local',
			'used' => 'used', 'occasion' => 'used', 'tweedehands' => 'used', 'second-hand' => 'used',
		);
		$exemptWords = array('exempt', 'non soumis', 'nonsoumis', 'not subject', 'niet onderworpen', '-', 'none');
		$first = true;
		foreach ($lines as $num => $line) {
			if (trim($line) === '' || $line[0] === '#') {
				continue;
			}
			// Separator: ';' unless the line has more ',' outside quoted values.
			$unquoted = preg_replace('/"(?:[^"]|"")*"/', '', $line);
			$sep = (substr_count($unquoted, ';') >= substr_count($unquoted, ',')) ? ';' : ',';
			$cols = array_map('trim', str_getcsv($line, $sep, '"', '\\'));
			if ($first) {
				$first = false;
				if (isset($cols[0]) && in_array(strtolower($cols[0]), array('ref', 'reference', 'référence', 'referentie', 'product'), true)) {
					continue;
				}
			}
			$lineNo = $num + 1;
			$ref = $cols[0] ?? '';
			// Undo the protection added by csvField() ('=… → =…).
			if (strlen($ref) > 1 && $ref[0] === "'" && strpos('=+-@', $ref[1]) !== false) {
				$ref = substr($ref, 1);
			}
			$code = $cols[1] ?? '';
			$units = $cols[2] ?? '';
			$origin = function_exists('mb_strtolower') ? mb_strtolower($cols[3] ?? '') : strtolower($cols[3] ?? '');
			if ($ref === '') {
				$errors[] = 'Line ' . $lineNo . ': empty ref';
				continue;
			}
			$exempt = in_array(strtolower($code), $exemptWords, true);
			if (!$exempt && !preg_match('/^\d(\.\d{1,2}){1,2}$/', $code)) {
				$errors[] = 'Line ' . $lineNo . ': invalid Recupel code "' . $code . '"';
				continue;
			}
			$units = str_replace(',', '.', $units);
			if ($units === '') {
				$units = '1';
			}
			if (!is_numeric($units) || (float) $units <= 0) {
				$errors[] = 'Line ' . $lineNo . ': invalid quantity "' . $units . '"';
				continue;
			}
			if (!array_key_exists($origin, $originMap)) {
				$errors[] = 'Line ' . $lineNo . ': invalid origin "' . $origin . '" (import, local or used)';
				continue;
			}
			$rows[] = array('line' => $lineNo, 'ref' => $ref, 'code' => $exempt ? '' : $code, 'units' => (float) $units, 'origin' => $originMap[$origin], 'exempt' => $exempt);
		}
		return array('rows' => $rows, 'errors' => $errors);
	}

	/**
	 * Apply parsed classification rows (all or nothing per product).
	 *
	 * Lines with the same ref are grouped: the codes of the file replace the
	 * codes of the product.
	 *
	 * @param array<int,array<string,mixed>> $rows   Rows from parseClassificationCsv()
	 * @param User                           $user   User
	 * @param bool                           $dryRun Only check, write nothing
	 * @return array{products:int, errors:string[]}
	 */
	public function importClassification(array $rows, $user, $dryRun = false)
	{
		$catalog = new EmrecupelCatalog($this->db);
		$byRef = array();
		$errors = array();
		foreach ($rows as $row) {
			$byRef[$row['ref']][] = $row;
		}
		$done = 0;
		foreach ($byRef as $ref => $list) {
			$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product WHERE ref = '" . $this->db->escape($ref) . "' AND entity IN (" . getEntity('product') . ")";
			$resql = $this->db->query($sql);
			$obj = $resql ? $this->db->fetch_object($resql) : null;
			if (!$obj) {
				$errors[] = $ref . ': unknown product';
				continue;
			}
			$exempt = false;
			$origin = '';
			$items = array();
			foreach ($list as $r) {
				if ($r['exempt']) {
					$exempt = true;
				} else {
					if (!$catalog->isAssignable($r['code'])) {
						$errors[] = $ref . ' (line ' . $r['line'] . '): unknown Recupel code ' . $r['code'];
						continue 2;
					}
					$items[] = array('code' => $r['code'], 'units' => $r['units']);
				}
				if ($r['origin'] !== '') {
					$origin = $r['origin'];
				}
			}
			if ($exempt && !empty($items)) {
				$errors[] = $ref . ': both "not subject" and Recupel codes';
				continue;
			}
			if ($dryRun) {
				$done++;
				continue;
			}
			$res = $this->save((int) $obj->rowid, $exempt ? self::STATUS_EXEMPT : self::STATUS_SUBJECT, $origin, $items, $user);
			if ($res < 0) {
				$errors[] = $ref . ': ' . $this->error;
				continue;
			}
			$done++;
		}
		return array('products' => $done, 'errors' => $errors);
	}

	/**
	 * Export the classification of every classified product (same format as the import).
	 *
	 * @return string CSV content
	 */
	public function exportClassification()
	{
		$out = "ref;code;quantity;origin\n";
		$sql = "SELECT p.ref, ep.status, ep.origin, pc.code, pc.units FROM " . MAIN_DB_PREFIX . "emrecupel_product as ep";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "product as p ON p.rowid = ep.fk_product";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "emrecupel_product_code as pc ON pc.fk_product = ep.fk_product";
		$sql .= " WHERE p.entity IN (" . getEntity('product') . ")";
		$sql .= " ORDER BY p.ref, pc.position, pc.rowid";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$code = $obj->status === self::STATUS_EXEMPT ? 'exempt' : (string) $obj->code;
				if ($code === '') {
					continue;
				}
				$units = $obj->status === self::STATUS_EXEMPT ? '' : rtrim(rtrim(number_format((float) $obj->units, 4, '.', ''), '0'), '.');
				$out .= self::csvField($obj->ref) . ';' . $code . ';' . $units . ';' . $obj->origin . "\n";
			}
		}
		return $out;
	}

	/**
	 * @param string $v Value
	 * @return string
	 */
	public static function csvField($v)
	{
		$v = (string) $v;
		// A value starting with = + - @ would be run as a formula by a spreadsheet.
		if ($v !== '' && strpos('=+-@', $v[0]) !== false) {
			$v = "'" . $v;
		}
		if (strpbrk($v, ";,\"\n\r") !== false) {
			return '"' . str_replace('"', '""', $v) . '"';
		}
		return $v;
	}
}
