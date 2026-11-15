<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Settings
 *
 * @spec openspec/changes/payroll-special-regimes/tasks.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * payroll-special-regimes, task 1.1: the benefit entitlement, the office
 * fields on a political-office contract, and the payslip fields a benefit or
 * special regime fills.
 */
class SpecialRegimesDeclarationTest extends TestCase {

	/**
	 * A decoded file under lib/Settings.
	 *
	 * @param string $path The path below lib/Settings.
	 *
	 * @return array<string, mixed>
	 */
	private function json(string $path): array {
		$decoded = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/' . $path), true);
		$this->assertIsArray($decoded, $path);
		return $decoded;
	}

	/**
	 * The special-regimes fragment's schemas.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function schemas(): array {
		return $this->json('register.d/hr-special-regimes.json')['components']['schemas'];
	}

	public function testTheBenefitEntitlementCarriesTheDesignedFields(): void {
		$entitlement = $this->schemas()['BenefitEntitlement'];
		$properties = $entitlement['properties'];

		foreach (['employeeId', 'userId', 'kind', 'decisionReference', 'startDate', 'endDate', 'paymentMode', 'monthlyAmount',
			'referenceSalary', 'schedule', 'offsetIncomeMonthly', 'lumpSumAmount', 'lumpSumPeriod', 'status', 'administrationId'] as $field) {
			$this->assertArrayHasKey($field, $properties, $field);
			$this->assertNotEmpty($properties[$field]['title'] ?? '', $field . ' has a title');
		}

		$this->assertSame(['rvu', 'wachtgeld'], $properties['kind']['enum']);
		$this->assertSame(['periodic', 'lump-sum'], $properties['paymentMode']['enum']);
		$this->assertSame(['fromMonth', 'toMonth', 'percentage'], $properties['schedule']['items']['required']);
		$this->assertSame(1, preg_match('/' . $properties['lumpSumPeriod']['pattern'] . '/', '2026-04'));
		$this->assertSame(0, preg_match('/' . $properties['lumpSumPeriod']['pattern'] . '/', '2026-13'));
	}

	public function testActivatingIsGuardedAgainstTheBeneficiaryAndEndingIsFinal(): void {
		$lifecycle = $this->schemas()['BenefitEntitlement']['configuration']['x-openregister-lifecycle'];

		$this->assertSame(['field' => 'status', 'initial' => 'draft', 'terminal' => ['ended']], array_intersect_key($lifecycle, array_flip(['field', 'initial', 'terminal'])));
		$this->assertSame(['draft'], $lifecycle['transitions']['activate']['from']);
		$this->assertSame('active', $lifecycle['transitions']['activate']['to']);
		$this->assertSame('OCA\\Humaniq\\Lifecycle\\NoSelfApprovalGuard', $lifecycle['transitions']['activate']['requires']);
		$this->assertSame(['active'], $lifecycle['transitions']['end']['from']);
		$this->assertSame('ended', $lifecycle['transitions']['end']['to']);
		$this->assertTrue(class_exists('OCA\\Humaniq\\Lifecycle\\NoSelfApprovalGuard'));
	}

	public function testOnlyPayrollAndHrReadOthersEntitlements(): void {
		$authorization = $this->schemas()['BenefitEntitlement']['authorization'];

		$this->assertSame(['humaniq-hr'], $authorization['create']);
		$this->assertContains(['group' => 'authenticated', 'match' => ['userId' => '$userId']], $authorization['read']);
		$this->assertNotContains('authenticated', $authorization['read']);
	}

	public function testAPoliticalOfficeIsAContractTypeWithItsOfficeAndTerm(): void {
		$type = $this->json('register.d/hr-objects.json')['components']['schemas']['EmploymentContract']['properties']['type'];
		$office = $this->schemas()['EmploymentContract']['properties'];

		$this->assertContains('political-office', $type['enum']);
		$this->assertSame('Political office', $type['x-enum-labels']['political-office']);
		$this->assertSame(['burgemeester', 'wethouder', 'raadslid', 'commissielid', 'statenlid', 'gedeputeerde'], $office['officeRole']['enum']);
		$this->assertSame(array_keys($office['officeRole']['x-enum-labels']), $office['officeRole']['enum']);
		$this->assertSame('date', $office['termStart']['format']);
		$this->assertSame('date', $office['termEnd']['format']);
	}

	public function testAPayslipNamesItsBenefitAndRegime(): void {
		$payslip = $this->schemas()['Payslip']['properties'];

		$this->assertSame('BenefitEntitlement', $payslip['benefitEntitlementId']['$ref']);
		$this->assertSame('string', $payslip['benefitEntitlementId']['type'], 'a list type makes OpenRegister refuse the schema');
		$this->assertSame(['rvu', 'wachtgeld', 'politiek-ambt'], $payslip['specialRegime']['enum']);
		$this->assertArrayHasKey('rvuEindheffing', $payslip);
	}

	public function testTheRegisterCarriesTheEntitlement(): void {
		$schemas = $this->json('humaniq_register.json')['components']['registers']['humaniq']['schemas'];

		$this->assertContains('BenefitEntitlement', $schemas);
	}
}
