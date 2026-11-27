<?php
/* Copyright (C) 2026 E-dem SRL <support@e-dem.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/emrecupelfeepolicy.class.php
 * \ingroup emrecupel
 * \brief   Pure decision and computation rules for the Recupel fee.
 *
 * No database, no Dolibarr global: every input comes from the caller, so the
 * whole policy is covered by unit tests. EmrecupelDocumentSync gathers the
 * context from Dolibarr objects and delegates every decision here.
 *
 * Legal background (Recupel FAQ 2.13, CNC advice 2009/9):
 *  - household appliance sold to a reseller of electrical appliances: the fee
 *    must be shown separately, per appliance;
 *  - any other case (end user, professional appliance): "Recupel fee
 *    included" is enough, a separate line remains allowed;
 *  - nothing is due on appliances delivered outside Belgium;
 *  - a second-hand appliance already placed on the Belgian market must not
 *    carry a fee;
 *  - VAT on the fee follows the VAT of the appliance.
 */
class EmrecupelFeePolicy
{
	/** Fee applies */
	const APPLY = 'apply';
	/** Document type not enabled in setup */
	const SKIP_DOCTYPE = 'doctype_disabled';
	/** Line is not a product/service line (title, subtotal, free text…) */
	const SKIP_NOT_PRODUCT = 'not_a_product_line';
	/** Product has no Recupel category */
	const SKIP_NO_CATEGORY = 'no_category';
	/** Category unknown, deactivated or "not subject" */
	const SKIP_CATEGORY_INACTIVE = 'category_inactive';
	/** No tariff valid at the document date */
	const SKIP_NO_TARIFF = 'no_tariff';
	/** Customer flagged as exempt */
	const SKIP_CUSTOMER_EXEMPT = 'customer_exempt';
	/** Delivery outside Belgium (export / intra-EU) */
	const SKIP_EXPORT = 'export';
	/** Second-hand appliance of Belgian origin: charging a fee is forbidden */
	const SKIP_USED = 'used_goods';
	/** User removed the fee by hand on this line */
	const SKIP_OPTED_OUT = 'opted_out';
	/** Zero quantity */
	const SKIP_ZERO_QTY = 'zero_qty';

	/** Product explicitly flagged "not subject" */
	const SKIP_PRODUCT_EXEMPT = 'product_exempt';

	/** Display: separate fee line */
	const MODE_LINE = 'line';
	/** Display: fee included in the price, amount mentioned under the product line and summed in the footer */
	const MODE_INCLUDED = 'included';
	/** Display: fee included in the price, only a footer mention with the total */
	const MODE_FOOTER = 'footer';

	/** Fee type: all-in fee (household appliances, 1.50 from 2027) */
	const FEE_ALLIN = 'allin';
	/** Fee type: administrative fee (professional appliances) */
	const FEE_ADMIN = 'admin';

	/** Declaration bucket: appliance placed on the Belgian market by us (imported/produced) */
	const BUCKET_MARKET = 'market';
	/** Declaration bucket: fee paid to a Belgian supplier, appliance sold abroad ("Returnable") */
	const BUCKET_RETURNABLE = 'returnable';
	/** Declaration bucket: bought in Belgium and sold in Belgium, nothing to declare (control only) */
	const BUCKET_CONTROL = 'control';
	/** Declaration bucket: not declared at all (import re-exported, used goods) */
	const BUCKET_NONE = 'none';

	/** Company profile A: buys in Belgium, sells in Belgium */
	const PROFILE_RESELLER = 'reseller';
	/** Company profile B: reseller affiliated for export refunds */
	const PROFILE_EXPORTER = 'exporter';
	/** Company profile C: importer or manufacturer, Recupel member */
	const PROFILE_PRODUCER = 'producer';

	/** Yearly cap of administrative fees per member (EUR excl. VAT) */
	const ADMIN_FEE_CAP = 10000.0;

	/** Customer role: end user (company or consumer) */
	const ROLE_ENDUSER = 'enduser';
	/** Customer role: reseller of electrical appliances */
	const ROLE_RESELLER = 'reseller';
	/** Customer role: never charge (e.g. Recupel member buying for export) */
	const ROLE_EXEMPT = 'exempt';

	/** Origin: imported or produced by us, we declare it to Recupel */
	const ORIGIN_IMPORT = 'import';
	/** Origin: bought from a Belgian supplier who charged the fee */
	const ORIGIN_LOCAL = 'local';
	/** Origin: second-hand appliance already placed on the Belgian market */
	const ORIGIN_USED = 'used';

	/** Country rule: charge only customers located in Belgium */
	const COUNTRY_BE_ONLY = 'BE';
	/** Country rule: charge every customer */
	const COUNTRY_ALL = 'ALL';

	/**
	 * Decide whether a fee is due for one Recupel code of a source line.
	 *
	 * Keys of $ctx (all optional, safe defaults):
	 *  - doctype_enabled (bool)
	 *  - is_product_line (bool)     line with fk_product and a normal product_type
	 *  - opted_out (bool)           user deliberately removed the fee
	 *  - qty (float)
	 *  - category_code (string)
	 *  - category_active (bool)     known, active and subject to a fee
	 *  - tariff_amount (float|null) unit amount excl. VAT, null when no tariff
	 *  - customer_role (string)     ROLE_*
	 *  - customer_country (string)  ISO code, '' when unknown
	 *  - country_rule (string)      COUNTRY_BE_ONLY or COUNTRY_ALL
	 *  - origin (string)            ORIGIN_*
	 *
	 * @param array<string,mixed> $ctx Context
	 * @return string APPLY or one of the SKIP_* reasons
	 */
	public static function decide(array $ctx): string
	{
		if (empty($ctx['doctype_enabled'])) {
			return self::SKIP_DOCTYPE;
		}
		if (empty($ctx['is_product_line'])) {
			return self::SKIP_NOT_PRODUCT;
		}
		if (!empty($ctx['product_exempt'])) {
			return self::SKIP_PRODUCT_EXEMPT;
		}
		if (empty($ctx['category_code'])) {
			return self::SKIP_NO_CATEGORY;
		}
		if (empty($ctx['category_active'])) {
			return self::SKIP_CATEGORY_INACTIVE;
		}
		if (isset($ctx['customer_role']) && $ctx['customer_role'] === self::ROLE_EXEMPT) {
			return self::SKIP_CUSTOMER_EXEMPT;
		}
		if (self::isExport($ctx['customer_country'] ?? '', $ctx['country_rule'] ?? self::COUNTRY_BE_ONLY)) {
			return self::SKIP_EXPORT;
		}
		if (isset($ctx['origin']) && $ctx['origin'] === self::ORIGIN_USED) {
			return self::SKIP_USED;
		}
		if (!empty($ctx['opted_out'])) {
			return self::SKIP_OPTED_OUT;
		}
		if (!array_key_exists('tariff_amount', $ctx) || $ctx['tariff_amount'] === null) {
			return self::SKIP_NO_TARIFF;
		}
		if (empty($ctx['qty']) || (float) $ctx['qty'] == 0.0) {
			return self::SKIP_ZERO_QTY;
		}

		return self::APPLY;
	}

	/**
	 * Tell whether a sale leaves the Belgian market.
	 *
	 * Unknown country: we assume a domestic sale. Under-charging a Belgian
	 * customer is a compliance risk, over-charging an exporter is visible on the
	 * document and easy to correct.
	 *
	 * @param string $country ISO country code of the customer
	 * @param string $rule    COUNTRY_BE_ONLY or COUNTRY_ALL
	 * @return bool
	 */
	public static function isExport(string $country, string $rule = self::COUNTRY_BE_ONLY): bool
	{
		if ($rule !== self::COUNTRY_BE_ONLY) {
			return false;
		}
		$country = strtoupper(trim($country));
		return $country !== '' && $country !== 'BE';
	}

	/**
	 * Valid display modes.
	 *
	 * @return string[]
	 */
	public static function modes(): array
	{
		return array(self::MODE_LINE, self::MODE_INCLUDED, self::MODE_FOOTER);
	}

	/**
	 * Default display matrix (Recupel FAQ 2.13 and research § 5.3).
	 *
	 * @return array<string,string>
	 */
	public static function defaultSettings(): array
	{
		return array(
			'reseller_professional' => self::MODE_INCLUDED,
			'enduser_household' => self::MODE_INCLUDED,
			'enduser_professional' => self::MODE_INCLUDED,
		);
	}

	/**
	 * How the fee is shown for an appliance and a customer.
	 *
	 * The only legal obligation is the separate mention of an all-in fee
	 * (household appliances, and 1.50 from 2027) on a sale to a reseller of
	 * electrical appliances: that case is always MODE_LINE, whatever the setup
	 * or the override. Every other case follows the override of the customer,
	 * then the company setup.
	 *
	 * @param string               $useType  household or professional (usage of the code)
	 * @param string               $feeType  FEE_ALLIN or FEE_ADMIN (type of the rate at the document date)
	 * @param string               $role     ROLE_ENDUSER or ROLE_RESELLER
	 * @param array<string,string> $settings Keys reseller_professional, enduser_household, enduser_professional
	 * @param string               $override Mode forced on the customer ('' = none)
	 * @return string MODE_*
	 */
	public static function displayMode(string $useType, string $feeType, string $role, array $settings = array(), string $override = ''): string
	{
		$pro = ($useType === 'professional');
		if ($role === self::ROLE_RESELLER && ($feeType === self::FEE_ALLIN || ($feeType === '' && !$pro))) {
			return self::MODE_LINE;
		}
		if (in_array($override, self::modes(), true)) {
			return $override;
		}
		if ($role === self::ROLE_RESELLER) {
			$key = 'reseller_professional';
		} else {
			$key = $pro ? 'enduser_professional' : 'enduser_household';
		}
		$defaults = self::defaultSettings();
		$mode = isset($settings[$key]) && $settings[$key] !== '' ? (string) $settings[$key] : $defaults[$key];
		return in_array($mode, self::modes(), true) ? $mode : $defaults[$key];
	}

	/**
	 * Tell whether a display mode needs the legal "separate mention" (cannot
	 * be changed by the user).
	 *
	 * @param string $useType household or professional
	 * @param string $feeType FEE_ALLIN or FEE_ADMIN
	 * @param string $role    Customer role
	 * @return bool
	 */
	public static function isLineMandatory(string $useType, string $feeType, string $role): bool
	{
		return $role === self::ROLE_RESELLER && ($feeType === self::FEE_ALLIN || ($feeType === '' && $useType !== 'professional'));
	}

	/**
	 * Default origin of products for a company profile.
	 *
	 * @param string $profile PROFILE_*
	 * @return string ORIGIN_*
	 */
	public static function profileDefaultOrigin(string $profile): string
	{
		return $profile === self::PROFILE_PRODUCER ? self::ORIGIN_IMPORT : self::ORIGIN_LOCAL;
	}

	/**
	 * Declaration bucket of an appliance sold on a validated invoice.
	 *
	 *  - imported/produced and sold in Belgium: "brought to market";
	 *  - bought in Belgium (fee paid to the supplier) and sold abroad: "Returnable";
	 *  - bought in Belgium and sold in Belgium: nothing to declare, the fee was
	 *    passed on (control statement of a reseller);
	 *  - imported and sold abroad, second-hand goods: never declared.
	 *
	 * @param string $origin ORIGIN_*
	 * @param bool   $export Delivered outside Belgium
	 * @return string BUCKET_*
	 */
	public static function bucket(string $origin, bool $export): string
	{
		if ($origin === self::ORIGIN_USED) {
			return self::BUCKET_NONE;
		}
		if ($origin === self::ORIGIN_IMPORT) {
			return $export ? self::BUCKET_NONE : self::BUCKET_MARKET;
		}
		return $export ? self::BUCKET_RETURNABLE : self::BUCKET_CONTROL;
	}

	/**
	 * Buckets shown in the declaration of a company profile.
	 *
	 * @param string $profile PROFILE_*
	 * @return string[] BUCKET_*
	 */
	public static function declaredBuckets(string $profile): array
	{
		if ($profile === self::PROFILE_PRODUCER) {
			return array(self::BUCKET_MARKET, self::BUCKET_RETURNABLE);
		}
		if ($profile === self::PROFILE_EXPORTER) {
			return array(self::BUCKET_RETURNABLE);
		}
		return array();
	}

	/**
	 * VAT-inclusive fee for one sold unit, as shown in "included" mode.
	 *
	 * @param float      $ht      Unit rate excl. VAT
	 * @param float|null $ttc     Official unit rate incl. VAT (21 %)
	 * @param float      $units   Appliances per sold unit
	 * @param float      $vatRate VAT rate of the product line
	 * @return float
	 */
	public static function includedAmount(float $ht, ?float $ttc, float $units, float $vatRate): float
	{
		if ($units <= 0) {
			$units = 1.0;
		}
		if ($ttc !== null && abs($vatRate - 21.0) < 0.0001) {
			return round($ttc * $units, 4);
		}
		return round($ht * $units * (1 + $vatRate / 100), 4);
	}

	/**
	 * Start and end dates of a declaration period.
	 *
	 * @param string $type  month, quarter or year
	 * @param int    $year  Year
	 * @param int    $index Month (1-12) or quarter (1-4); ignored for a year
	 * @return array{0:string,1:string}
	 */
	public static function periodBounds(string $type, int $year, int $index = 1): array
	{
		if ($type === 'month') {
			$index = max(1, min(12, $index));
			$start = sprintf('%04d-%02d-01', $year, $index);
			return array($start, date('Y-m-t', strtotime($start)));
		}
		if ($type === 'year') {
			return array(sprintf('%04d-01-01', $year), sprintf('%04d-12-31', $year));
		}
		return self::quarterBounds($year, $index);
	}

	/**
	 * Origin of a product, its own value or the company default.
	 *
	 * @param string $productOrigin Value of the product (may be '')
	 * @param string $default       Company default
	 * @return string ORIGIN_*
	 */
	public static function effectiveOrigin(string $productOrigin, string $default = self::ORIGIN_LOCAL): string
	{
		$valid = array(self::ORIGIN_IMPORT, self::ORIGIN_LOCAL, self::ORIGIN_USED);
		if (in_array($productOrigin, $valid, true)) {
			return $productOrigin;
		}
		return in_array($default, $valid, true) ? $default : self::ORIGIN_LOCAL;
	}

	/**
	 * Price of the fee line for one sold unit.
	 *
	 * The fee line keeps the quantity of the product line (easier to read);
	 * a product that contains several appliances (pack of 4 lamps) carries a
	 * multiplier. When the official VAT-inclusive amount is known and the
	 * line VAT is the reference rate (21 %), the line is priced VAT included
	 * so that the customer pays the round official amount (10,00 € and not
	 * 9,99 €).
	 *
	 * @param float      $ht       Unit rate excl. VAT
	 * @param float|null $ttc      Official unit rate incl. VAT (21 %)
	 * @param float      $units    Appliances per sold unit
	 * @param float      $vatRate  VAT rate of the line
	 * @param bool       $preferTtc Use the VAT-inclusive amount when possible
	 * @return array{base:string,price:float,subprice:float}
	 *   base HT or TTC, price = amount to give to addline in that base,
	 *   subprice = expected unit price excl. VAT stored by Dolibarr
	 */
	public static function linePrice(float $ht, ?float $ttc, float $units, float $vatRate, bool $preferTtc = true): array
	{
		if ($units <= 0) {
			$units = 1.0;
		}
		if ($preferTtc && $ttc !== null && abs($vatRate - 21.0) < 0.0001) {
			$priceTtc = round($ttc * $units, 4);
			return array('base' => 'TTC', 'price' => $priceTtc, 'subprice' => round($priceTtc / 1.21, 5));
		}
		$priceHt = round($ht * $units, 5);
		return array('base' => 'HT', 'price' => $priceHt, 'subprice' => $priceHt);
	}

	/**
	 * Estimated Recupel amount (excl. VAT) of a declaration row: appliances ×
	 * rate, kept with 8 decimals. Recupel bills the number of appliances of the
	 * period × the rate, so the amount is rounded once, on the total, never per
	 * invoice line (5 lines of 4 appliances at 0,1240 = 2,48, not 5 × 0,50).
	 *
	 * @param float $rate  Unit rate excl. VAT
	 * @param float $units Signed number of appliances
	 * @return float
	 */
	public static function ledgerAmount(float $rate, float $units): float
	{
		return round($rate * $units, 8);
	}

	/**
	 * Number of appliances to declare for a line.
	 *
	 * @param float $qty   Quantity of the line
	 * @param float $units Appliances per sold unit
	 * @param bool  $creditNote True for a credit note (counts negatively)
	 * @return float
	 */
	public static function declaredUnits(float $qty, float $units = 1.0, bool $creditNote = false): float
	{
		if ($units <= 0) {
			$units = 1.0;
		}
		$n = $qty * $units;
		return $creditNote ? -abs($n) : $n;
	}

	/**
	 * Build the description of the fee line from a template.
	 *
	 * Placeholders: {CODE}, {LABEL}, {AMOUNT} (unit tariff), {UNITS}.
	 *
	 * @param string $template Template
	 * @param string $code     Category code
	 * @param string $label    Category label in the customer language
	 * @param string $amount   Formatted unit tariff
	 * @param float  $units    Appliances per sold unit
	 * @return string
	 */
	public static function buildDescription(string $template, string $code, string $label, string $amount = '', float $units = 1.0): string
	{
		$unitsStr = (string) ($units == (int) $units ? (int) $units : $units);
		$desc = strtr($template, array(
			'{CODE}' => $code,
			'{LABEL}' => $label,
			'{AMOUNT}' => $amount,
			'{UNITS}' => $unitsStr,
		));
		if ($units > 1 && strpos($template, '{UNITS}') === false) {
			$desc .= ' (x' . $unitsStr . ')';
		}
		return trim($desc);
	}

	/**
	 * Tell whether an existing fee line must be rewritten.
	 *
	 * Amounts are compared in absolute value: Dolibarr stores credit note
	 * prices as negative numbers.
	 *
	 * @param array<string,mixed> $current  qty, subprice, tva_tx, vat_src_code, category_code, units
	 * @param array<string,mixed> $expected Same keys
	 * @return bool
	 */
	public static function feeLineDiffers(array $current, array $expected): bool
	{
		$eps = 0.00001;
		if (abs((float) $current['qty'] - (float) $expected['qty']) > $eps) {
			return true;
		}
		if (abs(abs((float) $current['subprice']) - abs((float) $expected['subprice'])) > 0.0001) {
			return true;
		}
		if (abs((float) $current['tva_tx'] - (float) $expected['tva_tx']) > $eps) {
			return true;
		}
		if ((string) ($current['vat_src_code'] ?? '') !== (string) ($expected['vat_src_code'] ?? '')) {
			return true;
		}
		if ((string) $current['category_code'] !== (string) $expected['category_code']) {
			return true;
		}
		if (abs((float) $current['units'] - (float) $expected['units']) > $eps) {
			return true;
		}
		return false;
	}

	/**
	 * Generate a link key shared by a product line and its fee lines.
	 *
	 * Stored in a line extrafield, so Dolibarr copies it with the lines when a
	 * proposal becomes an order, an invoice or a credit note: the pair stays
	 * linked across documents without any mapping table.
	 *
	 * @return string 13 characters
	 */
	public static function newLinkKey(): string
	{
		return 'r' . bin2hex(random_bytes(6));
	}

	/**
	 * Marker stored on a product line whose fee was removed by hand.
	 *
	 * @param string $key Link key
	 * @return string
	 */
	public static function optOutKey(string $key): string
	{
		return '-' . ltrim($key, '-');
	}

	/**
	 * @param string|null $key Link key
	 * @return bool
	 */
	public static function isOptOutKey(?string $key): bool
	{
		return is_string($key) && $key !== '' && $key[0] === '-';
	}

	/**
	 * Quarter deadline of a Recupel declaration: the 20th of the month after
	 * the end of the period.
	 *
	 * @param string $periodEnd YYYY-MM-DD
	 * @return string YYYY-MM-DD
	 */
	public static function declarationDeadline(string $periodEnd): string
	{
		$ts = strtotime($periodEnd . ' 12:00:00');
		$firstNext = strtotime(date('Y-m-01', $ts) . ' +1 month');
		return date('Y-m-20', $firstNext);
	}

	/**
	 * Start and end dates of a quarter.
	 *
	 * @param int $year    Year
	 * @param int $quarter 1 to 4
	 * @return array{0:string,1:string}
	 */
	public static function quarterBounds(int $year, int $quarter): array
	{
		$quarter = max(1, min(4, $quarter));
		$m1 = ($quarter - 1) * 3 + 1;
		$start = sprintf('%04d-%02d-01', $year, $m1);
		$end = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $m1 + 2)));
		return array($start, $end);
	}
}
