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
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Standards\RuleEngine;
use OCA\Humaniq\Support\RegisterSlugLookup;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\Humaniq\Support\KeepsReadableRows;
use OCA\Humaniq\Support\ResolvesObjectServiceByClass;

/**
 * On-demand Arbeidstijdenwet cross-check over one roster's RosterAssignments.
 *
 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C05
 */
class RosterCheckService {

	use KeepsReadableRows;
	use ResolvesObjectServiceByClass;

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
	 * @param RosterCrossChecks $crossChecks The competence (design D3) and leave (design D10) cross-checks.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly RosterCrossChecks $crossChecks = new RosterCrossChecks(),
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
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-005
	 */
	public function checkRoster(string $rosterId, array $context = []): array {
		$rosterId = trim($rosterId);
		if ($rosterId === '') {
			return $this->emptyReport();
		}

		$register = $this->registerSlug();
		if ($register === null) {
			return $this->unresolvedRegisterReport();
		}

		return $this->evaluateRosters(self::firstWithId($this->loadAll('Roster', $register), $rosterId), $context, $register);
	}//end checkRoster()

	/**
	 * The first row with the id, as a one-row list, or an empty list.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 * @param string                           $id   The id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function firstWithId(array $rows, string $id): array {
		foreach ($rows as $row) {
			if ((string)($row['id'] ?? $row['@self']['id'] ?? '') === $id) {
				return [$row];
			}
		}

		return [];

	}//end firstWithId()

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
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-005
	 */
	public function checkPeriod(string $period, ?string $administrationId = null, array $context = []): array {
		$period = trim($period);
		if ($period === '') {
			return $this->emptyReport();
		}

		$register = $this->registerSlug();
		if ($register === null) {
			return $this->unresolvedRegisterReport();
		}

		$rosters = array_values(
			array_filter(
				$this->loadAll('Roster', $register),
				static fn (array $roster): bool => (string)($roster['period'] ?? '') === $period && self::inAdministration($roster, $administrationId)
			)
		);

		return $this->evaluateRosters($rosters, $context, $register);
	}//end checkPeriod()

	/**
	 * Whether a roster belongs to the administration filter (no filter admits every roster).
	 *
	 * @param array<string, mixed> $roster           The Roster row.
	 * @param string|null          $administrationId The administration filter.
	 *
	 * @return bool
	 */
	private static function inAdministration(array $roster, ?string $administrationId): bool {
		return $administrationId === null || $administrationId === ''
			|| (string)($roster['administrationId'] ?? '') === $administrationId;

	}//end inAdministration()

	/**
	 * Load the RosterAssignments of the given rosters, fill any missing
	 * planned-clock fields in-memory from their Shift, build the local
	 * daily-rest sibling index, and run the RuleEngine over exactly that
	 * assignment set.
	 *
	 * @param array<int, array<string, mixed>> $rosters The resolved Roster row(s).
	 * @param array<string, mixed> $context Evaluation context (e.g. jurisdiction).
	 * @param string $register The slug this instance's humaniq register answers
	 *                         to, already resolved by the caller.
	 *
	 * @return array<string, mixed> {rostersChecked, assignmentsChecked, violations, mandatoryViolations, registerResolved}.
	 */
	private function evaluateRosters(array $rosters, array $context, string $register): array {
		if ($rosters === []) {
			return $this->emptyReport();
		}

		$assignments = $this->assignmentsOf(rosters: $rosters, register: $register);
		$shiftsById = self::rowsById($this->loadAll('Shift', $register));
		$projected = self::projected($assignments, $shiftsById);

		$context['rostering'] = self::rosteringIndex($projected);

		$report = ['rostersChecked' => count($rosters)] + self::workingTimeReport($projected, $context);

		// The competence cross-check runs in the SAME act as the working-time
		// rules (REQ-ROST-C02), so `occ humaniq:roster:check` and
		// POST /api/roster/check still answer the whole question in one call.
		// It keeps the never-throw posture: a competence list that cannot be
		// read costs its own findings and nothing else.
		$competenceFindings = $this->crossCheck(
			fn (): array => $this->crossChecks->competences->findings(
				assignments: $projected,
				shiftsById: $shiftsById,
				competences: $this->loadAll('EmployeeCompetence', $register)
			),
			'competence'
		);

		$report = $this->withFindings(report: $report, findings: $competenceFindings, counter: 'competenceFindings');

		return $this->withFindings(report: $report, findings: $this->leaveFindings(assignments: $projected, register: $register), counter: 'leaveFindings');
	}//end evaluateRosters()

	/**
	 * Every row with an id, keyed by that id (a later row wins).
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function rowsById(array $rows): array {
		$byId = [];
		foreach ($rows as $row) {
			$id = (string)($row['id'] ?? $row['@self']['id'] ?? '');
			if ($id !== '') {
				$byId[$id] = $row;
			}
		}

		return $byId;

	}//end rowsById()

	/**
	 * The local daily-rest sibling index of the projected assignments.
	 *
	 * @param array<int, array<string, mixed>> $projected The projected assignments.
	 *
	 * @return array<string, mixed>
	 */
	private static function rosteringIndex(array $projected): array {
		return [
			'plannedClockByEmployeeDate' => RosterAssignmentProjectionService::plannedClockIndex($projected),
		];

	}//end rosteringIndex()

	/**
	 * The assignments with any missing planned-clock fields filled from their Shift.
	 *
	 * @param array<int, array<string, mixed>>    $assignments The RosterAssignments.
	 * @param array<string, array<string, mixed>> $shiftsById  The Shifts by id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function projected(array $assignments, array $shiftsById): array {
		$projected = [];
		foreach ($assignments as $assignment) {
			$projected[] = RosterAssignmentProjectionService::withProjection($assignment, $shiftsById);
		}

		return $projected;

	}//end projected()

	/**
	 * The report counts and the working-time violations of the projected assignments.
	 *
	 * @param array<int, array<string, mixed>> $projected The projected assignments.
	 * @param array<string, mixed>             $context   Evaluation context, with the rostering index.
	 *
	 * @return array<string, mixed>
	 */
	private static function workingTimeReport(array $projected, array $context): array {
		$violations = self::workingTimeViolations($projected, $context);

		return [
			'assignmentsChecked' => count($projected),
			'violations' => $violations,
			'mandatoryViolations' => count(array_filter($violations, static fn (array $row): bool => $row['severity'] === 'mandatory')),
			'competenceFindings' => 0,
			'leaveFindings' => 0,
			'registerResolved' => true,
		];

	}//end workingTimeReport()

	/**
	 * One report row per working-time rule an assignment violates.
	 *
	 * @param array<int, array<string, mixed>> $projected The projected assignments.
	 * @param array<string, mixed>             $context   Evaluation context, with the rostering index.
	 *
	 * @return array<int, array<string, string>>
	 */
	private static function workingTimeViolations(array $projected, array $context): array {
		$rows = [];
		foreach ($projected as $assignment) {
			foreach (RuleEngine::evaluate('RosterAssignment', $assignment, $context) as $violation) {
				$rows[] = [
					'kind' => CompetenceCheckService::WORKING_TIME_KIND,
					'objectType' => 'RosterAssignment',
					'objectId' => (string)($assignment['id'] ?? $assignment['@self']['id'] ?? ''),
					'ruleId' => $violation->ruleId,
					'severity' => $violation->severity,
					'statement' => $violation->statement,
				];
			}
		}

		return $rows;

	}//end workingTimeViolations()

	/**
	 * Run one cross-check; a cross-check that throws costs its own findings and is logged.
	 *
	 * @param \Closure(): array<int, array<string, mixed>> $check The cross-check.
	 * @param string                                       $name  The cross-check's name, for the log line.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function crossCheck(\Closure $check, string $name): array {
		try {
			return $check();
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: the ' . $name . ' cross-check could not run: ' . $e->getMessage());
			return [];
		}

	}//end crossCheck()

	/**
	 * The leave findings of one roster: who is planned on a day of approved
	 * leave or absence (REQ-ROST-C05).
	 *
	 * @param string $rosterId The Roster id.
	 *
	 * @return array<int, array<string, mixed>> The leave findings.
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C05
	 */
	public function leaveFindingsOf(string $rosterId): array {
		$violations = (array)($this->checkRoster($rosterId)['violations'] ?? []);

		return array_values(array_filter($violations, static fn (array $finding): bool => (string)($finding['kind'] ?? '') === LeaveConflictCheckService::FINDING_KIND));
	}//end leaveFindingsOf()

	/**
	 * The assignments that belong to the given rosters.
	 *
	 * @param array<int, array<string, mixed>> $rosters  The rosters.
	 * @param string                           $register The resolved register slug.
	 *
	 * @return array<int, array<string, mixed>> The assignments.
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C02
	 */
	private function assignmentsOf(array $rosters, string $register): array {
		$rosterIds = array_map(static fn (): bool => true, self::rowsById($rosters));

		$assignments = [];
		foreach ($this->loadAll('RosterAssignment', $register) as $assignment) {
			$rosterId = (string)($assignment['rosterId'] ?? '');
			if ($rosterId !== '' && isset($rosterIds[$rosterId]) === true) {
				$assignments[] = $assignment;
			}
		}

		return $assignments;
	}//end assignmentsOf()

	/**
	 * Add cross-check findings to the report, counting them and their
	 * mandatory ones.
	 *
	 * @param array<string, mixed>             $report   The report so far.
	 * @param array<int, array<string, mixed>> $findings The findings.
	 * @param string                           $counter  The report key that counts them.
	 *
	 * @return array<string, mixed> The report.
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C05
	 */
	private function withFindings(array $report, array $findings, string $counter): array {
		foreach ($findings as $finding) {
			$report['violations'][] = $finding;
			++$report[$counter];
			if (($finding['severity'] ?? '') === 'mandatory') {
				$report['mandatoryViolations']++;
			}
		}

		return $report;
	}//end withFindings()

	/**
	 * The leave cross-check (REQ-ROST-C05), in the same act as the other two.
	 *
	 * Never-throw: leave that cannot be read costs its own findings and
	 * nothing else, like the competence cross-check.
	 *
	 * @param array<int, array<string, mixed>> $assignments The projected assignments.
	 * @param string $register The resolved register slug.
	 *
	 * @return array<int, array<string, mixed>> The leave findings.
	 *
	 * @spec openspec/specs/rostering/spec.md#REQ-ROST-C05
	 */
	private function leaveFindings(array $assignments, string $register): array {
		return $this->crossCheck(
			fn (): array => $this->crossChecks->leave->findings(
				assignments: $assignments,
				leaveRequests: $this->loadAll('LeaveRequest', $register),
				sickLeaveCases: $this->loadAll('SickLeaveCase', $register)
			),
			'leave'
		);
	}//end leaveFindings()

	/**
	 * The zero-result report shape: the register WAS read, and it held no
	 * matching roster.
	 *
	 * `registerResolved` is what tells this apart from
	 * {@see unresolvedRegisterReport()}, whose zeros mean nothing was read at
	 * all. Both used to be this one shape, and a caller could not tell "no
	 * roster matched" from "this instance has no humaniq register" — which is
	 * the defect ConductionNL/openregister#3579 describes: a panel of zeros
	 * that reads as a clean result.
	 *
	 * @return array<string, mixed>
	 */
	private function emptyReport(): array {
		return [
			'rostersChecked' => 0,
			'assignmentsChecked' => 0,
			'violations' => [],
			'mandatoryViolations' => 0,
			'competenceFindings' => 0,
			'leaveFindings' => 0,
			'registerResolved' => true,
		];

	}//end emptyReport()

	/**
	 * The report for an instance that carries no humaniq register.
	 *
	 * Nothing was read, so nothing can be said about compliance. This is NOT
	 * the same answer as "checked, and clean": it carries
	 * `registerResolved => false` and an `error` naming the cause, so a caller
	 * (and `occ humaniq:roster:check`) reports an unanswerable check rather
	 * than a passing one.
	 *
	 * @return array<string, mixed>
	 */
	private function unresolvedRegisterReport(): array {
		$message = 'Het humaniq-register is niet gevonden op deze instance, dus er is niets gecontroleerd. '
			. 'Voer de humaniq-reparatiestap uit of stel het register in bij de humaniq-instellingen.';

		$this->logger->error('RosterCheckService: ' . $message);

		return [
			'rostersChecked' => 0,
			'assignmentsChecked' => 0,
			'violations' => [],
			'mandatoryViolations' => 0,
			'competenceFindings' => 0,
			'leaveFindings' => 0,
			'registerResolved' => false,
			'error' => $message,
		];

	}//end unresolvedRegisterReport()

	/**
	 * Load all objects of a schema (capped), as plain arrays. Never throws —
	 * degrades to an empty list and logs a warning (the RuleAuditService
	 * idiom).
	 *
	 * @param string $schema The schema name.
	 * @param string $register The slug this instance's humaniq register answers
	 *                         to, already resolved by the caller — so an empty
	 *                         result here means an empty schema, never an
	 *                         unreachable register.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadAll(string $schema, string $register): array {
		try {
			$rows = $this->objectService()
				->setRegister($register)
				->setSchema($schema)
				->findAll(['limit' => self::LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->warning('RosterCheckService: could not load ' . $schema . ': ' . $e->getMessage());
			return [];
		}

		return $this->normaliseRows($rows);
	}//end loadAll()

	/**
	 * The slug this instance's humaniq register answers to, or null when absent.
	 *
	 * This used to end `return $register === '' ? 'hrmq' : $register;` under a
	 * note saying the `hrmq` fallback was frozen across the rename — while the
	 * `getValueString()` default beside it already said `humaniq`. So the frozen
	 * branch was reachable only for a config value stored as the empty string,
	 * and every ordinary instance took the canonical default instead. On an
	 * instance that has not yet run `MigrateRegisterSlug` the register is still
	 * `hrmq`, every load below matched nothing, and the check reported zero
	 * rosters and zero violations — indistinguishable from a compliant roster.
	 * See ConductionNL/openregister#3579.
	 *
	 * NOTE for the HTTP path: `RosterController::check()` still resolves its
	 * RBAC probe through `SettingsService::getRegisterSlug()`, which carries the
	 * same unresolved-canonical shape across 57 call sites and is tracked
	 * separately on that issue. On an unmigrated instance the endpoint
	 * therefore still answers 404 before reaching this service; `occ
	 * humaniq:roster:check` shows the resolved answer today.
	 *
	 * @return string|null The slug, or null when this instance carries no
	 *                     humaniq register under any of its known slugs.
	 */
	private function registerSlug(): ?string {
		return (new RegisterSlugLookup($this->container, $this->appConfig))->slugOrNull();
	}//end registerSlug()
}//end class
