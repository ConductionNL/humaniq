<?php

/**
 * PensionPremiumServiceTest
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * payroll-engine-port-from-shillinq 2.2: the participation in force on the
 * last day of the period, the administration's scheme of its fund, and the
 * PensionBase base for the REQ-PPP-001 and REQ-PPP-002 scenarios.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/payroll-engine-port-from-shillinq/specs/payroll-pension-premium/spec.md#REQ-PPP-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Payroll\CalculationInput;
use OCA\Humaniq\Payroll\CalculationInputMapper;
use OCA\Humaniq\Payroll\CalculationLevies;
use OCA\Humaniq\Payroll\CalculationWage;
use OCA\Humaniq\Payroll\InsuranceTariffs;
use OCA\Humaniq\Payroll\PensionPremium;
use OCA\Humaniq\Payroll\WageTaxElection;
use OCA\Humaniq\Service\PensionPremiumService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use PHPUnit\Framework\TestCase;

/**
 * The pension base per employee for one run.
 */
class PensionPremiumServiceTest extends TestCase {

	/**
	 * The scheme of the spec: franchise 18,000, no cap below the salary, 7.5% and 15%.
	 *
	 * @var array<string, mixed>
	 */
	private const SCHEME = [
		'id' => 'scheme-pfab-2026', 'administrationId' => 'ADM-001', 'fund' => 'pfab', 'franchise' => 18000,
		'maxPensionableSalary' => 137800, 'employeePercentage' => 7.5, 'employerPercentage' => 15.0,
		'validFrom' => '2026-01-01', 'validUntil' => '2026-12-31',
	];

	/**
	 * A full-time contract (40 hours, the service's full-time week).
	 *
	 * @var array<string, mixed>
	 */
	private const FULL_TIME = ['hoursPerWeek' => 40.0];

	/**
	 * A service over a fixed set of rows per schema.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $rows The rows.
	 *
	 * @return PensionPremiumService
	 */
	private function service(array $rows): PensionPremiumService {
		$store = $this->getMockBuilder(HoursRegisterGateway::class)
			->disableOriginalConstructor()
			->onlyMethods(['loadAll', 'findFiltered'])
			->getMock();
		$store->method('loadAll')->willReturnCallback(static fn (string $schema): array => ($rows[$schema] ?? []));
		$store->method('findFiltered')->willReturnCallback(
			static fn (string $schema, array $filters): array => array_values(array_filter(($rows[$schema] ?? []), static fn (array $row): bool => array_intersect_assoc($filters, $row) === $filters))
		);

		return new PensionPremiumService(store: $store);
	}//end service()

	/**
	 * The premium for one employee in a period.
	 *
	 * @param array<int, array<string, mixed>> $participations The participations.
	 * @param string                           $period         The period.
	 * @param int                              $grossCents     The regular wage.
	 *
	 * @return PensionPremium|null
	 */
	private function premium(array $participations, string $period, int $grossCents): ?PensionPremium {
		$service = $this->service(['PensionParticipation' => $participations, 'PensionScheme' => [self::SCHEME, array_merge(self::SCHEME, ['administrationId' => 'ADM-002', 'employeePercentage' => 99.0])]]);

		return $service->premiumFor(inputs: $service->inputs(administrationId: 'ADM-001', period: $period), employeeId: 'emp-1', grossCents: $grossCents, contract: self::FULL_TIME);
	}//end premium()

	/**
	 * REQ-PPP-001: 4,000 a month less 1,500 of franchise is a base of 2,500.00 with the scheme's percentages.
	 *
	 * @return void
	 */
	public function testAFullTimeParticipantHasABaseOf2500(): void {
		$premium = $this->premium([['employeeId' => 'emp-1', 'fund' => 'pfab', 'startDate' => '2026-01-01', 'participationFactor' => 1]], '2026-04', 400000);

		$this->assertNotNull($premium);
		$this->assertSame(250000, $premium->pensionBaseCents);
		$this->assertSame(7.5, $premium->employeePct);
		$this->assertSame(15.0, $premium->employerPct, 'the scheme of the run\'s own administration');
	}//end testAFullTimeParticipantHasABaseOf2500()

	/**
	 * REQ-PPP-001: a salary below the franchise has a base of 0.
	 *
	 * @return void
	 */
	public function testASalaryBelowTheFranchiseHasNoBase(): void {
		$premium = $this->premium([['employeeId' => 'emp-1', 'fund' => 'pfab', 'startDate' => '2026-01-01']], '2026-04', 120000);

		$this->assertSame(0, $premium?->pensionBaseCents);
	}//end testASalaryBelowTheFranchiseHasNoBase()

	/**
	 * REQ-PPP-001: no participation, no premium.
	 *
	 * @return void
	 */
	public function testNoParticipationIsNoPremium(): void {
		$this->assertNull($this->premium([['employeeId' => 'emp-2', 'fund' => 'pfab', 'startDate' => '2026-01-01']], '2026-04', 400000));
	}//end testNoParticipationIsNoPremium()

	/**
	 * REQ-PPP-002: enrolled from 1 May, the May run computes the premium and the April run does not.
	 *
	 * @return void
	 */
	public function testAParticipationFromMayCountsInMayNotApril(): void {
		$participation = [['employeeId' => 'emp-1', 'fund' => 'pfab', 'startDate' => '2026-05-01']];

		$this->assertNull($this->premium($participation, '2026-04', 400000));
		$this->assertNotNull($this->premium($participation, '2026-05', 400000));
	}//end testAParticipationFromMayCountsInMayNotApril()

	/**
	 * A participation in a fund the administration has no scheme for adds nothing.
	 *
	 * @return void
	 */
	public function testAFundWithoutASchemeIsNoPremium(): void {
		$this->assertNull($this->premium([['employeeId' => 'emp-1', 'fund' => 'abp', 'startDate' => '2026-01-01']], '2026-04', 400000));
	}//end testAFundWithoutASchemeIsNoPremium()

	/**
	 * The participation factor scales the wage, and a part-timer's franchise follows the part-time factor.
	 *
	 * @return void
	 */
	public function testTheFactorScalesTheWageAndPartTimeTheFranchise(): void {
		$service = $this->service(['PensionParticipation' => [['employeeId' => 'emp-1', 'fund' => 'pfab', 'startDate' => '2026-01-01', 'participationFactor' => 0.5]], 'PensionScheme' => [self::SCHEME]]);
		$inputs  = $service->inputs(administrationId: 'ADM-001', period: '2026-04');

		// 4,000 x 0.5 = 2,000 less 1,500 = 500.
		$this->assertSame(50000, $service->premiumFor(inputs: $inputs, employeeId: 'emp-1', grossCents: 400000, contract: self::FULL_TIME)?->pensionBaseCents);
		// Half-time on 2,000: 2,000 x 0.5 = 1,000 less 1,500 x 0.5 = 250.
		$this->assertSame(25000, $service->premiumFor(inputs: $inputs, employeeId: 'emp-1', grossCents: 200000, contract: ['hoursPerWeek' => 20.0])?->pensionBaseCents);
	}//end testTheFactorScalesTheWageAndPartTimeTheFranchise()

	/**
	 * The pension part survives the input snapshot and reaches the pack only with a participation.
	 *
	 * @return void
	 */
	public function testThePensionPartRoundTripsAndMapsOnlyWhenPresent(): void {
		$wage   = new CalculationWage(grossMonthlySalaryCents: 400000, dateOfBirth: '1990-04-12', period: '2026-04', jurisdiction: 'NL');
		$levies = new CalculationLevies(wageTax: new WageTaxElection(taxTableColor: 'wit', loonheffingskortingToegepast: true, thirtyPercentRulingRate: 0.0), insurance: new InsuranceTariffs(awfTariff: 'low', aofTariff: 'laag', whkPercentage: 1.52, verzekeringsplichtig: true));
		$with   = new CalculationInput(wage: $wage, levies: $levies, pension: new PensionPremium(pensionBaseCents: 250000, employeePct: 7.5, employerPct: 15.0));
		$without = new CalculationInput(wage: $wage, levies: $levies);

		$again = CalculationInput::fromCanonicalJson($with->toCanonicalJson());
		$this->assertSame(250000, $again->pension->pensionBaseCents);
		$this->assertSame(15.0, $again->pension->employerPct);
		$this->assertStringNotContainsString('pension', $without->toCanonicalJson(), 'a snapshot without a participation keeps its old form');

		$mapper = new CalculationInputMapper();
		$this->assertSame(250000, $mapper->toPackInputs($with)['pensionBase']);
		$this->assertArrayNotHasKey('pensionBase', $mapper->toPackInputs($without));
	}//end testThePensionPartRoundTripsAndMapsOnlyWhenPresent()

	/**
	 * A negative base or percentage is refused.
	 *
	 * @return void
	 */
	public function testANegativePensionPartIsRefused(): void {
		$this->expectException(\InvalidArgumentException::class);
		new PensionPremium(pensionBaseCents: -1);
	}//end testANegativePensionPartIsRefused()
}//end class
