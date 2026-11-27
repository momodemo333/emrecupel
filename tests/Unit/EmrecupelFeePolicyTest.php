<?php
/* Tests unitaires de EmrecupelFeePolicy (logique pure). */

use PHPUnit\Framework\TestCase;

/**
 * @covers EmrecupelFeePolicy
 */
class EmrecupelFeePolicyTest extends TestCase
{
	/**
	 * Contexte complet qui aboutit à APPLY ; chaque test en retire ou modifie une clé.
	 *
	 * @param array<string,mixed> $over Surcharges
	 * @return array<string,mixed>
	 */
	private function ctx(array $over = array()): array
	{
		return array_merge(array(
			'doctype_enabled' => true,
			'is_product_line' => true,
			'product_exempt' => false,
			'opted_out' => false,
			'qty' => 2,
			'category_code' => '1.1',
			'category_active' => true,
			'tariff_amount' => 8.2645,
			'customer_role' => EmrecupelFeePolicy::ROLE_ENDUSER,
			'customer_country' => 'BE',
			'country_rule' => EmrecupelFeePolicy::COUNTRY_BE_ONLY,
			'origin' => EmrecupelFeePolicy::ORIGIN_LOCAL,
		), $over);
	}

	// ---------------------------------------------------------------- decide()

	public function testDecideAppliesOnFullContext(): void
	{
		$this->assertSame(EmrecupelFeePolicy::APPLY, EmrecupelFeePolicy::decide($this->ctx()));
	}

	public function testDecideEmptyContextIsDoctypeDisabled(): void
	{
		$this->assertSame(EmrecupelFeePolicy::SKIP_DOCTYPE, EmrecupelFeePolicy::decide(array()));
	}

	/**
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public function skipProvider(): array
	{
		return array(
			'doctype disabled' => array(array('doctype_enabled' => false), EmrecupelFeePolicy::SKIP_DOCTYPE),
			'not a product line' => array(array('is_product_line' => false), EmrecupelFeePolicy::SKIP_NOT_PRODUCT),
			'product exempt' => array(array('product_exempt' => true), EmrecupelFeePolicy::SKIP_PRODUCT_EXEMPT),
			'no category' => array(array('category_code' => ''), EmrecupelFeePolicy::SKIP_NO_CATEGORY),
			'category inactive' => array(array('category_active' => false), EmrecupelFeePolicy::SKIP_CATEGORY_INACTIVE),
			'customer exempt' => array(array('customer_role' => EmrecupelFeePolicy::ROLE_EXEMPT), EmrecupelFeePolicy::SKIP_CUSTOMER_EXEMPT),
			'export FR' => array(array('customer_country' => 'FR'), EmrecupelFeePolicy::SKIP_EXPORT),
			'export lowercase padded' => array(array('customer_country' => ' nl '), EmrecupelFeePolicy::SKIP_EXPORT),
			'used goods' => array(array('origin' => EmrecupelFeePolicy::ORIGIN_USED), EmrecupelFeePolicy::SKIP_USED),
			'opted out' => array(array('opted_out' => true), EmrecupelFeePolicy::SKIP_OPTED_OUT),
			'tariff null' => array(array('tariff_amount' => null), EmrecupelFeePolicy::SKIP_NO_TARIFF),
			'qty zero int' => array(array('qty' => 0), EmrecupelFeePolicy::SKIP_ZERO_QTY),
			'qty zero float' => array(array('qty' => 0.0), EmrecupelFeePolicy::SKIP_ZERO_QTY),
			'qty zero string' => array(array('qty' => '0'), EmrecupelFeePolicy::SKIP_ZERO_QTY),
			'qty zero decimal string' => array(array('qty' => '0.000'), EmrecupelFeePolicy::SKIP_ZERO_QTY),
		);
	}

	/**
	 * @dataProvider skipProvider
	 * @param array<string,mixed> $over
	 */
	public function testDecideSkipReasons(array $over, string $expected): void
	{
		$this->assertSame($expected, EmrecupelFeePolicy::decide($this->ctx($over)));
	}

	public function testDecideMissingTariffKeyIsNoTariff(): void
	{
		$ctx = $this->ctx();
		unset($ctx['tariff_amount']);
		$this->assertSame(EmrecupelFeePolicy::SKIP_NO_TARIFF, EmrecupelFeePolicy::decide($ctx));
	}

	public function testDecideMissingQtyIsZeroQty(): void
	{
		$ctx = $this->ctx();
		unset($ctx['qty']);
		$this->assertSame(EmrecupelFeePolicy::SKIP_ZERO_QTY, EmrecupelFeePolicy::decide($ctx));
	}

	public function testDecideZeroTariffStillApplies(): void
	{
		// Cartouches 2026 : montant 0,00 mais tarif connu.
		$this->assertSame(EmrecupelFeePolicy::APPLY, EmrecupelFeePolicy::decide($this->ctx(array('tariff_amount' => 0.0))));
	}

	public function testDecideNegativeQtyApplies(): void
	{
		// Ligne d'avoir : quantité négative.
		$this->assertSame(EmrecupelFeePolicy::APPLY, EmrecupelFeePolicy::decide($this->ctx(array('qty' => -1))));
	}

	public function testDecideUnknownCountryIsDomestic(): void
	{
		$this->assertSame(EmrecupelFeePolicy::APPLY, EmrecupelFeePolicy::decide($this->ctx(array('customer_country' => ''))));
		$ctx = $this->ctx();
		unset($ctx['customer_country'], $ctx['country_rule']);
		$this->assertSame(EmrecupelFeePolicy::APPLY, EmrecupelFeePolicy::decide($ctx));
	}

	public function testDecideCountryRuleAllChargesForeignCustomers(): void
	{
		$this->assertSame(EmrecupelFeePolicy::APPLY, EmrecupelFeePolicy::decide($this->ctx(array(
			'customer_country' => 'FR',
			'country_rule' => EmrecupelFeePolicy::COUNTRY_ALL,
		))));
	}

	public function testDecideReasonPriority(): void
	{
		// Client exonéré ET export ET occasion : l'exonération client gagne.
		$this->assertSame(EmrecupelFeePolicy::SKIP_CUSTOMER_EXEMPT, EmrecupelFeePolicy::decide($this->ctx(array(
			'customer_role' => EmrecupelFeePolicy::ROLE_EXEMPT,
			'customer_country' => 'FR',
			'origin' => EmrecupelFeePolicy::ORIGIN_USED,
		))));
		// Export ET occasion ET opt-out : l'export gagne.
		$this->assertSame(EmrecupelFeePolicy::SKIP_EXPORT, EmrecupelFeePolicy::decide($this->ctx(array(
			'customer_country' => 'DE',
			'origin' => EmrecupelFeePolicy::ORIGIN_USED,
			'opted_out' => true,
		))));
		// Produit non soumis ET sans catégorie : non soumis gagne.
		$this->assertSame(EmrecupelFeePolicy::SKIP_PRODUCT_EXEMPT, EmrecupelFeePolicy::decide($this->ctx(array(
			'product_exempt' => true,
			'category_code' => '',
		))));
	}

	// -------------------------------------------------------------- isExport()

	public function testIsExport(): void
	{
		$this->assertFalse(EmrecupelFeePolicy::isExport('BE'));
		$this->assertFalse(EmrecupelFeePolicy::isExport('be'));
		$this->assertFalse(EmrecupelFeePolicy::isExport(' BE '));
		$this->assertFalse(EmrecupelFeePolicy::isExport(''));
		$this->assertFalse(EmrecupelFeePolicy::isExport('   '));
		$this->assertTrue(EmrecupelFeePolicy::isExport('FR'));
		$this->assertTrue(EmrecupelFeePolicy::isExport('lu'));
		$this->assertFalse(EmrecupelFeePolicy::isExport('FR', EmrecupelFeePolicy::COUNTRY_ALL));
		$this->assertFalse(EmrecupelFeePolicy::isExport('FR', 'whatever'));
	}

	// ------------------------------------------------- modes / defaultSettings

	public function testModes(): void
	{
		$this->assertSame(array('line', 'included', 'footer'), EmrecupelFeePolicy::modes());
	}

	public function testDefaultSettingsAreIncludedEverywhere(): void
	{
		$this->assertSame(array(
			'reseller_professional' => 'included',
			'enduser_household' => 'included',
			'enduser_professional' => 'included',
		), EmrecupelFeePolicy::defaultSettings());
	}

	// ----------------------------------------------------------- displayMode()

	/**
	 * @return array<string,array{0:string,1:string,2:string,3:array<string,string>,4:string,5:string}>
	 */
	public function displayModeProvider(): array
	{
		$all = array('reseller_professional' => 'footer', 'enduser_household' => 'footer', 'enduser_professional' => 'footer');
		return array(
			// Obligation légale : revendeur + tout compris => toujours ligne.
			'reseller household allin' => array('household', 'allin', 'reseller', array(), '', 'line'),
			'reseller household allin ignores override' => array('household', 'allin', 'reseller', array(), 'footer', 'line'),
			'reseller household allin ignores settings' => array('household', 'allin', 'reseller', $all, 'included', 'line'),
			'reseller household empty fee type' => array('household', '', 'reseller', $all, 'footer', 'line'),
			'reseller 1.50 in 2027 (pro but allin)' => array('professional', 'allin', 'reseller', $all, 'footer', 'line'),
			// Revendeur + administrative : réglages.
			'reseller pro admin default' => array('professional', 'admin', 'reseller', array(), '', 'included'),
			'reseller pro admin setting' => array('professional', 'admin', 'reseller', array('reseller_professional' => 'line'), '', 'line'),
			'reseller pro admin override' => array('professional', 'admin', 'reseller', array('reseller_professional' => 'line'), 'footer', 'footer'),
			'reseller pro empty fee type' => array('professional', '', 'reseller', array('reseller_professional' => 'footer'), '', 'footer'),
			// Utilisateur final.
			'enduser household default' => array('household', 'allin', 'enduser', array(), '', 'included'),
			'enduser household setting' => array('household', 'allin', 'enduser', array('enduser_household' => 'footer'), '', 'footer'),
			'enduser household uses its own key' => array('household', 'allin', 'enduser', array('enduser_professional' => 'line'), '', 'included'),
			'enduser pro setting' => array('professional', 'admin', 'enduser', array('enduser_professional' => 'line'), '', 'line'),
			'enduser pro override' => array('professional', 'admin', 'enduser', array('enduser_professional' => 'line'), 'included', 'included'),
			'enduser override line' => array('household', 'allin', 'enduser', array(), 'line', 'line'),
			'enduser invalid override ignored' => array('household', 'allin', 'enduser', array('enduser_household' => 'footer'), 'bogus', 'footer'),
			'enduser invalid setting falls back' => array('household', 'allin', 'enduser', array('enduser_household' => 'bogus'), '', 'included'),
			'enduser empty setting falls back' => array('household', 'allin', 'enduser', array('enduser_household' => ''), '', 'included'),
			'unknown use type treated as household' => array('', 'allin', 'enduser', array('enduser_household' => 'footer'), '', 'footer'),
		);
	}

	/**
	 * @dataProvider displayModeProvider
	 * @param array<string,string> $settings
	 */
	public function testDisplayMode(string $use, string $fee, string $role, array $settings, string $override, string $expected): void
	{
		$this->assertSame($expected, EmrecupelFeePolicy::displayMode($use, $fee, $role, $settings, $override));
	}

	/**
	 * displayMode() et isLineMandatory() doivent rester cohérents.
	 */
	public function testIsLineMandatoryMatchesDisplayMode(): void
	{
		$noLine = array('reseller_professional' => 'footer', 'enduser_household' => 'footer', 'enduser_professional' => 'footer');
		foreach (array('household', 'professional', '') as $use) {
			foreach (array('allin', 'admin', '') as $fee) {
				foreach (array('reseller', 'enduser', 'exempt') as $role) {
					$mandatory = EmrecupelFeePolicy::isLineMandatory($use, $fee, $role);
					$mode = EmrecupelFeePolicy::displayMode($use, $fee, $role, $noLine, 'included');
					$this->assertSame($mandatory, $mode === 'line', "$use/$fee/$role");
				}
			}
		}
	}

	public function testIsLineMandatory(): void
	{
		$this->assertTrue(EmrecupelFeePolicy::isLineMandatory('household', 'allin', 'reseller'));
		$this->assertTrue(EmrecupelFeePolicy::isLineMandatory('professional', 'allin', 'reseller'));
		$this->assertTrue(EmrecupelFeePolicy::isLineMandatory('household', '', 'reseller'));
		$this->assertFalse(EmrecupelFeePolicy::isLineMandatory('professional', '', 'reseller'));
		$this->assertFalse(EmrecupelFeePolicy::isLineMandatory('professional', 'admin', 'reseller'));
		$this->assertFalse(EmrecupelFeePolicy::isLineMandatory('household', 'allin', 'enduser'));
		$this->assertFalse(EmrecupelFeePolicy::isLineMandatory('household', 'allin', 'exempt'));
	}

	// ------------------------------------------------- origin / profile / bucket

	public function testProfileDefaultOrigin(): void
	{
		$this->assertSame('import', EmrecupelFeePolicy::profileDefaultOrigin(EmrecupelFeePolicy::PROFILE_PRODUCER));
		$this->assertSame('local', EmrecupelFeePolicy::profileDefaultOrigin(EmrecupelFeePolicy::PROFILE_RESELLER));
		$this->assertSame('local', EmrecupelFeePolicy::profileDefaultOrigin(EmrecupelFeePolicy::PROFILE_EXPORTER));
		$this->assertSame('local', EmrecupelFeePolicy::profileDefaultOrigin(''));
	}

	public function testEffectiveOrigin(): void
	{
		$this->assertSame('import', EmrecupelFeePolicy::effectiveOrigin('import', 'local'));
		$this->assertSame('used', EmrecupelFeePolicy::effectiveOrigin('used', 'import'));
		$this->assertSame('import', EmrecupelFeePolicy::effectiveOrigin('', 'import'));
		$this->assertSame('local', EmrecupelFeePolicy::effectiveOrigin(''));
		$this->assertSame('import', EmrecupelFeePolicy::effectiveOrigin('bogus', 'import'));
		$this->assertSame('local', EmrecupelFeePolicy::effectiveOrigin('', 'bogus'));
		$this->assertSame('local', EmrecupelFeePolicy::effectiveOrigin('IMPORT', ''), 'case-sensitive');
	}

	public function testBucket(): void
	{
		$this->assertSame('market', EmrecupelFeePolicy::bucket('import', false));
		$this->assertSame('none', EmrecupelFeePolicy::bucket('import', true));
		$this->assertSame('returnable', EmrecupelFeePolicy::bucket('local', true));
		$this->assertSame('control', EmrecupelFeePolicy::bucket('local', false));
		$this->assertSame('none', EmrecupelFeePolicy::bucket('used', false));
		$this->assertSame('none', EmrecupelFeePolicy::bucket('used', true));
		// Origine inconnue : traitée comme achat local.
		$this->assertSame('control', EmrecupelFeePolicy::bucket('', false));
		$this->assertSame('returnable', EmrecupelFeePolicy::bucket('', true));
	}

	public function testDeclaredBuckets(): void
	{
		$this->assertSame(array('market', 'returnable'), EmrecupelFeePolicy::declaredBuckets('producer'));
		$this->assertSame(array('returnable'), EmrecupelFeePolicy::declaredBuckets('exporter'));
		$this->assertSame(array(), EmrecupelFeePolicy::declaredBuckets('reseller'));
		$this->assertSame(array(), EmrecupelFeePolicy::declaredBuckets(''));
	}

	// ------------------------------------------------------------ linePrice()

	public function testLinePriceTtcBaseAt21(): void
	{
		$r = EmrecupelFeePolicy::linePrice(8.2645, 10.0, 1, 21.0);
		$this->assertSame('TTC', $r['base']);
		$this->assertEqualsWithDelta(10.0, $r['price'], 1e-9);
		$this->assertEqualsWithDelta(8.26446, $r['subprice'], 1e-9);
	}

	public function testLinePriceTtcWithUnits(): void
	{
		$r = EmrecupelFeePolicy::linePrice(8.2645, 10.0, 4, 21.0);
		$this->assertSame('TTC', $r['base']);
		$this->assertEqualsWithDelta(40.0, $r['price'], 1e-9);
		$this->assertEqualsWithDelta(33.05785, $r['subprice'], 1e-9);
	}

	public function testLinePriceSmallTtcAmount(): void
	{
		// 4.6 en 2026 : 0,0121 TTC.
		$r = EmrecupelFeePolicy::linePrice(0.01, 0.0121, 1, 21.0);
		$this->assertSame('TTC', $r['base']);
		$this->assertEqualsWithDelta(0.0121, $r['price'], 1e-9);
		$this->assertEqualsWithDelta(0.01, $r['subprice'], 1e-9);
	}

	public function testLinePriceHtBaseWhenVatIsNot21(): void
	{
		foreach (array(6.0, 12.0, 0.0, 20.9) as $vat) {
			$r = EmrecupelFeePolicy::linePrice(8.2645, 10.0, 1, $vat);
			$this->assertSame('HT', $r['base'], "VAT $vat");
			$this->assertEqualsWithDelta(8.2645, $r['price'], 1e-9);
			$this->assertEqualsWithDelta(8.2645, $r['subprice'], 1e-9);
		}
	}

	public function testLinePriceHtBaseWhenTtcUnknown(): void
	{
		$r = EmrecupelFeePolicy::linePrice(0.0661, null, 3, 21.0);
		$this->assertSame('HT', $r['base']);
		$this->assertEqualsWithDelta(0.1983, $r['price'], 1e-9);
		$this->assertEqualsWithDelta(0.1983, $r['subprice'], 1e-9);
	}

	public function testLinePriceHtBaseWhenTtcNotPreferred(): void
	{
		$r = EmrecupelFeePolicy::linePrice(8.2645, 10.0, 1, 21.0, false);
		$this->assertSame('HT', $r['base']);
		$this->assertEqualsWithDelta(8.2645, $r['price'], 1e-9);
	}

	public function testLinePriceToleratesFloatVatRate(): void
	{
		$this->assertSame('TTC', EmrecupelFeePolicy::linePrice(8.2645, 10.0, 1, 21.00001)['base']);
		$this->assertSame('HT', EmrecupelFeePolicy::linePrice(8.2645, 10.0, 1, 21.001)['base']);
	}

	public function testLinePriceNonPositiveUnitsDefaultToOne(): void
	{
		foreach (array(0.0, -2.0) as $units) {
			$r = EmrecupelFeePolicy::linePrice(8.2645, 10.0, $units, 21.0);
			$this->assertEqualsWithDelta(10.0, $r['price'], 1e-9, "units $units");
			$r = EmrecupelFeePolicy::linePrice(8.2645, null, $units, 6.0);
			$this->assertEqualsWithDelta(8.2645, $r['price'], 1e-9, "units $units");
		}
	}

	public function testLinePriceHtRoundsToFiveDecimals(): void
	{
		$r = EmrecupelFeePolicy::linePrice(0.123456789, null, 1, 6.0);
		$this->assertEqualsWithDelta(0.12346, $r['price'], 1e-12);
	}

	// ------------------------------------------------------- includedAmount()

	public function testIncludedAmount(): void
	{
		$this->assertEqualsWithDelta(10.0, EmrecupelFeePolicy::includedAmount(8.2645, 10.0, 1, 21.0), 1e-9);
		$this->assertEqualsWithDelta(20.0, EmrecupelFeePolicy::includedAmount(8.2645, 10.0, 2, 21.0), 1e-9);
		// TTC inconnu à 21 % : HT × 1,21 arrondi à 4 décimales.
		$this->assertEqualsWithDelta(10.0, EmrecupelFeePolicy::includedAmount(8.2645, null, 1, 21.0), 1e-9);
		// TVA 6 % : recalcul depuis le HT.
		$this->assertEqualsWithDelta(8.7604, EmrecupelFeePolicy::includedAmount(8.2645, 10.0, 1, 6.0), 1e-9);
		// TVA 0 % : HT.
		$this->assertEqualsWithDelta(8.2645, EmrecupelFeePolicy::includedAmount(8.2645, 10.0, 1, 0.0), 1e-9);
	}

	public function testIncludedAmountNonPositiveUnitsDefaultToOne(): void
	{
		$this->assertEqualsWithDelta(10.0, EmrecupelFeePolicy::includedAmount(8.2645, 10.0, 0, 21.0), 1e-9);
		$this->assertEqualsWithDelta(10.0, EmrecupelFeePolicy::includedAmount(8.2645, 10.0, -1, 21.0), 1e-9);
	}

	// -------------------------------------------------------- declaredUnits()

	public function testDeclaredUnits(): void
	{
		$this->assertEqualsWithDelta(3.0, EmrecupelFeePolicy::declaredUnits(3), 1e-9);
		$this->assertEqualsWithDelta(12.0, EmrecupelFeePolicy::declaredUnits(3, 4), 1e-9);
		$this->assertEqualsWithDelta(3.0, EmrecupelFeePolicy::declaredUnits(3, 0), 1e-9);
		$this->assertEqualsWithDelta(3.0, EmrecupelFeePolicy::declaredUnits(3, -2), 1e-9);
		$this->assertEqualsWithDelta(0.0, EmrecupelFeePolicy::declaredUnits(0, 4), 1e-9);
		$this->assertEqualsWithDelta(1.5, EmrecupelFeePolicy::declaredUnits(0.5, 3), 1e-9);
	}

	public function testDeclaredUnitsCreditNoteIsAlwaysNegative(): void
	{
		// Avoir : quantité positive ou négative selon la façon dont Dolibarr la stocke.
		$this->assertEqualsWithDelta(-3.0, EmrecupelFeePolicy::declaredUnits(3, 1, true), 1e-9);
		$this->assertEqualsWithDelta(-3.0, EmrecupelFeePolicy::declaredUnits(-3, 1, true), 1e-9);
		$this->assertEqualsWithDelta(-8.0, EmrecupelFeePolicy::declaredUnits(-2, 4, true), 1e-9);
		$this->assertEqualsWithDelta(0.0, EmrecupelFeePolicy::declaredUnits(0, 1, true), 1e-9);
		// Hors avoir, une quantité négative reste négative.
		$this->assertEqualsWithDelta(-3.0, EmrecupelFeePolicy::declaredUnits(-3, 1, false), 1e-9);
	}

	// ----------------------------------------------------- buildDescription()

	public function testBuildDescriptionPlaceholders(): void
	{
		$this->assertSame(
			'Recupel 1.1 - Frigos : 10,00 €',
			EmrecupelFeePolicy::buildDescription('Recupel {CODE} - {LABEL} : {AMOUNT}', '1.1', 'Frigos', '10,00 €')
		);
	}

	public function testBuildDescriptionAppendsUnitsWhenTemplateHasNone(): void
	{
		$this->assertSame('Recupel 3.2 (x4)', EmrecupelFeePolicy::buildDescription('Recupel {CODE}', '3.2', 'LED', '', 4));
		$this->assertSame('Recupel 3.2 (x2.5)', EmrecupelFeePolicy::buildDescription('Recupel {CODE}', '3.2', 'LED', '', 2.5));
	}

	public function testBuildDescriptionUnitsPlaceholder(): void
	{
		$this->assertSame('Recupel 3.2 4 x', EmrecupelFeePolicy::buildDescription('Recupel {CODE} {UNITS} x', '3.2', 'LED', '', 4));
		$this->assertSame('1', EmrecupelFeePolicy::buildDescription('{UNITS}', '3.2', 'LED'));
		$this->assertSame('0.5', EmrecupelFeePolicy::buildDescription('{UNITS}', '3.2', 'LED', '', 0.5));
	}

	public function testBuildDescriptionNoSuffixForOneOrLess(): void
	{
		$this->assertSame('Recupel 3.2', EmrecupelFeePolicy::buildDescription('Recupel {CODE}', '3.2', 'LED', '', 1));
		$this->assertSame('Recupel 3.2', EmrecupelFeePolicy::buildDescription('Recupel {CODE}', '3.2', 'LED', '', 0.5));
	}

	public function testBuildDescriptionTrims(): void
	{
		$this->assertSame('Recupel', EmrecupelFeePolicy::buildDescription('  Recupel {AMOUNT} ', '1.1', 'x', ''));
	}

	// ------------------------------------------------------ feeLineDiffers()

	/**
	 * @return array<string,mixed>
	 */
	private function feeLine(array $over = array()): array
	{
		return array_merge(array(
			'qty' => 2.0,
			'subprice' => 8.26446,
			'tva_tx' => 21.0,
			'vat_src_code' => '',
			'category_code' => '1.1',
			'units' => 1.0,
		), $over);
	}

	public function testFeeLineDiffersIdentical(): void
	{
		$this->assertFalse(EmrecupelFeePolicy::feeLineDiffers($this->feeLine(), $this->feeLine()));
	}

	public function testFeeLineDiffersIgnoresSignOfSubprice(): void
	{
		$this->assertFalse(EmrecupelFeePolicy::feeLineDiffers($this->feeLine(array('subprice' => -8.26446)), $this->feeLine()));
	}

	public function testFeeLineDiffersToleranceOnSubprice(): void
	{
		$this->assertFalse(EmrecupelFeePolicy::feeLineDiffers($this->feeLine(array('subprice' => 8.26450)), $this->feeLine()));
		$this->assertTrue(EmrecupelFeePolicy::feeLineDiffers($this->feeLine(array('subprice' => 8.26470)), $this->feeLine()));
	}

	public function testFeeLineDiffersStringNumbersFromDatabase(): void
	{
		$this->assertFalse(EmrecupelFeePolicy::feeLineDiffers(
			$this->feeLine(array('qty' => '2', 'subprice' => '8.26446000', 'tva_tx' => '21.000', 'units' => '1')),
			$this->feeLine()
		));
	}

	/**
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function feeLineChangeProvider(): array
	{
		return array(
			'qty' => array(array('qty' => 3.0)),
			'qty sign' => array(array('qty' => -2.0)),
			'subprice' => array(array('subprice' => 10.0)),
			'tva_tx' => array(array('tva_tx' => 6.0)),
			'vat_src_code' => array(array('vat_src_code' => 'BE-REV')),
			'category_code' => array(array('category_code' => '1.2')),
			'category_code 1.5 vs 1.50' => array(array('category_code' => '1.5')),
			'units' => array(array('units' => 2.0)),
		);
	}

	/**
	 * @dataProvider feeLineChangeProvider
	 * @param array<string,mixed> $over
	 */
	public function testFeeLineDiffersOnEachField(array $over): void
	{
		$expected = $this->feeLine(array('category_code' => '1.50'));
		$current = array_merge($expected, $over);
		$this->assertTrue(EmrecupelFeePolicy::feeLineDiffers($current, $expected));
		$this->assertTrue(EmrecupelFeePolicy::feeLineDiffers($expected, $current), 'symmetric');
	}

	public function testFeeLineDiffersMissingVatSrcCodeEqualsEmpty(): void
	{
		$current = $this->feeLine();
		unset($current['vat_src_code']);
		$this->assertFalse(EmrecupelFeePolicy::feeLineDiffers($current, $this->feeLine()));
		$this->assertTrue(EmrecupelFeePolicy::feeLineDiffers($current, $this->feeLine(array('vat_src_code' => 'X'))));
	}

	// --------------------------------------------------------- link keys

	public function testNewLinkKey(): void
	{
		$a = EmrecupelFeePolicy::newLinkKey();
		$b = EmrecupelFeePolicy::newLinkKey();
		$this->assertMatchesRegularExpression('/^r[0-9a-f]{12}$/', $a);
		$this->assertSame(13, strlen($a));
		$this->assertNotSame($a, $b);
		$this->assertFalse(EmrecupelFeePolicy::isOptOutKey($a));
	}

	public function testOptOutKey(): void
	{
		$this->assertSame('-rabc', EmrecupelFeePolicy::optOutKey('rabc'));
		$this->assertSame('-rabc', EmrecupelFeePolicy::optOutKey('-rabc'), 'idempotent');
		$this->assertSame('-rabc', EmrecupelFeePolicy::optOutKey('--rabc'));
		$this->assertTrue(EmrecupelFeePolicy::isOptOutKey(EmrecupelFeePolicy::optOutKey(EmrecupelFeePolicy::newLinkKey())));
	}

	public function testIsOptOutKey(): void
	{
		$this->assertTrue(EmrecupelFeePolicy::isOptOutKey('-rabc'));
		$this->assertFalse(EmrecupelFeePolicy::isOptOutKey('rabc'));
		$this->assertFalse(EmrecupelFeePolicy::isOptOutKey(''));
		$this->assertFalse(EmrecupelFeePolicy::isOptOutKey(null));
	}

	// ------------------------------------------------ declarationDeadline()

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function deadlineProvider(): array
	{
		return array(
			'Q1' => array('2026-03-31', '2026-04-20'),
			'Q2' => array('2026-06-30', '2026-07-20'),
			'Q3' => array('2026-09-30', '2026-10-20'),
			'Q4 crosses year' => array('2026-12-31', '2027-01-20'),
			'January (31 days)' => array('2026-01-31', '2026-02-20'),
			'February non-leap' => array('2027-02-28', '2027-03-20'),
			'February leap' => array('2028-02-29', '2028-03-20'),
			'November' => array('2026-11-30', '2026-12-20'),
			'year' => array('2026-12-31', '2027-01-20'),
			'mid-month date' => array('2026-05-15', '2026-06-20'),
		);
	}

	/**
	 * @dataProvider deadlineProvider
	 */
	public function testDeclarationDeadline(string $end, string $expected): void
	{
		$this->assertSame($expected, EmrecupelFeePolicy::declarationDeadline($end));
	}

	// ----------------------------------------------------- quarterBounds()

	public function testQuarterBounds(): void
	{
		$this->assertSame(array('2026-01-01', '2026-03-31'), EmrecupelFeePolicy::quarterBounds(2026, 1));
		$this->assertSame(array('2026-04-01', '2026-06-30'), EmrecupelFeePolicy::quarterBounds(2026, 2));
		$this->assertSame(array('2026-07-01', '2026-09-30'), EmrecupelFeePolicy::quarterBounds(2026, 3));
		$this->assertSame(array('2026-10-01', '2026-12-31'), EmrecupelFeePolicy::quarterBounds(2026, 4));
	}

	public function testQuarterBoundsClamped(): void
	{
		$this->assertSame(array('2026-01-01', '2026-03-31'), EmrecupelFeePolicy::quarterBounds(2026, 0));
		$this->assertSame(array('2026-01-01', '2026-03-31'), EmrecupelFeePolicy::quarterBounds(2026, -3));
		$this->assertSame(array('2026-10-01', '2026-12-31'), EmrecupelFeePolicy::quarterBounds(2026, 5));
	}

	// ------------------------------------------------------ periodBounds()

	/**
	 * @return array<string,array{0:string,1:int,2:int,3:array{0:string,1:string}}>
	 */
	public function periodProvider(): array
	{
		return array(
			'month January' => array('month', 2026, 1, array('2026-01-01', '2026-01-31')),
			'month February 2026' => array('month', 2026, 2, array('2026-02-01', '2026-02-28')),
			'month February 2028 leap' => array('month', 2028, 2, array('2028-02-01', '2028-02-29')),
			'month February 2000 leap' => array('month', 2000, 2, array('2000-02-01', '2000-02-29')),
			'month February 2100 not leap' => array('month', 2100, 2, array('2100-02-01', '2100-02-28')),
			'month April' => array('month', 2026, 4, array('2026-04-01', '2026-04-30')),
			'month December' => array('month', 2026, 12, array('2026-12-01', '2026-12-31')),
			'month 13 clamped' => array('month', 2026, 13, array('2026-12-01', '2026-12-31')),
			'month 0 clamped' => array('month', 2026, 0, array('2026-01-01', '2026-01-31')),
			'quarter 1 2028' => array('quarter', 2028, 1, array('2028-01-01', '2028-03-31')),
			'quarter 4' => array('quarter', 2026, 4, array('2026-10-01', '2026-12-31')),
			'year ignores index' => array('year', 2026, 7, array('2026-01-01', '2026-12-31')),
			'unknown type is quarter' => array('foo', 2026, 2, array('2026-04-01', '2026-06-30')),
		);
	}

	/**
	 * @dataProvider periodProvider
	 * @param array{0:string,1:string} $expected
	 */
	public function testPeriodBounds(string $type, int $year, int $index, array $expected): void
	{
		$this->assertSame($expected, EmrecupelFeePolicy::periodBounds($type, $year, $index));
	}

	public function testPeriodBoundsDefaultIndex(): void
	{
		$this->assertSame(array('2026-01-01', '2026-03-31'), EmrecupelFeePolicy::periodBounds('quarter', 2026));
		$this->assertSame(array('2026-01-01', '2026-01-31'), EmrecupelFeePolicy::periodBounds('month', 2026));
	}

	public function testPeriodEndFeedsDeadline(): void
	{
		list(, $end) = EmrecupelFeePolicy::periodBounds('quarter', 2026, 4);
		$this->assertSame('2027-01-20', EmrecupelFeePolicy::declarationDeadline($end));
		list(, $end) = EmrecupelFeePolicy::periodBounds('month', 2028, 2);
		$this->assertSame('2028-03-20', EmrecupelFeePolicy::declarationDeadline($end));
	}

	/**
	 * Regression 2.0.1: the estimated amount of the declaration is the number
	 * of appliances × the rate, rounded once. Rounding every invoice line to
	 * 2 decimals gave 5 × round(4 × 0,1240) = 2,50 instead of 20 × 0,1240 = 2,48.
	 */
	public function testLedgerAmountIsNotRoundedPerLine(): void
	{
		$this->assertEqualsWithDelta(0.496, EmrecupelFeePolicy::ledgerAmount(0.1240, 4), 1e-9);
		$sum = 0.0;
		for ($i = 0; $i < 5; $i++) {
			$sum += EmrecupelFeePolicy::ledgerAmount(0.1240, 4);
		}
		$this->assertSame(2.48, round($sum, 2));
		$this->assertEqualsWithDelta(-0.0826, EmrecupelFeePolicy::ledgerAmount(0.0826, -1), 1e-9);
		$this->assertEqualsWithDelta(0.12345679, EmrecupelFeePolicy::ledgerAmount(0.123456789, 1), 1e-12);
	}
}
