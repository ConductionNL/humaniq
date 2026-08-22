<?php

/**
 * Roster Check Service
 *
 * The pre-publish (and standing) Arbeidstijdenwet verdict for a roster
 * (rostering MVP design D5, REQ-ROST-005): resolves a `Roster` and its
 * `RosterAssignment`s through OpenRegister's `ObjectService` (the
 * `RuleAuditService` container-resolve idiom) and runs the `RuleEngine`
 * over exactly that assignment set — regardless of publish status, so a
 * `concept` roster can be validated before publishing — returning
 * per-assignment violations and a mandatory/advisory summary.
 *
 * The sibling index the daily-rest predicate needs
 * (`rostering.plannedClockByEmployeeDate`) is built LOCALLY from exactly the
 * checked assignment set (unlike `RuleAuditService::buildRosterContext()`,
 * which only ever sees `gepubliceerd` rosters) so a concept roster's own
 * consecutive-day assignments can be cross-checked against each other before
 * anything is published.
 *
 * Any assignment missing its projected `plannedStart`/`plannedEnd`/
 * `plannedBreakMinutes` is filled in-memory via
 * `RosterAssignmentProjectionService` from its referenced `Shift` for the
 * PURPOSES OF THIS CHECK ONLY — an already-projected assignment is always
 * read raw and never recomputed, preserving "a published plan is stable
 * against a later Shift edit" (design D2). Never-throw degradation
 * (`loadAll()` swallows and logs).
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
 * @spec openspec/changes/rostering/specs/rostering/spec.md#REQ-ROST-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Standards\RuleEngine;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * On-demand Arbeidstijdenwet cross-check over one roster's RosterAssignments.
 */
class RosterCheckService {

	/**
	 * Max objects loaded per type for a check run.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Check one Roster (by id) against the ATW cross-check, regardless of
	 * its publish status.
	 *
	 * @param string $rosterId The Roster id.
	 * @param array<string, mixed> $context Evaluation context (e.g. jurisdiction).
	 *
	 * @return array<string, mixed> {rostersChecked, assignmentsChecked, violations: [{objectType, objectId, ruleId, severity, statement}], mandatoryViolations}.
	 *
	 * @spec openspec/changes/rostering/specs/rostering/spec.md#REQ-ROST-005
	 */
	public function checkRoster(string $rosterId, array $context = []): array {
		$rosterId = trim($rosterId);
		if ($rosterId === '') {
			return $this->emptyReport();
		}

		$rosters = [];
		foreach ($this->loadAll('Roster') as $roster) {
			if ((string)($roster['id'] ?? $roster['@self']['id'] ?? '') === $rosterId) {
				$rosters[] = $roster;
				break;
			}
		}

		return $this->evaluateRosters($rosters, $context);
	}//end checkRoster()

	/**
	 * Check every Roster of a planning period (optionally scoped to one
	 * administration) against the ATW cross-check, regardless of publish
	 * status.
	 *
	 * @param string $period Planning period (`YYYY-Www` or `YYYY-MM`).
	 * @param string|null $administrationId Only rosters of this administration, or null for all.
	 * @param array<string, mixed> $context Evaluation context (e.g. jurisdiction).
	 *
	 * @return array<string, mixed> {rostersChecked, assignmentsChecked, violations, mandatoryViolations}.
	 *
	 * @spec openspec/changes/rostering/specs/rostering/spec.md#REQ-ROST-005
	 */
	public function checkPeriod(string $period, ?string $administrationId = null, array $context = []): array {
		$period = trim($period);
		if ($period === '') {
			return $this->emptyReport();
		}

		$rosters = [];
		foreach ($this->loadAll('Roster') as $roster) {
			if ((string)($roster['period'] ?? '') !== $period) {
				continue;
			}

			if ($administrationId !== null && $administrationId !== ''
				&& (string)($roster['administrationId'] ?? '') !== $administrationId
			) {
				continue;
			}

			$rosters[] = $roster;
		}

		return $this->evaluateRosters($rosters, $context);
	}//end checkPeriod()

	/**
	 * Load the RosterAssignments of the given rosters, fill any missing
	 * planned-clock fields in-memory from their Shift, build the local
	 * daily-rest sibling index, and run the RuleEngine over exactly that
	 * assignment set.
	 *
	 * @param array<int, array<string, mixed>> $rosters The resolved Roster row(s).
	 * @param array<string, mixed> $context Evaluation context (e.g. jurisdiction).
	 *
	 * @return array<string, mixed> {rostersChecked, assignmentsChecked, violations, mandatoryViolations}.
	 */
	private function evaluateRosters(array $rosters, array $context): array {
		if ($rosters === []) {
			return $this->emptyReport();
		}

		$rosterIds = [];
		foreach ($rosters as $roster) {
			$id = (string)($roster['id'] ?? $roster['@self']['id'] ?? '');
			if ($id !== '') {
				$rosterIds[$id] = true;
			}
		}

		$assignments = [];
		foreach ($this->loadAll('RosterAssignment') as $assignment) {
			$rosterId = (string)($assignment['rosterId'] ?? '');
			if ($rosterId !== '' && isset($rosterIds[$rosterId]) === true) {
				$assignments[] = $assignment;
			}
		}

		$shiftsById = [];
		foreach ($this->loadAll('Shift') as $shift) {
			$id = (string)($shift['id'] ?? $shift['@self']['id'] ?? '');
			if ($id !== '') {
				$shiftsById[$id] = $shift;
			}
		}

		$projected = [];
		foreach ($assignments as $assignment) {
			$projected[] = $this->withProjection($assignment, $shiftsById);
		}

		$context['rostering'] = ['plannedClockByEmployeeDate' => $this->buildLocalIndex($projected)];

		$report = [
			'rostersChecked' => count($rosters),
			'assignmentsChecked' => count($projected),
			'violations' => [],
			'mandatoryViolations' => 0,
		];

		foreach ($projected as $assignment) {
			foreach (RuleEngine::evaluate('RosterAssignment', $assignment, $context) as $violation) {
				$report['violations'][] = [
					'objectType' => 'RosterAssignment',
					'objectId' => (string)($assignment['id'] ?? $assignment['@self']['id'] ?? ''),
					'ruleId' => $violation->ruleId,
					'severity' => $violation->severity,
					'statement' => $violation->statement,
				];

				if ($violation->severity === 'mandatory') {
					$report['mandatoryViolations']++;
				}
			}
		}

		return $report;
	}//end evaluateRosters()

	/**
	 * Fill an assignment's `plannedStart`/`plannedEnd`/`plannedBreakMinutes`
	 * from its referenced Shift ONLY when they are missing — an
	 * already-projected assignment is returned unchanged (design D2
	 * stability: never re-derive a value the write path already stored).
	 *
	 * @param array<string, mixed> $assignment The RosterAssignment.
	 * @param array<string, array<string, mixed>> $shiftsById Shift rows keyed by id.
	 *
	 * @return array<string, mixed>
	 */
	private function withProjection(array $assignment, array $shiftsById): array {
		$hasPlannedStart = (trim((string)($assignment['plannedStart'] ?? '')) !== '');
		$hasPlannedEnd = (trim((string)($assignment['plannedEnd'] ?? '')) !== '');
		if ($hasPlannedStart === true && $hasPlannedEnd === true) {
			return $assignment;
		}

		$shiftId = (string)($assignment['shiftId'] ?? '');
		$shift = ($shiftsById[$shiftId] ?? null);
		if (is_array($shift) === false) {
			return $assignment;
		}

		$date = (string)($assignment['date'] ?? '');
		if ($date === '') {
			return $assignment;
		}

		$planned = RosterAssignmentProjectionService::project($shift, $date);

		return array_merge(
			$assignment,
			[
				'plannedStart' => $assignment['plannedStart'] ?? $planned['plannedStart'],
				'plannedEnd' => $assignment['plannedEnd'] ?? $planned['plannedEnd'],
				'plannedBreakMinutes' => $assignment['plannedBreakMinutes'] ?? $planned['plannedBreakMinutes'],
			]
		);

	}//end withProjection()

	/**
	 * Build the `employeeId => [date => ['clockIn' => plannedStart, 'clockOut' => plannedEnd]]`
	 * sibling index from exactly the given (already-projected) assignment
	 * set — the `RuleAuditService::buildRosterContext()` shape, scoped to
	 * this check's own assignments only.
	 *
	 * @param array<int, array<string, mixed>> $assignments The (projected) RosterAssignment rows.
	 *
	 * @return array<string, mixed>
	 */
	private function buildLocalIndex(array $assignments): array {
		$index = [];
		foreach ($assignments as $assignment) {
			$employeeId = (string)($assignment['employeeId'] ?? '');
			$date = (string)($assignment['date'] ?? '');
			if ($employeeId === '' || $date === '') {
				continue;
			}

			$index[$employeeId][$date] = [
				'clockIn' => ($assignment['plannedStart'] ?? null),
				'clockOut' => ($assignment['plannedEnd'] ?? null),
			];
		}

		return $index;
	}//end buildLocalIndex()

	/**
	 * The zero-result report shape.
	 *
	 * @return array<string, mixed>
	 */
	private function emptyReport(): array {
		return [
			'rostersChecked' => 0,
			'assignmentsChecked' => 0,
			'violations' => [],
			'mandatoryViolations' => 0,
		];

	}//end emptyReport()

	/**
	 * Load all objects of a schema (capped), as plain arrays. Never throws —
	 * degrades to an empty list and logs a warning (the RuleAuditService
	 * idiom).
	 *
	 * @param string $schema The schema name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadAll(string $schema): array {
		try {
			$rows = $this->objectService()
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['limit' => self::LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->warning('RosterCheckService: could not load ' . $schema . ': ' . $e->getMessage());
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
	 * @return mixed The OpenRegister ObjectService.
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching. class_exists() rather
		// than SettingsService::isOpenRegisterAvailable(), because this class
		// does not inject SettingsService and adding a constructor dependency
		// purely to ask a yes/no question is the wrong trade. It answers the
		// same question the container would otherwise have answered fatally,
		// with a message that names the app the admin has to install.
		if (class_exists('OCA\OpenRegister\Service\ObjectService') === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * @return string The configured register slug.
	 *
	 * The 'hrmq' fallback is FROZEN across the Humaniq rename: OpenRegister's
	 * ImportHandler resolves the register BY SLUG. Renaming it would create a
	 * second, empty register and orphan every employee, contract, payslip and
	 * payroll run already stored under the 'hrmq' slug.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'hrmq');
		return $register === '' ? 'hrmq' : $register;
	}//end register()

}//end class
