<?php

/**
 * SettingsService tests.
 *
 * The app settings fall back to their defaults when unset, blank or not a
 * usable value, and the few normalised ones (AOF tariff, WHK percentage,
 * minimum group size, extra leave hours) are clamped to what payroll can use.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Each setting answers its stored value, or its default when that is unusable.
 *
 * @covers \OCA\Humaniq\Service\SettingsService
 * @uses   \OCA\Humaniq\Service\OpenRegisterAvailability
 */
class SettingsServiceTest extends TestCase {

	/**
	 * The service over the given stored values.
	 *
	 * @param array<string, mixed> $stored Stored value per key.
	 * @param bool                 $openRegister Whether OpenRegister is installed and enabled.
	 *
	 * @return SettingsService
	 */
	private function settings(array $stored, bool $openRegister = true): SettingsService {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => array_key_exists($key, $stored) ? (string)$stored[$key] : $default
		);
		$config->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default = false): bool => array_key_exists($key, $stored) ? (bool)$stored[$key] : $default
		);
		$config->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => array_key_exists($key, $stored) ? (int)$stored[$key] : $default
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn($openRegister);
		$apps->method('isEnabledForUser')->willReturn($openRegister);
		$apps->method('isEnabledForAnyone')->willReturn($openRegister);

		return new SettingsService($config, $apps);
	}//end settings()

	/**
	 * The register slug is the stored one, or humaniq when unset or blank.
	 *
	 * @return void
	 */
	public function testTheRegisterSlug(): void {
		self::assertSame('hr-test', $this->settings(['register' => 'hr-test'])->getRegisterSlug());
		self::assertSame('humaniq', $this->settings([])->getRegisterSlug());
		self::assertSame('humaniq', $this->settings(['register' => ''])->getRegisterSlug());
	}//end testTheRegisterSlug()

	/**
	 * Each ledger account has its own default, used when unset or blank.
	 *
	 * @return void
	 */
	public function testTheLedgerAccounts(): void {
		$defaults = $this->settings([]);
		self::assertSame(
			['4001', '4002', '1701', '1702', '4010'],
			[
				$defaults->getGlPostAccountGross(),
				$defaults->getGlPostAccountEmployerCharges(),
				$defaults->getGlPostAccountWageTaxLiability(),
				$defaults->getGlPostAccountNetWagesLiability(),
				$defaults->getGlPostAccountReimbursements(),
			]
		);

		$stored = $this->settings(['glpost_account_gross' => '8000', 'glpost_account_reimbursements' => '']);
		self::assertSame('8000', $stored->getGlPostAccountGross());
		self::assertSame('4010', $stored->getGlPostAccountReimbursements());
	}//end testTheLedgerAccounts()

	/**
	 * The net-pay execution day and the signing deadline fall back on anything not positive.
	 *
	 * @return void
	 */
	public function testDaysFallBackWhenNotPositive(): void {
		self::assertSame(25, $this->settings([])->getNetPayExecutionDay());
		self::assertSame(20, $this->settings(['netpay_execution_day' => '20'])->getNetPayExecutionDay());
		self::assertSame(25, $this->settings(['netpay_execution_day' => '0'])->getNetPayExecutionDay());
		self::assertSame(25, $this->settings(['netpay_execution_day' => 'soon'])->getNetPayExecutionDay());

		self::assertSame(14, $this->settings([])->getOfferSigningDeadlineDays());
		self::assertSame(7, $this->settings(['offer_signing_deadline_days' => '7'])->getOfferSigningDeadlineDays());
		self::assertSame(14, $this->settings(['offer_signing_deadline_days' => '-3'])->getOfferSigningDeadlineDays());
	}//end testDaysFallBackWhenNotPositive()

	/**
	 * The debtor IBAN is as stored; the wage-tax payee id and prefill source are trimmed.
	 *
	 * @return void
	 */
	public function testIdentifiersAreReadAsStored(): void {
		$settings = $this->settings([
			'netpay_debtor_iban' => 'NL91ABNA0417164300',
			'wagetax_payee_id' => ' bd-1 ',
			'documents_template_contract' => 'tpl-9',
			'prefill_brp_source' => ' haalcentraal ',
		]);

		self::assertSame('NL91ABNA0417164300', $settings->getNetPayDebtorIban());
		self::assertSame('bd-1', $settings->getWageTaxPayeeId());
		self::assertSame('tpl-9', $settings->getDocumentsTemplateId('contract'));
		self::assertSame('', $settings->getDocumentsTemplateId('offer'));
		self::assertSame('haalcentraal', $settings->getRegisterPrefillSource('brp'));
		self::assertSame('', $this->settings([])->getNetPayDebtorIban());
	}//end testIdentifiersAreReadAsStored()

	/**
	 * The employer block on documents falls back to the example employer field by field.
	 *
	 * @return void
	 */
	public function testTheEmployerBlock(): void {
		self::assertSame(
			[
				'name' => 'Gemeente Voorbeeld',
				'address' => 'Voorbeeldstraat 1, 1234 AB Amsterdam',
				'kvkNumber' => '12345678',
				'loonheffingennummer' => '123456789L01',
			],
			$this->settings([
				'documents_employer_name' => 'Gemeente Voorbeeld',
				'documents_employer_address' => '',
				'documents_employer_loonheffingennummer' => '123456789L01',
			])->getDocumentsEmployerBlock()
		);
		self::assertSame('Voorbeeld Werkgever B.V.', $this->settings([])->getDocumentsEmployerName());
		self::assertSame('000000000L01', $this->settings([])->getDocumentsEmployerLoonheffingennummer());
	}//end testTheEmployerBlock()

	/**
	 * The AOF tariff is high only when set to it, in any case and spacing; anything else is low.
	 *
	 * @return void
	 */
	public function testTheAofTariffIsHighOnlyWhenSetSo(): void {
		self::assertSame('laag', $this->settings([])->getPayrollAofTariff());
		self::assertSame('hoog', $this->settings(['payroll_aof_tariff' => ' HOOG '])->getPayrollAofTariff());
		self::assertSame('laag', $this->settings(['payroll_aof_tariff' => 'midden'])->getPayrollAofTariff());
	}//end testTheAofTariffIsHighOnlyWhenSetSo()

	/**
	 * The WHK percentage is the stored number when it is one and not negative, else the tables' default.
	 *
	 * @return void
	 */
	public function testTheWhkPercentage(): void {
		self::assertSame(1.52, $this->settings([])->getPayrollWhkPercentage(1.52));
		self::assertSame(0.88, $this->settings(['payroll_whk_percentage' => ' 0.88 '])->getPayrollWhkPercentage(1.52));
		self::assertSame(0.0, $this->settings(['payroll_whk_percentage' => '0'])->getPayrollWhkPercentage(1.52));
		self::assertSame(1.52, $this->settings(['payroll_whk_percentage' => 'n.v.t.'])->getPayrollWhkPercentage(1.52));
		self::assertSame(1.52, $this->settings(['payroll_whk_percentage' => '-1'])->getPayrollWhkPercentage(1.52));
	}//end testTheWhkPercentage()

	/**
	 * Extra statutory leave hours are a non-negative number, zero when unset or unusable.
	 *
	 * @return void
	 */
	public function testTheExtraLeaveHours(): void {
		self::assertSame(0.0, $this->settings([])->getLeaveBovenwettelijkAnnualHours());
		self::assertSame(38.5, $this->settings(['leave_bovenwettelijk_annual_hours' => '38.5'])->getLeaveBovenwettelijkAnnualHours());
		self::assertSame(0.0, $this->settings(['leave_bovenwettelijk_annual_hours' => 'veel'])->getLeaveBovenwettelijkAnnualHours());
		self::assertSame(0.0, $this->settings(['leave_bovenwettelijk_annual_hours' => '-8'])->getLeaveBovenwettelijkAnnualHours());
		self::assertSame(0.0, $this->settings(['leave_bovenwettelijk_annual_hours' => ''])->getLeaveBovenwettelijkAnnualHours());
	}//end testTheExtraLeaveHours()

	/**
	 * The switches have their defaults, and the minimum group size is at least one.
	 *
	 * @return void
	 */
	public function testSwitchesAndTheMinimumGroup(): void {
		self::assertTrue($this->settings([])->isLeaveAccrualEnabled());
		self::assertFalse($this->settings(['leave_accrual_enabled' => false])->isLeaveAccrualEnabled());
		self::assertFalse($this->settings([])->isRetentionExpiryEnabled());
		self::assertTrue($this->settings(['retention_expiry_enabled' => true])->isRetentionExpiryEnabled());

		self::assertSame(5, $this->settings([])->getPayTransparencyMinimumGroup());
		self::assertSame(10, $this->settings(['pay_transparency_minimum_group' => 10])->getPayTransparencyMinimumGroup());
		self::assertSame(1, $this->settings(['pay_transparency_minimum_group' => 0])->getPayTransparencyMinimumGroup());
	}//end testSwitchesAndTheMinimumGroup()

	/**
	 * Whether OpenRegister is available follows the app manager.
	 *
	 * @return void
	 */
	public function testOpenRegisterAvailability(): void {
		self::assertTrue($this->settings([], true)->isOpenRegisterAvailable());
		self::assertFalse($this->settings([], false)->isOpenRegisterAvailable());
	}//end testOpenRegisterAvailability()
}//end class
