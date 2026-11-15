<?php

/**
 * Humaniq Payroll Bookkeeping Settings
 *
 * Where the payroll's money is booked: the RGS-coded GL accounts the payroll
 * journal entry posts to, and the payee the wage tax remittance is paid to.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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

namespace OCA\Humaniq\Service;

use OCP\AppFramework\Services\IAppConfig;

/**
 * Reads the payroll bookkeeping settings from humaniq's app config.
 *
 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-003
 */
final class PayrollBookkeepingSettings {

	/**
	 * @param IAppConfig $appConfig humaniq's app config.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * The RGS-coded GL account for gross wages (payroll-glpost-shillinq D2 line 1:
	 * debit loonkosten bruto), configurable via app config key
	 * `glpost_account_gross`.
	 *
	 * @return string
	 */
	public function getGlPostAccountGross(): string {
		return $this->glPostAccount('glpost_account_gross', '4001');
	}//end getGlPostAccountGross()

	/**
	 * The RGS-coded GL account for employer social charges (payroll-glpost-shillinq
	 * D2 line 2: debit werkgeverslasten sociale premies), configurable via app
	 * config key `glpost_account_employer_charges`.
	 *
	 * @return string
	 */
	public function getGlPostAccountEmployerCharges(): string {
		return $this->glPostAccount('glpost_account_employer_charges', '4002');
	}//end getGlPostAccountEmployerCharges()

	/**
	 * The RGS-coded GL account for the wage-tax liability (payroll-glpost-shillinq
	 * D2 line 3: credit loonheffing-schuld), configurable via app config key
	 * `glpost_account_wage_tax_liability`.
	 *
	 * @return string
	 */
	public function getGlPostAccountWageTaxLiability(): string {
		return $this->glPostAccount('glpost_account_wage_tax_liability', '1701');
	}//end getGlPostAccountWageTaxLiability()

	/**
	 * The RGS-coded GL account for the net-wages liability (payroll-glpost-shillinq
	 * D2 line 4: credit netto-loonschuld, which absorbs the balancing remainder R),
	 * configurable via app config key `glpost_account_net_wages_liability`.
	 *
	 * @return string
	 */
	public function getGlPostAccountNetWagesLiability(): string {
		return $this->glPostAccount('glpost_account_net_wages_liability', '1702');
	}//end getGlPostAccountNetWagesLiability()

	/**
	 * The GL account for employee expense reimbursements paid through payroll
	 * (payroll-expenses-and-allowances D6: debit declaraties), configurable via
	 * app config key `glpost_account_reimbursements`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/payroll-expenses-and-allowances/spec.md#REQ-PEA-004
	 */
	public function getGlPostAccountReimbursements(): string {
		return $this->glPostAccount('glpost_account_reimbursements', '4010');
	}//end getGlPostAccountReimbursements()

	/**
	 * Read a `glpost_account_*` config key, falling back to its placeholder
	 * default when unset or blank.
	 *
	 * @param string $key The app config key.
	 * @param string $default The placeholder default account number.
	 *
	 * @return string
	 */
	private function glPostAccount(string $key, string $default): string {
		$value = $this->appConfig->getAppValueString($key, $default);
		return $value === '' ? $default : $value;
	}//end glPostAccount()

	/**
	 * The shillinq Payee (id or slug) that receives the wage tax remittance:
	 * the Belastingdienst (payroll-wage-tax-remittance-shillinq D4), app config
	 * key `wagetax_payee_id`. Empty means the hand-off fails closed.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/payroll-wage-tax-remittance-shillinq/specs/payroll-wage-tax-remittance-shillinq/spec.md#REQ-PWR-003
	 */
	public function getWageTaxPayeeId(): string {
		return trim($this->appConfig->getAppValueString('wagetax_payee_id', ''));
	}//end getWageTaxPayeeId()

}//end class
