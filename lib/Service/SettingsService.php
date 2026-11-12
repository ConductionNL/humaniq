<?php

/**
 * Humaniq Settings Service
 *
 * Reads humaniq's app config: the register slug, whether OpenRegister is
 * available, and the per-feature settings. The register import itself lives in
 * RegisterImporter.
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
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;

/**
 * Reads humaniq's app config.
 */
class SettingsService {

	/**
	 * @param IAppConfig $appConfig The app config interface.
	 * @param IAppManager $appManager The app manager.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
	) {

	}//end __construct()

	/**
	 * Whether OpenRegister is installed and available. Delegates to
	 * OpenRegisterAvailability, which callers that need nothing else from
	 * this class inject directly.
	 *
	 * @return bool
	 */
	public function isOpenRegisterAvailable(): bool {
		return (new OpenRegisterAvailability($this->appManager))->isAvailable();
	}//end isOpenRegisterAvailable()

	/**
	 * The configured register slug, falling back to 'hrmq' when unset.
	 *
	 * The fallback moved to 'humaniq' with the register slug, and the two must
	 * agree. OpenRegister's ImportHandler::autoCreateRegisterIfApplication()
	 * resolves the register BY SLUG (from `x-openregister.app` in
	 * lib/Settings/humaniq_register.json), and its not-found branch CREATES an
	 * empty register rather than failing — which is why the earlier note here
	 * froze this value. What makes it safe to move is `MigrateRegisterSlug`,
	 * which renames the existing register ROW ahead of the import; the import
	 * then updates that row instead of forking a second, empty one.
	 *
	 * That step also re-points the STORED `register` config value when it still
	 * says 'hrmq'. Without that, MigrateAppConfigKeys would have copied the old
	 * app id's value across verbatim and every reader here would go on asking
	 * for a register nothing answers to — silently, because this method has a
	 * default and a default turns missing data into wrong behaviour.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/app-identity/spec.md
	 */
	public function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'humaniq');
		if ($slug === '') {
			return 'humaniq';
		}

		return $slug;
	}//end getRegisterSlug()

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
		$value = $this->appConfig->getValueString(Application::APP_ID, $key, $default);
		return $value === '' ? $default : $value;
	}//end glPostAccount()

	/**
	 * The day of the wage-period month used as the shillinq PaymentRun
	 * `executionDate` (payroll-sepa-netpay-shillinq design.md D5 — the
	 * customary Dutch salary date), configurable via app config key
	 * `netpay_execution_day`, clamped by the caller to the period's last day.
	 *
	 * @return int
	 */
	public function getNetPayExecutionDay(): int {
		$value = $this->appConfig->getValueString(Application::APP_ID, 'netpay_execution_day', '25');
		$day = (int)$value;

		return $day > 0 ? $day : 25;
	}//end getNetPayExecutionDay()

	/**
	 * The debtor IBAN passed through to the created shillinq PaymentRun's
	 * `debtorAccountIban` (payroll-sepa-netpay-shillinq design.md D5),
	 * configurable via app config key `netpay_debtor_iban`. Empty/unset means
	 * the field is omitted (nullable — the bookkeeper completes it in shillinq
	 * before approval).
	 *
	 * @return string
	 */
	public function getNetPayDebtorIban(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'netpay_debtor_iban', '');
	}//end getNetPayDebtorIban()

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
		return trim($this->appConfig->getValueString(Application::APP_ID, 'wagetax_payee_id', ''));
	}//end getWageTaxPayeeId()

	/**
	 * The docudesk template UUID configured for one HR document type
	 * (humaniq-docudesk-documents design.md D3 -- config-first template
	 * selection), configurable via app config key
	 * `documents_template_{documentType}`. Empty default means the caller
	 * falls through to namespace/category discovery.
	 *
	 * @param string $documentType One of arbeidsovereenkomst/aanbiedingsbrief/werkgeversverklaring/getuigschrift.
	 *
	 * @return string The configured template UUID, or '' when unset.
	 *
	 * @spec openspec/changes/archive/2026-07-13-hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-003
	 */
	public function getDocumentsTemplateId(string $documentType): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'documents_template_' . $documentType, '');
	}//end getDocumentsTemplateId()

	/**
	 * The employer name merged into every docudesk render's `adHocData.employer`
	 * block (humaniq-docudesk-documents design.md D2), configurable via app config
	 * key `documents_employer_name`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-07-13-hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-002
	 */
	public function getDocumentsEmployerName(): string {
		return $this->documentsEmployerField('documents_employer_name', 'Voorbeeld Werkgever B.V.');
	}//end getDocumentsEmployerName()

	/**
	 * The employer address merged into every docudesk render's
	 * `adHocData.employer` block, configurable via app config key
	 * `documents_employer_address`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-07-13-hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-002
	 */
	public function getDocumentsEmployerAddress(): string {
		return $this->documentsEmployerField('documents_employer_address', 'Voorbeeldstraat 1, 1234 AB Amsterdam');
	}//end getDocumentsEmployerAddress()

	/**
	 * The employer KvK (chamber of commerce) number merged into every docudesk
	 * render's `adHocData.employer` block, configurable via app config key
	 * `documents_employer_kvk`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/archive/2026-07-13-hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-002
	 */
	public function getDocumentsEmployerKvkNumber(): string {
		return $this->documentsEmployerField('documents_employer_kvk', '12345678');
	}//end getDocumentsEmployerKvkNumber()

	/**
	 * The employer loonheffingennummer merged into every docudesk render's
	 * `adHocData.employer` block (payslip-pdf-docudesk design.md D3), and
	 * snapshotted onto a `Jaaropgaaf` at aggregation time, configurable via
	 * app config key `documents_employer_loonheffingennummer`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
	 */
	public function getDocumentsEmployerLoonheffingennummer(): string {
		return $this->documentsEmployerField('documents_employer_loonheffingennummer', '000000000L01');
	}//end getDocumentsEmployerLoonheffingennummer()

	/**
	 * The full employer block passed as `adHocData.employer` on every docudesk
	 * render call (humaniq-docudesk-documents design.md D2; `loonheffingennummer`
	 * added by payslip-pdf-docudesk design.md D3).
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/archive/2026-07-13-hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-002
	 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
	 */
	public function getDocumentsEmployerBlock(): array {
		return [
			'name' => $this->getDocumentsEmployerName(),
			'address' => $this->getDocumentsEmployerAddress(),
			'kvkNumber' => $this->getDocumentsEmployerKvkNumber(),
			'loonheffingennummer' => $this->getDocumentsEmployerLoonheffingennummer(),
		];

	}//end getDocumentsEmployerBlock()

	/**
	 * Read a `documents_employer_*` config key, falling back to its placeholder
	 * default when unset or blank.
	 *
	 * @param string $key The app config key.
	 * @param string $default The placeholder default value.
	 *
	 * @return string
	 */
	private function documentsEmployerField(string $key, string $default): string {
		$value = $this->appConfig->getValueString(Application::APP_ID, $key, $default);
		return $value === '' ? $default : $value;
	}//end documentsEmployerField()

	/**
	 * The offer-acceptance window, in days, added to "now" to compute the
	 * `deadline` passed into docudesk's `SigningService::createRequest()` for
	 * an offer-letter signing request (offer-esign design.md "Config delta").
	 * Distinct from docudesk's own generic `signing_request_expiry_days`
	 * (30-day default) — this is humaniq's own business rule for how long a
	 * candidate has to accept an offer. Configurable via app config key
	 * `offer_signing_deadline_days`.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/offer-esign/spec.md#REQ-OFFR-003
	 */
	public function getOfferSigningDeadlineDays(): int {
		$value = $this->appConfig->getValueString(Application::APP_ID, 'offer_signing_deadline_days', '14');
		$days = (int)$value;

		return $days > 0 ? $days : 14;
	}//end getOfferSigningDeadlineDays()

	/**
	 * The employer's Aof (arbeidsongeschiktheidsfonds) tariff classification
	 * (payroll-core-engine design.md D2 step 9): `laag` for small employers,
	 * `hoog` for other employers — a per-employer fact the tax-year tables
	 * cannot know. Configurable via app config key `payroll_aof_tariff`;
	 * anything other than `hoog` falls back to the honest default `laag`.
	 *
	 * @return string `laag` or `hoog`.
	 *
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-001
	 */
	public function getPayrollAofTariff(): string {
		$value = strtolower(trim($this->appConfig->getValueString(Application::APP_ID, 'payroll_aof_tariff', 'laag')));
		return $value === 'hoog' ? 'hoog' : 'laag';
	}//end getPayrollAofTariff()

	/**
	 * The employer's gedifferentieerde Whk (Werkhervattingskas) percentage
	 * (payroll-core-engine design.md D2 step 9) — employer-specific, set by
	 * the Belastingdienst Whk-beschikking. Configurable via app config key
	 * `payroll_whk_percentage`; unset/blank/non-numeric falls back to the
	 * tables' flagged national average passed in by the caller (the tables
	 * file marks its `whk` value `placeholder: true`).
	 *
	 * @param float $tablesDefault The tables' flagged national-average Whk percentage.
	 *
	 * @return float The Whk percentage (percentage scale, e.g. 1.52).
	 *
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-001
	 */
	public function getPayrollWhkPercentage(float $tablesDefault): float {
		$value = trim($this->appConfig->getValueString(Application::APP_ID, 'payroll_whk_percentage', ''));
		if ($value === '' || is_numeric($value) === false) {
			return $tablesDefault;
		}

		$percentage = (float)$value;
		return $percentage >= 0.0 ? $percentage : $tablesDefault;
	}//end getPayrollWhkPercentage()

	/**
	 * The CalDAV principal that owns the target shared calendar
	 * (leave-calendar-nc design.md D6), e.g. `principals/users/hr`,
	 * configurable via app config key `leave_calendar_principal`. Empty
	 * (the default) means the sync is not configured and every run ends
	 * `skipped-no-calendar`. The configured calendar SHOULD be one the
	 * owning account shares with the team — the sync is a one-way
	 * projection, so events manually edited on the calendar are overwritten
	 * by the next `occ humaniq:calendar:sync`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-001
	 */
	public function getLeaveCalendarPrincipal(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'leave_calendar_principal', '');
	}//end getLeaveCalendarPrincipal()

	/**
	 * The CalDAV calendar URI (on the principal above) the sync writes
	 * approved-leave/sickness events into (leave-calendar-nc design.md D6),
	 * as shown in the Calendar app's link/edit dialog, configurable via app
	 * config key `leave_calendar_uri`. Empty (the default) means the sync is
	 * not configured and every run ends `skipped-no-calendar`. One-way
	 * projection caveat: manual edits to events on this calendar are
	 * overwritten by the next sync.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/leave-calendar-nc/specs/leave-calendar-nc/spec.md#REQ-LC-001
	 */
	public function getLeaveCalendarUri(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'leave_calendar_uri', '');
	}//end getLeaveCalendarUri()

	/**
	 * The CalDAV principal that owns the target shared calendar interview
	 * events are projected onto (interview-scheduling design.md D6), e.g.
	 * `principals/users/recruiting`, configurable via app config key
	 * `interview_calendar_principal`. Empty (the default) means the sync is
	 * not configured and every run ends `skipped-no-calendar`. Deliberately
	 * a SEPARATE key from `leave_calendar_principal` — an org may want
	 * interviews on the recruiting team's own shared calendar, distinct from
	 * the company-wide absence calendar. The configured calendar SHOULD be
	 * one the owning account shares with the interview panel — the sync is
	 * a one-way projection, so events manually edited on the calendar are
	 * overwritten by the next `occ humaniq:interview:sync`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-002
	 */
	public function getInterviewCalendarPrincipal(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'interview_calendar_principal', '');
	}//end getInterviewCalendarPrincipal()

	/**
	 * The CalDAV calendar URI (on the principal above) the interview sync
	 * writes VEVENTs into (interview-scheduling design.md D6), as shown in
	 * the Calendar app's link/edit dialog, configurable via app config key
	 * `interview_calendar_uri`. Empty (the default) means the sync is not
	 * configured and every run ends `skipped-no-calendar`. Deliberately a
	 * SEPARATE key from `leave_calendar_uri` (design.md D6). One-way
	 * projection caveat: manual edits to events on this calendar are
	 * overwritten by the next sync.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-002
	 */
	public function getInterviewCalendarUri(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'interview_calendar_uri', '');
	}//end getInterviewCalendarUri()

	/**
	 * Whether `LeaveAccrualJob` may run (leave-accrual-job design.md D6) — an
	 * operator off-switch, configurable via app config key
	 * `leave_accrual_enabled`. Defaults to `true`; the job returns immediately
	 * with zero writes when this is `false`.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-005
	 */
	public function isLeaveAccrualEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'leave_accrual_enabled', true);
	}//end isLeaveAccrualEnabled()

	/**
	 * Whether `RetentionExpiryJob` may release lapsed humaniq floor holds and
	 * mark expired records for OpenRegister's destruction list
	 * (compliance-retention-expiry D4), configurable via app config key
	 * `retention_expiry_enabled`. Defaults to `false`: deleting personnel
	 * data is irreversible, so an existing installation does nothing until an
	 * admin switches it on; while off the job only logs what it would do.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/personnel-retention-expiry/spec.md#REQ-RET-002
	 */
	public function isRetentionExpiryEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'retention_expiry_enabled', false);
	}//end isRetentionExpiryEnabled()

	/**
	 * The leave type codes the net FTE of a formation leaves out
	 * (people-formation-positions design.md D3), configurable via app config
	 * key `formation_net_fte_leave_types` as a comma-separated list. Defaults
	 * to parental leave and pregnancy and childbirth leave, the tender's
	 * categories; long-term sickness is the separate threshold below.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-003
	 */
	public function getFormationNetFteLeaveTypes(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, 'formation_net_fte_leave_types', 'parental,zwangerschap');

		return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $code): bool => $code !== ''));
	}//end getFormationNetFteLeaveTypes()

	/**
	 * The number of weeks after which a sickness case counts against the net
	 * FTE of a formation (people-formation-positions design.md D3),
	 * configurable via app config key `formation_long_term_sick_weeks`.
	 * Defaults to 6.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-003
	 */
	public function getFormationLongTermSickWeeks(): int {
		return max(0, $this->appConfig->getValueInt(Application::APP_ID, 'formation_long_term_sick_weeks', 6));
	}//end getFormationLongTermSickWeeks()

	/**
	 * department-figures: the fewest people a unit must hold in a period
	 * before its manager sees its figures, so a small team's absence or wage
	 * cost cannot be traced to one person. App config key
	 * `department_figures_minimum_members`, default 5; HR and accountants are
	 * not bound by it.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
	 */
	public function getDepartmentFiguresMinimumMembers(): int {
		return max(1, $this->appConfig->getValueInt(Application::APP_ID, 'department_figures_minimum_members', 5));
	}//end getDepartmentFiguresMinimumMembers()

	/**
	 * reporting-pay-transparency: the fewest women and the fewest men a group
	 * needs before the pay transparency report shows its figures. App config
	 * key `pay_transparency_minimum_group`, default 5.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-002
	 */
	public function getPayTransparencyMinimumGroup(): int {
		return max(1, $this->appConfig->getValueInt(Application::APP_ID, 'pay_transparency_minimum_group', 5));
	}//end getPayTransparencyMinimumGroup()

	/**
	 * The integriq source uuid a base register is called through
	 * (people-register-prefill D1): app config `prefill_rdw_source` or
	 * `prefill_brp_source`. Empty when not configured.
	 *
	 * @param string $register The register key: `rdw` or `brp`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
	 */
	public function getRegisterPrefillSource(string $register): string {
		return trim($this->appConfig->getValueString(Application::APP_ID, 'prefill_' . $register . '_source', ''));
	}//end getRegisterPrefillSource()

	/**
	 * The annual bovenwettelijk (above-statutory) leave hours LeaveAccrualJob
	 * accrues 1/12 of per month (leave-accrual-job design.md D3), configurable
	 * via app config key `leave_bovenwettelijk_annual_hours`. Defaults to `0`
	 * — statutory-only until an employer configures it; CAO-derived
	 * bovenwettelijk is a named fast-follow.
	 *
	 * @return float
	 *
	 * @spec openspec/specs/leave-accrual-job/spec.md#REQ-ACCR-003
	 */
	public function getLeaveBovenwettelijkAnnualHours(): float {
		$value = trim($this->appConfig->getValueString(Application::APP_ID, 'leave_bovenwettelijk_annual_hours', '0'));
		if ($value === '' || is_numeric($value) === false) {
			return 0.0;
		}

		$hours = (float)$value;
		return $hours >= 0.0 ? $hours : 0.0;
	}//end getLeaveBovenwettelijkAnnualHours()

}//end class
