<?php

/**
 * Unit tests for BracketOp — the affine bracket-table lookup.
 *
 * Three things here are invisible if they break: the row selection (first row
 * whose `tot` reaches the value, a null `tot` matching unconditionally), the
 * declared `unit` conversion (the table corpus carries no unit marker of its
 * own, so an assumed unit would be out by a factor of 100), and the refusal
 * of the progressive mode that is a named follow-up rather than a behaviour.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll\Dsl\Ops
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll\Dsl\Ops;

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\Dsl\Ops\BracketOp;
use OCA\Humaniq\Payroll\Dsl\RefResolver;
use OCA\Humaniq\Payroll\Dsl\StepContext;
use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * `bracket(value, table, unit, mode: affine)`.
 *
 * @covers \OCA\Humaniq\Payroll\Dsl\Ops\BracketOp
 * @uses   \OCA\Humaniq\Payroll\Dsl\Ops\AbstractOp
 * @uses   \OCA\Humaniq\Payroll\Dsl\RefResolver
 * @uses   \OCA\Humaniq\Payroll\Dsl\ParsedRef
 * @uses   \OCA\Humaniq\Payroll\Dsl\StepContext
 * @uses   \OCA\Humaniq\Payroll\Dsl\DslException
 * @uses   \OCA\Humaniq\Payroll\TaxTables
 */
class BracketOpTest extends TestCase {

	/**
	 * The op declares the name a pack writes.
	 *
	 * @return void
	 */
	public function testTheOpDeclaresItsPackName(): void {
		$this->assertSame('bracket', (new BracketOp(new RefResolver()))->name());

	}//end testTheOpDeclaresItsPackName()

	/**
	 * The first row whose `tot` reaches the value is applied, in the affine
	 * form `(value - a) * percentage / 100 + c`.
	 *
	 * @return void
	 */
	public function testTheFirstRowReachingTheValueIsAppliedAffinely(): void {
		// Row 1 of the cent table: a = 0, c = 0, 10% of 50000 = 5000.
		$this->assertEqualsWithDelta(5000.0, $this->evaluate(50000, 'cent'), 1.0e-6);

		// Row 2: a = 100000, c = 10000, so (250000 - 100000) * 20% + 10000.
		$this->assertEqualsWithDelta(40000.0, $this->evaluate(250000, 'cent'), 1.0e-6);

	}//end testTheFirstRowReachingTheValueIsAppliedAffinely()

	/**
	 * A row boundary belongs to the lower row: a value exactly on `tot` takes
	 * that row, not the next one. A real affine table is continuous at its
	 * boundaries, which hides the off-by-one, so this uses a deliberately
	 * discontinuous table where the two rows disagree on the boundary value.
	 *
	 * @return void
	 */
	public function testARowBoundaryBelongsToTheLowerRow(): void {
		$discontinuous = [
			[
				'tot'        => 100000,
				'a'          => 0,
				'c'          => 0,
				'percentage' => 10,
			],
			[
				'tot'        => null,
				'a'          => 0,
				'c'          => 777,
				'percentage' => 10,
			],
		];

		$this->assertEqualsWithDelta(
			10000.0,
			$this->evaluateSpec(
				[
					'value' => 100000,
					'table' => $discontinuous,
					'unit'  => 'cent',
					'mode'  => 'affine',
				]
			),
			1.0e-6,
			'on row 1 tot: row 1 applies, so no 777 constant'
		);

		$this->assertEqualsWithDelta(
			10778.0,
			$this->evaluateSpec(
				[
					'value' => 100010,
					'table' => $discontinuous,
					'unit'  => 'cent',
					'mode'  => 'affine',
				]
			),
			1.0e-6,
			'one step above it, row 2 applies'
		);

	}//end testARowBoundaryBelongsToTheLowerRow()

	/**
	 * A null `tot` is the unbounded top bracket and matches whatever is left,
	 * however large.
	 *
	 * @return void
	 */
	public function testANullTotIsTheUnboundedTopBracket(): void {
		// Row 3: a = 500000, c = 90000, 40% above it.
		$this->assertEqualsWithDelta(290000.0, $this->evaluate(1000000, 'cent'), 1.0e-6);
		$this->assertEqualsWithDelta(4090000.0, $this->evaluate(10500000, 'cent'), 1.0e-6);

	}//end testANullTotIsTheUnboundedTopBracket()

	/**
	 * A table whose last row is bounded, and whose bounds all fall below the
	 * value, falls back to that last row rather than refusing — a table with
	 * no unbounded top bracket still yields its top row's tariff.
	 *
	 * @return void
	 */
	public function testATableWithNoUnboundedTopRowFallsBackToItsLastRow(): void {
		$bounded = [
			[
				'tot'        => 100000,
				'a'          => 0,
				'c'          => 0,
				'percentage' => 10,
			],
			[
				'tot'        => 500000,
				'a'          => 100000,
				'c'          => 10000,
				'percentage' => 20,
			],
		];

		// Above every `tot`: row 2 applies, (900000 - 100000) * 20% + 10000.
		$this->assertEqualsWithDelta(
			170000.0,
			$this->evaluateSpec(
				[
					'value' => 900000,
					'table' => $bounded,
					'unit'  => 'cent',
					'mode'  => 'affine',
				]
			),
			1.0e-6
		);

	}//end testATableWithNoUnboundedTopRowFallsBackToItsLastRow()

	/**
	 * With `unit: euro` the row's `tot`/`a`/`c` are read as euros and
	 * converted through the tables' own euro-to-cents rule, so the same
	 * numbers mean a hundred times more — which is why the unit is declared
	 * and never assumed.
	 *
	 * @return void
	 */
	public function testTheDeclaredUnitDecidesWhatTheRowNumbersMean(): void {
		// 250000 cents lands in euro-row 1 (tot 100000 euro = 10 000 000 cents).
		$euro = $this->evaluate(250000, 'euro');

		$this->assertEqualsWithDelta(25000.0, $euro, 1.0e-6);
		$this->assertNotEquals(
			$this->evaluate(250000, 'cent'),
			$euro,
			'the same table read in euros is a different bracket'
		);

	}//end testTheDeclaredUnitDecidesWhatTheRowNumbersMean()

	/**
	 * Only `affine` is supported; the progressive-sum mode is a named
	 * follow-up and is refused rather than approximated.
	 *
	 * @return void
	 */
	public function testOnlyTheAffineModeIsSupported(): void {
		foreach (['progressive', ''] as $mode) {
			try {
				$this->evaluateSpec(
					[
						'value' => 50000,
						'table' => $this->rows(),
						'unit'  => 'cent',
						'mode'  => $mode,
					]
				);
				$this->fail('mode "' . $mode . '" must be refused');
			} catch (DslException $e) {
				$this->assertStringContainsString('alleen mode "affine"', $e->getMessage());
			}
		}

	}//end testOnlyTheAffineModeIsSupported()

	/**
	 * An absent or unknown unit is refused, listing the units a table may
	 * declare.
	 *
	 * @return void
	 */
	public function testAnAbsentOrUnknownUnitIsRefused(): void {
		foreach (['pond', ''] as $unit) {
			try {
				$this->evaluateSpec(
					[
						'value' => 50000,
						'table' => $this->rows(),
						'unit'  => $unit,
						'mode'  => 'affine',
					]
				);
				$this->fail('unit "' . $unit . '" must be refused');
			} catch (DslException $e) {
				$this->assertStringContainsString(implode('|', BracketOp::UNITS), $e->getMessage());
			}
		}

	}//end testAnAbsentOrUnknownUnitIsRefused()

	/**
	 * A table that is absent, empty or not a list of rows is refused.
	 *
	 * @return void
	 */
	public function testAMalformedTableIsRefused(): void {
		foreach ([null, [], 'tabel'] as $table) {
			try {
				$this->evaluateSpec(
					[
						'value' => 50000,
						'table' => $table,
						'unit'  => 'cent',
						'mode'  => 'affine',
					]
				);
				$this->fail('a malformed table must be refused');
			} catch (DslException $e) {
				$this->assertStringContainsString('niet-lege rijenlijst', $e->getMessage());
			}
		}

	}//end testAMalformedTableIsRefused()

	/**
	 * The value is required and must be numeric, like every other money param.
	 *
	 * @return void
	 */
	public function testTheValueIsRequiredAndMustBeNumeric(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/"value"/');

		$this->evaluateSpec(
			[
				'table' => $this->rows(),
				'unit'  => 'cent',
				'mode'  => 'affine',
			]
		);

	}//end testTheValueIsRequiredAndMustBeNumeric()

	/**
	 * The table may be handed in as a reference to a binding rather than
	 * inline, which is how a pack points at a table-set column.
	 *
	 * @return void
	 */
	public function testTheTableMayBeAReference(): void {
		$this->assertEqualsWithDelta(
			5000.0,
			$this->evaluateSpec(
				[
					'value' => 50000,
					'table' => '@binding.schijven',
					'unit'  => 'cent',
					'mode'  => 'affine',
				]
			),
			1.0e-6
		);

	}//end testTheTableMayBeAReference()

	/**
	 * Evaluate the op over the fixture rows for one value and unit.
	 *
	 * @param int    $value The subject value, in cents.
	 * @param string $unit  The declared money unit of the rows.
	 *
	 * @return mixed
	 */
	private function evaluate(int $value, string $unit): mixed {
		return $this->evaluateSpec(
			[
				'value' => $value,
				'table' => $this->rows(),
				'unit'  => $unit,
				'mode'  => 'affine',
			]
		);

	}//end evaluate()

	/**
	 * Evaluate the op against a full spec.
	 *
	 * @param array<string, mixed> $spec The declared spec.
	 *
	 * @return mixed
	 */
	private function evaluateSpec(array $spec): mixed {
		$ctx = new StepContext(
			['income' => 50000],
			TaxTables::load('nl-2026'),
			'2026-02',
			['currency' => 'EUR']
		);
		$ctx->setBinding('schijven', $this->rows());

		return (new BracketOp(new RefResolver()))->evaluate($spec, $ctx);
	}//end evaluateSpec()

	/**
	 * A three-row affine bracket table: 10% to 1000,00, then 20%, then 40%
	 * unbounded, with precomputed `a`/`c` constants per row.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows(): array {
		return [
			[
				'tot'        => 100000,
				'a'          => 0,
				'c'          => 0,
				'percentage' => 10,
			],
			[
				'tot'        => 500000,
				'a'          => 100000,
				'c'          => 10000,
				'percentage' => 20,
			],
			[
				'tot'        => null,
				'a'          => 500000,
				'c'          => 90000,
				'percentage' => 40,
			],
		];

	}//end rows()

}//end class
