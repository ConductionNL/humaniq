<?php

/**
 * Obligations Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Backs the Dashboard's full-width Obligations `object-table` widget
 * (humaniq-dashboard-steering-indicators REQ-DSI-008/009): merges every
 * due-and-not-done WVP milestone (`SickLeaveCase`), expiring temporary
 * contract (`EmploymentContract`), and expiring BHV certificate
 * (`BhvCertificering`) for the caller's administration into one list sorted
 * by nearest due date, each row carrying a best-effort mandatory
 * rule-violation badge.
 *
 * Split out of {@see AnalyticsService} — which backs the three TREND
 * widgets — because "merge three schemas into a sorted list with a rule
 * badge" and "bucket three other schemas into a time series" are two jobs,
 * and one class doing both tripped phpmd's class-complexity threshold. The
 * `AbsenceProgression`/`AssetDialectMapper` split precedent, applied here.
 *
 * THE REFUSAL THIS CLASS CARRIES
 * -------------------------------
 * It is NOT a second `RuleAuditService::audit()` (design.md D5): no
 * full-corpus walk, no cross-object context built. It calls
 * `RuleAuditService::mandatoryViolationIds()` — a thin, already-baselined-
 * for-phpmd-StaticAccess wrapper around `RuleEngine::evaluate()` — once per
 * already-loaded row, for exactly the three obligation schemas.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-008
 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-009
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;
use OCA\Humaniq\Kernel\Contracts\ObligationsServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\Humaniq\Support\KeepsReadableRows;
use OCA\Humaniq\Support\ResolvesObjectServiceWithReload;

/**
 * Cross-schema obligations merge + best-effort rule badge.
 */
class ObligationsService implements ObligationsServiceInterface {

	use KeepsReadableRows;
	use ResolvesObjectServiceWithReload;

	/**
	 * The WVP milestone Due -> Done field pairs a `SickLeaveCase`
	 * contributes to the Obligations list (REQ-DSI-008) when Due is set and
	 * Done is not.
	 *
	 * @var array<string, string>
	 */
	private const WVP_MILESTONE_FIELDS = [
		'probleemanalyseDue' => 'probleemanalyseDone',
		'planVanAanpakDue' => 'planVanAanpakDone',
		'uwv42WeekMeldingDue' => 'uwv42WeekMeldingDone',
		'eerstejaarsevaluatieDue' => 'eerstejaarsevaluatieDone',
	];

	/**
	 * `EmploymentContract` expiry window, days — hr-signals'
	 * `nl-signaal-contract-verloopt` `parameters.windowDays`
	 * (lib/Standards/rules/labour.json), unchanged by this endpoint
	 * (REQ-DSI-008).
	 *
	 * @var int
	 */
	private const CONTRACT_EXPIRY_WINDOW_DAYS = 60;

	/**
	 * `BhvCertificering` expiry window, days — bhv-organisatie's
	 * `nl-bhv-certificaat-verloopt` `parameters.windowDays`, unchanged by
	 * this endpoint (REQ-DSI-008).
	 *
	 * @var int
	 */
	private const BHV_EXPIRY_WINDOW_DAYS = 90;

	/**
	 * `nl-signaal-proeftijd-eindigt` `parameters.windowDays`.
	 */
	private const PROBATION_END_WINDOW_DAYS = 14;

	/**
	 * Max Obligations rows returned (REQ-DSI-008).
	 *
	 * @var int
	 */
	private const OBLIGATIONS_LIMIT = 10;

	/**
	 * Max objects loaded per schema — the `RuleAuditService::loadAll()` /
	 * `AdministrationService::loadAll()` `findAll(['limit' => N])`-then-
	 * filter-in-PHP convention.
	 *
	 * @var int
	 */
	private const LOAD_LIMIT = 10000;

	/**
	 * @param ContainerInterface $container DI container for the ObjectService resolve.
	 * @param SettingsService $settingsService The register-slug source.
	 * @param RuleAuditService $ruleAuditService Supplies the per-row mandatory-violation badge.
	 * @param LoggerInterface $logger Logger.
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly RuleAuditService $ruleAuditService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The Obligations list: every due-and-not-done WVP milestone, expiring
	 * temporary contract, and expiring BHV certificate for the caller's
	 * administration, merged into one list sorted by nearest `dueDate`,
	 * capped at OBLIGATIONS_LIMIT, each carrying a best-effort mandatory
	 * rule-violation badge (REQ-DSI-008/009).
	 *
	 * @param string $administrationId The caller's ALREADY-AUTHORIZED active administration (REQ-DSI-005) — never resolved here.
	 *
	 * @return array<int, array{type: string, employeeId: string, subject: string, dueDate: string, route: string, violations: array<int, string>}>
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-008
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-009
	 */
	public function getObligations(string $administrationId): array {
		$sickLeaveRows = $this->sickLeaveMilestoneRows($administrationId);
		$contracts = $this->loadFiltered('EmploymentContract', $administrationId);
		$rows = array_merge(
			$sickLeaveRows,
			$this->expiringContractRows($contracts),
			$this->probationEndingRows($contracts),
			$this->expiringBhvRows($administrationId)
		);

		usort($rows, static fn (array $left, array $right): int => ($left['dueDate'] <=> $right['dueDate']));
		$rows = array_slice($rows, 0, self::OBLIGATIONS_LIMIT);

		foreach ($rows as $index => $row) {
			$rows[$index]['violations'] = $this->ruleAuditService->mandatoryViolationIds($row['type'], $row['object']);
			unset($rows[$index]['object']);
		}

		return $rows;
	}//end getObligations()

	/**
	 * `SickLeaveCase` rows contributing a due-and-not-done WVP milestone
	 * (REQ-DSI-008).
	 *
	 * @param string $administrationId The caller's active administration.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function sickLeaveMilestoneRows(string $administrationId): array {
		$rows = [];
		foreach ($this->loadFiltered('SickLeaveCase', $administrationId) as $case) {
			foreach (self::WVP_MILESTONE_FIELDS as $dueField => $doneField) {
				$dueDate = trim((string)($case[$dueField] ?? ''));
				$doneDate = trim((string)($case[$doneField] ?? ''));
				if ($dueDate === '' || $doneDate !== '') {
					continue;
				}

				$rows[] = [
					'type' => 'SickLeaveCase',
					'employeeId' => (string)($case['employeeId'] ?? ''),
					'subject' => $dueField,
					'dueDate' => $dueDate,
					'route' => 'SickLeaveCaseDetail',
					'object' => $case,
				];
			}
		}

		return $rows;
	}//end sickLeaveMilestoneRows()

	/**
	 * `EmploymentContract` rows expiring within the unchanged hr-signals
	 * 60-day window (REQ-DSI-008).
	 *
	 * @param array<int, array<string, mixed>> $contracts The administration's contracts.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function expiringContractRows(array $contracts): array {
		$temporary = array_filter(
			$contracts,
			static fn (array $contract): bool => (string)($contract['type'] ?? '') === 'temporary'
		);

		return $this->contractDueRows(contracts: $temporary, dateField: 'endDate', windowDays: self::CONTRACT_EXPIRY_WINDOW_DAYS, subject: null);
	}//end expiringContractRows()

	/**
	 * `EmploymentContract` rows whose probation period ends within the
	 * hr-signals 14-day window, beside the expiring contracts (REQ-SIG-007).
	 *
	 * @param array<int, array<string, mixed>> $contracts The administration's contracts.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/hr-signals/spec.md#REQ-SIG-007
	 */
	private function probationEndingRows(array $contracts): array {
		return $this->contractDueRows(contracts: $contracts, dateField: 'proeftijdEndDate', windowDays: self::PROBATION_END_WINDOW_DAYS, subject: 'proeftijdEndDate');
	}//end probationEndingRows()

	/**
	 * `EmploymentContract` rows whose date field falls between today and the
	 * end of the window.
	 *
	 * @param array<int, array<string, mixed>> $contracts  The contracts to check.
	 * @param string                           $dateField  The contract date that falls due.
	 * @param int                              $windowDays The window in days.
	 * @param string|null                      $subject    The row subject; null names the employee.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DateValues is a static date-parsing helper.
	 *
	 * @spec openspec/specs/hr-signals/spec.md#REQ-SIG-007
	 */
	private function contractDueRows(array $contracts, string $dateField, int $windowDays, ?string $subject): array {
		$rows = [];
		[$today, $windowEnd] = $this->expiryWindow($windowDays);
		foreach ($contracts as $contract) {
			$dueDate = DateValues::parse($contract[$dateField] ?? null);
			if ($dueDate === null || $dueDate < $today || $dueDate > $windowEnd) {
				continue;
			}

			$rows[] = [
				'type' => 'EmploymentContract',
				'employeeId' => (string)($contract['employeeId'] ?? ''),
				'subject' => ($subject ?? (string)($contract['employeeId'] ?? '')),
				'dueDate' => $dueDate->format('Y-m-d'),
				'route' => 'EmploymentContractDetail',
				'object' => $contract,
			];
		}

		return $rows;
	}//end contractDueRows()

	/**
	 * `BhvCertificering` rows expiring within the unchanged bhv-organisatie
	 * 90-day window (REQ-DSI-008).
	 *
	 * @param string $administrationId The caller's active administration.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function expiringBhvRows(string $administrationId): array {
		$rows = [];
		[$today, $windowEnd] = $this->expiryWindow(self::BHV_EXPIRY_WINDOW_DAYS);
		foreach ($this->loadFiltered('BhvCertificering', $administrationId) as $certificate) {
			$validUntil = DateValues::parse($certificate['certificaatGeldigTot'] ?? null);
			if ($validUntil === null || $validUntil < $today || $validUntil > $windowEnd) {
				continue;
			}

			$rows[] = [
				'type' => 'BhvCertificering',
				'employeeId' => (string)($certificate['employeeId'] ?? ''),
				'subject' => (string)($certificate['employeeId'] ?? ''),
				'dueDate' => $validUntil->format('Y-m-d'),
				'route' => 'BhvCertificeringDetail',
				'object' => $certificate,
			];
		}

		return $rows;
	}//end expiringBhvRows()

	/**
	 * `[today, today+$days]`, both at midnight — the shared window bound
	 * `expiringContractRows()`/`expiringBhvRows()` clip against.
	 *
	 * @param int $days Window length in days.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function expiryWindow(int $days): array {
		$today = new DateTimeImmutable('today');
		$end = $today->modify(sprintf('+%d days', $days));

		// `modify()` returns false on an unparseable expression. $days is an
		// int from a caller-controlled constant so it cannot realistically
		// fail — but psalm is right that the signature promised something the
		// body could not guarantee, and a window silently collapsing to a
		// single day would narrow the obligations list without any error. Fall
		// back to today, which shows FEWER rows rather than wrong ones.
		if ($end === false) {
			return [$today, $today];
		}

		return [$today, $end];
	}//end expiryWindow()

	/**
	 * Load all objects of a schema, filtered to those denormalized-scoped
	 * to `$administrationId` — a row with a null/blank/mismatched
	 * `administrationId` is excluded, the same implicit page-scoping rule
	 * REQ-MULTI-004 already applies everywhere else.
	 *
	 * @param string $schema The schema name.
	 * @param string $administrationId The caller's active administration.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function loadFiltered(string $schema, string $administrationId): array {
		return AdministrationRows::scopedTo($this->loadAll($schema), $administrationId);
	}//end loadFiltered()

	/**
	 * Load all objects of a schema (capped), as plain arrays — the
	 * `RuleAuditService::loadAll()` precedent.
	 *
	 * @param string $schema The schema name.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function loadAll(string $schema): array {
		try {
			$rows = $this->objectService()
				->setRegister($this->settingsService->getRegisterSlug())
				->setSchema($schema)
				->findAll(['limit' => self::LOAD_LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->warning('ObligationsService: could not load ' . $schema . ': ' . $e->getMessage());
			return [];
		}

		return $this->normaliseRows($rows);
	}//end loadAll()
}//end class
