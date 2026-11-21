<?php

/**
 * Tests for the `band` op: the percentage of the band a value falls in.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/payroll-reservation-payouts/specs/payroll-reservation-payouts/spec.md#requirement-the-engine-shall-tax-a-special-payment-at-the-special-rate-req-rsp-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll\Dsl\Ops;

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\Dsl\Ops\BandOp;
use OCA\Humaniq\Payroll\Dsl\RefResolver;
use OCA\Humaniq\Payroll\Dsl\StepContext;
use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * A band table with three rows: 10% up to 1000.00, 20% up to 5000.00, 40% above.
 */
class BandOpTest extends TestCase {

	/**
	 * The op declares its pack name.
	 *
	 * @return void
	 */
	public function testTheOpDeclaresItsPackName(): void {
		$this->assertSame('band', (new BandOp(new RefResolver()))->name());
	}//end testTheOpDeclaresItsPackName()

	/**
	 * The first band, a middle band and the open top band give their percentage, not an amount.
	 *
	 * @return void
	 */
	public function testEachBandGivesItsPercentage(): void {
		$this->assertSame(10.0, $this->evaluate(50000, 'cent'));
		$this->assertSame(20.0, $this->evaluate(250000, 'cent'));
		$this->assertSame(40.0, $this->evaluate(1000000, 'cent'));
		$this->assertSame(40.0, $this->evaluate(999999999, 'cent'));
	}//end testEachBandGivesItsPercentage()

	/**
	 * A value on a band's `tot` belongs to that band.
	 *
	 * @return void
	 */
	public function testABoundaryBelongsToTheLowerBand(): void {
		$this->assertSame(10.0, $this->evaluate(100000, 'cent'));
		$this->assertSame(20.0, $this->evaluate(100001, 'cent'));
	}//end testABoundaryBelongsToTheLowerBand()

	/**
	 * Euro `tot` values are converted to cents before comparing.
	 *
	 * @return void
	 */
	public function testEuroBandsAreComparedInCents(): void {
		// 250000 cents is 2500 euro: in the first euro band (tot 100000 euro).
		$this->assertSame(10.0, $this->evaluate(250000, 'euro'));
		// 20 000 000 cents is 200 000 euro: above 100 000 euro, below 500 000 euro.
		$this->assertSame(20.0, $this->evaluate(20000000, 'euro'));
	}//end testEuroBandsAreComparedInCents()

	/**
	 * Without an open top band the last band applies above every `tot`.
	 *
	 * @return void
	 */
	public function testABoundedTableFallsBackToItsLastBand(): void {
		$bounded = [['tot' => 100000, 'percentage' => 10], ['tot' => 500000, 'percentage' => 36.97]];

		$this->assertSame(36.97, $this->evaluateSpec(['value' => 900000, 'table' => $bounded, 'unit' => 'cent']));
	}//end testABoundedTableFallsBackToItsLastBand()

	/**
	 * The table may be a reference to a binding.
	 *
	 * @return void
	 */
	public function testTheTableMayBeAReference(): void {
		$this->assertSame(20.0, $this->evaluateSpec(['value' => 250000, 'table' => '@binding.banden', 'unit' => 'cent']));
	}//end testTheTableMayBeAReference()

	/**
	 * An absent or unknown unit is refused.
	 *
	 * @return void
	 */
	public function testAnAbsentOrUnknownUnitIsRefused(): void {
		foreach (['pond', ''] as $unit) {
			try {
				$this->evaluateSpec(['value' => 50000, 'table' => $this->rows(), 'unit' => $unit]);
				$this->fail('unit "' . $unit . '" must be refused');
			} catch (DslException $e) {
				$this->assertStringContainsString(implode('|', BandOp::UNITS), $e->getMessage());
			}
		}
	}//end testAnAbsentOrUnknownUnitIsRefused()

	/**
	 * A malformed table, row or percentage is refused.
	 *
	 * @return void
	 */
	public function testAMalformedTableIsRefused(): void {
		$cases = [
			'no table' => [null, 'niet-lege rijenlijst'],
			'an empty table' => [[], 'niet-lege rijenlijst'],
			'a string' => ['tabel', 'niet-lege rijenlijst'],
			'a scalar row' => [[5], 'rijen met "tot" en "percentage"'],
			'no percentage' => [[['tot' => null]], 'numeriek "percentage"'],
			'a text percentage' => [[['tot' => null, 'percentage' => 'veel']], 'numeriek "percentage"'],
		];
		foreach ($cases as $name => [$table, $message]) {
			try {
				$this->evaluateSpec(['value' => 50000, 'table' => $table, 'unit' => 'cent']);
				$this->fail($name . ' must be refused');
			} catch (DslException $e) {
				$this->assertStringContainsString($message, $e->getMessage(), $name);
			}
		}
	}//end testAMalformedTableIsRefused()

	/**
	 * The value is required.
	 *
	 * @return void
	 */
	public function testTheValueIsRequired(): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessageMatches('/"value"/');

		$this->evaluateSpec(['table' => $this->rows(), 'unit' => 'cent']);
	}//end testTheValueIsRequired()

	/**
	 * Evaluate the three-band table for a value.
	 *
	 * @param int    $value The value in cents.
	 * @param string $unit  The declared unit.
	 *
	 * @return mixed The percentage.
	 */
	private function evaluate(int $value, string $unit): mixed {
		return $this->evaluateSpec(['value' => $value, 'table' => $this->rows(), 'unit' => $unit]);
	}//end evaluate()

	/**
	 * Evaluate a spec in a context carrying the table as binding `banden`.
	 *
	 * @param array<string, mixed> $spec The spec.
	 *
	 * @return mixed The percentage.
	 */
	private function evaluateSpec(array $spec): mixed {
		$ctx = new StepContext(['income' => 50000], TaxTables::load('nl-2026'), '2026-05', ['currency' => 'EUR']);
		$ctx->setBinding('banden', $this->rows());

		return (new BandOp(new RefResolver()))->evaluate($spec, $ctx);
	}//end evaluateSpec()

	/**
	 * The three-band table.
	 *
	 * @return list<array{tot: int|null, percentage: int}> The rows.
	 */
	private function rows(): array {
		return [
			['tot' => 100000, 'percentage' => 10],
			['tot' => 500000, 'percentage' => 20],
			['tot' => null, 'percentage' => 40],
		];
	}//end rows()
}//end class
