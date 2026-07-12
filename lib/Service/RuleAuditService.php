<?php

/**
 * Rule Audit Service
 *
 * Audits the HR/labour data in the register against the machine-checkable rule
 * corpus: it loads every object of each engine-supported type, runs the
 * RuleEngine over it, and aggregates a compliance report — coverage (how many
 * corpus rules are actually enforceable today), how many objects were checked,
 * how many are compliant, and the violations grouped by severity and by rule.
 *
 * This is the "does hrmq comply?" answer: it does not change data, it reports
 * the live compliance posture so gaps are visible and traceable back to the
 * standard/law each rule cites.
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
 * @spec openspec/changes/hrm-rule-audit/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use OCA\Hrmq\AppInfo\Application;
use OCA\Hrmq\Standards\RuleCatalogue;
use OCA\Hrmq\Standards\RuleEngine;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Read-only compliance auditor over the register's HR/labour objects.
 */
class RuleAuditService
{

    /**
     * Max objects loaded per type for the audit.
     *
     * @var int
     */
    private const LIMIT = 10000;


    /**
     * @param ContainerInterface $container DI container for lazy ObjectService resolution.
     * @param IAppConfig         $appConfig App config for the register slug.
     * @param LoggerInterface    $logger    Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * Run the audit and return the structured report.
     *
     * @param array<string, mixed> $context Evaluation context (e.g. jurisdiction).
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/time-attendance-mvp/specs/time-attendance/spec.md#REQ-TA-004
     */
    public function audit(array $context=[]): array
    {
        // Cross-type pre-pass (pension-filing-upa-mvp): a lightweight sibling index
        // so per-object predicates can see PayrollRun/PensionFiling relations without
        // each CheckProvider re-querying the register. The predicate contract already
        // carries $context; only this index is new.
        $context['related'] = $this->buildRelatedContext();

        // payroll-glpost-shillinq: a per-run active-PayrollGLPost-count index so
        // NlGlPostChecks::checks()['PayrollGLPost']['nl-glpost-idempotent-per-run']
        // stays a pure fn(array $o, array $context) instead of re-querying siblings.
        $context['glpost'] = $this->buildGlPostContext();

        // time-attendance-mvp: a per-employee date-indexed AttendanceRecord clock
        // index so NlAttendanceChecks::checks()['AttendanceRecord']
        // ['nl-atw-dagelijkse-rust'] can resolve the previous working day's
        // clockOut for the same employee without re-querying the register.
        $context['attendance'] = $this->buildAttendanceContext();

        $corpusTotal      = RuleCatalogue::count();
        $machineCheckable = count(RuleCatalogue::machineCheckable());
        $enforceable      = count(RuleEngine::checkedRuleIds());

        $report = [
            'catalogueVersion'      => RuleCatalogue::version(),
            'corpusTotal'           => $corpusTotal,
            'machineCheckable'      => $machineCheckable,
            'enforceableRules'      => $enforceable,
            'coveragePct'           => $machineCheckable > 0 ? round(($enforceable / $machineCheckable) * 100, 1) : 0.0,
            'types'                 => [],
            'objectsChecked'        => 0,
            'objectsCompliant'      => 0,
            'objectsWithViolations' => 0,
            'violationsBySeverity'  => ['mandatory' => 0, 'conditional' => 0, 'recommended' => 0],
            'topViolatedRules'      => [],
        ];

        $byRule = [];

        foreach (RuleEngine::supportedTypes() as $type) {
            $objects  = $this->loadAll($type);
            $typeStat = ['checked' => 0, 'compliant' => 0, 'withViolations' => 0, 'violations' => 0];

            foreach ($objects as $object) {
                $violations = RuleEngine::evaluate($type, $object, $context);
                $typeStat['checked']++;
                $report['objectsChecked']++;

                if (empty($violations) === true) {
                    $typeStat['compliant']++;
                    $report['objectsCompliant']++;
                    continue;
                }

                $typeStat['withViolations']++;
                $report['objectsWithViolations']++;
                foreach ($violations as $violation) {
                    $typeStat['violations']++;
                    $report['violationsBySeverity'][$violation->severity] = (($report['violationsBySeverity'][$violation->severity] ?? 0) + 1);
                    $byRule[$violation->ruleId] = (($byRule[$violation->ruleId] ?? 0) + 1);
                }
            }

            $report['types'][$type] = $typeStat;
        }//end foreach

        arsort($byRule);
        foreach (array_slice($byRule, 0, 15, true) as $ruleId => $count) {
            $report['topViolatedRules'][] = ['ruleId' => $ruleId, 'count' => $count];
        }

        return $report;

    }//end audit()


    /**
     * Build the cross-type sibling index consumed by the PayrollRun/PensionFiling
     * predicates (pension-filing-upa-mvp): a PayrollRun `{id, period, status}` index
     * keyed by id, plus the set of periods with an approved-or-later run, and the
     * set of periods that have at least one PensionFiling. Also builds the
     * Employee `{id, loonheffingenVerklaringOnFile, startDate}` index keyed by
     * id/slug and the EmploymentContract `{type, startDate, endDate}` index keyed
     * by employeeId (onboarding-wizard-mvp) consumed by NlOnboardingChecks, and an
     * OrgUnit `{id, parentUnitId, active}` index keyed by id (org-chart-basic)
     * consumed by NlOrgChecks for the assignment-consistency (active-unit lookup)
     * and unit-cycle (parent walk) predicates. Loads independently of the main
     * per-type loop (a small, side-effect-free reload) so the index is ready
     * before any object of either type is evaluated. Degrades gracefully to empty
     * sets when a schema does not exist yet in the register.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildRelatedContext(): array
    {
        $byId            = [];
        $approvedPeriods = [];
        foreach ($this->loadAll('PayrollRun') as $run) {
            $id     = (string) ($run['id'] ?? $run['@self']['id'] ?? '');
            $period = (string) ($run['period'] ?? '');
            $status = (string) ($run['status'] ?? '');

            if ($id !== '') {
                $byId[$id] = ['id' => $id, 'period' => $period, 'status' => $status];
            }

            if ($period !== '' && in_array($status, ['approved', 'posted', 'paid'], true) === true) {
                $approvedPeriods[$period] = true;
            }
        }

        $filedPeriods = [];
        foreach ($this->loadAll('PensionFiling') as $filing) {
            $period = (string) ($filing['period'] ?? '');
            if ($period !== '') {
                $filedPeriods[$period] = true;
            }
        }

        // onboarding-wizard-mvp: an Employee index (loonheffingenVerklaringOnFile +
        // startDate, keyed by id/slug — the Timesheet/Onboarding employeeId
        // reference mechanism) so NlOnboardingChecks::checks()['Onboarding']
        // ['nl-onboarding-loonheffingenverklaring'] can resolve the hire's Employee
        // without re-querying the register.
        $employeesById = [];
        foreach ($this->loadAll('Employee') as $employee) {
            $id = (string) ($employee['id'] ?? $employee['@self']['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $employeesById[$id] = [
                'id'                            => $id,
                'loonheffingenVerklaringOnFile' => (bool) ($employee['loonheffingenVerklaringOnFile'] ?? false),
                'startDate'                     => (string) ($employee['startDate'] ?? ''),
            ];
        }

        // onboarding-wizard-mvp: an EmploymentContract index keyed by employeeId
        // (the contract type/startDate/endDate that resolves for a given hire) so
        // nl-onboarding-proeftijd-bewaking can apply the BW 7:652 contract-type cap.
        // When more than one contract resolves for the same employeeId, the last
        // one loaded wins (MVP simplification — contracts are optional data today,
        // design.md D3); tightening to "most recent by startDate" is a check-only
        // change once contract history matters for this predicate.
        $contractsByEmployeeId = [];
        foreach ($this->loadAll('EmploymentContract') as $contract) {
            $employeeId = (string) ($contract['employeeId'] ?? '');
            if ($employeeId === '') {
                continue;
            }

            $contractsByEmployeeId[$employeeId] = [
                'type'      => (string) ($contract['type'] ?? ''),
                'startDate' => (string) ($contract['startDate'] ?? ''),
                'endDate'   => (string) ($contract['endDate'] ?? ''),
            ];
        }

        // org-chart-basic: an OrgUnit index (id, parentUnitId, active), keyed by
        // id, so NlOrgChecks::checks()['OrgAssignment']['nl-org-assignment-consistency']
        // can resolve an assignment's unit without re-querying the register, and
        // ['OrgUnit']['nl-org-unit-cycle'] can walk the parentUnitId chain through
        // the same index.
        $orgUnitsById = [];
        foreach ($this->loadAll('OrgUnit') as $unit) {
            $id = (string) ($unit['id'] ?? $unit['@self']['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $orgUnitsById[$id] = [
                'id'           => $id,
                'parentUnitId' => (string) ($unit['parentUnitId'] ?? ''),
                'active'       => (bool) ($unit['active'] ?? true),
            ];
        }

        return [
            'PayrollRun'         => [
                'byId'            => $byId,
                'approvedPeriods' => array_keys($approvedPeriods),
            ],
            'PensionFiling'      => [
                'filedPeriods' => array_keys($filedPeriods),
            ],
            'Employee'           => [
                'byId' => $employeesById,
            ],
            'EmploymentContract' => [
                'byEmployeeId' => $contractsByEmployeeId,
            ],
            'OrgUnit'            => [
                'byId' => $orgUnitsById,
            ],
        ];

    }//end buildRelatedContext()


    /**
     * Build the per-run active-PayrollGLPost-count index consumed by
     * NlGlPostChecks' `nl-glpost-idempotent-per-run` predicate
     * (payroll-glpost-shillinq): a `payrollRunId => count of pending/posted
     * PayrollGLPost records` map. Degrades gracefully to an empty map when the
     * PayrollGLPost schema does not exist yet in the register (e.g. before the
     * hr-glpost.json fragment has been imported).
     *
     * @return array<string, mixed>
     */
    private function buildGlPostContext(): array
    {
        $activeCountByRun = [];
        foreach ($this->loadAll('PayrollGLPost') as $glPost) {
            $status = (string) ($glPost['status'] ?? '');
            if (in_array($status, ['pending', 'posted'], true) === false) {
                continue;
            }

            $runId = (string) ($glPost['payrollRunId'] ?? '');
            if ($runId === '') {
                continue;
            }

            $activeCountByRun[$runId] = (($activeCountByRun[$runId] ?? 0) + 1);
        }

        return ['activeCountByRun' => $activeCountByRun];

    }//end buildGlPostContext()


    /**
     * Build the per-employee date-indexed clock index consumed by
     * NlAttendanceChecks' `nl-atw-dagelijkse-rust` predicate
     * (time-attendance-mvp, design D3): `clockByEmployeeDate` maps
     * `employeeId => [date => ['clockIn' => ..., 'clockOut' => ...]]` from
     * every `AttendanceRecord` in the register, loaded once per audit run —
     * the same pattern as `buildRelatedContext()`/`buildGlPostContext()`.
     * Degrades gracefully to an empty index when the AttendanceRecord schema
     * does not exist yet in the register.
     *
     * @return array<string, mixed>
     */
    private function buildAttendanceContext(): array
    {
        $clockByEmployeeDate = [];
        foreach ($this->loadAll('AttendanceRecord') as $record) {
            $employeeId = (string) ($record['employeeId'] ?? '');
            $date       = (string) ($record['date'] ?? '');
            if ($employeeId === '' || $date === '') {
                continue;
            }

            $clockByEmployeeDate[$employeeId][$date] = [
                'clockIn'  => ($record['clockIn'] ?? null),
                'clockOut' => ($record['clockOut'] ?? null),
            ];
        }

        return ['clockByEmployeeDate' => $clockByEmployeeDate];

    }//end buildAttendanceContext()


    /**
     * Load all objects of a schema (capped), as plain arrays.
     *
     * @param string $schema The schema name.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadAll(string $schema): array
    {
        try {
            $rows = $this->objectService()
                ->setRegister($this->register())
                ->setSchema($schema)
                ->findAll(['limit' => self::LIMIT]);
        } catch (\Throwable $e) {
            $this->logger->warning('RuleAuditService: could not load '.$schema.': '.$e->getMessage());
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
    private function normaliseRows(mixed $rows): array
    {
        $out = [];
        foreach ((is_array($rows) === true ? $rows : []) as $row) {
            if (is_array($row) === true) {
                $out[] = $row;
                continue;
            }

            if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
                $out[] = (array) $row->jsonSerialize();
            }
        }

        return $out;

    }//end normaliseRows()


    /**
     * @return mixed The OpenRegister ObjectService.
     */
    private function objectService(): mixed
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()


    /**
     * @return string The configured register slug.
     */
    private function register(): string
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'hrmq');
        return $register === '' ? 'hrmq' : $register;

    }//end register()


}//end class
