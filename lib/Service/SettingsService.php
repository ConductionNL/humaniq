<?php

/**
 * Hrmq Settings Service
 *
 * Loads the hrmq OpenRegister register from lib/Settings/hrmq_register.json,
 * deep-merging any modular schema fragments from lib/Settings/register.d/*.json
 * (ADR-037), and hands the merged configuration to OpenRegister's
 * ConfigurationService::importFromApp. The fragment-content signature is folded
 * into the version so OpenRegister re-imports whenever a fragment changes. This is
 * the only configuration hrmq owns — every HR/labour object lives in the register.
 *
 * @category Service
 * @package  OCA\Hrmq\Service
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

namespace OCA\Hrmq\Service;

use OCA\Hrmq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Imports the hrmq register into OpenRegister.
 */
class SettingsService {

	/**
	 * @param IAppConfig $appConfig The app config interface.
	 * @param IAppManager $appManager The app manager.
	 * @param ContainerInterface $container The DI container.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether OpenRegister is installed and available.
	 *
	 * @return bool
	 */
	public function isOpenRegisterAvailable(): bool {
		return $this->appManager->isInstalled('openregister');
	}//end isOpenRegisterAvailable()

	/**
	 * The configured register slug, falling back to 'hrmq' when unset.
	 *
	 * @return string
	 */
	public function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'hrmq');
		if ($slug === '') {
			return 'hrmq';
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
	 * The docudesk template UUID configured for one HR document type
	 * (hrmq-docudesk-documents design.md D3 -- config-first template
	 * selection), configurable via app config key
	 * `documents_template_{documentType}`. Empty default means the caller
	 * falls through to namespace/category discovery.
	 *
	 * @param string $documentType One of arbeidsovereenkomst/aanbiedingsbrief/werkgeversverklaring/getuigschrift.
	 *
	 * @return string The configured template UUID, or '' when unset.
	 *
	 * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-003
	 */
	public function getDocumentsTemplateId(string $documentType): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'documents_template_' . $documentType, '');
	}//end getDocumentsTemplateId()

	/**
	 * The employer name merged into every docudesk render's `adHocData.employer`
	 * block (hrmq-docudesk-documents design.md D2), configurable via app config
	 * key `documents_employer_name`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-002
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
	 * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-002
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
	 * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-002
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
	 * @spec openspec/changes/payslip-pdf-docudesk/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
	 */
	public function getDocumentsEmployerLoonheffingennummer(): string {
		return $this->documentsEmployerField('documents_employer_loonheffingennummer', '000000000L01');
	}//end getDocumentsEmployerLoonheffingennummer()

	/**
	 * The full employer block passed as `adHocData.employer` on every docudesk
	 * render call (hrmq-docudesk-documents design.md D2; `loonheffingennummer`
	 * added by payslip-pdf-docudesk design.md D3).
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/changes/hrmq-docudesk-documents/specs/hrmq-docudesk-documents/spec.md#REQ-HDD-002
	 * @spec openspec/changes/payslip-pdf-docudesk/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-002
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
	 * (30-day default) — this is hrmq's own business rule for how long a
	 * candidate has to accept an offer. Configurable via app config key
	 * `offer_signing_deadline_days`.
	 *
	 * @return int
	 *
	 * @spec openspec/changes/offer-esign/specs/offer-esign/spec.md#REQ-OFFR-003
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
	 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
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
	 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
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
	 * by the next `occ hrmq:calendar:sync`.
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
	 * overwritten by the next `occ hrmq:interview:sync`.
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
	 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-005
	 */
	public function isLeaveAccrualEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'leave_accrual_enabled', true);
	}//end isLeaveAccrualEnabled()

	/**
	 * The annual bovenwettelijk (above-statutory) leave hours LeaveAccrualJob
	 * accrues 1/12 of per month (leave-accrual-job design.md D3), configurable
	 * via app config key `leave_bovenwettelijk_annual_hours`. Defaults to `0`
	 * — statutory-only until an employer configures it; CAO-derived
	 * bovenwettelijk is a named fast-follow.
	 *
	 * @return float
	 *
	 * @spec openspec/changes/leave-accrual-job/specs/leave-accrual-job/spec.md#REQ-ACCR-003
	 */
	public function getLeaveBovenwettelijkAnnualHours(): float {
		$value = trim($this->appConfig->getValueString(Application::APP_ID, 'leave_bovenwettelijk_annual_hours', '0'));
		if ($value === '' || is_numeric($value) === false) {
			return 0.0;
		}

		$hours = (float)$value;
		return $hours >= 0.0 ? $hours : 0.0;
	}//end getLeaveBovenwettelijkAnnualHours()

	/**
	 * Import the register idempotently (skips when the version is unchanged).
	 *
	 * @return array<string, mixed> Result with success flag, message, and version.
	 */
	public function loadConfiguration(): array {
		return $this->runLoadConfiguration(force: false);
	}//end loadConfiguration()

	/**
	 * Force re-import of the register (bypasses the already-configured guard).
	 *
	 * @return array<string, mixed> Result with success flag, message, and version.
	 */
	public function loadConfigurationForced(): array {
		return $this->runLoadConfiguration(force: true);
	}//end loadConfigurationForced()

	/**
	 * Load and parse hrmq_register.json, deep-merging register.d/*.json fragments.
	 *
	 * @return array<string, mixed> Either ['data' => array, 'version' => string]
	 *                              or ['success' => false, 'message' => string].
	 */
	private function loadRegisterConfigData(): array {
		$configPath = __DIR__ . '/../Settings/hrmq_register.json';
		if (file_exists($configPath) === false) {
			$this->logger->error('Hrmq: hrmq_register.json not found at ' . $configPath);
			return [
				'success' => false,
				'message' => 'Configuration file hrmq_register.json not found.',
			];
		}

		$configContent = file_get_contents($configPath);
		if ($configContent === false) {
			$this->logger->error('Hrmq: failed to read hrmq_register.json');
			return [
				'success' => false,
				'message' => 'Failed to read configuration file.',
			];
		}

		$configData = json_decode($configContent, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			$this->logger->error('Hrmq: failed to parse hrmq_register.json: ' . json_last_error_msg());
			return [
				'success' => false,
				'message' => 'Failed to parse configuration file: ' . json_last_error_msg(),
			];
		}

		// ADR-037: merge modular register fragments from Settings/register.d/*.json.
		// Each domain CheckProvider drops its own schema fragment here instead of
		// editing the monolith, so concurrent builds touch disjoint files. OpenAPI
		// `components.schemas` is a keyed object, so disjoint fragments union by key.
		$fragmentDir = __DIR__ . '/../Settings/register.d';
		$fragmentSig = '';
		if (is_dir($fragmentDir) === true) {
			$fragmentFiles = (glob($fragmentDir . '/*.json') ?: []);
			sort($fragmentFiles);
			foreach ($fragmentFiles as $fragmentFile) {
				$fragmentContent = file_get_contents($fragmentFile);
				if ($fragmentContent === false) {
					continue;
				}

				$fragmentData = json_decode($fragmentContent, true);
				if (json_last_error() !== JSON_ERROR_NONE) {
					$this->logger->warning(
						'Hrmq: skipping malformed register fragment ' . basename($fragmentFile)
						. ': ' . json_last_error_msg()
					);
					continue;
				}

				$configData = self::deepMergeConfig(base: $configData, overlay: $fragmentData);
				$fragmentSig .= basename($fragmentFile) . ':' . md5($fragmentContent) . ';';
			}
		}//end if

		// Fold the fragment signature into the version so OpenRegister's
		// version-gated importFromApp re-imports whenever fragments change.
		$version = ($configData['info']['version'] ?? '0.0.0');
		if ($fragmentSig !== '') {
			$version .= '+frag.' . substr(md5($fragmentSig), 0, 8);
		}

		return [
			'data' => $configData,
			'version' => $version,
		];

	}//end loadRegisterConfigData()

	/**
	 * Deep-merge a register fragment onto the base config (ADR-037).
	 *
	 * Associative arrays (OpenAPI objects like `components.schemas`) are merged by
	 * key union (recursing on shared keys); list arrays are concatenated; scalars
	 * in the fragment overwrite the base. Disjoint fragments never collide.
	 *
	 * @param array<mixed> $base The accumulated config.
	 * @param array<mixed> $overlay The fragment to merge in.
	 *
	 * @return array<mixed> The merged config.
	 */
	private static function deepMergeConfig(array $base, array $overlay): array {
		foreach ($overlay as $key => $value) {
			$base[$key] = self::mergeConfigValue(($base[$key] ?? null), $value);
		}

		return $base;
	}//end deepMergeConfig()

	/**
	 * Merge a single overlay value onto its base counterpart (ADR-037).
	 *
	 * Extracted from {@see self::deepMergeConfig()} so the per-key decision
	 * reads as a sequence of guards rather than nested if/else branches. The
	 * behaviour is unchanged: only when BOTH sides are arrays does anything
	 * other than a plain overwrite happen. `is_array($baseValue)` subsumes the
	 * former `isset($base[$key])` test — a null base can never be an array.
	 *
	 * @param mixed $baseValue The current value in the base config, or null when absent.
	 * @param mixed $value The overlay value to merge in.
	 *
	 * @return mixed The merged value for this key.
	 */
	private static function mergeConfigValue(mixed $baseValue, mixed $value): mixed {
		if (is_array($value) === false || is_array($baseValue) === false) {
			return $value;
		}

		$baseIsList = ($baseValue === [] || array_keys($baseValue) === range(0, (count($baseValue) - 1)));
		$overlayIsList = ($value === [] || array_keys($value) === range(0, (count($value) - 1)));
		if ($baseIsList === true && $overlayIsList === true) {
			return array_merge($baseValue, $value);
		}

		return self::deepMergeConfig(base: $baseValue, overlay: $value);
	}//end mergeConfigValue()

	/**
	 * Internal implementation for loadConfiguration / loadConfigurationForced.
	 *
	 * @param bool $force Force re-import even if already configured.
	 *
	 * @return array<string, mixed>
	 */
	private function runLoadConfiguration(bool $force): array {
		if ($this->isOpenRegisterAvailable() === false) {
			$this->logger->warning('Hrmq: OpenRegister not available, skipping register initialization');
			return [
				'success' => false,
				'message' => 'OpenRegister is not installed or enabled.',
			];
		}

		try {
			$configLoad = $this->loadRegisterConfigData();
			if (isset($configLoad['success']) === true && $configLoad['success'] === false) {
				return $configLoad;
			}

			$configData = $configLoad['data'];
			$configVersion = $configLoad['version'];

			$configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
			$result = $configurationService->importFromApp(
				appId: Application::APP_ID,
				data: $configData,
				version: $configVersion,
				force: $force
			);

			if (empty($result) === false) {
				$errors = ($result['errors'] ?? []);
				$warnings = ($result['warnings'] ?? []);

				if (empty($errors) === false) {
					$this->logger->error('Hrmq: register configuration imported with errors', ['errors' => $errors]);
					return [
						'success' => false,
						'message' => 'Configuration import completed with errors.',
						'errors' => $errors,
						'warnings' => $warnings,
						'version' => ($result['version'] ?? 'unknown'),
					];
				}

				if (empty($warnings) === false) {
					$this->logger->warning('Hrmq: register configuration imported with warnings', ['warnings' => $warnings]);
				}

				$this->logger->info('Hrmq: register configuration imported successfully');
				return [
					'success' => true,
					'message' => 'Configuration imported successfully.',
					'warnings' => $warnings,
					'version' => ($result['version'] ?? 'unknown'),
				];
			}//end if

			// OR's importFromApp returns an all-empty result on the version-unchanged
			// idempotent-skip path (force=false, same version). That is success, not
			// failure — the register is already up-to-date.
			$this->logger->info('Hrmq: register configuration already up-to-date (version-unchanged skip)');
			return [
				'success' => true,
				'skipped' => true,
				'message' => 'Configuration already up-to-date (version-unchanged skip).',
			];
		} catch (\Throwable $e) {
			$this->logger->error('Hrmq: configuration import failed', ['exception' => $e->getMessage()]);
			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}//end try

	}//end runLoadConfiguration()

}//end class
