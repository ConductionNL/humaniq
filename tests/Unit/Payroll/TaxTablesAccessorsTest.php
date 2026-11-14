<?php

/**
 * TaxTables accessor tests.
 *
 * Each accessor reads its leaf of the tables document: from the right group,
 * in the right column for an employee below or at AOW age, and in cents
 * where it is an amount. Pinned against the bundled 2026 document, read raw
 * here, so a moved leaf, a swapped column or a missed conversion shows.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use OCA\Humaniq\Payroll\TaxTables;
use OCA\Humaniq\Tests\Unit\Support\PackFixtures;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The accessors read the documented leaves, columns and units.
 *
 * @covers \OCA\Humaniq\Payroll\TaxTables
 */
class TaxTablesAccessorsTest extends TestCase {

	/**
	 * The raw 2026 document.
	 *
	 * @var array<string, mixed>
	 */
	private array $raw;

	/**
	 * The tables built from it.
	 *
	 * @var TaxTables
	 */
	private TaxTables $tables;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->raw = PackFixtures::tables('nl-2026');
		$this->tables = TaxTables::fromDocument($this->raw, 'test');
	}//end setUp()

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		TaxTables::useSource(null);
		TaxTables::resetAvailableIdsCache();
	}//end tearDown()

	/**
	 * A raw value of the document.
	 *
	 * @param string ...$path The path under `parameters`.
	 *
	 * @return mixed
	 */
	private function v(string ...$path): mixed {
		$node = $this->raw['parameters'];
		foreach ($path as $segment) {
			$node = $node[$segment];
		}

		return $node;
	}//end v()

	/**
	 * A raw euro amount of the document, in cents.
	 *
	 * @param string ...$path The path under `parameters`.
	 *
	 * @return int
	 */
	private function c(string ...$path): int {
		return (int)round((float)$this->v(...$path) * 100);
	}//end c()

	/**
	 * The wage-tax limits are amounts in cents, and a period counts by its own factor, a month by default.
	 *
	 * @return void
	 */
	public function testTheWageTaxLimitsAndPeriods(): void {
		self::assertSame($this->c('loonheffing', 'Lv', 'value'), $this->tables->lv());
		self::assertSame($this->c('loonheffing', 'Lmax', 'value'), $this->tables->lmax());
		self::assertSame(12, $this->tables->tijdvakFactor());
		self::assertSame(52, $this->tables->tijdvakFactor('week'));
		self::assertSame(13, $this->tables->tijdvakFactor('vierweken'));
		self::assertSame(12, $this->tables->tijdvakFactor('fortnight'), 'an unknown period counts as a month');
	}//end testTheWageTaxLimitsAndPeriods()

	/**
	 * Brackets come in cents with their percentage, and the last bracket is open.
	 *
	 * @return void
	 */
	public function testTheBracketsOfASet(): void {
		$rows = $this->v('loonheffing', 'schijven', 'belowAow', 'value');
		$brackets = $this->tables->schijven('belowAow');

		self::assertCount(count($rows), $brackets);
		self::assertSame((int)round((float)$rows[0]['tot'] * 100), $brackets[0]['tot']);
		self::assertSame((float)$rows[0]['percentage'], $brackets[0]['percentage']);
		self::assertSame((int)round((float)$rows[0]['a'] * 100), $brackets[0]['a']);
		self::assertSame((int)round((float)$rows[0]['c'] * 100), $brackets[0]['c']);
		self::assertNull($brackets[array_key_last($brackets)]['tot']);
	}//end testTheBracketsOfASet()

	/**
	 * The general and labour tax credits read the column of the employee's age.
	 *
	 * @return void
	 */
	public function testTheTaxCreditsReadTheAgeColumn(): void {
		foreach (['belowAow' => false, 'aowAge' => true] as $col => $aow) {
			self::assertSame(
				[
					'm1' => $this->c('heffingskortingen', 'ahkm1', 'value', $col),
					'g1' => $this->c('heffingskortingen', 'ahkg1', 'value', $col),
					'g2' => $this->c('heffingskortingen', 'ahkg2', 'value', $col),
					'a1' => (float)$this->v('heffingskortingen', 'ahka1', 'value', $col),
				],
				$this->tables->ahk($aow),
				$col
			);

			$ark = $this->tables->ark($aow);
			self::assertSame((float)$this->v('heffingskortingen', 'arko1', 'value', $col), $ark['o1'], $col);
			self::assertSame((float)$this->v('heffingskortingen', 'arka1', 'value', $col), $ark['a1'], $col);
			self::assertSame($this->c('heffingskortingen', 'arkg4', 'value', $col), $ark['g4'], $col);
			self::assertSame($this->c('heffingskortingen', 'arkm3', 'value', $col), $ark['m3'], $col);
		}

		self::assertNotSame($this->tables->ahk(false), $this->tables->ahk(true), 'the two columns differ in 2026');
		self::assertSame(
			[
				'm1' => $this->c('heffingskortingen', 'oukm1', 'value', 'aowAge'),
				'g1' => $this->c('heffingskortingen', 'oukg1', 'value', 'aowAge'),
				'g2' => $this->c('heffingskortingen', 'oukg2', 'value', 'aowAge'),
				'a1' => (float)$this->v('heffingskortingen', 'ouka1', 'value', 'aowAge'),
			],
			$this->tables->ouk()
		);
	}//end testTheTaxCreditsReadTheAgeColumn()

	/**
	 * The insurance rates are percentages and the premium ceilings are monthly amounts in cents.
	 *
	 * @return void
	 */
	public function testTheInsurances(): void {
		self::assertSame(
			['aow' => (float)$this->v('volksverzekeringen', 'aow', 'value'), 'anw' => (float)$this->v('volksverzekeringen', 'anw', 'value'), 'wlz' => (float)$this->v('volksverzekeringen', 'wlz', 'value')],
			$this->tables->volksverzekeringenRates()
		);
		self::assertSame((int)$this->v('aow', 'leeftijdJaren', 'value'), $this->tables->aowLeeftijdJaren());

		$ceiling = $this->c('werknemersverzekeringen', 'maximumpremieloon', 'value', 'maand');
		self::assertSame(
			['werkgeversheffing' => (float)$this->v('zvw', 'werkgeversheffing', 'value'), 'inhouding' => (float)$this->v('zvw', 'inhouding', 'value'), 'maximumBijdrageloonMaand' => $ceiling],
			$this->tables->zvw()
		);
		self::assertSame(
			[
				'maximumPremieloonMaand' => $ceiling,
				'awfLaag' => (float)$this->v('werknemersverzekeringen', 'awf', 'value', 'laag'),
				'awfHoog' => (float)$this->v('werknemersverzekeringen', 'awf', 'value', 'hoog'),
				'aofLaag' => (float)$this->v('werknemersverzekeringen', 'aof', 'value', 'laag'),
				'aofHoog' => (float)$this->v('werknemersverzekeringen', 'aof', 'value', 'hoog'),
				'wkoOpslag' => (float)$this->v('werknemersverzekeringen', 'wkoOpslag', 'value'),
				'whkDefault' => (float)$this->v('werknemersverzekeringen', 'whk', 'value'),
			],
			$this->tables->werknemersverzekeringen()
		);
	}//end testTheInsurances()

	/**
	 * The wage norms and schemes read their amounts in cents and their rates as given.
	 *
	 * @return void
	 */
	public function testTheWageNormsAndSchemes(): void {
		self::assertSame((float)$this->v('vakantiebijslag', 'minRatePercent', 'value'), $this->tables->vakantiebijslagRate());
		self::assertSame(['referentiemaandloonCents' => $this->c('wml', 'referentiemaandloon', 'value')], $this->tables->wml());
		self::assertSame(['jaarnormCents' => $this->c('gebruikelijkloon', 'jaarnorm', 'value')], $this->tables->gebruikelijkloon());
		self::assertSame(
			[
				'percent' => (float)$this->v('dertigProcentRegeling', 'percent', 'value'),
				'maxDurationMonths' => (int)$this->v('dertigProcentRegeling', 'maxDurationMonths', 'value'),
				'aftoppingsgrensMaandCents' => $this->c('dertigProcentRegeling', 'aftoppingsgrens', 'value', 'maand'),
				'aftoppingsgrensJaarCents' => $this->c('dertigProcentRegeling', 'aftoppingsgrens', 'value', 'jaar'),
				'salarisnormAlgemeenCents' => $this->c('dertigProcentRegeling', 'salarisnormAlgemeen', 'value'),
				'salarisnormMasterOnder30Cents' => $this->c('dertigProcentRegeling', 'salarisnormMasterOnder30', 'value'),
			],
			$this->tables->dertigProcentRegeling()
		);
		self::assertSame(
			[
				'tranche1Percent' => (float)$this->v('wkr', 'vrijeRuimteTranche1Percent', 'value'),
				'tranche1GrensCents' => $this->c('wkr', 'vrijeRuimteTranche1Grens', 'value'),
				'tranche2Percent' => (float)$this->v('wkr', 'vrijeRuimteTranche2Percent', 'value'),
				'eindheffingPercent' => (float)$this->v('wkr', 'eindheffingPercent', 'value'),
			],
			$this->tables->wkr()
		);
		self::assertSame(
			[
				'standardPercent' => (float)$this->v('bijtellingPrivegebruikAuto', 'standardPercent', 'value'),
				'evReducedPercent' => (float)$this->v('bijtellingPrivegebruikAuto', 'evReducedPercent', 'value'),
				'evReducedCataloguswaardeCapCents' => $this->c('bijtellingPrivegebruikAuto', 'evReducedCataloguswaardeCap', 'value'),
			],
			$this->tables->bijtellingPrivegebruikAuto()
		);
	}//end testTheWageNormsAndSchemes()

	/**
	 * Euros become whole cents, rounded half away from zero.
	 *
	 * @return void
	 */
	public function testEurosBecomeRoundedCents(): void {
		self::assertSame(1235, $this->tables->toCents(12.345));
		self::assertSame(-13, $this->tables->toCents(-0.125));
		self::assertSame(0, $this->tables->toCents(0.004));
	}//end testEurosBecomeRoundedCents()

	/**
	 * A leaf the document lacks is named in the refusal.
	 *
	 * @return void
	 */
	public function testAMissingLeafIsNamed(): void {
		$raw = $this->raw;
		unset($raw['parameters']['wkr']);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('TaxTables (nl-2026): ontbrekende parameter "wkr".');

		TaxTables::fromDocument($raw, 'test')->wkr();
	}//end testAMissingLeafIsNamed()

	/**
	 * A document without parameters, or an id with characters a file name may not carry, is refused.
	 *
	 * @return void
	 */
	public function testMalformedInputIsRefused(): void {
		try {
			TaxTables::fromDocument(['id' => 'x'], 'upload 7');
			self::fail('a document without parameters must be refused');
		} catch (RuntimeException $e) {
			self::assertSame('TaxTables: tabelbestand mist "parameters": upload 7', $e->getMessage());
		}

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('TaxTables: ongeldige tabel-id "../nl-2026".');

		TaxTables::load(' ../nl-2026 ');
	}//end testMalformedInputIsRefused()

	/**
	 * A source that is not a tables source is not consulted for an unknown id.
	 *
	 * @return void
	 */
	public function testASourceOfAnotherKindIsIgnored(): void {
		TaxTables::useSource(static fn (): object => new \stdClass());

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/^TaxTables: tabelbestand niet gevonden en geen actieve geüploade tabellen voor nl-1999: /');

		TaxTables::load('nl-1999');
	}//end testASourceOfAnotherKindIsIgnored()

	/**
	 * The bundled ids are listed from disk once, and listed again after a reset.
	 *
	 * @return void
	 */
	public function testTheBundledIdsAreListedFromDisk(): void {
		TaxTables::resetAvailableIdsCache();
		$ids = TaxTables::availableIds();

		self::assertContains('nl-2026', $ids);
		self::assertSame($ids, TaxTables::availableIds());
		self::assertTrue(TaxTables::isBundled(' nl-2026 '));
		self::assertFalse(TaxTables::isBundled('nl-1999'));
	}//end testTheBundledIdsAreListedFromDisk()
}//end class
