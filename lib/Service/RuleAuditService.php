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
use OCA\Hrmq\Standards\CaoRegistry;
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

        // payroll-sepa-netpay-shillinq: an employee-IBAN-presence index (keyed by
        // id, slug, AND employeeNumber -- the same three-way resolution
        // PayrollNetPayService uses) plus the set of periods with a payable
        // (approved/posted) PayrollRun, so NlNetPayChecks::checks()['Payslip']
        // ['nl-netpay-iban-present'] stays a pure fn(array $o, array $context)
        // instead of re-querying siblings.
        $context['netpay'] = $this->buildNetPayContext();

        // hrmq-docudesk-documents: a per-contract index of contracts with an
        // active generated arbeidsovereenkomst GeneratedDocument, so
        // NlDocumentChecks::checks()['EmploymentContract']
        // ['nl-contract-schriftelijk'] can resolve document evidence for a
        // permanent written contract without re-querying the register.
        $context['documents'] = $this->buildDocumentsContext();

        // hr-signals: a full-list (not last-wins) EmploymentContract index per
        // employeeId, so NlSignalChecks::checks()['EmploymentContract']
        // ['nl-signaal-contract-verloopt'] can decide whether a successor
        // contract exists for the same employee -- the existing
        // `related.EmploymentContract.byEmployeeId` index above deliberately
        // keeps only the last-loaded contract per employee and cannot answer
        // that question (design.md D4).
        $context['signals'] = $this->buildSignalsContext();

        // payroll-core-engine: a full PayrollRun-by-id index (engineVersion
        // included) so NlEngineChecks::checks()['Payslip']
        // ['nl-engine-output-consistency'] can resolve a payslip's producing
        // run (vacuous when the run is hand-entered/unresolvable) without
        // re-querying the register — the glpost context precedent. The
        // existing `related.PayrollRun.byId` index deliberately projects only
        // {id, period, status} and is left untouched (design.md D7).
        $context['payroll'] = $this->buildPayrollContext();

        // cao-library: an Employee-by-id index (for the pay-scale check's
        // grossMonthlySalary resolution) plus each employee's active-contract
        // CAO id (for the leave check), and the corpus keyed by id -- the
        // `payroll.runsById` precedent. NlCaoChecks reads `cao.employeesById`
        // and `cao.caoByEmployeeId` cross-object without re-querying siblings.
        $context['cao'] = $this->buildCaoContext();

        // retro-adjustments: the original-Payslip-by-id / Employee-by-id /
        // EmploymentContract-by-employeeId indexes plus the two employer
        // config values (aofTariff, an optional whk override) so
        // NlRetroChecks::checks()['PayrollAdjustment']
        // ['nl-retro-adjustment-consistency'] can independently recompute a
        // correction's delta (the `payroll.runsById` precedent) without
        // re-querying siblings or gaining a new service dependency.
        $context['retro'] = $this->buildRetroContext();
        // rostering: a per-employee date-indexed PLANNED clock index, built
        // ONLY from gepubliceerd-roster RosterAssignments (design D4's scope
        // discipline — a concept roster is work-in-progress and must not
        // raise mandatory violations app-wide; it is checked on demand via
        // RosterCheckService instead), so NlRosterChecks::checks()
        // ['RosterAssignment']['nl-atw-dagelijkse-rust'] can resolve the
        // previous planned working day's plannedEnd for the same employee —
        // the buildAttendanceContext() precedent, mirrored onto planned data.
        $context['rostering'] = $this->buildRosterContext();

        // comp-cycles: a SalaryBand-by-id index so CompChecks::checks()
        // ['CompAdjustment']['comp-adjustment-within-band'] can resolve the
        // targeted band's [minSalary, maxSalary] without re-querying the
        // register — the `cao.employeesById` / `payroll.runsById` precedent.
        $context['comp'] = $this->buildCompContext();

        // functiehuis-hr21: a Normfunctie-by-id index (caoSchaal +
        // caoSchaalVerified) so NlHr21Checks::checks()['EmploymentContract']
        // ['nl-hr21-schaal-consistentie'] can resolve a contract's assigned
        // normfunctie's mapped schaal without re-querying the register — the
        // `buildCompContext()` salaryBandsById precedent, mirrored onto
        // Normfunctie.
        $context['hr21'] = $this->buildHr21Context();

        // wkr-administration: a per-(administrationId, year) fiscale-loonsom
        // + vrije-ruimte-used aggregate so NlWkrChecks::checks()
        // ['WkrAssessment']['nl-wkr-eindheffing-exposure'] can recompute the
        // administration-level exposure without re-querying the register —
        // the `buildPayrollContext()`/`buildGlPostContext()` precedent.
        $context['wkr'] = $this->buildWkrContext();

        // fleet-bijtelling: a Vehicle-by-id + CarAssignment-by-id index so
        // NlFleetChecks::checks()['Payslip']['nl-bijtelling-auto-privegebruik']
        // can re-derive a payslip's recorded bijtelling from its referenced
        // CarAssignment/Vehicle without re-querying the register — the
        // `buildPayrollContext()` `runsById`/`loonbeslagenById` precedent.
        $context['fleet'] = $this->buildFleetContext();

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
     * Employee `{id, loonheffingenVerklaringOnFile, startDate, endDate}` index
     * keyed by id/slug (the `endDate` field added by offboarding-wizard-mvp for
     * nl-offboarding-einddatum-consistentie) and the EmploymentContract `{type,
     * startDate, endDate}` index keyed by employeeId (onboarding-wizard-mvp)
     * consumed by NlOnboardingChecks, an OrgUnit `{id, parentUnitId, active}`
     * index keyed by id (org-chart-basic) consumed by NlOrgChecks for the
     * assignment-consistency (active-unit lookup) and unit-cycle (parent walk)
     * predicates, and a LeaveBalance `{leaveType, year, entitledHours,
     * bovenwettelijkHours, usedHours}` list index keyed by employeeId
     * (offboarding-wizard-mvp) consumed by NlOffboardingChecks for the
     * verlofsaldo-uitbetaling predicate. Also builds an Asset `{id, status,
     * active}` index keyed by id (asset-management-mvp, the OrgUnit index
     * shape) consumed by NlAssetChecks for the assignment-consistency
     * predicate's asset-status lookup, and a defensive Offboarding
     * `plannedCompletionByEmployeeId` index (asset-management-mvp) mapping
     * each employeeId to the latest `lastWorkingDay` of its non-cancelled
     * (not `geannuleerd`) Offboarding cases, consumed by NlAssetChecks for the
     * inname-bij-offboarding predicate -- degrading to an empty map (vacuous
     * pass) when the Offboarding schema does not exist yet in the register
     * (the parallel offboarding-wizard-mvp change lands in either order).
     * mss-team-scope extends the Employee index with `nextcloudUserId`, the
     * OrgUnit index with `managerId`, and adds a new OrgAssignment
     * `byEmployeeId` index (`{orgUnitId, endDate}` lists), all consumed by
     * NlOrgChecks::checks()['Timesheet'/'Expense'/'LeaveRequest']
     * ['nl-mss-manager-consistency'] to resolve a record's employee's active
     * placement's unit manager without re-querying the register.
     * multi-administratie further extends the Employee index with
     * `administrationId` (the denormalized tenant key, REQ-MULTI-001),
     * consumed by NlAdministratieChecks::checks()
     * ['nl-administratie-scope-consistency'] to resolve the expected
     * administratie for every Employee-anchored schema this change scopes;
     * the Payslip variant of that same check reuses the existing
     * `payroll.runsById` index (buildPayrollContext()) instead, since a
     * Payslip's parent is its PayrollRun, not its Employee.
     * abp-aansluiting further extends this pre-pass with three additions
     * (design.md D3): the `PayrollRun.byId` entries gain `administrationId`;
     * a new `Administration.abpPlichtigByAdministrationId` map
     * (`administrationId` business key -> `abpAansluitingsplichtig` bool),
     * loaded from `loadAll('Administration')` once; and a new
     * `PensionFiling.abpFiledPeriodsByAdministrationId` map
     * (`administrationId` -> set of periods with at least one `fund: "abp"`
     * filing), kept separate from the existing, unchanged, fund-blind global
     * `filedPeriods` set -- consumed by
     * NlAbpChecks::checks()['PayrollRun']['nl-abp-fund-required'].
     * Loads independently of the main per-type loop (a small, side-effect-free
     * reload) so the index is ready before any object of either type is
     * evaluated. Degrades gracefully to empty sets when a schema does not
     * exist yet in the register.
     *
     * @return array<string, array<string, mixed>>
     *
     * @spec openspec/changes/offboarding-wizard-mvp/specs/offboarding-wizard/spec.md#REQ-OFB-004
     * @spec openspec/changes/asset-management-mvp/specs/asset-management/spec.md#REQ-AST-005
     * @spec openspec/changes/mss-team-scope/specs/mss-team-scope/spec.md#REQ-MSS-005
     * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-007
     * @spec openspec/changes/abp-aansluiting/specs/abp-aansluiting/spec.md#REQ-ABP-003
     */
    private function buildRelatedContext(): array
    {
        $byId            = [];
        $approvedPeriods = [];
        foreach ($this->loadAll('PayrollRun') as $run) {
            $id               = (string) ($run['id'] ?? $run['@self']['id'] ?? '');
            $period           = (string) ($run['period'] ?? '');
            $status           = (string) ($run['status'] ?? '');
            $administrationId = (string) ($run['administrationId'] ?? '');

            if ($id !== '') {
                $byId[$id] = [
                    'id'               => $id,
                    'period'           => $period,
                    'status'           => $status,
                    // abp-aansluiting (REQ-ABP-003): the run's own denormalized
                    // administratie key, consumed by NlAbpChecks to resolve the
                    // run's Administration and its own abpFiledPeriods entry.
                    'administrationId' => $administrationId,
                ];
            }

            if ($period !== '' && in_array($status, ['approved', 'posted', 'paid'], true) === true) {
                $approvedPeriods[$period] = true;
            }
        }

        $filedPeriods                    = [];
        $abpFiledPeriodsByAdministrationId = [];
        foreach ($this->loadAll('PensionFiling') as $filing) {
            $period = (string) ($filing['period'] ?? '');
            if ($period !== '') {
                $filedPeriods[$period] = true;
            }

            // abp-aansluiting (REQ-ABP-003): a SECOND, narrower index alongside
            // the global filedPeriods set above -- fund AND tenant scoped,
            // unlike that set. Only `fund: "abp"` filings with both a period
            // and an administrationId contribute.
            $administrationId = (string) ($filing['administrationId'] ?? '');
            if ($period !== '' && $administrationId !== '' && (string) ($filing['fund'] ?? '') === 'abp') {
                $abpFiledPeriodsByAdministrationId[$administrationId][$period] = true;
            }
        }

        // abp-aansluiting (REQ-ABP-003): an Administration index keyed on the
        // `administrationId` business key (not the object UUID -- the same
        // key every denormalized child field already uses), carrying only
        // the boolean NlAbpChecks needs to scope its predicate. Loaded once,
        // no per-object IO (the buildPayrollContext() precedent). Degrades to
        // an empty map when the Administration schema does not exist yet in
        // the register.
        $abpPlichtigByAdministrationId = [];
        foreach ($this->loadAll('Administration') as $administration) {
            $administrationId = (string) ($administration['administrationId'] ?? '');
            if ($administrationId === '') {
                continue;
            }

            $abpPlichtigByAdministrationId[$administrationId] = (bool) ($administration['abpAansluitingsplichtig'] ?? false);
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
                // offboarding-wizard-mvp: the case's endDate, consumed by
                // nl-offboarding-einddatum-consistentie (BW 7:667).
                'endDate'                       => (string) ($employee['endDate'] ?? ''),
                // mss-team-scope: the employee's own Nextcloud account id
                // (when this Employee IS a manager), consumed by
                // NlOrgChecks::checks()['Timesheet'/'Expense'/'LeaveRequest']
                // ['nl-mss-manager-consistency'] to resolve a manager
                // Employee's account for comparison against a record's
                // stamped managerUserId.
                'nextcloudUserId'               => (string) ($employee['nextcloudUserId'] ?? ''),
                // multi-administratie: the employee's own denormalized
                // administratie key, consumed by
                // NlAdministratieChecks::checks() (every Employee-anchored
                // schema this change denormalizes onto)
                // ['nl-administratie-scope-consistency'] to resolve the
                // parent employee's expected administrationId.
                'administrationId'              => (string) ($employee['administrationId'] ?? ''),
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
                // mss-team-scope: the unit's manager (Employee UUID),
                // consumed by NlOrgChecks::checks()['Timesheet'/'Expense'/
                // 'LeaveRequest']['nl-mss-manager-consistency'] to resolve
                // the manager for an active placement's unit.
                'managerId'    => (string) ($unit['managerId'] ?? ''),
            ];
        }

        // mss-team-scope: an OrgAssignment index keyed by employeeId (a list
        // of {orgUnitId, endDate} placements, start dates irrelevant to
        // activeness-at-audit — the predicate applies the endDate rule
        // itself) so NlOrgChecks::checks()['Timesheet'/'Expense'/
        // 'LeaveRequest']['nl-mss-manager-consistency'] can resolve a
        // record's employee's active placements without re-querying the
        // register.
        $orgAssignmentsByEmployeeId = [];
        foreach ($this->loadAll('OrgAssignment') as $assignment) {
            $employeeId = trim((string) ($assignment['employeeId'] ?? ''));
            if ($employeeId === '') {
                continue;
            }

            $orgAssignmentsByEmployeeId[$employeeId][] = [
                'orgUnitId' => (string) ($assignment['orgUnitId'] ?? ''),
                'endDate'   => (string) ($assignment['endDate'] ?? ''),
            ];
        }

        // offboarding-wizard-mvp: a LeaveBalance index keyed by employeeId (the
        // list of {leaveType, year, entitledHours, bovenwettelijkHours,
        // usedHours} rows) so nl-offboarding-verlofsaldo-uitbetaling can sum
        // each employee's open leave balance without re-querying the register.
        $leaveBalancesByEmployeeId = [];
        foreach ($this->loadAll('LeaveBalance') as $balance) {
            $employeeId = (string) ($balance['employeeId'] ?? '');
            if ($employeeId === '') {
                continue;
            }

            $leaveBalancesByEmployeeId[$employeeId][] = [
                'leaveType'           => (string) ($balance['leaveType'] ?? ''),
                'year'                => (int) ($balance['year'] ?? 0),
                'entitledHours'       => (float) ($balance['entitledHours'] ?? 0),
                'bovenwettelijkHours' => (float) ($balance['bovenwettelijkHours'] ?? 0),
                'usedHours'           => (float) ($balance['usedHours'] ?? 0),
            ];
        }

        // asset-management-mvp: an Asset index (id, status, active), keyed by
        // id, so NlAssetChecks::checks()['AssetAssignment']
        // ['nl-asset-assignment-consistency'] can resolve an open assignment's
        // asset status without re-querying the register.
        $assetsById = [];
        foreach ($this->loadAll('Asset') as $asset) {
            $id = (string) ($asset['id'] ?? $asset['@self']['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $assetsById[$id] = [
                'id'     => $id,
                'status' => (string) ($asset['status'] ?? ''),
                'active' => (bool) ($asset['active'] ?? true),
            ];
        }

        // asset-management-mvp (design.md D3): a defensive Offboarding
        // plannedCompletionByEmployeeId index -- the latest `lastWorkingDay`
        // among an employee's non-cancelled (status !== geannuleerd)
        // Offboarding cases, keyed by employeeId -- consumed by
        // NlAssetChecks::checks()['AssetAssignment']
        // ['nl-asset-inname-bij-offboarding']. Entries with a missing
        // employeeId/lastWorkingDay, or an unparseable date, are skipped
        // (skipping degrades to vacuous pass, never to a false violation).
        // Degrades to an empty map when the Offboarding schema does not exist
        // yet in the register -- the two changes land in either order.
        $plannedCompletionByEmployeeId = [];
        foreach ($this->loadAll('Offboarding') as $offboarding) {
            if ((string) ($offboarding['status'] ?? '') === 'geannuleerd') {
                continue;
            }

            $employeeId = trim((string) ($offboarding['employeeId'] ?? ''));
            $lastWorkingDay = trim((string) ($offboarding['lastWorkingDay'] ?? ''));
            if ($employeeId === '' || $lastWorkingDay === '') {
                continue;
            }

            $candidate = strtotime($lastWorkingDay);
            if ($candidate === false) {
                continue;
            }

            $current = ($plannedCompletionByEmployeeId[$employeeId] ?? null);
            if ($current === null || strtotime($current) === false || $candidate > strtotime($current)) {
                $plannedCompletionByEmployeeId[$employeeId] = $lastWorkingDay;
            }
        }

        return [
            'PayrollRun'         => [
                'byId'            => $byId,
                'approvedPeriods' => array_keys($approvedPeriods),
            ],
            'PensionFiling'      => [
                'filedPeriods'                      => array_keys($filedPeriods),
                // abp-aansluiting (REQ-ABP-003): administrationId -> [period => true].
                'abpFiledPeriodsByAdministrationId' => $abpFiledPeriodsByAdministrationId,
            ],
            'Administration'     => [
                // abp-aansluiting (REQ-ABP-003): administrationId -> abpAansluitingsplichtig bool.
                'abpPlichtigByAdministrationId' => $abpPlichtigByAdministrationId,
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
            'OrgAssignment'      => [
                'byEmployeeId' => $orgAssignmentsByEmployeeId,
            ],
            'LeaveBalance'       => [
                'byEmployeeId' => $leaveBalancesByEmployeeId,
            ],
            'Asset'              => [
                'byId' => $assetsById,
            ],
            'Offboarding'        => [
                'plannedCompletionByEmployeeId' => $plannedCompletionByEmployeeId,
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
     * Run the RuleEngine over EXACTLY one period's PayrollRun(s) + their
     * payslips — the run-scoped corpus audit behind `occ hrmq:payroll:verify`
     * (payroll-core-engine design.md D7): the same corpus that audits
     * hand-entered data audits a computed run, so the engine has no private
     * truth. The full cross-object context is built exactly as in `audit()`
     * (the sibling indexes are cheap and keep every cross-object predicate
     * working in the scoped run too).
     *
     * @param string               $period           Wage period (YYYY-MM).
     * @param string|null          $administrationId Only runs of this administration, or null for all.
     * @param array<string, mixed> $context          Evaluation context (e.g. jurisdiction).
     *
     * @return array<string, mixed> {runsChecked, payslipsChecked, violations: [{objectType, objectId, ruleId, severity, statement}], mandatoryViolations}.
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-006
     */
    public function auditPayrollRunScope(string $period, ?string $administrationId=null, array $context=[]): array
    {
        $context['related']    = $this->buildRelatedContext();
        $context['glpost']     = $this->buildGlPostContext();
        $context['attendance'] = $this->buildAttendanceContext();
        $context['netpay']     = $this->buildNetPayContext();
        $context['documents']  = $this->buildDocumentsContext();
        $context['signals']    = $this->buildSignalsContext();
        $context['payroll']    = $this->buildPayrollContext();
        $context['cao']        = $this->buildCaoContext();
        $context['retro']      = $this->buildRetroContext();
        $context['wkr']        = $this->buildWkrContext();
        $context['fleet']      = $this->buildFleetContext();

        $runs   = [];
        $runIds = [];
        foreach ($this->loadAll('PayrollRun') as $run) {
            if ((string) ($run['period'] ?? '') !== $period) {
                continue;
            }

            if ($administrationId !== null && $administrationId !== ''
                && (string) ($run['administrationId'] ?? '') !== $administrationId
            ) {
                continue;
            }

            $runs[] = $run;
            $id     = (string) ($run['id'] ?? $run['@self']['id'] ?? '');
            if ($id !== '') {
                $runIds[$id] = true;
            }
        }

        $payslips = [];
        foreach ($this->loadAll('Payslip') as $payslip) {
            $runId = (string) ($payslip['payrollRunId'] ?? '');
            if ($runId !== '' && isset($runIds[$runId]) === true) {
                $payslips[] = $payslip;
            }
        }

        $report = [
            'runsChecked'         => count($runs),
            'payslipsChecked'     => count($payslips),
            'violations'          => [],
            'mandatoryViolations' => 0,
        ];

        foreach ([['PayrollRun', $runs], ['Payslip', $payslips]] as [$type, $objects]) {
            foreach ($objects as $object) {
                foreach (RuleEngine::evaluate($type, $object, $context) as $violation) {
                    $report['violations'][] = [
                        'objectType' => $type,
                        'objectId'   => (string) ($object['id'] ?? $object['@self']['id'] ?? ''),
                        'ruleId'     => $violation->ruleId,
                        'severity'   => $violation->severity,
                        'statement'  => $violation->statement,
                    ];

                    if ($violation->severity === 'mandatory') {
                        $report['mandatoryViolations']++;
                    }
                }
            }
        }

        return $report;

    }//end auditPayrollRunScope()


    /**
     * Build the PayrollRun-by-id index consumed by NlEngineChecks'
     * `nl-engine-output-consistency` predicate (payroll-core-engine design.md
     * D7): `runsById` maps each PayrollRun id to the FULL run row (the
     * predicate reads `engineVersion` to scope itself to engine-produced
     * runs). Degrades gracefully to an empty map when the PayrollRun schema
     * does not exist yet in the register.
     *
     * loonbeslag (design.md D6): also builds `loonbeslagenById`, the FULL
     * Loonbeslag row keyed by id -- the SAME `runsById` shape, consumed by
     * `NlLoonbeslagChecks::checks()['Payslip']
     * ['nl-loonbeslag-beslagvrije-voet-floor']` (resolving a payslip's
     * `loonbeslagId` reference) and `['Loonbeslag']
     * ['nl-loonbeslag-single-active']` (scanning every OTHER Loonbeslag for
     * the same employee's overlapping effective range) without either
     * predicate re-querying the register. Degrades gracefully to an empty map
     * when the Loonbeslag schema does not exist yet in the register.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-007
     * @spec openspec/changes/loonbeslag/specs/loonbeslag/spec.md#REQ-BESLAG-007
     */
    private function buildPayrollContext(): array
    {
        $runsById = [];
        foreach ($this->loadAll('PayrollRun') as $run) {
            $id = (string) ($run['id'] ?? $run['@self']['id'] ?? '');
            if ($id !== '') {
                $runsById[$id] = $run;
            }
        }

        $loonbeslagenById = [];
        foreach ($this->loadAll('Loonbeslag') as $loonbeslag) {
            $id = (string) ($loonbeslag['id'] ?? $loonbeslag['@self']['id'] ?? '');
            if ($id !== '') {
                $loonbeslagenById[$id] = $loonbeslag;
            }
        }

        return [
            'runsById'         => $runsById,
            'loonbeslagenById' => $loonbeslagenById,
        ];

    }//end buildPayrollContext()


    /**
     * Build the Vehicle-by-id + CarAssignment-by-id indexes consumed by
     * NlFleetChecks' `nl-bijtelling-auto-privegebruik` predicate
     * (fleet-bijtelling design.md D5, the `buildPayrollContext()`
     * `runsById`/`loonbeslagenById` precedent): the FULL row keyed by id for
     * each, so the predicate can resolve a Payslip's `carAssignmentId` ->
     * CarAssignment -> `vehicleId` -> Vehicle chain without re-querying the
     * register. Degrades gracefully to empty maps when the Vehicle/
     * CarAssignment schemas do not exist yet in the register.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/fleet-bijtelling/specs/fleet-bijtelling/spec.md#REQ-FLEET-004
     */
    private function buildFleetContext(): array
    {
        $vehiclesById = [];
        foreach ($this->loadAll('Vehicle') as $vehicle) {
            $id = (string) ($vehicle['id'] ?? $vehicle['@self']['id'] ?? '');
            if ($id !== '') {
                $vehiclesById[$id] = $vehicle;
            }
        }

        $carAssignmentsById = [];
        foreach ($this->loadAll('CarAssignment') as $assignment) {
            $id = (string) ($assignment['id'] ?? $assignment['@self']['id'] ?? '');
            if ($id !== '') {
                $carAssignmentsById[$id] = $assignment;
            }
        }

        return [
            'vehiclesById'       => $vehiclesById,
            'carAssignmentsById' => $carAssignmentsById,
        ];

    }//end buildFleetContext()


    /**
     * Build the CAO cross-object context consumed by NlCaoChecks
     * (cao-library design.md D4, the `payroll.runsById` precedent):
     *
     * - `caosById`: the CAO corpus keyed by id (`CaoRegistry`), so the
     *   reference data is available to the predicates without re-reading the
     *   corpus per object.
     * - `employeesById`: each Employee's `{id, grossMonthlySalary}`, keyed by
     *   id, so `nl-cao-minimumloon-schaal` can resolve the owning employee's
     *   salary for an EmploymentContract without re-querying siblings.
     * - `caoByEmployeeId`: each employee's active-contract CAO id (last-loaded
     *   contract wins when more than one resolves -- the same MVP
     *   simplification as `related.EmploymentContract.byEmployeeId`), so
     *   `nl-cao-verlof-minimum` can resolve a LeaveBalance's employee's CAO.
     *
     * Degrades gracefully to empty maps when the Employee/EmploymentContract
     * schemas do not exist yet in the register.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-003
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-004
     */
    private function buildCaoContext(): array
    {
        $employeesById = [];
        foreach ($this->loadAll('Employee') as $employee) {
            $id = (string) ($employee['id'] ?? $employee['@self']['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $employeesById[$id] = [
                'id'                 => $id,
                'grossMonthlySalary' => ($employee['grossMonthlySalary'] ?? null),
            ];
        }

        $caoByEmployeeId = [];
        foreach ($this->loadAll('EmploymentContract') as $contract) {
            $employeeId = trim((string) ($contract['employeeId'] ?? ''));
            $caoId      = trim((string) ($contract['cao'] ?? ''));
            if ($employeeId === '' || $caoId === '') {
                continue;
            }

            $caoByEmployeeId[$employeeId] = $caoId;
        }

        return [
            'caosById'        => CaoRegistry::availableCaos(),
            'employeesById'   => $employeesById,
            'caoByEmployeeId' => $caoByEmployeeId,
        ];

    }//end buildCaoContext()


    /**
     * Build the SalaryBand-by-id index consumed by
     * `CompChecks::checks()['CompAdjustment']['comp-adjustment-within-band']`
     * (comp-cycles design.md D7) — the `buildCaoContext()` employeesById
     * precedent, mirrored onto SalaryBand.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
     */
    private function buildCompContext(): array
    {
        $salaryBandsById = [];
        foreach ($this->loadAll('SalaryBand') as $band) {
            $id = (string) ($band['id'] ?? $band['@self']['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $salaryBandsById[$id] = [
                'minSalary' => ($band['minSalary'] ?? null),
                'maxSalary' => ($band['maxSalary'] ?? null),
            ];
        }

        return [
            'salaryBandsById' => $salaryBandsById,
        ];

    }//end buildCompContext()


    /**
     * Build the Normfunctie-by-id index consumed by
     * `NlHr21Checks::checks()['EmploymentContract']['nl-hr21-schaal-consistentie']`
     * (functiehuis-hr21) — the `buildCompContext()` salaryBandsById precedent,
     * mirrored onto Normfunctie.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/functiehuis-hr21/specs/functiehuis-hr21/spec.md#REQ-HR21-003
     */
    private function buildHr21Context(): array
    {
        $normfunctiesById = [];
        foreach ($this->loadAll('Normfunctie') as $normfunctie) {
            $id = (string) ($normfunctie['id'] ?? $normfunctie['@self']['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $normfunctiesById[$id] = [
                'caoSchaal'         => ($normfunctie['caoSchaal'] ?? null),
                'caoSchaalVerified' => ($normfunctie['caoSchaalVerified'] ?? false),
            ];
        }

        return [
            'normfunctiesById' => $normfunctiesById,
        ];

    }//end buildHr21Context()


    /**
     * Build the per-(administrationId, year) fiscale-loonsom + vrije-ruimte-
     * used aggregate consumed by NlWkrChecks'
     * `nl-wkr-eindheffing-exposure` predicate (wkr-administration design.md
     * D3, the `buildPayrollContext()` precedent): for every Payslip, resolves
     * its effective administrationId (its own denormalized field when
     * present, else its producing PayrollRun's administrationId via
     * `payrollRunId` -- the NlAdministratieChecks parent-resolution idiom) and
     * derives its year from `period` (`YYYY-MM`/`YYYY-Pnn` -> the `YYYY`
     * prefix), then sums `grossPay` into `loonsom` and `wkrUsed` into
     * `payslipWkrUsed` for that (administrationId, year) key. Every
     * WkrDeclaration is summed by its own `administrationId`/`year` fields
     * into `vrijeRuimteDeclared` (category `vrije-ruimte`) or
     * `eindheffingDeclared` (category `eindheffing`) -- `gericht-vrijgesteld`
     * declarations are recorded elsewhere but never summed here (design.md
     * D1/D3). Degrades gracefully to an empty map when the Payslip/PayrollRun/
     * WkrDeclaration schemas do not exist yet in the register.
     *
     * @return array<string, array<int, array<string, mixed>>>
     *
     * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-004
     */
    private function buildWkrContext(): array
    {
        $runsById = [];
        foreach ($this->loadAll('PayrollRun') as $run) {
            $id = (string) ($run['id'] ?? $run['@self']['id'] ?? '');
            if ($id !== '') {
                $runsById[$id] = (string) ($run['administrationId'] ?? '');
            }
        }

        $aggregate = [];

        foreach ($this->loadAll('Payslip') as $payslip) {
            $administrationId = trim((string) ($payslip['administrationId'] ?? ''));
            if ($administrationId === '') {
                $runId = (string) ($payslip['payrollRunId'] ?? '');
                $administrationId = trim((string) ($runsById[$runId] ?? ''));
            }

            if ($administrationId === '') {
                // Unresolvable administration — cannot be attributed to any
                // (administrationId, year) key, so excluded from the aggregate.
                continue;
            }

            $year = self::yearFromPeriod((string) ($payslip['period'] ?? ''));
            if ($year === null) {
                continue;
            }

            $bucket = &$aggregate[$administrationId][$year];
            $bucket['loonsom']             = (($bucket['loonsom'] ?? 0.0) + ((float) ($payslip['grossPay'] ?? 0)));
            $bucket['payslipWkrUsed']       = (($bucket['payslipWkrUsed'] ?? 0.0) + ((float) ($payslip['wkrUsed'] ?? 0)));
            $bucket['vrijeRuimteDeclared']  = ($bucket['vrijeRuimteDeclared'] ?? 0.0);
            $bucket['eindheffingDeclared']  = ($bucket['eindheffingDeclared'] ?? 0.0);
            unset($bucket);
        }//end foreach

        foreach ($this->loadAll('WkrDeclaration') as $declaration) {
            $administrationId = trim((string) ($declaration['administrationId'] ?? ''));
            $year             = (int) ($declaration['year'] ?? 0);
            $category         = (string) ($declaration['wkrCategory'] ?? '');
            if ($administrationId === '' || $year === 0 || $category === 'gericht-vrijgesteld') {
                continue;
            }

            $bucket = &$aggregate[$administrationId][$year];
            $bucket['loonsom']            = ($bucket['loonsom'] ?? 0.0);
            $bucket['payslipWkrUsed']     = ($bucket['payslipWkrUsed'] ?? 0.0);
            $bucket['vrijeRuimteDeclared'] = ($bucket['vrijeRuimteDeclared'] ?? 0.0);
            $bucket['eindheffingDeclared'] = ($bucket['eindheffingDeclared'] ?? 0.0);

            if ($category === 'vrije-ruimte') {
                $bucket['vrijeRuimteDeclared'] += ((float) ($declaration['amount'] ?? 0));
            } else if ($category === 'eindheffing') {
                $bucket['eindheffingDeclared'] += ((float) ($declaration['amount'] ?? 0));
            }

            unset($bucket);
        }//end foreach

        return $aggregate;

    }//end buildWkrContext()


    /**
     * Derive a calendar year from a wage period (`YYYY-MM` or `YYYY-Pnn`) —
     * the leading four-digit year prefix. Returns null when unparseable.
     *
     * @param string $period Wage period string.
     *
     * @return int|null
     */
    private static function yearFromPeriod(string $period): ?int
    {
        if (preg_match('/^(\d{4})/', $period, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];

    }//end yearFromPeriod()


    /**
     * Build the cross-object context consumed by NlRetroChecks'
     * `nl-retro-adjustment-consistency` predicate (retro-adjustments
     * design.md D7, the `payroll.runsById` precedent):
     *
     * - `payslipsById`: every Payslip keyed by id, so the predicate can
     *   resolve a PayrollAdjustment's `originalPayslipId` (the sealed
     *   "stored" side of the recomputed-minus-stored diff) without
     *   re-querying the register.
     * - `employeesById`: each Employee's `{dateOfBirth, taxTableColor,
     *   loonheffingskortingToegepast}`, keyed by id -- the CalculationInput
     *   fields the predicate cannot get from the PayrollAdjustment itself.
     * - `contractsByEmployeeId`: each employee's `{type, writtenContract,
     *   awfTariff}` (last-loaded contract wins per employee -- the
     *   `related.EmploymentContract.byEmployeeId` MVP simplification).
     * - `aofTariff` / `whkPercentageOverride`: the SAME config keys and
     *   fallback semantics as `SettingsService::getPayrollAofTariff()` /
     *   `getPayrollWhkPercentage()` (payroll-core-engine), read directly via
     *   the already-injected `IAppConfig` so this context builder needs no
     *   new service dependency -- NlRetroChecks itself stays a pure predicate
     *   over `$context` (design.md D-static-severity).
     *
     * Degrades gracefully to empty maps when the Payslip/Employee/
     * EmploymentContract/PayrollAdjustment schemas do not exist yet in the
     * register.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-001
     */
    private function buildRetroContext(): array
    {
        $payslipsById = [];
        foreach ($this->loadAll('Payslip') as $payslip) {
            $id = (string) ($payslip['id'] ?? $payslip['@self']['id'] ?? '');
            if ($id !== '') {
                $payslipsById[$id] = $payslip;
            }
        }

        $employeesById = [];
        foreach ($this->loadAll('Employee') as $employee) {
            $id = (string) ($employee['id'] ?? $employee['@self']['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $employeesById[$id] = [
                'dateOfBirth'                  => ($employee['dateOfBirth'] ?? null),
                'taxTableColor'                => (string) ($employee['taxTableColor'] ?? ''),
                'loonheffingskortingToegepast' => (($employee['loonheffingskortingToegepast'] ?? true) === true),
            ];
        }

        $contractsByEmployeeId = [];
        foreach ($this->loadAll('EmploymentContract') as $contract) {
            $employeeId = trim((string) ($contract['employeeId'] ?? ''));
            if ($employeeId === '') {
                continue;
            }

            $contractsByEmployeeId[$employeeId] = [
                'type'            => (string) ($contract['type'] ?? ''),
                'writtenContract' => (($contract['writtenContract'] ?? false) === true),
                'awfTariff'       => (string) ($contract['awfTariff'] ?? ''),
            ];
        }

        $aofValue = strtolower(trim($this->appConfig->getValueString(Application::APP_ID, 'payroll_aof_tariff', 'laag')));
        $whkRaw   = trim($this->appConfig->getValueString(Application::APP_ID, 'payroll_whk_percentage', ''));

        return [
            'payslipsById'          => $payslipsById,
            'employeesById'         => $employeesById,
            'contractsByEmployeeId' => $contractsByEmployeeId,
            'aofTariff'             => ($aofValue === 'hoog' ? 'hoog' : 'laag'),
            'whkPercentageOverride' => ($whkRaw !== '' && is_numeric($whkRaw) === true && ((float) $whkRaw) >= 0.0) ? (float) $whkRaw : null,
        ];

    }//end buildRetroContext()


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
     * Build the per-employee date-indexed PLANNED clock index consumed by
     * NlRosterChecks' `nl-atw-dagelijkse-rust` predicate (rostering MVP,
     * design D4): `plannedClockByEmployeeDate` maps
     * `employeeId => [date => ['clockIn' => plannedStart, 'clockOut' => plannedEnd]]`
     * from every `RosterAssignment` whose `Roster` is `gepubliceerd` — the
     * `buildAttendanceContext()` precedent, mirrored onto planned instead of
     * realised clock data. A `concept` roster's assignments are deliberately
     * excluded so the standing `occ hrmq:rules:audit` never raises a
     * mandatory violation for a work-in-progress plan (design D4's scope
     * discipline; on-demand checking of a concept roster is
     * `RosterCheckService`'s job). Degrades gracefully to an empty index
     * when the Roster/RosterAssignment schemas do not exist yet in the
     * register.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/rostering/specs/rostering/spec.md#REQ-ROST-004
     */
    private function buildRosterContext(): array
    {
        $publishedRosterIds = [];
        foreach ($this->loadAll('Roster') as $roster) {
            if ((string) ($roster['status'] ?? '') !== 'gepubliceerd') {
                continue;
            }

            $id = (string) ($roster['id'] ?? $roster['@self']['id'] ?? '');
            if ($id !== '') {
                $publishedRosterIds[$id] = true;
            }
        }

        $plannedClockByEmployeeDate = [];
        foreach ($this->loadAll('RosterAssignment') as $assignment) {
            $rosterId = (string) ($assignment['rosterId'] ?? '');
            if ($rosterId === '' || isset($publishedRosterIds[$rosterId]) === false) {
                continue;
            }

            $employeeId = (string) ($assignment['employeeId'] ?? '');
            $date       = (string) ($assignment['date'] ?? '');
            if ($employeeId === '' || $date === '') {
                continue;
            }

            $plannedClockByEmployeeDate[$employeeId][$date] = [
                'clockIn'  => ($assignment['plannedStart'] ?? null),
                'clockOut' => ($assignment['plannedEnd'] ?? null),
            ];
        }

        return ['plannedClockByEmployeeDate' => $plannedClockByEmployeeDate];

    }//end buildRosterContext()


    /**
     * Build the employee-IBAN-presence index and payable-period set consumed
     * by NlNetPayChecks' `nl-netpay-iban-present` predicate
     * (payroll-sepa-netpay-shillinq, design.md D2/D4): `ibanByEmployeeKey` maps
     * each Employee's object id, slug, AND employeeNumber to whether an `iban`
     * is present -- the same three-way resolution PayrollNetPayService uses --
     * and `payablePeriods` is the set of periods with a payable
     * (approved/posted) PayrollRun. Degrades gracefully to empty
     * sets when the Employee/PayrollRun schemas do not exist yet in the
     * register.
     *
     * @return array<string, mixed>
     */
    private function buildNetPayContext(): array
    {
        $ibanByEmployeeKey = [];
        foreach ($this->loadAll('Employee') as $employee) {
            $hasIban = (trim((string) ($employee['iban'] ?? '')) !== '');

            $id = (string) ($employee['id'] ?? $employee['@self']['id'] ?? '');
            if ($id !== '') {
                $ibanByEmployeeKey[$id] = $hasIban;
            }

            $slug = (string) ($employee['@self']['slug'] ?? '');
            if ($slug !== '') {
                $ibanByEmployeeKey[$slug] = $hasIban;
            }

            $number = trim((string) ($employee['employeeNumber'] ?? ''));
            if ($number !== '') {
                $ibanByEmployeeKey[$number] = $hasIban;
            }
        }

        $payablePeriods = [];
        foreach ($this->loadAll('PayrollRun') as $run) {
            $period = (string) ($run['period'] ?? '');
            $status = (string) ($run['status'] ?? '');
            if ($period !== '' && in_array($status, ['approved', 'posted'], true) === true) {
                $payablePeriods[$period] = true;
            }
        }

        return [
            'ibanByEmployeeKey' => $ibanByEmployeeKey,
            'payablePeriods'    => array_keys($payablePeriods),
        ];

    }//end buildNetPayContext()


    /**
     * Build the per-contract and per-payslip document-evidence indexes
     * consumed by NlDocumentChecks' `nl-contract-schriftelijk` and
     * `nl-loonstrook-verplicht` predicates (hrmq-docudesk-documents design.md
     * D-corpus, extended by payslip-pdf-docudesk design.md D7):
     * `generatedArbeidsovereenkomstByContract` maps `contractId => true` for
     * every `GeneratedDocument` of type `arbeidsovereenkomst` in status
     * `generated` that references it; `generatedLoonstrookByPayslip` maps
     * `payslipId => true` for every `GeneratedDocument` of type `loonstrook`
     * in status `generated` that references it (via `payslipId`). Degrades
     * gracefully to empty maps when the GeneratedDocument schema does not
     * exist yet in the register.
     *
     * @return array<string, mixed>
     */
    private function buildDocumentsContext(): array
    {
        $byContract = [];
        $byPayslip  = [];
        foreach ($this->loadAll('GeneratedDocument') as $document) {
            if ((string) ($document['status'] ?? '') !== 'generated') {
                continue;
            }

            $documentType = (string) ($document['documentType'] ?? '');

            if ($documentType === 'arbeidsovereenkomst') {
                $contractId = trim((string) ($document['contractId'] ?? ''));
                if ($contractId !== '') {
                    $byContract[$contractId] = true;
                }

                continue;
            }

            if ($documentType === 'loonstrook') {
                $payslipId = trim((string) ($document['payslipId'] ?? ''));
                if ($payslipId !== '') {
                    $byPayslip[$payslipId] = true;
                }
            }
        }//end foreach

        return [
            'generatedArbeidsovereenkomstByContract' => $byContract,
            'generatedLoonstrookByPayslip'           => $byPayslip,
        ];

    }//end buildDocumentsContext()


    /**
     * Build the full-list per-employee EmploymentContract index consumed by
     * NlSignalChecks' `nl-signaal-contract-verloopt` successor predicate
     * (hr-signals, design.md D4): `contractsByEmployeeId` maps each
     * `employeeId` to the FULL list of its contracts (`{id, type, startDate,
     * endDate}`), unlike `buildRelatedContext()`'s
     * `EmploymentContract.byEmployeeId` index, which keeps only the
     * last-loaded contract per employee and therefore cannot answer "does a
     * successor contract exist" for a sibling. Each row carries its own
     * object id so the predicate can exclude itself when scanning for a
     * successor. Loads independently of `buildRelatedContext()` (an
     * accepted duplicate reload, design.md Risks) and degrades gracefully to
     * an empty index when the EmploymentContract schema does not exist yet
     * in the register.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/hr-signals/specs/hr-signals/spec.md#REQ-SIG-004
     */
    private function buildSignalsContext(): array
    {
        $contractsByEmployeeId = [];
        foreach ($this->loadAll('EmploymentContract') as $contract) {
            $employeeId = (string) ($contract['employeeId'] ?? '');
            if ($employeeId === '') {
                continue;
            }

            $contractsByEmployeeId[$employeeId][] = [
                'id'        => (string) ($contract['id'] ?? $contract['@self']['id'] ?? ''),
                'type'      => (string) ($contract['type'] ?? ''),
                'startDate' => (string) ($contract['startDate'] ?? ''),
                'endDate'   => (string) ($contract['endDate'] ?? ''),
            ];
        }

        return ['contractsByEmployeeId' => $contractsByEmployeeId];

    }//end buildSignalsContext()


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
