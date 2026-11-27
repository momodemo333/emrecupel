<?php
/* Tests du parseur de barème et contrôle des barèmes embarqués (data/*.csv). */

use PHPUnit\Framework\TestCase;

/**
 * @covers EmrecupelCatalog
 */
class ScaleCsvTest extends TestCase
{
	const HEADER = 'code;parent_code;use_type;fee_type;label_fr;label_nl;label_en;amount_ht;amount_ttc;date_start;date_end;source';

	/** @var array<string,array<string,array<string,string>>> année => code => ligne */
	private static $scales = array();

	/**
	 * @param string $year 2026 ou 2027
	 * @return array<string,array<string,string>>
	 */
	private static function scale(string $year): array
	{
		if (!isset(self::$scales[$year])) {
			$file = dirname(__DIR__, 2) . '/data/recupel-scale-' . $year . '.csv';
			$parsed = EmrecupelCatalog::parseScaleCsv((string) file_get_contents($file));
			if (!empty($parsed['errors'])) {
				throw new RuntimeException($year . ': ' . implode(' | ', $parsed['errors']));
			}
			$byCode = array();
			foreach ($parsed['rows'] as $row) {
				if (isset($byCode[$row['code']])) {
					throw new RuntimeException($year . ': duplicate code ' . $row['code']);
				}
				$byCode[$row['code']] = $row;
			}
			self::$scales[$year] = $byCode;
		}
		return self::$scales[$year];
	}

	/**
	 * @param string $year
	 * @return array<string,array<string,string>> codes feuilles (avec montant)
	 */
	private static function leaves(string $year): array
	{
		return array_filter(self::scale($year), function ($r) {
			return $r['amount_ht'] !== '';
		});
	}

	/**
	 * @return array{0:string}[]
	 */
	public function yearProvider(): array
	{
		return array('2026' => array('2026'), '2027' => array('2027'));
	}

	/**
	 * @return array<string,mixed>
	 */
	private function parse(string $content): array
	{
		return EmrecupelCatalog::parseScaleCsv($content);
	}

	// =================================================== parseScaleCsv()

	public function testParsesFullRow(): void
	{
		$res = $this->parse(self::HEADER . "\n1.1;1;household;allin;Frigo;Koelkast;Fridge;8.2645;10.0000;2026-01-01;;src\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertCount(1, $res['rows']);
		$this->assertSame(array(
			'code' => '1.1', 'parent_code' => '1', 'use_type' => 'household', 'fee_type' => 'allin',
			'label_fr' => 'Frigo', 'label_nl' => 'Koelkast', 'label_en' => 'Fridge',
			'amount_ht' => '8.2645', 'amount_ttc' => '10.0000', 'date_start' => '2026-01-01', 'date_end' => '', 'source' => 'src',
		), $res['rows'][0]);
	}

	public function testStripsBomAndSkipsCommentsAndBlankLines(): void
	{
		$csv = "\xEF\xBB\xBF# comment before header\n\n" . self::HEADER . "\n# comment\n   \n1.1;1;household;allin;A;B;C;1;1.21;2026-01-01;;\n";
		$res = $this->parse($csv);
		$this->assertSame(array(), $res['errors']);
		$this->assertCount(1, $res['rows']);
		$this->assertSame('1.1', $res['rows'][0]['code']);
	}

	public function testBomOnHeaderLine(): void
	{
		$res = $this->parse("\xEF\xBB\xBFcode;amount_ht;date_start\n1.1;1;2026-01-01\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertSame('1.1', $res['rows'][0]['code']);
	}

	public function testCrLfLineEndings(): void
	{
		$res = $this->parse("code;amount_ht;date_start\r\n1.1;1;2026-01-01\r\n1.2;2;2026-01-01\r\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertCount(2, $res['rows']);
		$this->assertSame('2026-01-01', $res['rows'][1]['date_start']);
	}

	public function testHeaderIsCaseInsensitiveAndOrderFree(): void
	{
		$res = $this->parse(" Date_Start ;AMOUNT_HT;Code\n2026-01-01;3.5;2.1\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertSame('2.1', $res['rows'][0]['code']);
		$this->assertSame('3.5', $res['rows'][0]['amount_ht']);
		$this->assertSame('2026-01-01', $res['rows'][0]['date_start']);
	}

	public function testMissingOptionalColumnsBecomeEmpty(): void
	{
		$res = $this->parse("code;amount_ht;date_start\n1.1;1;2026-01-01\n");
		$row = $res['rows'][0];
		foreach (array('parent_code', 'use_type', 'fee_type', 'label_fr', 'label_nl', 'label_en', 'amount_ttc', 'date_end', 'source') as $k) {
			$this->assertSame('', $row[$k], $k);
		}
	}

	public function testShortRowPadsWithEmpty(): void
	{
		$res = $this->parse(self::HEADER . "\n1;;;;Parent\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertSame('Parent', $res['rows'][0]['label_fr']);
		$this->assertSame('', $res['rows'][0]['amount_ht']);
	}

	public function testMissingRequiredColumnsAbort(): void
	{
		$res = $this->parse("code;label_fr\n1.1;x\n");
		$this->assertSame(array(), $res['rows']);
		$this->assertCount(1, $res['errors']);
		$this->assertStringContainsString('Missing columns', $res['errors'][0]);
		$this->assertStringContainsString('amount_ht', $res['errors'][0]);
		$this->assertStringContainsString('date_start', $res['errors'][0]);
		$this->assertStringNotContainsString('code', substr($res['errors'][0], strlen('Missing columns')));
	}

	public function testEmptyContent(): void
	{
		$this->assertSame(array('rows' => array(), 'errors' => array()), $this->parse(''));
		$this->assertSame(array('rows' => array(), 'errors' => array()), $this->parse("# only a comment\n"));
	}

	public function testDecimalCommaIsNormalised(): void
	{
		$res = $this->parse("code;amount_ht;amount_ttc;date_start\n1.1;8,2645;10,00;2026-01-01\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertSame('8.2645', $res['rows'][0]['amount_ht']);
		$this->assertSame('10.00', $res['rows'][0]['amount_ttc']);
	}

	public function testQuotedFieldWithSeparator(): void
	{
		$res = $this->parse("code;label_fr;amount_ht;date_start\n1.1;\"Frigo; combiné\";1;2026-01-01\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertSame('Frigo; combiné', $res['rows'][0]['label_fr']);
	}

	public function testZeroAmountIsValid(): void
	{
		$res = $this->parse("code;amount_ht;amount_ttc;date_start\n4.5.2;0;0.0000;2026-01-01\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertSame('0', $res['rows'][0]['amount_ht']);
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function invalidRowProvider(): array
	{
		return array(
			'negative amount_ht' => array('1.1;;;;;;;-1;;2026-01-01;;', 'invalid amount_ht'),
			'negative amount_ttc' => array('1.1;;;;;;;1;-0,01;2026-01-01;;', 'invalid amount_ttc'),
			'non numeric amount' => array('1.1;;;;;;;abc;;2026-01-01;;', 'invalid amount_ht'),
			'thousand separator' => array('1.1;;;;;;;1.000,50;;2026-01-01;;', 'invalid amount_ht'),
			'date dd/mm/yyyy' => array('1.1;;;;;;;1;;01/01/2026;;', 'invalid date_start'),
			'date without padding' => array('1.1;;;;;;;1;;2026-1-1;;', 'invalid date_start'),
			'invalid date_end' => array('1.1;;;;;;;1;;2026-01-01;31-12-2026;', 'invalid date_end'),
			'amount without date' => array('1.1;;;;;;;1;;;;', 'date_start required'),
			'fee_type invalid' => array('1.1;;;forfait;;;;1;;2026-01-01;;', 'fee_type must be allin or admin'),
			'fee_type wrong case' => array('1.1;;;ALLIN;;;;1;;2026-01-01;;', 'fee_type must be allin or admin'),
			'use_type invalid' => array('1.1;;menager;;;;;1;;2026-01-01;;', 'use_type must be household or professional'),
			'code empty' => array(';;;;x;;;1;;2026-01-01;;', 'invalid code'),
			'code with space' => array('1 1;;;;;;;1;;2026-01-01;;', 'invalid code'),
			'code with quote' => array("1'1;;;;;;;1;;2026-01-01;;", 'invalid code'),
			'code too long' => array('1.2.3.4.5.6.7.8.9;;;;;;;1;;2026-01-01;;', 'invalid code'),
		);
	}

	/**
	 * @dataProvider invalidRowProvider
	 */
	public function testInvalidRowIsRejectedWithLineNumber(string $line, string $message): void
	{
		$csv = "# comment\n" . self::HEADER . "\n" . $line . "\n1.2;;;;;;;1;;2026-01-01;;\n";
		$res = $this->parse($csv);
		$this->assertCount(1, $res['errors'], implode(' | ', $res['errors']));
		$this->assertStringStartsWith('Line 3:', $res['errors'][0]);
		$this->assertStringContainsString($message, $res['errors'][0]);
		// La ligne valide suivante est conservée.
		$this->assertCount(1, $res['rows']);
		$this->assertSame('1.2', $res['rows'][0]['code']);
	}

	public function testAcceptedEnumValues(): void
	{
		$csv = self::HEADER . "\n"
			. "a;;household;allin;;;;1;;2026-01-01;;\n"
			. "b;;professional;admin;;;;1;;2026-01-01;;\n"
			. "c;;;;;;;1;;2026-01-01;;\n";
		$res = $this->parse($csv);
		$this->assertSame(array(), $res['errors']);
		$this->assertCount(3, $res['rows']);
	}

	// ================================================ compareCodes / dayBefore

	public function testCompareCodes(): void
	{
		$this->assertSame(-1, EmrecupelCatalog::compareCodes('4.2', '4.10'));
		$this->assertSame(-1, EmrecupelCatalog::compareCodes('4.10', '4.50'));
		$this->assertSame(1, EmrecupelCatalog::compareCodes('4.50', '4.10'));
		$this->assertSame(0, EmrecupelCatalog::compareCodes('4.1.1', '4.1.1'));
		$this->assertSame(-1, EmrecupelCatalog::compareCodes('4', '4.1'), 'parent before child');
		$this->assertSame(-1, EmrecupelCatalog::compareCodes('4.1', '4.1.1'));
		$this->assertSame(1, EmrecupelCatalog::compareCodes('1.50', '1.5'));
		$this->assertSame(-1, EmrecupelCatalog::compareCodes('5.14', '6'));

		$codes = array('4.50', '4.10', '4', '4.1.6', '4.2.1', '4.1.10', '4.9', '1.50', '1.1', '10.1');
		usort($codes, array('EmrecupelCatalog', 'compareCodes'));
		$this->assertSame(array('1.1', '1.50', '4', '4.1.6', '4.1.10', '4.2.1', '4.9', '4.10', '4.50', '10.1'), $codes);
	}

	public function testDayBefore(): void
	{
		$this->assertSame('2025-12-31', EmrecupelCatalog::dayBefore('2026-01-01'));
		$this->assertSame('2026-06-30', EmrecupelCatalog::dayBefore('2026-07-01'));
		$this->assertSame('2028-02-29', EmrecupelCatalog::dayBefore('2028-03-01'));
		$this->assertSame('2027-02-28', EmrecupelCatalog::dayBefore('2027-03-01'));
		$this->assertSame('2026-03-28', EmrecupelCatalog::dayBefore('2026-03-29'), 'DST change in Europe');
	}

	// ================================================== barèmes embarqués

	/**
	 * @dataProvider yearProvider
	 */
	public function testBundledScaleParsesWithoutError(string $year): void
	{
		$file = dirname(__DIR__, 2) . '/data/recupel-scale-' . $year . '.csv';
		$parsed = EmrecupelCatalog::parseScaleCsv((string) file_get_contents($file));
		$this->assertSame(array(), $parsed['errors']);
		$this->assertCount(98, $parsed['rows']);
		$codes = array_column($parsed['rows'], 'code');
		$this->assertSame(count($codes), count(array_unique($codes)), 'no duplicate code');
	}

	/**
	 * @dataProvider yearProvider
	 */
	public function testBundledScaleStructure(string $year): void
	{
		$scale = self::scale($year);
		$leaves = self::leaves($year);
		$parents = array_filter($scale, function ($r) {
			return $r['amount_ht'] === '';
		});
		$this->assertCount(92, $leaves);
		$this->assertSame(array('1', '2', '3', '4', '5', '6'), array_map('strval', array_keys($parents)));
		foreach ($parents as $code => $p) {
			$this->assertSame('', $p['parent_code'], "parent $code has no parent");
			$this->assertSame('', $p['amount_ttc'], "parent $code has no amount");
			$this->assertSame('', $p['date_start'], "parent $code has no date");
		}
		foreach ($leaves as $code => $r) {
			$this->assertArrayHasKey($r['parent_code'], $parents, "leaf $code has an existing parent");
			$this->assertSame(explode('.', (string) $code)[0], $r['parent_code'], "leaf $code parent is its first segment");
			$this->assertSame($year . '-01-01', $r['date_start'], "leaf $code date_start");
			$this->assertSame('', $r['date_end'], "leaf $code date_end");
			$this->assertNotSame('', $r['source'], "leaf $code source");
			$this->assertContains($r['use_type'], array('household', 'professional'), "leaf $code use_type");
			$this->assertContains($r['fee_type'], array('allin', 'admin'), "leaf $code fee_type");
			$this->assertNotSame('', $r['amount_ttc'], "leaf $code amount_ttc");
		}
	}

	/**
	 * @dataProvider yearProvider
	 */
	public function testBundledScaleLabels(string $year): void
	{
		$artefacts = array('Exceptions', 'Notes', 'Dimensions', 'Structure of the appliance list');
		foreach (self::scale($year) as $code => $r) {
			foreach (array('label_fr', 'label_nl', 'label_en') as $f) {
				$label = $r[$f];
				$this->assertNotSame('', trim($label), "$code $f not empty");
				$this->assertSame(trim($label), $label, "$code $f trimmed");
				foreach ($artefacts as $a) {
					$this->assertStringNotContainsString($a, $label, "$code $f contains PDF artefact '$a'");
				}
			}
		}
	}

	/**
	 * @dataProvider yearProvider
	 */
	public function testBundledScaleVatConsistency(string $year): void
	{
		foreach (self::leaves($year) as $code => $r) {
			$ht = (float) $r['amount_ht'];
			$ttc = (float) $r['amount_ttc'];
			$okHt = abs(round($ttc / 1.21, 4) - $ht) <= 0.0001;
			$okTtc = abs($ht * 1.21 - $ttc) <= 0.01;
			$this->assertTrue($okHt || $okTtc, "$code: HT $ht / TTC $ttc inconsistent with 21 % VAT");
		}
	}

	/**
	 * @dataProvider yearProvider
	 */
	public function testBundledScaleProfessionalCodes(string $year): void
	{
		foreach (self::leaves($year) as $code => $r) {
			$second = (int) explode('.', (string) $code)[1];
			$expected = $second >= 50 ? 'professional' : 'household';
			$this->assertSame($expected, $r['use_type'], "$code use_type");
			if ($expected === 'household') {
				$this->assertSame('allin', $r['fee_type'], "$code household is allin");
			}
		}
	}

	public function testBothYearsHaveSameCodesAndLabels(): void
	{
		$this->assertSame(array_keys(self::scale('2026')), array_keys(self::scale('2027')));
	}

	/**
	 * Comparaison exhaustive avec la référence indépendante extraite du § 2.2
	 * de la recherche (tests/Fixtures/recupel-reference.php).
	 */
	public function testBundledScalesMatchIndependentReference(): void
	{
		$ref = require dirname(__DIR__) . '/Fixtures/recupel-reference.php';
		$this->assertCount(92, $ref);
		$s26 = self::leaves('2026');
		$s27 = self::leaves('2027');
		$this->assertEqualsCanonicalizing(array_map('strval', array_keys($ref)), array_map('strval', array_keys($s26)), '2026 codes');
		$this->assertEqualsCanonicalizing(array_map('strval', array_keys($ref)), array_map('strval', array_keys($s27)), '2027 codes');

		foreach ($ref as $code => $r) {
			list($use, $ttc26, $ht26, $ttc27, $ht27, $type26, $type27) = $r;
			$a = $s26[$code];
			$b = $s27[$code];
			$this->assertSame($use, $a['use_type'], "$code use 2026");
			$this->assertSame($use, $b['use_type'], "$code use 2027");
			$this->assertEqualsWithDelta($ttc26, (float) $a['amount_ttc'], 0.00001, "$code TTC 2026");
			$this->assertEqualsWithDelta($ht26, (float) $a['amount_ht'], 0.00001, "$code HT 2026");
			$this->assertEqualsWithDelta($ttc27, (float) $b['amount_ttc'], 0.00001, "$code TTC 2027");
			$this->assertEqualsWithDelta($ht27, (float) $b['amount_ht'], 0.00001, "$code HT 2027");
			$this->assertSame($type26, $a['fee_type'], "$code type 2026");
			$this->assertSame($type27, $b['fee_type'], "$code type 2027");
		}
	}

	/**
	 * Contrôles ciblés relus à la main dans la recherche.
	 *
	 * @return array<string,array{0:string,1:float,2:float,3:string,4:string}>
	 */
	public function spotCheckProvider(): array
	{
		return array(
			'1.1 fridge' => array('1.1', 10.00, 13.00, 'allin', 'allin'),
			'2.1 TV' => array('2.1', 5.00, 8.00, 'allin', 'allin'),
			'4.1.6 sunbeds' => array('4.1.6', 1.00, 40.00, 'allin', 'allin'),
			'2.4 ICT screen (decrease)' => array('2.4', 0.15, 0.05, 'allin', 'allin'),
			'4.6 luminaires' => array('4.6', 0.0121, 0.22, 'allin', 'allin'),
			'1.50 admin to allin' => array('1.50', 0.08, 13.00, 'admin', 'allin'),
			'4.62 professional' => array('4.62', 0.08, 0.10, 'admin', 'admin'),
		);
	}

	/**
	 * @dataProvider spotCheckProvider
	 */
	public function testSpotChecks(string $code, float $ttc26, float $ttc27, string $type26, string $type27): void
	{
		$a = self::scale('2026')[$code];
		$b = self::scale('2027')[$code];
		$this->assertEqualsWithDelta($ttc26, (float) $a['amount_ttc'], 0.00001);
		$this->assertEqualsWithDelta($ttc27, (float) $b['amount_ttc'], 0.00001);
		$this->assertSame($type26, $a['fee_type']);
		$this->assertSame($type27, $b['fee_type']);
	}

	public function testCode150In2027IsProfessionalButAllInSoLineIsMandatoryForReseller(): void
	{
		$r26 = self::scale('2026')['1.50'];
		$r27 = self::scale('2027')['1.50'];
		$this->assertSame('professional', $r27['use_type']);
		$this->assertFalse(EmrecupelFeePolicy::isLineMandatory($r26['use_type'], $r26['fee_type'], EmrecupelFeePolicy::ROLE_RESELLER));
		$this->assertTrue(EmrecupelFeePolicy::isLineMandatory($r27['use_type'], $r27['fee_type'], EmrecupelFeePolicy::ROLE_RESELLER));
	}

	public function testFridgeLinePriceFromBundledScale(): void
	{
		$r = self::scale('2026')['1.1'];
		$p = EmrecupelFeePolicy::linePrice((float) $r['amount_ht'], (float) $r['amount_ttc'], 1, 21.0);
		$this->assertSame('TTC', $p['base']);
		$this->assertEqualsWithDelta(10.0, $p['price'], 1e-9);
		$this->assertEqualsWithDelta(8.26446, $p['subprice'], 1e-9);
	}
}
