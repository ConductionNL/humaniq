<?php

/**
 * Payroll bookkeeping settings test
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
 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\PayrollBookkeepingSettings;
use OCA\Humaniq\Tests\Unit\Support\AppConfigValues;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The GL accounts the payroll journal posts to and the wage tax payee.
 *
 * @covers \OCA\Humaniq\Service\PayrollBookkeepingSettings
 * @uses   \OCA\Humaniq\Tests\Unit\Support\AppConfigValues
 */
class PayrollBookkeepingSettingsTest extends TestCase {

	/**
	 * Unset or blank, each GL account is its placeholder default.
	 *
	 * @return void
	 */
	public function testTheGlAccountsDefault(): void {
		foreach ([[], array_fill_keys(['glpost_account_gross', 'glpost_account_employer_charges', 'glpost_account_wage_tax_liability', 'glpost_account_net_wages_liability', 'glpost_account_reimbursements'], '')] as $values) {
			self::assertSame(['4001', '4002', '1701', '1702', '4010'], $this->accounts($this->settings($values)));
		}
	}//end testTheGlAccountsDefault()

	/**
	 * Each GL account reads its own key, as stored.
	 *
	 * @return void
	 */
	public function testEachGlAccountReadsItsOwnKey(): void {
		$settings = $this->settings(
			[
				'glpost_account_gross' => 'a',
				'glpost_account_employer_charges' => 'b',
				'glpost_account_wage_tax_liability' => 'c',
				'glpost_account_net_wages_liability' => 'd',
				'glpost_account_reimbursements' => ' e ',
			]
		);

		self::assertSame(['a', 'b', 'c', 'd', ' e '], $this->accounts($settings));
	}//end testEachGlAccountReadsItsOwnKey()

	/**
	 * The wage tax payee is trimmed and empty when unset.
	 *
	 * @return void
	 */
	public function testTheWageTaxPayee(): void {
		self::assertSame('', $this->settings([])->getWageTaxPayeeId());
		self::assertSame('payee-1', $this->settings(['wagetax_payee_id' => ' payee-1 '])->getWageTaxPayeeId());
	}//end testTheWageTaxPayee()

	/**
	 * The five GL accounts in journal order.
	 *
	 * @param PayrollBookkeepingSettings $settings The settings.
	 *
	 * @return list<string>
	 */
	private function accounts(PayrollBookkeepingSettings $settings): array {
		return [
			$settings->getGlPostAccountGross(),
			$settings->getGlPostAccountEmployerCharges(),
			$settings->getGlPostAccountWageTaxLiability(),
			$settings->getGlPostAccountNetWagesLiability(),
			$settings->getGlPostAccountReimbursements(),
		];
	}//end accounts()

	/**
	 * The settings over an app config holding the given values.
	 *
	 * @param array<string, string> $values The stored app config values by key.
	 *
	 * @return PayrollBookkeepingSettings
	 */
	private function settings(array $values): PayrollBookkeepingSettings {
		$config = AppConfigValues::answer($this->createMock(IAppConfig::class), $values);

		return new PayrollBookkeepingSettings($config);
	}//end settings()

}//end class
