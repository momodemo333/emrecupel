<?php
/* Copyright (C) 2026 E-dem SRL <support@e-dem.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/emrecupelcatalog.class.php
 * \ingroup emrecupel
 * \brief   Recupel scale: categories and dated rates (read, cache, import).
 */
class EmrecupelCatalog
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var array<int,array<string,array<string,mixed>>> entity => code => category row */
	private static $categoryCache = array();

	/** @var array<string,float|null> cache key => rate */
	private static $rateCache = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Drop the per-request caches (after an import or an edit).
	 *
	 * @return void
	 */
	public static function clearCache()
	{
		self::$categoryCache = array();
		self::$rateCache = array();
	}

	/**
	 * Entity of the scale rows.
	 *
	 * The Recupel scale is national and identical for every company, so it is
	 * stored once (entity 1) and shared by all entities of a multicompany setup.
	 *
	 * @return int
	 */
	private function entity()
	{
		return 1;
	}

	/**
	 * Load every category of the entity (active or not), keyed by code.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function getAllCategories()
	{
		$entity = $this->entity();
		if (isset(self::$categoryCache[$entity])) {
			return self::$categoryCache[$entity];
		}
		$rows = array();
		$sql = "SELECT rowid, code, parent_code, label_fr, label_nl, label_en, use_type, position, active";
		$sql .= " FROM " . MAIN_DB_PREFIX . "emrecupel_cat";
		$sql .= " WHERE entity = " . $entity;
		$sql .= " ORDER BY position ASC, code ASC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return $rows;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[(string) $obj->code] = array(
				'id' => (int) $obj->rowid,
				'code' => (string) $obj->code,
				'parent_code' => (string) $obj->parent_code,
				'label_fr' => (string) $obj->label_fr,
				'label_nl' => (string) $obj->label_nl,
				'label_en' => (string) $obj->label_en,
				'use_type' => (string) $obj->use_type,
				'position' => (int) $obj->position,
				'active' => (int) $obj->active,
			);
		}
		self::$categoryCache[$entity] = $rows;
		return $rows;
	}

	/**
	 * @param string $code Category code
	 * @return array<string,mixed>|null
	 */
	public function getCategory($code)
	{
		$all = $this->getAllCategories();
		$code = (string) $code;
		return isset($all[$code]) ? $all[$code] : null;
	}

	/**
	 * Tell whether a category can be assigned to a product (leaf category).
	 *
	 * @param string $code Category code
	 * @return bool
	 */
	public function isAssignable($code)
	{
		$cat = $this->getCategory($code);
		if ($cat === null || empty($cat['active'])) {
			return false;
		}
		foreach ($this->getAllCategories() as $other) {
			if ($other['parent_code'] === $cat['code']) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Label of a category in a language (fr, nl, en; other languages fall back to en).
	 *
	 * @param string $code     Category code
	 * @param string $langcode Dolibarr language code (fr_BE, nl_BE…)
	 * @return string
	 */
	public function getLabel($code, $langcode = 'fr_FR')
	{
		$cat = $this->getCategory($code);
		if ($cat === null) {
			return (string) $code;
		}
		$short = strtolower(substr((string) $langcode, 0, 2));
		$field = in_array($short, array('fr', 'nl', 'en'), true) ? 'label_' . $short : 'label_en';
		$label = $cat[$field] !== '' ? $cat[$field] : $cat['label_en'];
		return $label !== '' ? $label : (string) $code;
	}

	/**
	 * Rate of a category at a date.
	 *
	 * @param string $code Category code
	 * @param string $date Date YYYY-MM-DD (today when empty)
	 * @return array{ht:float,ttc:float|null,fee_type:string,date_start:string}|null Null when no rate covers the date
	 */
	public function getRateInfo($code, $date = '')
	{
		$date = $date ?: dol_print_date(dol_now(), '%Y-%m-%d');
		$entity = $this->entity();
		$key = $entity . '|' . $code . '|' . $date;
		if (array_key_exists($key, self::$rateCache)) {
			return self::$rateCache[$key];
		}
		$sql = "SELECT amount_ht, amount_ttc, fee_type, date_start FROM " . MAIN_DB_PREFIX . "emrecupel_rate";
		$sql .= " WHERE entity = " . $entity;
		$sql .= " AND category_code = '" . $this->db->escape((string) $code) . "'";
		$sql .= " AND date_start <= '" . $this->db->escape($date) . "'";
		$sql .= " AND (date_end IS NULL OR date_end >= '" . $this->db->escape($date) . "')";
		$sql .= " ORDER BY date_start DESC";
		$sql .= $this->db->plimit(1);
		$rate = null;
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$rate = array(
				'ht' => (float) $obj->amount_ht,
				'ttc' => ($obj->amount_ttc === null || $obj->amount_ttc === '') ? null : (float) $obj->amount_ttc,
				'fee_type' => (string) $obj->fee_type,
				'date_start' => (string) $obj->date_start,
			);
		}
		self::$rateCache[$key] = $rate;
		return $rate;
	}

	/**
	 * Rate excl. VAT of a category at a date.
	 *
	 * @param string $code Category code
	 * @param string $date Date YYYY-MM-DD
	 * @return float|null
	 */
	public function getRate($code, $date = '')
	{
		$info = $this->getRateInfo($code, $date);
		return $info === null ? null : $info['ht'];
	}

	/**
	 * Rate history of a category, most recent first.
	 *
	 * @param string $code Category code
	 * @return array<int,array<string,mixed>>
	 */
	public function getRateHistory($code)
	{
		$rows = array();
		$sql = "SELECT rowid, amount_ht, amount_ttc, fee_type, date_start, date_end, source FROM " . MAIN_DB_PREFIX . "emrecupel_rate";
		$sql .= " WHERE entity = " . $this->entity();
		$sql .= " AND category_code = '" . $this->db->escape((string) $code) . "'";
		$sql .= " ORDER BY date_start DESC";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$rows[] = array(
					'id' => (int) $obj->rowid,
					'amount_ht' => (float) $obj->amount_ht,
					'amount_ttc' => ($obj->amount_ttc === null ? null : (float) $obj->amount_ttc),
					'fee_type' => (string) $obj->fee_type,
					'date_start' => (string) $obj->date_start,
					'date_end' => $obj->date_end === null ? '' : (string) $obj->date_end,
					'source' => (string) $obj->source,
				);
			}
		}
		return $rows;
	}

	/**
	 * Every rate start date known for the entity (to build the scale selector).
	 *
	 * @return string[]
	 */
	public function getScaleDates()
	{
		$dates = array();
		$sql = "SELECT DISTINCT date_start FROM " . MAIN_DB_PREFIX . "emrecupel_rate WHERE entity = " . $this->entity() . " ORDER BY date_start DESC";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$dates[] = (string) $obj->date_start;
			}
		}
		return $dates;
	}

	/**
	 * Parse a scale file (CSV, ';' separated, UTF-8, header line).
	 *
	 * Columns: code;parent_code;use_type;fee_type;label_fr;label_nl;label_en;amount_ht;amount_ttc;date_start;date_end;source
	 * Lines starting with '#' are comments. date_end may be empty.
	 *
	 * @param string $content CSV content
	 * @return array{rows: array<int,array<string,string>>, errors: string[]}
	 */
	public static function parseScaleCsv($content)
	{
		$rows = array();
		$errors = array();
		$content = preg_replace('/^\xEF\xBB\xBF/', '', (string) $content);
		$lines = preg_split('/\r\n|\r|\n/', $content);
		$header = null;
		$expected = array('code', 'parent_code', 'use_type', 'fee_type', 'label_fr', 'label_nl', 'label_en', 'amount_ht', 'amount_ttc', 'date_start', 'date_end', 'source');
		foreach ($lines as $num => $line) {
			if (trim($line) === '' || $line[0] === '#') {
				continue;
			}
			$cols = str_getcsv($line, ';', '"', '\\');
			if ($header === null) {
				$header = array_map(function ($c) {
					return strtolower(trim((string) $c));
				}, $cols);
				$missing = array_diff(array('code', 'amount_ht', 'date_start'), $header);
				if (!empty($missing)) {
					$errors[] = 'Missing columns: ' . implode(', ', $missing);
					return array('rows' => array(), 'errors' => $errors);
				}
				continue;
			}
			$row = array();
			foreach ($expected as $name) {
				$idx = array_search($name, $header, true);
				$row[$name] = ($idx !== false && isset($cols[$idx])) ? trim((string) $cols[$idx]) : '';
			}
			$lineNo = $num + 1;
			if ($row['code'] === '' || !preg_match('/^[0-9A-Za-z.\-]{1,16}$/', $row['code'])) {
				$errors[] = 'Line ' . $lineNo . ': invalid code';
				continue;
			}
			foreach (array('amount_ht', 'amount_ttc') as $a) {
				if ($row[$a] !== '') {
					$row[$a] = str_replace(',', '.', $row[$a]);
					if (!is_numeric($row[$a]) || (float) $row[$a] < 0) {
						$errors[] = 'Line ' . $lineNo . ': invalid ' . $a;
						continue 2;
					}
				}
			}
			if (!in_array($row['fee_type'], array('', 'allin', 'admin'), true)) {
				$errors[] = 'Line ' . $lineNo . ': fee_type must be allin or admin';
				continue;
			}
			foreach (array('date_start', 'date_end') as $d) {
				if ($row[$d] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $row[$d])) {
					$errors[] = 'Line ' . $lineNo . ': invalid ' . $d . ' (YYYY-MM-DD expected)';
					continue 2;
				}
			}
			if ($row['amount_ht'] !== '' && $row['date_start'] === '') {
				$errors[] = 'Line ' . $lineNo . ': date_start required with an amount';
				continue;
			}
			if (!in_array($row['use_type'], array('', 'household', 'professional'), true)) {
				$errors[] = 'Line ' . $lineNo . ': use_type must be household or professional';
				continue;
			}
			$rows[] = $row;
		}
		return array('rows' => $rows, 'errors' => $errors);
	}

	/**
	 * Import parsed scale rows (idempotent upsert of categories and rates).
	 *
	 * A new rate closes the previous open rate of the same category the day
	 * before it starts, so a yearly scale can be imported in advance.
	 *
	 * @param array<int,array<string,string>> $rows Rows from parseScaleCsv()
	 * @return array{categories: int, rates: int, errors: string[]}
	 */
	public function importScale(array $rows)
	{
		$entity = $this->entity();
		$nbCat = 0;
		$nbRate = 0;
		$errors = array();
		$position = 0;
		$now = $this->db->idate(dol_now());

		$this->db->begin();
		foreach ($rows as $row) {
			$position += 10;
			$code = $this->db->escape($row['code']);
			$hasLabel = ($row['label_fr'] !== '' || $row['label_nl'] !== '' || $row['label_en'] !== '');

			$sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "emrecupel_cat WHERE entity = " . $entity . " AND code = '" . $code . "'";
			$resql = $this->db->query($sql);
			$existing = ($resql && ($obj = $this->db->fetch_object($resql))) ? (int) $obj->rowid : 0;
			if ($existing > 0) {
				if ($hasLabel) {
					$sql = "UPDATE " . MAIN_DB_PREFIX . "emrecupel_cat SET";
					$sql .= " parent_code = " . ($row['parent_code'] !== '' ? "'" . $this->db->escape($row['parent_code']) . "'" : "NULL");
					$sql .= ", label_fr = '" . $this->db->escape($row['label_fr']) . "'";
					$sql .= ", label_nl = '" . $this->db->escape($row['label_nl']) . "'";
					$sql .= ", label_en = '" . $this->db->escape($row['label_en']) . "'";
					if ($row['use_type'] !== '') {
						$sql .= ", use_type = '" . $this->db->escape($row['use_type']) . "'";
					}
					$sql .= ", position = " . $position;
					$sql .= " WHERE rowid = " . $existing;
					if (!$this->db->query($sql)) {
						$errors[] = $row['code'] . ': ' . $this->db->lasterror();
						continue;
					}
					$nbCat++;
				}
			} else {
				if (!$hasLabel) {
					$errors[] = $row['code'] . ': unknown category without label';
					continue;
				}
				$sql = "INSERT INTO " . MAIN_DB_PREFIX . "emrecupel_cat (entity, code, parent_code, label_fr, label_nl, label_en, use_type, position, active, date_creation) VALUES (";
				$sql .= $entity . ", '" . $code . "'";
				$sql .= ", " . ($row['parent_code'] !== '' ? "'" . $this->db->escape($row['parent_code']) . "'" : "NULL");
				$sql .= ", '" . $this->db->escape($row['label_fr']) . "'";
				$sql .= ", '" . $this->db->escape($row['label_nl']) . "'";
				$sql .= ", '" . $this->db->escape($row['label_en']) . "'";
				$sql .= ", '" . $this->db->escape($row['use_type'] !== '' ? $row['use_type'] : 'household') . "'";
				$sql .= ", " . $position . ", 1, '" . $now . "')";
				if (!$this->db->query($sql)) {
					$errors[] = $row['code'] . ': ' . $this->db->lasterror();
					continue;
				}
				$nbCat++;
			}

			if ($row['amount_ht'] === '') {
				continue;
			}
			$feeType = $row['fee_type'] !== '' ? $row['fee_type'] : ($row['use_type'] === 'professional' ? 'admin' : 'allin');
			$ttc = $row['amount_ttc'] !== '' ? (float) $row['amount_ttc'] : null;
			$res = $this->upsertRate($row['code'], (float) $row['amount_ht'], $row['date_start'], $row['date_end'], $row['source'], $ttc, $feeType);
			if ($res < 0) {
				$errors[] = $row['code'] . ': ' . $this->error;
				continue;
			}
			$nbRate += $res;
		}

		if (empty($errors)) {
			$this->db->commit();
		} else {
			// Partial imports would leave a half-updated scale: all or nothing.
			$this->db->rollback();
			$nbCat = 0;
			$nbRate = 0;
		}
		$this->refreshSelectable();
		self::clearCache();

		return array('categories' => $nbCat, 'rates' => $nbRate, 'errors' => $errors);
	}

	/**
	 * Insert or update one dated rate.
	 *
	 * @param string $code     Category code
	 * @param float  $amount   Amount excl. VAT
	 * @param string $start    YYYY-MM-DD
	 * @param string $end      YYYY-MM-DD or ''
	 * @param string     $source  Free text (document, URL)
	 * @param float|null $ttc     Official amount incl. VAT (21 %), null if unknown
	 * @param string     $feeType allin or admin
	 * @return int 1 if written, 0 if unchanged, -1 on error
	 */
	public function upsertRate($code, $amount, $start, $end = '', $source = '', $ttc = null, $feeType = 'allin')
	{
		$ttcSql = ($ttc === null ? "NULL" : price2num($ttc, 'MU'));
		$feeType = in_array($feeType, array('allin', 'admin'), true) ? $feeType : 'allin';
		$entity = $this->entity();
		$ecode = $this->db->escape((string) $code);
		$estart = $this->db->escape((string) $start);
		$endSql = ($end !== '' ? "'" . $this->db->escape($end) . "'" : "NULL");

		$sql = "SELECT rowid, amount_ht, amount_ttc, fee_type, date_end FROM " . MAIN_DB_PREFIX . "emrecupel_rate";
		$sql .= " WHERE entity = " . $entity . " AND category_code = '" . $ecode . "' AND date_start = '" . $estart . "'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$obj = $this->db->fetch_object($resql);
		if ($obj) {
			$sameTtc = ($ttc === null && $obj->amount_ttc === null) || ($ttc !== null && $obj->amount_ttc !== null && abs((float) $obj->amount_ttc - (float) $ttc) < 0.000001);
			if (abs((float) $obj->amount_ht - (float) $amount) < 0.000001 && $sameTtc && (string) $obj->fee_type === $feeType
				&& ($end === '' || (string) $obj->date_end === (string) $end)) {
				return 0;
			}
			$sql = "UPDATE " . MAIN_DB_PREFIX . "emrecupel_rate SET amount_ht = " . price2num($amount, 'MU') . ", amount_ttc = " . $ttcSql . ", fee_type = '" . $feeType . "'";
			if ($end !== '') {
				$sql .= ", date_end = '" . $this->db->escape($end) . "'";
			}
			$sql .= ", source = '" . $this->db->escape((string) $source) . "' WHERE rowid = " . (int) $obj->rowid;
		} else {
			// Close the rate that was open at that date (computed in PHP: DATE_SUB is not portable).
			$sql = "UPDATE " . MAIN_DB_PREFIX . "emrecupel_rate SET date_end = '" . $this->db->escape(self::dayBefore((string) $start)) . "'";
			$sql .= " WHERE entity = " . $entity . " AND category_code = '" . $ecode . "'";
			$sql .= " AND date_start < '" . $estart . "' AND (date_end IS NULL OR date_end >= '" . $estart . "')";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			// A rate already known after this one bounds the new one.
			if ($end === '') {
				$sql = "SELECT MIN(date_start) as nextstart FROM " . MAIN_DB_PREFIX . "emrecupel_rate";
				$sql .= " WHERE entity = " . $entity . " AND category_code = '" . $ecode . "' AND date_start > '" . $estart . "'";
				$resn = $this->db->query($sql);
				if ($resn && ($on = $this->db->fetch_object($resn)) && !empty($on->nextstart)) {
					$endSql = "'" . $this->db->escape(self::dayBefore(substr((string) $on->nextstart, 0, 10))) . "'";
				}
			}
			$sql = "INSERT INTO " . MAIN_DB_PREFIX . "emrecupel_rate (entity, category_code, amount_ht, amount_ttc, fee_type, date_start, date_end, source, date_creation) VALUES (";
			$sql .= $entity . ", '" . $ecode . "', " . price2num($amount, 'MU') . ", " . $ttcSql . ", '" . $feeType . "', '" . $estart . "', " . $endSql;
			$sql .= ", '" . $this->db->escape((string) $source) . "', '" . $this->db->idate(dol_now()) . "')";
		}
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		self::clearCache();
		return 1;
	}

	/**
	 * Day before a date.
	 *
	 * @param string $date YYYY-MM-DD
	 * @return string YYYY-MM-DD
	 */
	public static function dayBefore($date)
	{
		return date('Y-m-d', strtotime($date . ' 12:00:00 -1 day'));
	}

	/**
	 * Every leaf category with its rate at a date (scale screen, selectors).
	 *
	 * @param string $date     YYYY-MM-DD (today when empty)
	 * @param string $langcode Language of the labels
	 * @return array<string,array<string,mixed>> code => row (code, label, use_type, active, ht, ttc, fee_type, date_start)
	 */
	public function getScale($date = '', $langcode = 'fr_FR')
	{
		$out = array();
		foreach ($this->getAllCategories() as $code => $cat) {
			// Inactive leaves stay listed (flag 'active') so that history stays readable.
			if ($this->hasChildren($code)) {
				continue;
			}
			$rate = $this->getRateInfo($code, $date);
			$out[$code] = array(
				'code' => $code,
				'parent_code' => $cat['parent_code'],
				'label' => $this->getLabel($code, $langcode),
				'use_type' => $cat['use_type'],
				'active' => $cat['active'],
				'ht' => $rate === null ? null : $rate['ht'],
				'ttc' => $rate === null ? null : $rate['ttc'],
				'fee_type' => $rate === null ? '' : $rate['fee_type'],
				'date_start' => $rate === null ? '' : $rate['date_start'],
			);
		}
		uksort($out, array(__CLASS__, 'compareCodes'));
		return $out;
	}

	/**
	 * Tell whether a category has sub-categories (main categories 1 to 6).
	 *
	 * @param string $code Category code
	 * @return bool
	 */
	public function hasChildren($code)
	{
		foreach ($this->getAllCategories() as $other) {
			if ($other['parent_code'] === (string) $code) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Natural order of Recupel codes (4.2 < 4.10 < 4.50).
	 *
	 * @param string $a Code
	 * @param string $b Code
	 * @return int
	 */
	public static function compareCodes($a, $b)
	{
		$pa = array_map('intval', explode('.', (string) $a));
		$pb = array_map('intval', explode('.', (string) $b));
		$n = max(count($pa), count($pb));
		for ($i = 0; $i < $n; $i++) {
			$x = isset($pa[$i]) ? $pa[$i] : -1;
			$y = isset($pb[$i]) ? $pb[$i] : -1;
			if ($x !== $y) {
				return $x < $y ? -1 : 1;
			}
		}
		return 0;
	}

	/**
	 * Compare the scale valid at two dates (yearly switch assistant).
	 *
	 * @param string $dateOld YYYY-MM-DD
	 * @param string $dateNew YYYY-MM-DD
	 * @param string $langcode Language of the labels
	 * @return array<string,array<string,mixed>> code => row with old_*, new_*, status (same, changed, new, removed)
	 */
	public function compareScales($dateOld, $dateNew, $langcode = 'fr_FR')
	{
		$old = $this->getScale($dateOld, $langcode);
		$new = $this->getScale($dateNew, $langcode);
		$out = array();
		foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $code) {
			$o = isset($old[$code]) ? $old[$code] : null;
			$n = isset($new[$code]) ? $new[$code] : null;
			$oh = $o !== null ? $o['ht'] : null;
			$nh = $n !== null ? $n['ht'] : null;
			if ($oh === null && $nh === null) {
				continue;
			}
			if ($oh === null) {
				$status = 'new';
			} elseif ($nh === null) {
				$status = 'removed';
			} elseif (abs($oh - $nh) > 0.000001 || $o['fee_type'] !== $n['fee_type']) {
				$status = 'changed';
			} else {
				$status = 'same';
			}
			$out[$code] = array(
				'code' => $code,
				'label' => $n !== null ? $n['label'] : $o['label'],
				'use_type' => $n !== null ? $n['use_type'] : $o['use_type'],
				'old_ht' => $oh,
				'old_ttc' => $o !== null ? $o['ttc'] : null,
				'old_fee_type' => $o !== null ? $o['fee_type'] : '',
				'new_ht' => $nh,
				'new_ttc' => $n !== null ? $n['ttc'] : null,
				'new_fee_type' => $n !== null ? $n['fee_type'] : '',
				'status' => $status,
			);
		}
		uksort($out, array(__CLASS__, 'compareCodes'));
		return $out;
	}

	/**
	 * Next scale start date after a date (announced scale), '' if none.
	 *
	 * @param string $date YYYY-MM-DD
	 * @return string
	 */
	public function getNextScaleDate($date)
	{
		foreach (array_reverse($this->getScaleDates()) as $d) {
			if ($d > $date) {
				return $d;
			}
		}
		return '';
	}

	/**
	 * Activate or deactivate a category.
	 *
	 * @param string $code   Category code
	 * @param int    $active 0 or 1
	 * @return int
	 */
	public function setActive($code, $active)
	{
		$sql = "UPDATE " . MAIN_DB_PREFIX . "emrecupel_cat SET active = " . ((int) $active ? 1 : 0);
		$sql .= " WHERE entity = " . $this->entity() . " AND code = '" . $this->db->escape((string) $code) . "'";
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		self::clearCache();
		$this->refreshSelectable();
		return 1;
	}

	/**
	 * Recompute the `selectable` flag used by the product extrafield list:
	 * only active leaf categories can be assigned to a product.
	 *
	 * @return void
	 */
	public function refreshSelectable()
	{
		self::clearCache();
		$all = $this->getAllCategories();
		$parents = array();
		foreach ($all as $cat) {
			if ($cat['parent_code'] !== '') {
				$parents[$cat['parent_code']] = true;
			}
		}
		foreach ($all as $cat) {
			$selectable = (!empty($cat['active']) && !isset($parents[$cat['code']])) ? 1 : 0;
			$this->db->query("UPDATE " . MAIN_DB_PREFIX . "emrecupel_cat SET selectable = " . $selectable . " WHERE rowid = " . (int) $cat['id']);
		}
		self::clearCache();
	}

	/**
	 * Load the scale files bundled with the module (data/*.csv), oldest first.
	 *
	 * @return array{categories: int, rates: int, errors: string[]}
	 */
	public function importBundledScales()
	{
		$total = array('categories' => 0, 'rates' => 0, 'errors' => array());
		$dir = dol_buildpath('/emrecupel/data', 0);
		$files = glob($dir . '/recupel-scale-*.csv');
		if (empty($files)) {
			$total['errors'][] = 'No bundled scale found in ' . $dir;
			return $total;
		}
		sort($files);
		foreach ($files as $file) {
			$parsed = self::parseScaleCsv((string) file_get_contents($file));
			if (!empty($parsed['errors'])) {
				$total['errors'] = array_merge($total['errors'], $parsed['errors']);
				continue;
			}
			$res = $this->importScale($parsed['rows']);
			$total['categories'] += $res['categories'];
			$total['rates'] += $res['rates'];
			$total['errors'] = array_merge($total['errors'], $res['errors']);
		}
		return $total;
	}
}
