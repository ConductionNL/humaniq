<?php

/**
 * Obligations Service
 *
 * Backs the Dashboard's full-width Obligations `object-table` widget
 * (hrmq-dashboard-steering-indicators REQ-DSI-008/009): merges every
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
 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-008
 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-009
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use DateTimeImmutable;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Cross-schema obligations merge + best-effort rule badge.
 */
class ObligationsService {

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
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
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
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-008
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-009
	 */
	public function getObligations(string $administrationId): array {
		$rows = array_merge(
			$this->sickLeaveMilestoneRows($administrationId),
			$this->expiringContractRows($administrationId),
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
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
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
	 * @param string $administrationId The caller's active administration.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function expiringContractRows(string $administrationId): array {
		$rows = [];
		[$today, $windowEnd] = $this->expiryWindow(self::CONTRACT_EXPIRY_WINDOW_DAYS);
		foreach ($this->loadFiltered('EmploymentContract', $administrationId) as $contract) {
			if ((string)($contract['type'] ?? '') !== 'temporary') {
				continue;
			}

			$endDate = $this->parseDate($contract['endDate'] ?? null);
			if ($endDate === null || $endDate < $today || $endDate > $windowEnd) {
				continue;
			}

			$rows[] = [
				'type' => 'EmploymentContract',
				'employeeId' => (string)($contract['employeeId'] ?? ''),
				'subject' => (string)($contract['employeeId'] ?? ''),
				'dueDate' => $endDate->format('Y-m-d'),
				'route' => 'EmploymentContractDetail',
				'object' => $contract,
			];
		}

		return $rows;
	}//end expiringContractRows()

	/**
	 * `BhvCertificering` rows expiring within the unchanged bhv-organisatie
	 * 90-day window (REQ-DSI-008).
	 *
	 * @param string $administrationId The caller's active administration.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function expiringBhvRows(string $administrationId): array {
		$rows = [];
		[$today, $windowEnd] = $this->expiryWindow(self::BHV_EXPIRY_WINDOW_DAYS);
		foreach ($this->loadFiltered('BhvCertificering', $administrationId) as $certificate) {
			$validUntil = $this->parseDate($certificate['certificaatGeldigTot'] ?? null);
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
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
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
	 * Parse a stored date value.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return DateTimeImmutable|null Null when absent, blank, or unparseable.
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function parseDate(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception) {
			return null;
		}
	}//end parseDate()

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
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function loadFiltered(string $schema, string $administrationId): array {
		$rows = [];
		foreach ($this->loadAll($schema) as $row) {
			if ((string)($row['administrationId'] ?? '') === $administrationId) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end loadFiltered()

	/**
	 * Load all objects of a schema (capped), as plain arrays — the
	 * `RuleAuditService::loadAll()` precedent.
	 *
	 * @param string $schema The schema name.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
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

	/**
	 * Normalise a list of ObjectService rows (entities or arrays) to arrays.
	 *
	 * @param mixed $rows Raw rows.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function normaliseRows(mixed $rows): array {
		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$out[] = (array)$row->jsonSerialize();
			}
		}

		return $out;
	}//end normaliseRows()

	/**
	 * The OpenRegister ObjectService, once availability has been
	 * established (ADR-083 — the AdministrationService precedent).
	 *
	 * @return mixed The OpenRegister ObjectService.
	 *
	 * @throws \RuntimeException When OpenRegister is not installed.
	 *
	 * @spec openspec/changes/hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md
	 */
	private function objectService(): mixed {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'hrmq requires the OpenRegister app, which is not installed on this instance. '
				. 'Install and enable it, then reload.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

}//end class
