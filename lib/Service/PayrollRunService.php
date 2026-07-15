<?php

/**
 * Payroll Run Service
 *
 * Turns the pure `PayrollCalculator` into draft payroll runs (design.md D4/D5):
 * creates at most one `PayrollRun` per (period, administrationId) — the
 * netpay/glpost probe-before-create idempotency pattern — generates one
 * Payslip per active NL employee whose contract covers the period (upsert
 * keyed on `(payrollRunId, employeeId)`, orphaned engine payslips of that run
 * deleted, payslips with a different or null `payrollRunId` never touched),
 * rolls up cents-exact totals, and stamps `engineVersion` (the tax-year table
 * id) + `calculatedAt`.
 *
 * Recalculation is allowed only while the run is `draft` (design.md D4):
 * approved/posted/paid runs are downstream truth consumed by glpost/netpay
 * and are refused. The service never writes any `status` value other than
 * creating the initial `draft` — approval remains a human act on the
 * existing enum, and write-time guard wiring stays owned by the active
 * `hrmq-rule-compliance-enforcement` change. GL/clearing fields
 * (glExpensePosted/glLiabilityPosted/withholdings*) are never touched.
 *
 * Employees the engine cannot compute honestly are SKIPPED with a per-employee
 * reason in the outcome — never computed wrong, never silently dropped
 * (design.md D4): no covering contract, no `grossMonthlySalary` (the hourly x
 * Timesheet path is a named fast-follow), missing BSN/ID-verification (the
 * anoniementarief 52% path is a named fast-follow), or no NL tax-table colour.
 *
 * Writes go through OpenRegister's ObjectService (design.md D5): verified
 * against the openregister checkout that `allowCreate: false` is a UI-only
 * object-list affordance with zero server-side enforcement, so this
 * service-side write is legitimate and generation remains the only Payslip
 * create path (the manifest keeps every Payslip surface create-less).
 *
 * sick-pay-calc (design.md D4): before building the `CalculationInput`, an
 * open (gemeld) `SickLeaveCase` covering the period is looked up per
 * employee; when present, the pure `SickPayCalculator` computes the
 * doorbetaald loon and its `payableGrossCents` replaces the full salary as
 * the gross fed to `PayrollCalculator`, and the Payslip is additionally
 * stamped with the sick-pay fields (`sickLeaveCaseId`, `doorbetaaldLoon`,
 * `wachtdagDeduction`, `sickPayReferenceWage`, `sickPayPercentage`,
 * `sickPayMinimumWageFloor`, `sickPayYearOne`). No open case -> the
 * full-salary path and the Payslip shape are byte-identical to before.
 *
 * retro-adjustments (design.md D4): after computing each employee's payslip,
 * every `applied` `PayrollAdjustment` whose `settlementPeriod` equals this
 * run's period is summed (by `deltaNet`, cents-exact) into that employee's
 * `retroAdjustment` component and folded into `nettoPay` -- a nabetaling or
 * terugvordering line that lands in the CURRENT run only. `draft`
 * adjustments and adjustments settling a different period never surface
 * here; the sealed historical payslip each adjustment was diffed against is
 * never read or written by this service. No open/applied adjustment for an
 * employee -> `retroAdjustment` stays null and `nettoPay` is unchanged
 * (byte-identical to before this change).
 *
 * leave-buy-sell (design.md D6): after folding retro-adjustments, every
 * `settled` `LeaveTransaction` whose `settlementPeriod` equals this run's
 * period is summed (by `settledAmount`, cents-exact, signed: sold = payment,
 * bought = deduction) into that employee's `leaveBuySell` component and
 * folded into `nettoPay` on top of any retro-adjustment delta. The engine
 * (`PayrollCalculator`) is never invoked to (re)compute `settledAmount` --
 * `LeaveBuySellSettlementService` already computed and stored it; this
 * service only reads and folds the already-settled figure. No settled
 * transaction for an employee/period -> `leaveBuySell` stays null and
 * `nettoPay` is unchanged (byte-identical to before this change).
 *
 * loonbeslag (design.md D2/D3/D4): after folding retro-adjustments AND
 * leave-buy-sell -- against the fully-folded `nettoPay` (engine net +
 * retroAdjustment + leaveBuySell), never an intermediate figure -- the one
 * `actief` Loonbeslag covering an employee's period (resolved via the same
 * id/slug/employeeNumber key convention as `coveringContract()`/
 * `openSickCaseFor()`, deterministic earliest-`effectiveFrom` tie-break when
 * more than one match) contributes a floor-clamped deduction: `deduction =
 * min(orderedAmount, max(0, nettoPaySoFar - beslagvrijeVoet))`, folded into
 * `nettoPay` as the FOURTH and final current-run post-tax component
 * (`Payslip.loonbeslag`/`loonbeslagId`). `PayrollCalculator` is never invoked
 * for this figure -- entirely post-tax arithmetic here. No active Loonbeslag
 * for an employee/period -> `loonbeslag`/`loonbeslagId` stay null and
 * `nettoPay` is unchanged (byte-identical to before this change). Idempotent
 * per (loonbeslagId, period): recalculating a draft run re-derives the same
 * deduction from scratch every time -- no accumulator anywhere on
 * `Loonbeslag`.
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
 * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-005
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-003
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-004
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-005
 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-004
 * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-005
 * @spec openspec/changes/loonbeslag/specs/loonbeslag/spec.md#REQ-BESLAG-002
 * @spec openspec/changes/loonbeslag/specs/loonbeslag/spec.md#REQ-BESLAG-004
 * @spec openspec/changes/loonbeslag/specs/loonbeslag/spec.md#REQ-BESLAG-005
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use OCA\Hrmq\Payroll\CalculationInput;
use OCA\Hrmq\Payroll\CalculationResult;
use OCA\Hrmq\Payroll\PayrollCalculator;
use OCA\Hrmq\Payroll\SickPayCalculator;
use OCA\Hrmq\Payroll\SickPayInput;
use OCA\Hrmq\Payroll\SickPayResult;
use OCA\Hrmq\Payroll\TaxTables;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Generates draft payroll runs + engine payslips from the pure calculator.
 */
class PayrollRunService
{

    /**
     * The default administrationId when the occ command omits
     * `--administration` — matches the seed convention (`hr-seed.json` /
     * NlPayrollChecks::seedObjects()).
     *
     * @var string
     */
    private const DEFAULT_ADMINISTRATION = 'ADM-001';

    /**
     * Max objects loaded per type.
     *
     * @var int
     */
    private const LIMIT = 10000;

    /**
     * The full-time hours-per-week basis the sick-pay WML floor's part-time
     * factor is scaled against (design.md D3 — the same 36/36 full-time
     * convention already used elsewhere in the seed data).
     *
     * @var float
     */
    private const FULLTIME_HOURS_PER_WEEK = 36.0;

    /**
     * The first 52 weeks of a SickLeaveCase's firstSickDay, past which the
     * year-1 WML floor no longer applies (design.md D3, the
     * nl-loondoorbetaling-floor rule's maxWeeks/2 boundary).
     *
     * @var int
     */
    private const YEAR_ONE_WEEKS = 52;


    /**
     * @param ContainerInterface $container         DI container for lazy ObjectService resolution.
     * @param SettingsService    $settingsService   Register slug + employer-level payroll config.
     * @param PayrollCalculator  $calculator        The pure gross-to-net calculator.
     * @param SickPayCalculator  $sickPayCalculator The pure loondoorbetaling-bij-ziekte calculator (sick-pay-calc).
     * @param LoggerInterface    $logger            Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly SettingsService $settingsService,
        private readonly PayrollCalculator $calculator,
        private readonly SickPayCalculator $sickPayCalculator,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * Create or recalculate the draft PayrollRun for (period,
     * administrationId) — the occ `hrmq:payroll:run` entry point
     * (design.md D4).
     *
     * @param string      $period           Wage period, `YYYY-MM`.
     * @param string|null $administrationId The administration, or null for the seed-convention default.
     * @param bool        $recalculate      Whether an existing draft run may be regenerated.
     *
     * @return array<string, mixed> Outcome: {runId, period, administrationId, status, message, computed, skipped, totals}.
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-003
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-004
     */
    public function runFor(string $period, ?string $administrationId=null, bool $recalculate=false): array
    {
        $period           = trim($period);
        $administrationId = trim((string) ($administrationId ?? ''));
        if ($administrationId === '') {
            $administrationId = self::DEFAULT_ADMINISTRATION;
        }

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            return $this->outcome('', $period, $administrationId, 'failed', 'Ongeldige periode "'.$period.'" (verwacht JJJJ-MM).');
        }

        $existing = $this->findRun($period, $administrationId);

        if ($existing !== null) {
            $status = (string) ($existing['status'] ?? '');
            if ($status !== 'draft') {
                return $this->outcome(
                    $this->idOf($existing),
                    $period,
                    $administrationId,
                    'refused-not-draft',
                    'Loonrun heeft status "'.$status.'" — alleen concept-runs kunnen (her)berekend worden (goedgekeurde runs zijn geboekte waarheid).'
                );
            }

            if ($recalculate === false) {
                return $this->outcome(
                    $this->idOf($existing),
                    $period,
                    $administrationId,
                    'exists',
                    'Concept-loonrun bestaat al voor deze periode; gebruik --recalculate om opnieuw te berekenen (idempotente no-op).'
                );
            }

            return $this->generate($existing);
        }//end if

        try {
            $created = $this->toArray(
                $this->objectService()->saveObject(
                    object: [
                        'period'           => $period,
                        'administrationId' => $administrationId,
                        'jurisdiction'     => 'NL',
                        'status'           => 'draft',
                    ],
                    register: $this->register(),
                    schema: 'PayrollRun',
                    _rbac: false,
                    _multitenancy: false
                )
            );
        } catch (\Throwable $e) {
            $this->logger->error('PayrollRunService: kon PayrollRun niet aanmaken: '.$e->getMessage());
            return $this->outcome('', $period, $administrationId, 'failed', 'Aanmaken van de loonrun is mislukt: '.$e->getMessage());
        }

        return $this->generate($created);

    }//end runFor()


    /**
     * Recalculate one existing run in place — the guarded endpoint's entry
     * point (design.md D6). The controller has already RBAC-resolved the run;
     * this re-fetches it unscoped and applies the draft-only guard.
     *
     * @param string $runId The PayrollRun id.
     *
     * @return array<string, mixed> Outcome (see runFor()).
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-004
     */
    public function recalculateRun(string $runId): array
    {
        $run = null;
        foreach ($this->loadAll('PayrollRun') as $candidate) {
            if ($this->idOf($candidate) === $runId) {
                $run = $candidate;
                break;
            }
        }

        if ($run === null) {
            return $this->outcome($runId, '', '', 'failed', 'Loonrun niet gevonden.');
        }

        $status = (string) ($run['status'] ?? '');
        if ($status !== 'draft') {
            return $this->outcome(
                $runId,
                (string) ($run['period'] ?? ''),
                (string) ($run['administrationId'] ?? ''),
                'refused-not-draft',
                'Loonrun heeft status "'.$status.'" — alleen concept-runs kunnen herberekend worden.'
            );
        }

        return $this->generate($run);

    }//end recalculateRun()


    /**
     * Generate (or regenerate) the payslips + totals for a draft run
     * (design.md D4): per-employee calculate, upsert keyed
     * (payrollRunId, employeeId), orphan cleanup, cents-exact roll-up,
     * engineVersion/calculatedAt stamps. Never writes `status`. An open
     * (gemeld) SickLeaveCase covering the period substitutes the doorbetaald
     * loon for the full salary before the gross-to-net calculation
     * (sick-pay-calc design.md D4); absent a case the path is unchanged.
     *
     * @param array<string, mixed> $run The draft PayrollRun.
     *
     * @return array<string, mixed> Outcome (see runFor()).
     *
     * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-005
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-003
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-005
     * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-005
     * @spec openspec/changes/loonbeslag/specs/loonbeslag/spec.md#REQ-BESLAG-002
     * @spec openspec/changes/loonbeslag/specs/loonbeslag/spec.md#REQ-BESLAG-004
     */
    private function generate(array $run): array
    {
        $runId            = $this->idOf($run);
        $period           = (string) ($run['period'] ?? '');
        $administrationId = (string) ($run['administrationId'] ?? '');

        $tableId = 'nl-'.substr($period, 0, 4);
        try {
            $tables = TaxTables::load($tableId);
        } catch (\Throwable $e) {
            return $this->outcome($runId, $period, $administrationId, 'failed', 'Geen belastingtabellen voor deze periode ('.$tableId.'): '.$e->getMessage());
        }

        $aofTariff     = $this->settingsService->getPayrollAofTariff();
        $whkPercentage = $this->settingsService->getPayrollWhkPercentage($tables->werknemersverzekeringen()['whkDefault']);

        $contractsByEmployeeKey       = $this->contractsByEmployeeKey();
        $sickCasesByEmployeeKey       = $this->openSickCasesByEmployeeKey();
        $existingByEmployeeId         = $this->enginePayslipsByEmployeeId($runId);
        $retroAdjustmentsByEmployeeId = $this->appliedRetroAdjustmentsByEmployeeId($period);
        $leaveBuySellByEmployeeId     = $this->settledLeaveTransactionsByEmployeeId($period);
        $loonbeslagenByEmployeeKey    = $this->activeLoonbeslagenByEmployeeKey();

        $computed = [];
        $skipped  = [];
        $totals   = [
            'gross'           => 0,
            'loonheffing'     => 0,
            'employerCharges' => 0,
            'withholdings'    => 0,
            'net'             => 0,
        ];

        foreach ($this->loadAll('Employee') as $employee) {
            if ($this->coversPeriod((string) ($employee['startDate'] ?? ''), (string) ($employee['endDate'] ?? ''), $period) === false) {
                // Not employed in this period — not selected, not reported.
                continue;
            }

            $employeeId    = $this->idOf($employee);
            $employeeLabel = $this->employeeLabel($employee);

            $contract = $this->coveringContract($employee, $contractsByEmployeeKey, $period);
            if ($contract === null) {
                $skipped[] = ['employee' => $employeeLabel, 'reason' => 'no-contract (geen contract dat de periode dekt)'];
                continue;
            }

            $taxTableColor = trim((string) ($employee['taxTableColor'] ?? ''));
            if (in_array($taxTableColor, ['wit', 'groen'], true) === false) {
                $skipped[] = ['employee' => $employeeLabel, 'reason' => 'non-nl (geen NL tabelkleur wit/groen op het werknemersrecord)'];
                continue;
            }

            $grossMonthly = ($employee['grossMonthlySalary'] ?? null);
            if (is_numeric($grossMonthly) === false || ((float) $grossMonthly) <= 0.0) {
                $skipped[] = ['employee' => $employeeLabel, 'reason' => 'no-monthly-salary (hourly path: fast-follow)'];
                continue;
            }

            if (trim((string) ($employee['bsn'] ?? '')) === '' || ($employee['identityDocumentVerified'] ?? false) !== true) {
                // Anoniementarief precondition: never compute a knowingly-wrong
                // slip — the 52% flat path is a named fast-follow (design.md D2).
                $skipped[] = ['employee' => $employeeLabel, 'reason' => 'anoniementarief-precondition (BSN/ID-verificatie ontbreekt; 52%-tarief: fast-follow)'];
                continue;
            }

            $grossMonthlySalaryCents = (int) round(((float) $grossMonthly) * 100);

            // sick-pay-calc (design.md D4): an open (gemeld) SickLeaveCase
            // covering the period substitutes the doorbetaald loon for the
            // full salary as the gross fed into PayrollCalculator. No open
            // case -> the full-salary path below is completely unchanged.
            $sickCase   = $this->openSickCaseFor($employee, $sickCasesByEmployeeKey, $period);
            $sickResult = null;
            if ($sickCase !== null) {
                $sickInput  = $this->sickPayInputFor($sickCase, $contract, $grossMonthlySalaryCents, $period);
                $sickResult = $this->sickPayCalculator->compute($sickInput, $tables);

                $grossMonthlySalaryCents = $sickResult->payableGrossCents;
            }

            $input = new CalculationInput(
                grossMonthlySalaryCents: $grossMonthlySalaryCents,
                taxTableColor: $taxTableColor,
                loonheffingskortingToegepast: (($employee['loonheffingskortingToegepast'] ?? true) === true),
                dateOfBirth: (($employee['dateOfBirth'] ?? null) !== null ? (string) $employee['dateOfBirth'] : null),
                period: $period,
                awfTariff: $this->awfTariffFor($contract),
                aofTariff: $aofTariff,
                whkPercentage: $whkPercentage
            );

            $result = $this->calculator->calculate($input, $tables);

            // retro-adjustments (design.md D4): fold every APPLIED
            // PayrollAdjustment settling into THIS period for this employee
            // into the payslip's retroAdjustment component + nettoPay. No
            // applied adjustment -> retroAdjustmentCents is 0 and the payload
            // stays byte-identical to before this change.
            $retroAdjustmentCents = ($retroAdjustmentsByEmployeeId[$employeeId] ?? 0);

            // leave-buy-sell (design.md D6): fold every SETTLED LeaveTransaction
            // whose settlementPeriod equals THIS period for this employee into
            // the payslip's leaveBuySell component + nettoPay -- on top of any
            // retro-adjustment delta, never in place of it. No settled
            // transaction -> leaveBuySellCents is 0 and the payload stays
            // byte-identical to before this change.
            $leaveBuySellCents = ($leaveBuySellByEmployeeId[$employeeId] ?? 0);

            // loonbeslag (design.md D3): computed against the FULLY-folded
            // nettoPay-so-far (engine net + retroAdjustment + leaveBuySell) --
            // the fourth and final fold, so the beslagvrije voet protects the
            // employee's actual take-home this period, never an intermediate
            // figure a same-period nabetaling/leave-payout would still
            // inflate past.
            $nettoPaySoFarCents       = ($result->nettoPayCents + $retroAdjustmentCents + $leaveBuySellCents);
            $loonbeslag               = $this->activeLoonbeslagFor($employee, $loonbeslagenByEmployeeKey, $period);
            $loonbeslagDeductionCents = ($loonbeslag !== null) ? $this->loonbeslagDeductionCents($loonbeslag, $nettoPaySoFarCents) : 0;

            $payload = $this->payslipPayload($runId, $employee, $contract, $period, $result);
            $payload = array_merge($payload, $this->sickPayFields($sickCase, $sickResult));
            $payload = array_merge($payload, $this->retroAdjustmentFields($retroAdjustmentCents, $result->nettoPayCents));
            $payload = array_merge($payload, $this->leaveBuySellFields($leaveBuySellCents, ($result->nettoPayCents + $retroAdjustmentCents)));
            $payload = array_merge($payload, $this->loonbeslagFields($loonbeslag, $loonbeslagDeductionCents, $nettoPaySoFarCents));

            try {
                $existingPayslip = ($existingByEmployeeId[$employeeId] ?? null);
                $saved           = $this->savePayslip($payload, $existingPayslip);
                unset($existingByEmployeeId[$employeeId]);
            } catch (\Throwable $e) {
                $this->logger->error('PayrollRunService: kon loonstrook niet opslaan voor '.$employeeLabel.': '.$e->getMessage());
                return $this->outcome($runId, $period, $administrationId, 'failed', 'Opslaan van de loonstrook voor '.$employeeLabel.' is mislukt: '.$e->getMessage());
            }

            $computed[] = ['employee' => $employeeLabel, 'payslipId' => $this->idOf($saved)];

            $totals['gross']           += $result->grossPayCents;
            $totals['loonheffing']     += $result->loonheffingCents;
            $totals['employerCharges'] += $result->employerChargesCents;
            $totals['withholdings']    += $result->loonheffingCents;
            $totals['net']             += ($nettoPaySoFarCents - $loonbeslagDeductionCents);
        }//end foreach

        // Orphan cleanup (design.md D4): engine payslips of THIS run whose
        // employee no longer computes are deleted; payslips with a different
        // or null payrollRunId are never touched (they were never selected).
        foreach ($existingByEmployeeId as $orphan) {
            $orphanId = $this->idOf($orphan);
            try {
                $this->objectService()->deleteObject(
                    uuid: $orphanId,
                    register: $this->register(),
                    schema: 'Payslip',
                    _rbac: false,
                    _multitenancy: false
                );
            } catch (\Throwable $e) {
                $this->logger->warning('PayrollRunService: kon verweesde loonstrook '.$orphanId.' niet verwijderen: '.$e->getMessage());
            }
        }

        // Roll-up + stamps (design.md D4): totals cents-exact, engineVersion =
        // tables id, calculatedAt = now. Status and GL/clearing fields are
        // deliberately NOT written.
        $runUpdate = array_merge(
            $run,
            [
                'totalGross'           => $this->euros($totals['gross']),
                'totalLoonheffing'     => $this->euros($totals['loonheffing']),
                'totalEmployerCharges' => $this->euros($totals['employerCharges']),
                'totalWithholdings'    => $this->euros($totals['withholdings']),
                'totalNet'             => $this->euros($totals['net']),
                'engineVersion'        => $tables->id(),
                'calculatedAt'         => gmdate('Y-m-d\TH:i:s\Z'),
            ]
        );
        unset($runUpdate['@self']);

        try {
            $this->objectService()->saveObject(
                object: $runUpdate,
                register: $this->register(),
                schema: 'PayrollRun',
                uuid: ($runId === '' ? null : $runId),
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            $this->logger->error('PayrollRunService: kon PayrollRun-totalen niet opslaan: '.$e->getMessage());
            return $this->outcome($runId, $period, $administrationId, 'failed', 'Opslaan van de loonrun-totalen is mislukt: '.$e->getMessage());
        }

        $outcome             = $this->outcome($runId, $period, $administrationId, 'calculated', sprintf('%d loonstro(o)k(en) berekend, %d overgeslagen.', count($computed), count($skipped)));
        $outcome['computed'] = $computed;
        $outcome['skipped']  = $skipped;
        $outcome['totals']   = [
            'totalGross'           => $this->euros($totals['gross']),
            'totalLoonheffing'     => $this->euros($totals['loonheffing']),
            'totalEmployerCharges' => $this->euros($totals['employerCharges']),
            'totalWithholdings'    => $this->euros($totals['withholdings']),
            'totalNet'             => $this->euros($totals['net']),
        ];

        return $outcome;

    }//end generate()


    /**
     * Build the Payslip payload for one computed employee (design.md D4
     * "payslip stamping"): the D2 components, `payrollRunId`, `userId` from
     * the employee's `nextcloudUserId` (the mijn-hr convention), the
     * loonstrook-content booleans (the record carries the BW 7:626 facts —
     * rendering stays payslip-pdf-docudesk's concern), WKR fields 0 (no WKR
     * administration in the engine MVP), `anoniementariefApplied` false
     * (precondition employees are skipped, never computed at 52%).
     *
     * @param string               $runId    The PayrollRun id.
     * @param array<string, mixed> $employee The Employee.
     * @param array<string, mixed> $contract The covering EmploymentContract.
     * @param string               $period   Wage period (YYYY-MM).
     * @param CalculationResult    $result   The calculator output.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-005
     */
    private function payslipPayload(string $runId, array $employee, array $contract, string $period, CalculationResult $result): array
    {
        $nextcloudUserId = trim((string) ($employee['nextcloudUserId'] ?? ''));

        $payload = [
            'employeeId'               => $this->idOf($employee),
            'userId'                   => ($nextcloudUserId === '' ? null : $nextcloudUserId),
            'payrollRunId'             => $runId,
            'period'                   => $period,
            'jurisdiction'             => 'NL',
            'currency'                 => 'EUR',
            'grossPay'                 => $this->euros($result->grossPayCents),
            'loonheffing'              => $this->euros($result->loonheffingCents),
            'arbeidskorting'           => $this->euros($result->arbeidskortingCents),
            'volksverzekeringen'       => $this->euros($result->volksverzekeringenCents),
            'werknemersverzekeringen'  => $this->euros($result->werknemersverzekeringenCents),
            'zvw'                      => $this->euros($result->zvwCents),
            'zvwMode'                  => $result->zvwMode,
            'zvwRate'                  => $result->zvwRate,
            'anoniementariefApplied'   => false,
            'appliedTaxRate'           => $result->appliedTaxRate,
            'nettoPay'                 => $this->euros($result->nettoPayCents),
            'vakantiegeldReserved'     => $this->euros($result->vakantiegeldReservedCents),
            'vakantiegeldRate'         => $result->vakantiegeldRate,
            'wkrUsed'                  => 0.0,
            'wkrVrijeRuimteRemaining'  => 0.0,
            'wkrExcess'                => 0.0,
            'pensionContribution'      => 0.0,
            'statementProvided'        => true,
            'showsGrossWage'           => true,
            'showsDeductionBasis'      => true,
            'showsMinimumWage'         => true,
            'showsEmployerEmployeeIds' => true,
        ];

        $hoursPerWeek = ($contract['hoursPerWeek'] ?? null);
        if (is_numeric($hoursPerWeek) === true && ((float) $hoursPerWeek) > 0.0) {
            // Contracted monthly hours (52 weeks / 12 months) — feeds the
            // effective-hourly-rate minimum-wage checks.
            $payload['hoursWorked'] = round((((float) $hoursPerWeek) * 52 / 12), 2);
        }

        return $payload;

    }//end payslipPayload()


    /**
     * Upsert one engine Payslip: update the existing (payrollRunId,
     * employeeId)-keyed payslip in place, or create a new one.
     *
     * @param array<string, mixed>      $payload  The new payslip payload.
     * @param array<string, mixed>|null $existing The existing engine payslip for this (run, employee), if any.
     *
     * @return array<string, mixed> The saved object.
     */
    private function savePayslip(array $payload, ?array $existing): array
    {
        $uuid = null;
        if ($existing !== null) {
            $existingId = $this->idOf($existing);
            $uuid       = ($existingId === '' ? null : $existingId);
        }

        $saved = $this->objectService()->saveObject(
            object: $payload,
            register: $this->register(),
            schema: 'Payslip',
            uuid: $uuid,
            _rbac: false,
            _multitenancy: false
        );

        return $this->toArray($saved);

    }//end savePayslip()


    /**
     * The existing PayrollRun for (period, administrationId), or null —
     * the idempotency probe (design.md D4).
     *
     * @param string $period           Wage period (YYYY-MM).
     * @param string $administrationId The administration.
     *
     * @return array<string, mixed>|null
     */
    private function findRun(string $period, string $administrationId): ?array
    {
        foreach ($this->loadAll('PayrollRun') as $run) {
            if ((string) ($run['period'] ?? '') === $period
                && (string) ($run['administrationId'] ?? '') === $administrationId
            ) {
                return $run;
            }
        }

        return null;

    }//end findRun()


    /**
     * This run's existing ENGINE payslips (payrollRunId === $runId), keyed by
     * employeeId — the upsert/orphan-cleanup index. Payslips with a different
     * or null payrollRunId are never included (design.md D4).
     *
     * @param string $runId The PayrollRun id.
     *
     * @return array<string, array<string, mixed>>
     */
    private function enginePayslipsByEmployeeId(string $runId): array
    {
        $out = [];
        if ($runId === '') {
            return $out;
        }

        foreach ($this->loadAll('Payslip') as $payslip) {
            if ((string) ($payslip['payrollRunId'] ?? '') !== $runId) {
                continue;
            }

            $employeeId = (string) ($payslip['employeeId'] ?? '');
            if ($employeeId !== '') {
                $out[$employeeId] = $payslip;
            }
        }

        return $out;

    }//end enginePayslipsByEmployeeId()


    /**
     * All EmploymentContracts, indexed by every employee-reference key
     * (the netpay three-way convention: contracts may reference the employee
     * by object id, slug, or employeeNumber). Each key maps to the LIST of
     * that employee's contracts.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function contractsByEmployeeKey(): array
    {
        $out = [];
        foreach ($this->loadAll('EmploymentContract') as $contract) {
            $key = trim((string) ($contract['employeeId'] ?? ''));
            if ($key !== '') {
                $out[$key][] = $contract;
            }
        }

        return $out;

    }//end contractsByEmployeeKey()


    /**
     * The employee's contract covering the period, resolved via the id/slug/
     * employeeNumber keys, or null when none covers it.
     *
     * @param array<string, mixed>                              $employee               The Employee.
     * @param array<string, array<int, array<string, mixed>>> $contractsByEmployeeKey The contract index.
     * @param string                                            $period                 Wage period (YYYY-MM).
     *
     * @return array<string, mixed>|null
     */
    private function coveringContract(array $employee, array $contractsByEmployeeKey, string $period): ?array
    {
        $keys = array_filter(
            [
                $this->idOf($employee),
                (string) ($employee['@self']['slug'] ?? ''),
                trim((string) ($employee['employeeNumber'] ?? '')),
            ],
            static fn(string $key): bool => $key !== ''
        );

        foreach ($keys as $key) {
            foreach (($contractsByEmployeeKey[$key] ?? []) as $contract) {
                if ($this->coversPeriod((string) ($contract['startDate'] ?? ''), (string) ($contract['endDate'] ?? ''), $period) === true) {
                    return $contract;
                }
            }
        }

        return null;

    }//end coveringContract()


    /**
     * All open (gemeld) SickLeaveCases, indexed by every employee-reference
     * key (sick-pay-calc design.md D4, the same id/slug/employeeNumber
     * convention as `contractsByEmployeeKey()`). Each key maps to the LIST of
     * that employee's open cases.
     *
     * @return array<string, array<int, array<string, mixed>>>
     *
     * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-005
     */
    private function openSickCasesByEmployeeKey(): array
    {
        $out = [];
        foreach ($this->loadAll('SickLeaveCase') as $case) {
            if ((string) ($case['status'] ?? 'gemeld') !== 'gemeld') {
                continue;
            }

            $key = trim((string) ($case['employeeId'] ?? ''));
            if ($key !== '') {
                $out[$key][] = $case;
            }
        }

        return $out;

    }//end openSickCasesByEmployeeKey()


    /**
     * The employee's open SickLeaveCase covering the period — firstSickDay
     * on or before the period's last day (sick-pay-calc design.md D4) —
     * resolved via the id/slug/employeeNumber keys, or null when none
     * applies (the full-salary path stays unchanged).
     *
     * @param array<string, mixed>                             $employee               The Employee.
     * @param array<string, array<int, array<string, mixed>>> $sickCasesByEmployeeKey The open-case index.
     * @param string                                            $period                 Wage period (YYYY-MM).
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-005
     */
    private function openSickCaseFor(array $employee, array $sickCasesByEmployeeKey, string $period): ?array
    {
        $keys = array_filter(
            [
                $this->idOf($employee),
                (string) ($employee['@self']['slug'] ?? ''),
                trim((string) ($employee['employeeNumber'] ?? '')),
            ],
            static fn(string $key): bool => $key !== ''
        );

        foreach ($keys as $key) {
            foreach (($sickCasesByEmployeeKey[$key] ?? []) as $case) {
                // A still-open case has no end date; it "covers" the period
                // once its firstSickDay is on or before the period's last day.
                if ($this->coversPeriod((string) ($case['firstSickDay'] ?? ''), '', $period) === true) {
                    return $case;
                }
            }
        }

        return null;

    }//end openSickCaseFor()


    /**
     * Build the SickPayInput for one open case + covering contract
     * (sick-pay-calc design.md D2/D3/D4): reference = the employee's full
     * grossMonthlySalary (pre-substitution), aangepastLoon from the case,
     * percentage from the case, yearOne/firstSickDayInPeriod derived from
     * firstSickDay vs the period, wachtdag from the case, contract hours for
     * the WML floor's part-time factor.
     *
     * @param array<string, mixed> $case                     The open SickLeaveCase.
     * @param array<string, mixed> $contract                 The covering EmploymentContract.
     * @param int                   $grossMonthlySalaryCents The employee's full grossMonthlySalary, in cents (the reference wage).
     * @param string                $period                   Wage period (YYYY-MM).
     *
     * @return SickPayInput
     *
     * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-005
     */
    private function sickPayInputFor(array $case, array $contract, int $grossMonthlySalaryCents, string $period): SickPayInput
    {
        $firstSickDay  = trim((string) ($case['firstSickDay'] ?? ''));
        $aangepastLoon = ($case['aangepastLoon'] ?? null);
        $hoursPerWeek  = ($contract['hoursPerWeek'] ?? null);

        return new SickPayInput(
            referenceWageCents: $grossMonthlySalaryCents,
            aangepastLoonCents: (is_numeric($aangepastLoon) === true ? (int) round(((float) $aangepastLoon) * 100) : 0),
            loondoorbetalingPercentage: (float) ($case['loondoorbetalingPercentage'] ?? 70),
            yearOne: $this->isYearOne($firstSickDay, $period),
            wachtdag: (($case['wachtdag'] ?? false) === true),
            firstSickDayInPeriod: $this->coversPeriod($firstSickDay, $firstSickDay, $period),
            contractHoursPerWeek: (is_numeric($hoursPerWeek) === true ? (float) $hoursPerWeek : self::FULLTIME_HOURS_PER_WEEK),
            fulltimeHoursPerWeek: self::FULLTIME_HOURS_PER_WEEK
        );

    }//end sickPayInputFor()


    /**
     * Whether the run period falls within the first 52 weeks of firstSickDay
     * (sick-pay-calc design.md D3) — weeks elapsed between firstSickDay and
     * the period's first day, floored. An unparseable firstSickDay
     * defensively yields false (no WML floor rather than a guessed one).
     *
     * @param string $firstSickDay ISO-8601 firstSickDay.
     * @param string $period       Wage period (YYYY-MM).
     *
     * @return bool
     *
     * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-002
     */
    private function isYearOne(string $firstSickDay, string $period): bool
    {
        try {
            $periodStart = new \DateTimeImmutable($period.'-01');
            $day         = new \DateTimeImmutable($firstSickDay);
        } catch (\Throwable $e) {
            return false;
        }

        if ($periodStart < $day) {
            // The employee fell sick within (or after) this very period.
            return true;
        }

        $weeksElapsed = intdiv($periodStart->diff($day)->days, 7);

        return $weeksElapsed < self::YEAR_ONE_WEEKS;

    }//end isYearOne()


    /**
     * The sick-pay Payslip fields to merge onto the payload (sick-pay-calc
     * design.md D4): all null when no open case applies (a normal payslip
     * stays byte-identical to the pre-change shape).
     *
     * @param array<string, mixed>|null $case   The open SickLeaveCase, or null.
     * @param SickPayResult|null        $result The calculator output, or null.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/sick-pay-calc/spec.md#REQ-SICK-005
     */
    private function sickPayFields(?array $case, ?SickPayResult $result): array
    {
        if ($case === null || $result === null) {
            return [
                'sickLeaveCaseId'         => null,
                'doorbetaaldLoon'         => null,
                'wachtdagDeduction'       => null,
                'sickPayReferenceWage'    => null,
                'sickPayPercentage'       => null,
                'sickPayMinimumWageFloor' => null,
                'sickPayYearOne'          => null,
            ];
        }

        return [
            'sickLeaveCaseId'         => $this->idOf($case),
            'doorbetaaldLoon'         => $this->euros($result->doorbetaaldLoonCents),
            'wachtdagDeduction'       => $this->euros($result->wachtdagDeductionCents),
            'sickPayReferenceWage'    => $this->euros($result->referenceWageCents),
            'sickPayPercentage'       => $result->appliedPercentage,
            'sickPayMinimumWageFloor' => $this->euros($result->minimumWageFloorCents),
            'sickPayYearOne'          => $result->yearOne,
        ];

    }//end sickPayFields()


    /**
     * Every employee's summed `deltaNet` (cents) across `applied`
     * PayrollAdjustments whose `settlementPeriod` equals this run's period
     * (retro-adjustments design.md D4) -- the current-run-only folding index.
     * `draft` adjustments and adjustments settling a different period are
     * excluded (a draft adjustment affects no run). Degrades gracefully to an
     * empty map when the PayrollAdjustment schema does not exist yet in the
     * register (the two changes may land in either order).
     *
     * @param string $period Wage period (YYYY-MM).
     *
     * @return array<string, int>
     *
     * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-004
     */
    private function appliedRetroAdjustmentsByEmployeeId(string $period): array
    {
        $out = [];
        foreach ($this->loadAll('PayrollAdjustment') as $adjustment) {
            if ((string) ($adjustment['status'] ?? '') !== 'applied') {
                continue;
            }

            if ((string) ($adjustment['settlementPeriod'] ?? '') !== $period) {
                continue;
            }

            $employeeId = trim((string) ($adjustment['employeeId'] ?? ''));
            if ($employeeId === '') {
                continue;
            }

            $deltaNet = ($adjustment['deltaNet'] ?? 0);
            $cents    = is_numeric($deltaNet) === true ? (int) round(((float) $deltaNet) * 100) : 0;

            $out[$employeeId] = (($out[$employeeId] ?? 0) + $cents);
        }

        return $out;

    }//end appliedRetroAdjustmentsByEmployeeId()


    /**
     * The retroAdjustment + (adjusted) nettoPay Payslip fields to merge onto
     * the payload (retro-adjustments design.md D4): null/unchanged when no
     * applied adjustment settles this period for this employee -- a normal
     * payslip stays byte-identical to the pre-change shape.
     *
     * @param int $retroAdjustmentCents The summed applied delta for this employee/period, in cents.
     * @param int $nettoPayCents        The engine-computed nettoPay before folding the adjustment, in cents.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-004
     */
    private function retroAdjustmentFields(int $retroAdjustmentCents, int $nettoPayCents): array
    {
        if ($retroAdjustmentCents === 0) {
            return ['retroAdjustment' => null];
        }

        return [
            'retroAdjustment' => $this->euros($retroAdjustmentCents),
            'nettoPay'        => $this->euros($nettoPayCents + $retroAdjustmentCents),
        ];

    }//end retroAdjustmentFields()


    /**
     * Every employee's summed signed settled LeaveTransaction amount (cents)
     * whose settlementPeriod equals this run's period (leave-buy-sell
     * design.md D6) -- the current-run-only folding index. A `sell`
     * contributes a POSITIVE amount (a payment, increases net); a `buy`
     * contributes a NEGATIVE amount (a deduction, decreases net). Draft/
     * submitted/approved/rejected transactions and transactions settling a
     * different period are excluded. Degrades gracefully to an empty map
     * when the LeaveTransaction schema does not exist yet in the register
     * (the retro-adjustments cross-change-ordering precedent).
     *
     * @param string $period Wage period (YYYY-MM).
     *
     * @return array<string, int>
     *
     * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-005
     */
    private function settledLeaveTransactionsByEmployeeId(string $period): array
    {
        $out = [];
        foreach ($this->loadAll('LeaveTransaction') as $transaction) {
            if ((string) ($transaction['status'] ?? '') !== 'settled') {
                continue;
            }

            if ((string) ($transaction['settlementPeriod'] ?? '') !== $period) {
                continue;
            }

            $employeeId = trim((string) ($transaction['employeeId'] ?? ''));
            if ($employeeId === '') {
                continue;
            }

            $settledAmount = ($transaction['settledAmount'] ?? 0);
            $cents         = is_numeric($settledAmount) === true ? (int) round(((float) $settledAmount) * 100) : 0;
            $sign          = ((string) ($transaction['transactionType'] ?? '')) === 'sell' ? 1 : -1;

            $out[$employeeId] = (($out[$employeeId] ?? 0) + ($sign * $cents));
        }

        return $out;

    }//end settledLeaveTransactionsByEmployeeId()


    /**
     * The leaveBuySell + (adjusted) nettoPay Payslip fields to merge onto the
     * payload (leave-buy-sell design.md D6): null/unchanged when no settled
     * transaction folds into this period for this employee -- a normal
     * payslip stays byte-identical to the pre-change shape. `PayrollCalculator`
     * is never invoked to (re)compute this figure -- `settledAmount` is a
     * fact `LeaveBuySellSettlementService` already computed and stored; this
     * merely reads and folds it.
     *
     * @param int $leaveBuySellCents The summed settled amount for this employee/period, in cents.
     * @param int $nettoPayCents     The nettoPay-so-far (engine output, already folded with any retro-adjustment), in cents.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/specs/leave-buy-sell/spec.md#REQ-BUYSELL-005
     */
    private function leaveBuySellFields(int $leaveBuySellCents, int $nettoPayCents): array
    {
        if ($leaveBuySellCents === 0) {
            return ['leaveBuySell' => null];
        }

        return [
            'leaveBuySell' => $this->euros($leaveBuySellCents),
            'nettoPay'     => $this->euros($nettoPayCents + $leaveBuySellCents),
        ];

    }//end leaveBuySellFields()


    /**
     * Every `actief` Loonbeslag, indexed by every employee-reference key
     * (loonbeslag design.md D4, the `contractsByEmployeeKey()`/
     * `openSickCasesByEmployeeKey()` precedent: a Loonbeslag's `employeeId`
     * may be a literal id, slug, or employeeNumber string). Each key maps to
     * the LIST of that employee's `actief` Loonbeslag records; period
     * coverage and the earliest-`effectiveFrom` tie-break are resolved by
     * `activeLoonbeslagFor()`, not here.
     *
     * @return array<string, array<int, array<string, mixed>>>
     *
     * @spec openspec/changes/loonbeslag/specs/loonbeslag/spec.md#REQ-BESLAG-005
     */
    private function activeLoonbeslagenByEmployeeKey(): array
    {
        $out = [];
        foreach ($this->loadAll('Loonbeslag') as $loonbeslag) {
            if ((string) ($loonbeslag['status'] ?? '') !== 'actief') {
                continue;
            }

            $key = trim((string) ($loonbeslag['employeeId'] ?? ''));
            if ($key !== '') {
                $out[$key][] = $loonbeslag;
            }
        }

        return $out;

    }//end activeLoonbeslagenByEmployeeKey()


    /**
     * The employee's one `actief` Loonbeslag covering the period, resolved
     * via the id/slug/employeeNumber keys (the `coveringContract()`/
     * `openSickCaseFor()` precedent), or null when none covers it. When more
     * than one match resolves across the keys (design.md D4's MVP-scope
     * exception, machine-checked separately by `nl-loonbeslag-single-active`),
     * the earliest `effectiveFrom` wins deterministically -- never a silent
     * drop, never a double deduction.
     *
     * @param array<string, mixed>                              $employee                  The Employee.
     * @param array<string, array<int, array<string, mixed>>> $loonbeslagenByEmployeeKey The active-Loonbeslag index.
     * @param string                                             $period                    Wage period (YYYY-MM).
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/loonbeslag/specs/loonbeslag/spec.md#REQ-BESLAG-005
     */
    private function activeLoonbeslagFor(array $employee, array $loonbeslagenByEmployeeKey, string $period): ?array
    {
        $keys = array_filter(
            [
                $this->idOf($employee),
                (string) ($employee['@self']['slug'] ?? ''),
                trim((string) ($employee['employeeNumber'] ?? '')),
            ],
            static fn(string $key): bool => $key !== ''
        );

        $matches = [];
        $seenIds = [];
        foreach ($keys as $key) {
            foreach (($loonbeslagenByEmployeeKey[$key] ?? []) as $loonbeslag) {
                if ($this->coversPeriod((string) ($loonbeslag['effectiveFrom'] ?? ''), (string) ($loonbeslag['effectiveTo'] ?? ''), $period) === false) {
                    continue;
                }

                $id = $this->idOf($loonbeslag);
                if ($id !== '' && isset($seenIds[$id]) === true) {
                    // Already matched via a different key (e.g. both id AND
                    // employeeNumber resolved the same record) -- never
                    // double-count the same Loonbeslag.
                    continue;
                }

                if ($id !== '') {
                    $seenIds[$id] = true;
                }

                $matches[] = $loonbeslag;
            }
        }

        if ($matches === []) {
            return null;
        }

        usort(
            $matches,
            static function (array $a, array $b): int {
                $aFrom = strtotime((string) ($a['effectiveFrom'] ?? ''));
                $bFrom = strtotime((string) ($b['effectiveFrom'] ?? ''));
                return (($aFrom === false ? PHP_INT_MAX : $aFrom) <=> ($bFrom === false ? PHP_INT_MAX : $bFrom));
            }
        );

        return $matches[0];

    }//end activeLoonbeslagFor()


    /**
     * The floor-clamped garnishment deduction (design.md D2, REQ-BESLAG-002):
     * `min(orderedAmount, max(0, nettoPaySoFar - beslagvrijeVoet))`, cents-
     * exact. This is the hard rule by construction -- `max(0, ...)` clamps a
     * would-be-negative headroom to zero (nothing deducted once the employee
     * has no headroom above the voet from other components) and
     * `min(orderedAmount, ...)` never deducts more than the order specifies
     * even when headroom exceeds it, so the result can never push `nettoPay`
     * below `beslagvrijeVoet`.
     *
     * @param array<string, mixed> $loonbeslag         The covering `actief` Loonbeslag.
     * @param int                   $nettoPaySoFarCents nettoPay after retroAdjustment + leaveBuySell are already folded, in cents.
     *
     * @return int The deduction, in cents (0 when there is no headroom).
     *
     * @spec openspec/changes/loonbeslag/specs/loonbeslag/spec.md#REQ-BESLAG-002
     */
    private function loonbeslagDeductionCents(array $loonbeslag, int $nettoPaySoFarCents): int
    {
        $orderedAmount = ($loonbeslag['orderedAmount'] ?? 0);
        $orderedCents  = is_numeric($orderedAmount) === true ? (int) round(((float) $orderedAmount) * 100) : 0;

        $beslagvrijeVoet = ($loonbeslag['beslagvrijeVoet'] ?? 0);
        $voetCents       = is_numeric($beslagvrijeVoet) === true ? (int) round(((float) $beslagvrijeVoet) * 100) : 0;

        return min($orderedCents, max(0, ($nettoPaySoFarCents - $voetCents)));

    }//end loonbeslagDeductionCents()


    /**
     * The loonbeslag + (adjusted) nettoPay Payslip fields to merge onto the
     * payload (design.md D2/D4): both `loonbeslag`/`loonbeslagId` null when no
     * `actief` Loonbeslag covers this period -- a normal payslip stays
     * byte-identical to the pre-change shape. When a Loonbeslag covers the
     * period but the deduction is zero (no headroom), `loonbeslagId` is still
     * stamped (the floor check resolves it -- trivially satisfied since
     * `nettoPay` already sits at or below the voet from other components) but
     * `loonbeslag`/`nettoPay` are left unchanged, matching the
     * present-but-zero-is-null convention `retroAdjustment`/`leaveBuySell`
     * already use.
     *
     * @param array<string, mixed>|null $loonbeslag         The covering `actief` Loonbeslag, or null.
     * @param int                        $deductionCents     The floor-clamped deduction, in cents.
     * @param int                        $nettoPaySoFarCents nettoPay after retroAdjustment + leaveBuySell are already folded, in cents.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/loonbeslag/specs/loonbeslag/spec.md#REQ-BESLAG-002
     * @spec openspec/changes/loonbeslag/specs/loonbeslag/spec.md#REQ-BESLAG-004
     */
    private function loonbeslagFields(?array $loonbeslag, int $deductionCents, int $nettoPaySoFarCents): array
    {
        if ($loonbeslag === null) {
            return ['loonbeslag' => null, 'loonbeslagId' => null];
        }

        if ($deductionCents === 0) {
            return ['loonbeslag' => null, 'loonbeslagId' => $this->idOf($loonbeslag)];
        }

        return [
            'loonbeslag'   => $this->euros($deductionCents),
            'loonbeslagId' => $this->idOf($loonbeslag),
            'nettoPay'     => $this->euros($nettoPaySoFarCents - $deductionCents),
        ];

    }//end loonbeslagFields()


    /**
     * Whether a start/end date range covers the wage period: startDate on or
     * before the period's last day AND endDate null/blank or on/after the
     * period's first day.
     *
     * @param string $startDate ISO start date.
     * @param string $endDate   ISO end date, or ''/null-ish while open.
     * @param string $period    Wage period (YYYY-MM).
     *
     * @return bool
     */
    private function coversPeriod(string $startDate, string $endDate, string $period): bool
    {
        try {
            $periodStart = new \DateTimeImmutable($period.'-01');
        } catch (\Throwable $e) {
            return false;
        }

        $periodEnd = $periodStart->modify('last day of this month');

        $start = strtotime(trim($startDate));
        if ($start === false || $start > $periodEnd->getTimestamp()) {
            return false;
        }

        $endDate = trim($endDate);
        if ($endDate === '') {
            return true;
        }

        $end = strtotime($endDate);
        return $end === false || $end >= $periodStart->getTimestamp();

    }//end coversPeriod()


    /**
     * The contract's Awf tariff for the calculator (`low`/`high`), falling
     * back to the Wab-derived expectation (permanent + written -> low, else
     * high) when the field is absent.
     *
     * @param array<string, mixed> $contract The covering EmploymentContract.
     *
     * @return string `low` or `high`.
     */
    private function awfTariffFor(array $contract): string
    {
        $tariff = trim((string) ($contract['awfTariff'] ?? ''));
        if (in_array($tariff, ['low', 'high'], true) === true) {
            return $tariff;
        }

        $permanent = ((string) ($contract['type'] ?? '') === 'permanent');
        $written   = (($contract['writtenContract'] ?? false) === true);
        return ($permanent === true && $written === true) ? 'low' : 'high';

    }//end awfTariffFor()


    /**
     * A human label for an Employee in outcome reporting.
     *
     * @param array<string, mixed> $employee The Employee.
     *
     * @return string
     */
    private function employeeLabel(array $employee): string
    {
        $name = trim(trim((string) ($employee['firstName'] ?? '')).' '.trim((string) ($employee['lastName'] ?? '')));
        if ($name !== '') {
            return $name;
        }

        $number = trim((string) ($employee['employeeNumber'] ?? ''));
        if ($number !== '') {
            return $number;
        }

        $id = $this->idOf($employee);
        return $id === '' ? 'onbekend' : $id;

    }//end employeeLabel()


    /**
     * Build the base outcome array.
     *
     * @param string $runId            The PayrollRun id ('' when unknown).
     * @param string $period           The wage period.
     * @param string $administrationId The administration.
     * @param string $status           Outcome status (calculated/exists/refused-not-draft/failed).
     * @param string $message          Human-readable outcome message.
     *
     * @return array<string, mixed>
     */
    private function outcome(string $runId, string $period, string $administrationId, string $status, string $message): array
    {
        return [
            'runId'            => ($runId === '' ? null : $runId),
            'period'           => $period,
            'administrationId' => $administrationId,
            'status'           => $status,
            'message'          => $message,
            'computed'         => [],
            'skipped'          => [],
            'totals'           => null,
        ];

    }//end outcome()


    /**
     * Convert integer cents to a euro float rounded to 2 decimals (the
     * register's number fields are euro-denominated).
     *
     * @param int $cents The cents amount.
     *
     * @return float
     */
    private function euros(int $cents): float
    {
        return round(($cents / 100), 2);

    }//end euros()


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
            $rows = $this->objectService()->setRegister($this->register())->setSchema($schema)->findAll(['limit' => self::LIMIT]);
        } catch (\Throwable $e) {
            $this->logger->warning('PayrollRunService: kon '.$schema.' niet laden: '.$e->getMessage());
            return [];
        }

        $out = [];
        foreach ((is_array($rows) === true ? $rows : []) as $row) {
            $out[] = $this->toArray($row);
        }

        return $out;

    }//end loadAll()


    /**
     * Normalise an ObjectService row (entity or array) to an array.
     *
     * @param mixed $row The row.
     *
     * @return array<string, mixed>
     */
    private function toArray(mixed $row): array
    {
        if (is_array($row) === true) {
            return $row;
        }

        if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
            return (array) $row->jsonSerialize();
        }

        return [];

    }//end toArray()


    /**
     * The object id of a row, falling back to `@self.id`.
     *
     * @param array<string, mixed> $row The row.
     *
     * @return string
     */
    private function idOf(array $row): string
    {
        return (string) ($row['id'] ?? $row['@self']['id'] ?? '');

    }//end idOf()


    /**
     * @return mixed The OpenRegister ObjectService.
     */
    private function objectService(): mixed
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');

    }//end objectService()


    /**
     * @return string The configured hrmq register slug.
     */
    private function register(): string
    {
        return $this->settingsService->getRegisterSlug();

    }//end register()


}//end class
