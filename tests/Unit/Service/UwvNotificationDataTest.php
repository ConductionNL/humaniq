<?php

/**
 * Unit tests for UwvNotificationData.
 *
 * The 42-week notification carries the D3 fields and nothing else: no field
 * outside them, so no medical detail can travel with it; a recovered case and
 * a case whose notification is done are refused.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/specs/absence-deadlines-and-signals/spec.md#REQ-ADS-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\UwvNotificationData;
use PHPUnit\Framework\TestCase;

/**
 * The 42-week notification's content.
 */
class UwvNotificationDataTest extends TestCase {

	/**
	 * An open case in week 40, 50 percent back at work.
	 *
	 * @return array<string, mixed>
	 */
	private function openCase(): array {
		return [
			'employeeId' => 'emp-1',
			'firstSickDay' => '2025-12-22',
			'status' => 'gemeld',
			'uwv42WeekMeldingDue' => '2026-10-12',
			'uwv42WeekMeldingDone' => null,
			'absenceProgression' => [
				['effectiveFrom' => '2025-12-22', 'absencePercentage' => 100],
				['effectiveFrom' => '2026-06-01', 'absencePercentage' => 50],
			],
			'currentAbsencePercentage' => 50,
			'administrationId' => 'ADM-001',
			'diagnosis' => 'must never travel',
		];
	}//end openCase()

	/**
	 * The adviser files from a form holding the first sick day, the 50
	 * percent step and the 32 contract hours, and the D3 fields only.
	 *
	 * @return void
	 */
	public function testTheNotificationHoldsTheD3FieldsAndNothingElse(): void {
		$data = (new UwvNotificationData())->build(
			$this->openCase(),
			['firstName' => 'Sam', 'lastName' => 'Jansen', 'bsn' => '123456782', 'dateOfBirth' => '1990-03-14', 'iban' => 'NL00BANK0123456789'],
			['hoursPerWeek' => 32, 'type' => 'permanent', 'endDate' => null, 'hourlyWage' => 24.36],
			['name' => 'Conduction Demo B.V.', 'loonheffingennummer' => '000000000L01', 'kvkNumber' => '12345678', 'mode' => 'standard']
		);

		self::assertSame(
			[
				'employer' => ['name' => 'Conduction Demo B.V.', 'loonheffingennummer' => '000000000L01', 'kvkNumber' => '12345678'],
				'employee' => ['name' => 'Sam Jansen', 'bsn' => '123456782', 'dateOfBirth' => '1990-03-14'],
				'case' => [
					'firstSickDay' => '2025-12-22',
					'currentAbsencePercentage' => 50,
					'resumption' => [
						['effectiveFrom' => '2025-12-22', 'absencePercentage' => 100],
						['effectiveFrom' => '2026-06-01', 'absencePercentage' => 50],
					],
				],
				'contract' => ['hoursPerWeek' => 32, 'type' => 'permanent', 'endDate' => null],
			],
			$data
		);
	}//end testTheNotificationHoldsTheD3FieldsAndNothingElse()

	/**
	 * A recovered case, a done notification and a case without an employee
	 * are refused.
	 *
	 * @return void
	 */
	public function testARecoveredOrDoneCaseIsRefused(): void {
		$builder = new UwvNotificationData();

		self::assertNull($builder->refusal($this->openCase()));
		self::assertSame('refused-recovered', $builder->refusal(array_merge($this->openCase(), ['status' => 'hersteld']))['status']);
		self::assertSame('refused-already-done', $builder->refusal(array_merge($this->openCase(), ['uwv42WeekMeldingDone' => '2026-10-01']))['status']);
		self::assertSame('refused-not-found', $builder->refusal([])['status']);
	}//end testARecoveredOrDoneCaseIsRefused()

	/**
	 * The contract covering the first sick day is the one described.
	 *
	 * @return void
	 */
	public function testTheCoveringContractIsTheOneOnTheFirstSickDay(): void {
		$contracts = [
			['id' => 'old', 'startDate' => '2020-01-01', 'endDate' => '2023-12-31'],
			['id' => 'now', 'startDate' => '2024-01-01', 'endDate' => null],
		];

		self::assertSame('now', (new UwvNotificationData())->coveringContract($contracts, '2025-12-22')['id']);
		self::assertNull((new UwvNotificationData())->coveringContract($contracts, '2019-06-01'));
	}//end testTheCoveringContractIsTheOneOnTheFirstSickDay()

}//end class
