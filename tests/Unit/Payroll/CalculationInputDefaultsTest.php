<?php

/**
 * Unit tests for the defaults and pass-through of CalculationInput.
 *
 * A stored engineInputSnapshot without the loonheffingskorting election reads
 * back as not applied, one without a jurisdiction reads back as NL, and a
 * jurisdiction other than NL is carried through both construction and the
 * snapshot round trip.
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
 * @spec openspec/specs/audit-trail-payroll/spec.md#REQ-AUDP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use OCA\Humaniq\Payroll\CalculationInput;
use OCA\Humaniq\Payroll\CalculationLevies;
use OCA\Humaniq\Payroll\CalculationWage;
use OCA\Humaniq\Payroll\InsuranceTariffs;
use OCA\Humaniq\Payroll\WageTaxElection;
use PHPUnit\Framework\TestCase;

/**
 * Tests for CalculationInput defaults.
 *
 * @spec openspec/specs/audit-trail-payroll/spec.md#REQ-AUDP-002
 */
class CalculationInputDefaultsTest extends TestCase {

	/**
	 * A snapshot without the election reads as not applied; an explicit
	 * true still reads as applied.
	 *
	 * @return void
	 */
	public function testASnapshotWithoutTheKortingElectionReadsAsNotApplied(): void {
		$this->assertFalse(CalculationInput::fromDecoded(['taxTableColor' => 'wit'])->loonheffingskortingToegepast);
		$this->assertFalse(CalculationInput::fromDecoded(['loonheffingskortingToegepast' => null])->loonheffingskortingToegepast);
		$this->assertTrue(CalculationInput::fromDecoded(['loonheffingskortingToegepast' => true])->loonheffingskortingToegepast);

	}//end testASnapshotWithoutTheKortingElectionReadsAsNotApplied()

	/**
	 * A snapshot without a jurisdiction reads as NL, upper case.
	 *
	 * @return void
	 */
	public function testASnapshotWithoutAJurisdictionReadsAsNl(): void {
		$this->assertSame('NL', CalculationInput::fromDecoded([])->jurisdiction);

	}//end testASnapshotWithoutAJurisdictionReadsAsNl()

	/**
	 * A jurisdiction other than NL is carried through construction, the
	 * array form and the snapshot round trip.
	 *
	 * @return void
	 */
	public function testANonNlJurisdictionIsPassedThrough(): void {
		$input = new CalculationInput(
			wage: new CalculationWage(grossMonthlySalaryCents: 380000, dateOfBirth: null, period: '2026-02', jurisdiction: 'BE'),
			levies: new CalculationLevies(
				wageTax: new WageTaxElection(taxTableColor: 'wit', loonheffingskortingToegepast: true),
				insurance: new InsuranceTariffs(awfTariff: 'low', aofTariff: 'laag', whkPercentage: 1.52)
			)
		);

		$this->assertSame('BE', $input->jurisdiction);
		$this->assertSame('BE', $input->toArray()['jurisdiction']);
		$this->assertSame('BE', CalculationInput::fromDecoded($input->toArray())->jurisdiction);
		$this->assertSame('DE', CalculationInput::fromDecoded(['jurisdiction' => 'DE'])->jurisdiction);

	}//end testANonNlJurisdictionIsPassedThrough()

}//end class
