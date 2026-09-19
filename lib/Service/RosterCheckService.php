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
	 * @param CompetenceCheckService $competences The competence cross-check (design D3).
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly CompetenceCheckService $competences = new CompetenceCheckService(),
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

		$rosters = [];
		foreach ($this->loadAll('Roster', $register) as $roster) {
			if ((string)($roster['id'] ?? $roster['@self']['id'] ?? '') === $rosterId) {
				$rosters[] = $roster;
				break;
			}
		}

		return $this->evaluateRosters($rosters, $context, $register);
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

		$rosters = [];
		foreach ($this->loadAll('Roster', $register) as $roster) {
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

		return $this->evaluateRosters($rosters, $context, $register);
	}//end checkPeriod()

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

		$rosterIds = [];
		foreach ($rosters as $roster) {
			$id = (string)($roster['id'] ?? $roster['@self']['id'] ?? '');
			if ($id !== '') {
				$rosterIds[$id] = true;
			}
		}

		$assignments = [];
		foreach ($this->loadAll('RosterAssignment', $register) as $assignment) {
			$rosterId = (string)($assignment['rosterId'] ?? '');
			if ($rosterId !== '' && isset($rosterIds[$rosterId]) === true) {
				$assignments[] = $assignment;
			}
		}

		$shiftsById = [];
		foreach ($this->loadAll('Shift', $register) as $shift) {
			$id = (string)($shift['id'] ?? $shift['@self']['id'] ?? '');
			if ($id !== '') {
				$shiftsById[$id] = $shift;
			}
		}

		$projected = [];
		foreach ($assignments as $assignment) {
			$projected[] = RosterAssignmentProjectionService::withProjection($assignment, $shiftsById);
		}

		$context['rostering'] = [
			'plannedClockByEmployeeDate' => RosterAssignmentProjectionService::plannedClockIndex($projected),
		];

		$report = [
			'rostersChecked' => count($rosters),
			'assignmentsChecked' => count($projected),
			'violations' => [],
			'mandatoryViolations' => 0,
			'competenceFindings' => 0,
			'registerResolved' => true,
		];

		foreach ($projected as $assignment) {
			foreach (RuleEngine::evaluate('RosterAssignment', $assignment, $context) as $violation) {
				$report['violations'][] = [
					'kind' => CompetenceCheckService::WORKING_TIME_KIND,
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

		// The competence cross-check runs in the SAME act as the working-time
		// rules (REQ-ROST-C02), so `occ humaniq:roster:check` and
		// POST /api/roster/check still answer the whole question in one call.
		// It keeps the never-throw posture: a competence list that cannot be
		// read costs its own findings and nothing else.
		try {
			$competenceFindings = $this->competences->findings(
				assignments: $projected,
				shiftsById: $shiftsById,
				competences: $this->loadAll('EmployeeCompetence', $register)
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'humaniq: the competence cross-check could not run: ' . $e->getMessage()
			);
			$competenceFindings = [];
		}

		foreach ($competenceFindings as $finding) {
			$report['violations'][] = $finding;
			++$report['competenceFindings'];
			if (($finding['severity'] ?? '') === 'mandatory') {
				$report['mandatoryViolations']++;
			}
		}

		return $report;
	}//end evaluateRosters()

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
