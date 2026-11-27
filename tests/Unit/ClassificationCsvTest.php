<?php
/* Tests du parseur d'import de classification produits (ref;code;quantité;origine). */

use PHPUnit\Framework\TestCase;

/**
 * @covers EmrecupelProduct
 */
class ClassificationCsvTest extends TestCase
{
	/**
	 * @return array{rows: array<int,array<string,mixed>>, errors: string[]}
	 */
	private function parse(string $content): array
	{
		return EmrecupelProduct::parseClassificationCsv($content);
	}

	public function testParsesSimpleLineWithHeader(): void
	{
		$res = $this->parse("ref;code;quantity;origin\nFRIGO-01;1.1;1;import\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertSame(array(
			array('line' => 2, 'ref' => 'FRIGO-01', 'code' => '1.1', 'units' => 1.0, 'origin' => 'import', 'exempt' => false),
		), $res['rows']);
	}

	public function testHeaderIsOptional(): void
	{
		$res = $this->parse("FRIGO-01;1.1;2;local\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertCount(1, $res['rows']);
		$this->assertSame(1, $res['rows'][0]['line']);
		$this->assertSame(2.0, $res['rows'][0]['units']);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function headerProvider(): array
	{
		return array(
			'ref' => array('ref'),
			'Ref' => array('Ref'),
			'REFERENCE' => array('REFERENCE'),
			'référence' => array('référence'),
			'referentie' => array('referentie'),
			'product' => array('Product'),
		);
	}

	/**
	 * @dataProvider headerProvider
	 */
	public function testHeaderWords(string $word): void
	{
		$res = $this->parse($word . ";code;qty;origin\nA;1.1;;\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertCount(1, $res['rows']);
		$this->assertSame('A', $res['rows'][0]['ref']);
	}

	public function testHeaderOnlyRecognisedOnFirstDataLine(): void
	{
		// Après une première ligne de données, « ref;code » est une ligne invalide.
		$res = $this->parse("A;1.1\nref;code\n");
		$this->assertCount(1, $res['rows']);
		$this->assertCount(1, $res['errors']);
		$this->assertStringStartsWith('Line 2:', $res['errors'][0]);
	}

	public function testCommentsAndBlankLinesSkippedButCountedInLineNumbers(): void
	{
		$res = $this->parse("# export du 27/11\n\nref;code;quantity;origin\n\nA;1.1;1;\n# fin\nB;xx;1;\n");
		$this->assertCount(1, $res['rows']);
		$this->assertSame(5, $res['rows'][0]['line']);
		$this->assertCount(1, $res['errors']);
		$this->assertStringStartsWith('Line 7:', $res['errors'][0]);
	}

	public function testBomIsStripped(): void
	{
		$res = $this->parse("\xEF\xBB\xBFref;code;quantity;origin\nA;1.1;1;\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertCount(1, $res['rows']);
		$this->assertSame('A', $res['rows'][0]['ref']);
	}

	public function testBomWithoutHeader(): void
	{
		$res = $this->parse("\xEF\xBB\xBFA;1.1;1;\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertSame('A', $res['rows'][0]['ref']);
	}

	public function testCrLfLineEndings(): void
	{
		$res = $this->parse("ref;code;quantity;origin\r\nA;1.1;1;import\r\nB;2.1;1;local\r\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertCount(2, $res['rows']);
		$this->assertSame('local', $res['rows'][1]['origin']);
	}

	public function testCommaSeparatorAccepted(): void
	{
		$res = $this->parse("ref,code,quantity,origin\nA,4.1.6,2,import\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertSame(array('line' => 2, 'ref' => 'A', 'code' => '4.1.6', 'units' => 2.0, 'origin' => 'import', 'exempt' => false), $res['rows'][0]);
	}

	public function testCommaSeparatorWithQuotedDecimalComma(): void
	{
		$res = $this->parse("A,3.2,\"2,5\",local\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertSame(2.5, $res['rows'][0]['units']);
	}

	public function testSemicolonSeparatorWithDecimalComma(): void
	{
		$res = $this->parse("A;3.2;2,5;local\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertSame(2.5, $res['rows'][0]['units']);
		$this->assertSame('3.2', $res['rows'][0]['code']);
	}

	public function testDefaultQuantityIsOne(): void
	{
		$res = $this->parse("A;1.1\nB;1.1;\nC;1.1;;local\n");
		$this->assertSame(array(), $res['errors']);
		foreach ($res['rows'] as $row) {
			$this->assertSame(1.0, $row['units'], $row['ref']);
			$this->assertIsFloat($row['units']);
		}
		$this->assertSame('', $res['rows'][0]['origin']);
	}

	public function testValuesAreTrimmed(): void
	{
		$res = $this->parse("  A  ;  1.1 ; 3 ;  Import \n");
		$this->assertSame(array(), $res['errors']);
		$this->assertSame('A', $res['rows'][0]['ref']);
		$this->assertSame('1.1', $res['rows'][0]['code']);
		$this->assertSame(3.0, $res['rows'][0]['units']);
		$this->assertSame('import', $res['rows'][0]['origin']);
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function originProvider(): array
	{
		return array(
			'empty' => array('', ''),
			'import' => array('import', 'import'),
			'Import' => array('Import', 'import'),
			'imported' => array('imported', 'import'),
			'importe' => array('importe', 'import'),
			'importé' => array('importé', 'import'),
			'IMPORTÉ' => array('IMPORTÉ', 'import'),
			'produit' => array('produit', 'import'),
			'produced' => array('produced', 'import'),
			'geimporteerd' => array('geimporteerd', 'import'),
			'local' => array('local', 'local'),
			'BE' => array('BE', 'local'),
			'Belgique' => array('Belgique', 'local'),
			'belgium' => array('belgium', 'local'),
			'belgie' => array('belgie', 'local'),
			'België' => array('België', 'local'),
			'used' => array('used', 'used'),
			'Occasion' => array('Occasion', 'used'),
			'tweedehands' => array('tweedehands', 'used'),
			'second-hand' => array('second-hand', 'used'),
		);
	}

	/**
	 * @dataProvider originProvider
	 */
	public function testOriginWords(string $word, string $expected): void
	{
		$res = $this->parse("A;1.1;1;" . $word . "\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertSame($expected, $res['rows'][0]['origin']);
	}

	public function testDutchSpellingGeimporteerdWithDiaeresis(): void
	{
		// Régression : l'orthographe néerlandaise « geïmporteerd » (avec tréma)
		// est acceptée comme « geimporteerd ».
		$res = $this->parse("A;1.1;1;geïmporteerd\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertSame('import', $res['rows'][0]['origin']);
	}

	public function testUnknownOriginIsRejected(): void
	{
		$res = $this->parse("A;1.1;1;étranger\nB;1.1;1;china\n");
		$this->assertSame(array(), $res['rows']);
		$this->assertCount(2, $res['errors']);
		$this->assertStringStartsWith('Line 1:', $res['errors'][0]);
		$this->assertStringContainsString('invalid origin', $res['errors'][0]);
		$this->assertStringContainsString('"china"', $res['errors'][1]);
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function exemptProvider(): array
	{
		return array(
			'exempt' => array('exempt'),
			'EXEMPT' => array('EXEMPT'),
			'non soumis' => array('non soumis'),
			'Non Soumis' => array('Non Soumis'),
			'nonsoumis' => array('nonsoumis'),
			'not subject' => array('not subject'),
			'niet onderworpen' => array('niet onderworpen'),
			'dash' => array('-'),
			'none' => array('none'),
		);
	}

	/**
	 * @dataProvider exemptProvider
	 */
	public function testExemptWords(string $word): void
	{
		$res = $this->parse("CABLE;" . $word . ";;\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertTrue($res['rows'][0]['exempt']);
		$this->assertSame('', $res['rows'][0]['code']);
		$this->assertSame(1.0, $res['rows'][0]['units']);
	}

	public function testExemptStillValidatesOrigin(): void
	{
		$res = $this->parse("CABLE;exempt;;bogus\n");
		$this->assertSame(array(), $res['rows']);
		$this->assertStringContainsString('invalid origin', $res['errors'][0]);
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function codeProvider(): array
	{
		return array(
			'1.1' => array('1.1', true),
			'1.50' => array('1.50', true),
			'4.1.6' => array('4.1.6', true),
			'5.14' => array('5.14', true),
			'4.10' => array('4.10', true),
			'parent only' => array('1', false),
			'empty' => array('', false),
			'two-digit main' => array('10.1', false),
			'three-digit segment' => array('4.100', false),
			'four segments' => array('4.1.1.1', false),
			'comma decimal' => array('1,1', false),
			'trailing dot' => array('1.', false),
			'letters' => array('A.1', false),
			'free text' => array('frigo', false),
		);
	}

	/**
	 * @dataProvider codeProvider
	 */
	public function testCodeFormat(string $code, bool $valid): void
	{
		$res = $this->parse('A;"' . $code . '";1;' . "\n");
		if ($valid) {
			$this->assertSame(array(), $res['errors']);
			$this->assertSame($code, $res['rows'][0]['code']);
		} else {
			$this->assertSame(array(), $res['rows']);
			$this->assertCount(1, $res['errors']);
			$this->assertStringContainsString('invalid Recupel code', $res['errors'][0]);
		}
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function badQuantityProvider(): array
	{
		return array(
			'zero' => array('0'),
			'zero decimal' => array('0,0'),
			'negative' => array('-1'),
			'text' => array('two'),
			'thousand separator' => array('1.000,5'),
		);
	}

	/**
	 * @dataProvider badQuantityProvider
	 */
	public function testInvalidQuantity(string $qty): void
	{
		$res = $this->parse("A;1.1;" . $qty . ";\n");
		$this->assertSame(array(), $res['rows']);
		$this->assertCount(1, $res['errors']);
		$this->assertStringContainsString('invalid quantity', $res['errors'][0]);
	}

	public function testEmptyRefIsRejected(): void
	{
		$res = $this->parse("ref;code;quantity;origin\n;1.1;1;\n  ;1.1;1;\n");
		$this->assertSame(array(), $res['rows']);
		$this->assertCount(2, $res['errors']);
		$this->assertSame('Line 2: empty ref', $res['errors'][0]);
		$this->assertSame('Line 3: empty ref', $res['errors'][1]);
	}

	public function testSeveralLinesSameRef(): void
	{
		$res = $this->parse("PACK;3.2;4;local\nPACK;5.5.1;1;local\nOTHER;1.1;1;\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertCount(3, $res['rows']);
		$pack = array_values(array_filter($res['rows'], function ($r) {
			return $r['ref'] === 'PACK';
		}));
		$this->assertCount(2, $pack);
		$this->assertSame(array('3.2', '5.5.1'), array_column($pack, 'code'));
		$this->assertSame(array(4.0, 1.0), array_column($pack, 'units'));
	}

	public function testValidLinesKeptWhenOthersFail(): void
	{
		$res = $this->parse("A;1.1;1;\nB;9;1;\nC;2.1;0;\nD;2.1;1;mars\nE;6.50;1;used\n");
		$this->assertSame(array('A', 'E'), array_column($res['rows'], 'ref'));
		$this->assertCount(3, $res['errors']);
	}

	public function testEmptyContent(): void
	{
		$this->assertSame(array('rows' => array(), 'errors' => array()), $this->parse(''));
		$this->assertSame(array('rows' => array(), 'errors' => array()), $this->parse("ref;code;quantity;origin\n"));
	}

	// ================================================================ csvField

	public function testCsvField(): void
	{
		$this->assertSame('ABC-01', EmrecupelProduct::csvField('ABC-01'));
		$this->assertSame('', EmrecupelProduct::csvField(''));
		$this->assertSame('', EmrecupelProduct::csvField(null));
		$this->assertSame('12', EmrecupelProduct::csvField(12));
		$this->assertSame('"A;B"', EmrecupelProduct::csvField('A;B'));
		$this->assertSame('"A""B"', EmrecupelProduct::csvField('A"B'));
		$this->assertSame("\"A\nB\"", EmrecupelProduct::csvField("A\nB"));
		$this->assertSame("\"A\rB\"", EmrecupelProduct::csvField("A\rB"));
		$this->assertSame('"A,B"', EmrecupelProduct::csvField('A,B'));
		$this->assertSame("'=1+1", EmrecupelProduct::csvField('=1+1'));
		$this->assertSame("'@x", EmrecupelProduct::csvField('@x'));
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function roundTripProvider(): array
	{
		return array(
			'plain' => array('FRIGO-01'),
			'semicolon' => array('PACK;4'),
			'quote' => array('TV 55" OLED'),
			'one comma' => array('CABLE,2M'),
			'quote and semicolon' => array('A";B'),
			'formula' => array('=SUM(A1)'),
			'leading dash' => array('-PROMO-01'),
		);
	}

	/**
	 * Une ligne produite comme dans exportClassification() se relit à l'identique.
	 *
	 * @dataProvider roundTripProvider
	 */
	public function testExportLineRoundTrip(string $ref): void
	{
		$line = EmrecupelProduct::csvField($ref) . ';1.1;2;import';
		$res = $this->parse("ref;code;quantity;origin\n" . $line . "\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertSame($ref, $res['rows'][0]['ref']);
		$this->assertSame('1.1', $res['rows'][0]['code']);
		$this->assertSame(2.0, $res['rows'][0]['units']);
	}

	public function testExportLineRoundTripRefWithManyCommas(): void
	{
		// Régression : csvField() met entre guillemets une valeur contenant des
		// virgules et le parseur ne compte que les séparateurs hors guillemets.
		$ref = 'CABLE,2M,NOIR,EU,PRISE';
		$line = EmrecupelProduct::csvField($ref) . ';1.1;2;import';
		$res = $this->parse("ref;code;quantity;origin\n" . $line . "\n");
		$this->assertSame(array(), $res['errors']);
		$this->assertSame($ref, $res['rows'][0]['ref']);
	}
}
