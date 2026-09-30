<?php

/**
 * CaoComponentPayService: the components one payslip pays.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit
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
 * @spec openspec/specs/payroll-cao-components/spec.md#REQ-CCP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\CaoComponentCalculator;
use OCA\Humaniq\Service\CaoComponentPayService;
use OCA\Humaniq\Service\EmploymentTermsResolver;
use OCA\Humaniq\Standards\CaoRegistry;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Real resolver and calculator; the payslip fields against the real schema.
 */
class CaoComponentPayServiceTest extends TestCase {

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		CaoRegistry::reset();
	}//end setUp()

	/**
	 * The service.
	 *
	 * @return CaoComponentPayService
	 */
	private function service(): CaoComponentPayService {
		return new CaoComponentPayService(new EmploymentTermsResolver(), new CaoComponentCalculator(), new NullLogger());
	}//end service()

	/**
	 * A night worker under the example agreement: the shift allowance on the
	 * wage and the night premium on the approved night entry of a timesheet
	 * the run pays; an entry of another timesheet earns nothing.
	 *
	 * @return void
	 */
	public function testANightWorkerIsPaidBothComponents(): void {
		$contract = ['cao' => 'cao-voorbeeld', 'caoComponents' => ['ploegentoeslag', 'nachttoeslag', 'ort'], 'startDate' => '2025-01-01'];
		$entries = [
			['timesheetId' => 'ts-5', 'startedAt' => '2026-05-12T22:00:00+02:00', 'endedAt' => '2026-05-13T06:00:00+02:00', 'breakMinutes' => 0, 'hours' => 8],
			['timesheetId' => 'ts-other', 'startedAt' => '2026-05-14T00:00:00+02:00', 'endedAt' => '2026-05-14T06:00:00+02:00', 'hours' => 6],
		];
		$fold = $this->service()->foldFor(contract: $contract, regularWageCents: 300000, hoursPay: ['timesheetIds' => ['ts-5'], 'hourlyRate' => 20.0], entries: $entries, nonWorkingDates: null, period: '2026-05');

		$this->assertSame(30000 + 4800, $fold['totalCents']);
		$this->assertSame(['ort'], $fold['unresolved']);
		$fields = $this->service()->payslipFields(fold: $fold);
		$this->assertSame(348.0, $fields['caoComponentsTotal']);
		$this->assertSame(['ploegentoeslag', 'nachttoeslag'], array_column($fields['caoComponentLines'], 'key'));
		$this->assertSame(48.0, $fields['caoComponentLines'][1]['amount']);
		$this->assertSame(['ort'], $fields['caoComponentsUnresolved']);
		$this->assertSame([], RegisterSchemaValidator::errors('Payslip', array_merge(['employeeId' => '00000000-0000-4000-8000-000000000000', 'period' => '2026-05', 'jurisdiction' => 'NL', 'currency' => 'EUR', 'grossPay' => 3348.0, 'nettoPay' => 2500.0], $fields)));
	}//end testANightWorkerIsPaidBothComponents()

	/**
	 * A contract naming nothing folds nothing; a part month pays a fixed
	 * amount pro rata; an override without a reason pays nothing and lists
	 * every component as unresolved.
	 *
	 * @return void
	 */
	public function testNothingNamedAPartMonthAndARefusedOverride(): void {
		$service = $this->service();
		$this->assertNull($service->foldFor(contract: ['cao' => 'cao-voorbeeld'], regularWageCents: 300000, hoursPay: null, entries: [], nonWorkingDates: null, period: '2026-05'));
		$this->assertSame([], $service->payslipFields(fold: null));

		$half = $service->foldFor(contract: ['cao' => 'cao-voorbeeld', 'caoComponents' => ['ploegentoeslag'], 'startDate' => '2026-05-17'], regularWageCents: 300000, hoursPay: null, entries: [], nonWorkingDates: null, period: '2026-05');
		$this->assertSame(30000, $half['totalCents'], 'A percentage of the period wage is not pro rated again.');

		$refused = $service->foldFor(contract: ['cao' => 'cao-voorbeeld', 'caoComponents' => ['ploegentoeslag'], 'caoComponentOverrides' => ['ploegentoeslag' => ['pct' => 12]]], regularWageCents: 300000, hoursPay: null, entries: [], nonWorkingDates: null, period: '2026-05');
		$this->assertSame(0, $refused['totalCents']);
		$this->assertSame(['ploegentoeslag'], $refused['unresolved']);
	}//end testNothingNamedAPartMonthAndARefusedOverride()

	/**
	 * A contract naming the premium of an agreement whose leaf is a
	 * placeholder, without an override: nothing is paid and the payslip lists
	 * the premium as not paid.
	 *
	 * @return void
	 */
	public function testAnUnconfirmedAgreementIsListedNotPaid(): void {
		$fold = $this->service()->foldFor(contract: ['cao' => 'cao-zorg-vvt', 'caoComponents' => ['ort']], regularWageCents: 300000, hoursPay: ['timesheetIds' => ['ts-5'], 'hourlyRate' => 20.0], entries: [['timesheetId' => 'ts-5', 'startedAt' => '2026-05-12T22:00:00+02:00', 'endedAt' => '2026-05-13T06:00:00+02:00', 'hours' => 8]], nonWorkingDates: null, period: '2026-05');

		$this->assertSame(0, $fold['totalCents']);
		$fields = $this->service()->payslipFields(fold: $fold);
		$this->assertSame([], $fields['caoComponentLines']);
		$this->assertSame(['ort'], $fields['caoComponentsUnresolved']);
	}//end testAnUnconfirmedAgreementIsListedNotPaid()

	/**
	 * The share of the month a contract covers.
	 *
	 * @return void
	 */
	public function testTheMonthFraction(): void {
		$this->assertSame(1.0, CaoComponentPayService::monthFraction(contract: ['startDate' => '2025-01-01'], period: '2026-05'));
		$this->assertEqualsWithDelta(15 / 31, CaoComponentPayService::monthFraction(contract: ['startDate' => '2026-05-17'], period: '2026-05'), 0.0001);
		$this->assertEqualsWithDelta(10 / 31, CaoComponentPayService::monthFraction(contract: ['endDate' => '2026-05-10'], period: '2026-05'), 0.0001);
		$this->assertSame(1.0, CaoComponentPayService::monthFraction(contract: [], period: 'not-a-period'));
	}//end testTheMonthFraction()

}//end class
