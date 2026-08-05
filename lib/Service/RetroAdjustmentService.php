<?php

/**
 * Retro Adjustment Service
 *
 * Computes and settles terugwerkende kracht (TWK) corrections for a SEALED
 * prior-period payslip (design.md D1): the original, already-approved/posted/
 * paid Payslip and its PayrollRun are READ ONLY, never passed to
 * `saveObject()`/`deleteObject()`. `adjustFor()` resolves the stored original
 * Payslip for (originalPeriod, employeeId), recomputes an alternative result
 * with the corrected gross salary using the pure `PayrollCalculator` against
 * `TaxTables::load('nl-{originalYear}')` -- the ORIGINAL period's tax year,
 * never the current year (design.md D2, the same-tax-year MVP boundary) --
 * and stores only the cents-exact DIFFERENCE (recomputed - stored) on a new
 * `PayrollAdjustment` object, upserted idempotently keyed on `(originalPeriod,
 * employeeId, correctionRef)` (design.md D3).
 *
 * Adjustments apply only to originals whose PayrollRun is NOT `draft`
 * (design.md D5) -- a still-draft original is recomputed directly via the
 * existing `hrmq:payroll:run --recalculate` engine path; there is nothing for
 * this service to correct.
 *
 * The computed delta settles into the CURRENT open period, never the
 * historical one (design.md D4): `settlementPeriod` defaults to the most
 * recent open draft PayrollRun's period when not given explicitly, and only
 * once `--apply` flips `status: draft -> applied` does
 * `PayrollRunService.generate()` fold the delta into that period's payslip.
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
 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-001
 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-002
 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-003
 * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-005
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use DateTimeImmutable;
use OCA\Hrmq\Payroll\CalculationInput;
use OCA\Hrmq\Payroll\PayrollCalculator;
use OCA\Hrmq\Payroll\TaxTables;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Computes + settles TWK delta corrections against sealed payslips.
 */
class RetroAdjustmentService
{

    /**
     * Max objects loaded per type.
     *
     * @var int
     */
    private const LIMIT = 10000;


    /**
     * @param ContainerInterface $container      DI container for lazy ObjectService resolution.
     * @param SettingsService    $settingsService Register slug + employer-level payroll config.
     * @param PayrollCalculator  $calculator     The pure gross-to-net calculator (reused, never modified).
     * @param LoggerInterface    $logger         Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly SettingsService $settingsService,
        private readonly PayrollCalculator $calculator,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * Compute (and, with `$apply`, settle) a TWK correction -- the occ
     * `hrmq:payroll:adjust` entry point (design.md D3/D4/D5).
     *
     * @param string      $originalPeriod                     Wage period being corrected, `YYYY-MM`.
     * @param string      $employeeId                          The Employee id.
     * @param string      $correctionRef                       Caller-supplied idempotency key.
     * @param float|null  $correctedGrossMonthlySalary         Corrected gross monthly salary (euro); reused from an existing adjustment when omitted.
     * @param string|null $correctionType                      Free-text correction classification.
     * @param string|null $settlementPeriod                    The period to settle into, or null to auto-resolve the open draft run's period.
     * @param bool        $apply                                Whether to flip `status: draft -> applied` and stamp `settlementPayrollRunId`.
     *
     * @return array<string, mixed> Outcome: {adjustmentId, originalPeriod, employeeId, correctionRef, status, message, idempotent, delta, engineVersion, settlementPeriod, settlementLine}.
     *
     * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-001
     * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-002
     * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-003
     * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-005
     */
    public function adjustFor(
        string $originalPeriod,
        string $employeeId,
        string $correctionRef,
        ?float $correctedGrossMonthlySalary=null,
        ?string $correctionType=null,
        ?string $settlementPeriod=null,
        bool $apply=false
    ): array {
        $originalPeriod = trim($originalPeriod);
        $employeeId     = trim($employeeId);
        $correctionRef  = trim($correctionRef);

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $originalPeriod) !== 1) {
            return $this->outcome('', $originalPeriod, $employeeId, $correctionRef, 'failed', 'Ongeldige originele periode "'.$originalPeriod.'" (verwacht JJJJ-MM).');
        }

        if ($employeeId === '' || $correctionRef === '') {
            return $this->outcome('', $originalPeriod, $employeeId, $correctionRef, 'failed', 'employeeId en correctionRef zijn verplicht.');
        }

        // Probe-before-create idempotency (design.md D3): the SAME
        // (originalPeriod, employeeId, correctionRef) always resolves to the
        // SAME PayrollAdjustment.
        $existing   = $this->findAdjustment($originalPeriod, $employeeId, $correctionRef);
        $idempotent = ($existing !== null);

        $grossToUse = $correctedGrossMonthlySalary;
        if ($grossToUse === null && $existing !== null && is_numeric($existing['correctedGrossMonthlySalary'] ?? null) === true) {
            $grossToUse = (float) $existing['correctedGrossMonthlySalary'];
        }

        if ($grossToUse === null || $grossToUse <= 0.0) {
            return $this->outcome($this->idOf($existing ?? []), $originalPeriod, $employeeId, $correctionRef, 'corrected-gross-required', 'Een gecorrigeerd bruto maandsalaris (--gross) is verplicht bij de eerste berekening.');
        }

        $delta = $this->computeDelta($originalPeriod, $employeeId, $grossToUse);
        if ($delta['status'] !== 'ok') {
            return $this->outcome($this->idOf($existing ?? []), $originalPeriod, $employeeId, $correctionRef, $delta['status'], $delta['message']);
        }

        $resolvedSettlementPeriod = $this->resolveSettlementPeriod($settlementPeriod);
        if ($resolvedSettlementPeriod === null) {
            return $this->outcome($this->idOf($existing ?? []), $originalPeriod, $employeeId, $correctionRef, 'settlement-period-required', 'Kon geen concept-loonrun vinden om de correctie in te verwerken -- geef --settlement-period expliciet op.');
        }

        $deltaEuros     = $delta['deltaEuros'];
        $settlementLine = ($deltaEuros['net'] < 0.0) ? 'terugvordering' : 'nabetaling';

        $payload = [
            'employeeId'                   => $employeeId,
            'originalPayrollRunId'         => $delta['originalPayrollRunId'],
            'originalPayslipId'            => $delta['originalPayslipId'],
            'originalPeriod'               => $originalPeriod,
            'correctionType'               => $correctionType ?? ($existing['correctionType'] ?? null),
            'correctedGrossMonthlySalary'  => $grossToUse,
            'correctionRef'                => $correctionRef,
            'deltaGross'                   => $deltaEuros['gross'],
            'deltaLoonheffing'             => $deltaEuros['loonheffing'],
            'deltaNet'                     => $deltaEuros['net'],
            'deltaWerknemersverzekeringen' => $deltaEuros['werknemersverzekeringen'],
            'deltaZvw'                     => $deltaEuros['zvw'],
            'deltaVolksverzekeringen'      => $deltaEuros['volksverzekeringen'],
            'deltaVakantiegeldReserved'    => $deltaEuros['vakantiegeldReserved'],
            'engineVersion'                => $delta['engineVersion'],
            'settlementPeriod'             => $resolvedSettlementPeriod,
            'settlementLine'               => $settlementLine,
            'status'                       => ($existing['status'] ?? 'draft'),
            'settlementPayrollRunId'       => ($existing['settlementPayrollRunId'] ?? null),
            'calculatedAt'                 => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        if ($apply === true) {
            $payload['status']                 = 'applied';
            $payload['settlementPayrollRunId'] = $this->resolveRunForPeriod($resolvedSettlementPeriod, (string) ($delta['administrationId'] ?? ''));
        }

        try {
            $saved = $this->toArray(
                $this->objectService()->saveObject(
                    object: $payload,
                    register: $this->register(),
                    schema: 'PayrollAdjustment',
                    uuid: ($existing !== null ? ($this->idOf($existing) === '' ? null : $this->idOf($existing)) : null),
                    _rbac: false,
                    _multitenancy: false
                )
            );
        } catch (\Throwable $e) {
            $this->logger->error('RetroAdjustmentService: kon PayrollAdjustment niet opslaan: '.$e->getMessage());
            return $this->outcome($this->idOf($existing ?? []), $originalPeriod, $employeeId, $correctionRef, 'failed', 'Opslaan van de correctie is mislukt: '.$e->getMessage());
        }

        $status = ($apply === true) ? 'applied' : 'computed';

        $outcome                     = $this->outcome($this->idOf($saved), $originalPeriod, $employeeId, $correctionRef, $status, ($apply === true ? 'Correctie berekend en toegepast.' : 'Correctie berekend.'));
        $outcome['idempotent']       = $idempotent;
        $outcome['delta']           = $deltaEuros;
        $outcome['engineVersion']   = $delta['engineVersion'];
        $outcome['settlementPeriod'] = $resolvedSettlementPeriod;
        $outcome['settlementLine']  = $settlementLine;

        return $outcome;

    }//end adjustFor()


    /**
     * Recompute one existing PayrollAdjustment in place -- the guarded
     * endpoint's "Herrekenen" entry point (design.md D8). Refuses when the
     * adjustment is already `applied` (design.md D5/REQ-RETRO-007) -- the
     * controller has already RBAC-resolved the adjustment; this re-fetches it
     * unscoped, applies the applied-guard, and delegates to `adjustFor()`
     * with its own stored fields (so the SAME object is updated, never
     * duplicated).
     *
     * @param string $adjustmentId The PayrollAdjustment id.
     *
     * @return array<string, mixed> Outcome (see adjustFor()).
     *
     * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-007
     */
    public function recomputeAdjustment(string $adjustmentId): array
    {
        $adjustment = $this->findAdjustmentById($adjustmentId);
        if ($adjustment === null) {
            return $this->outcome($adjustmentId, '', '', '', 'failed', 'Correctie niet gevonden.');
        }

        if ((string) ($adjustment['status'] ?? '') === 'applied') {
            return $this->outcome(
                $adjustmentId,
                (string) ($adjustment['originalPeriod'] ?? ''),
                (string) ($adjustment['employeeId'] ?? ''),
                (string) ($adjustment['correctionRef'] ?? ''),
                'refused-applied',
                'Correctie is al toegepast -- herrekenen is niet meer mogelijk.'
            );
        }

        $correctedGross = ($adjustment['correctedGrossMonthlySalary'] ?? null);

        return $this->adjustFor(
            (string) ($adjustment['originalPeriod'] ?? ''),
            (string) ($adjustment['employeeId'] ?? ''),
            (string) ($adjustment['correctionRef'] ?? ''),
            (is_numeric($correctedGross) === true ? (float) $correctedGross : null),
            (($adjustment['correctionType'] ?? null) !== null ? (string) $adjustment['correctionType'] : null),
            (string) ($adjustment['settlementPeriod'] ?? ''),
            false
        );

    }//end recomputeAdjustment()


    /**
     * The delta-computation core, shared by `adjustFor()` and
     * `recomputeAdjustment()` (design.md D1/D2): resolves the sealed original
     * Payslip and its PayrollRun (READ ONLY -- never written), refuses a
     * still-draft original (D5), recomputes with the corrected gross against
     * the ORIGINAL period's tax year (D2), and diffs recomputed - stored into
     * cents-exact euro deltas.
     *
     * @param string $originalPeriod              Wage period being corrected, `YYYY-MM`.
     * @param string $employeeId                   The Employee id.
     * @param float  $correctedGrossMonthlySalary  Corrected gross monthly salary (euro).
     *
     * @return array<string, mixed> {status, message, deltaEuros, engineVersion, originalPayrollRunId, originalPayslipId, administrationId}.
     *
     * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-001
     * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-002
     * @spec openspec/changes/retro-adjustments/specs/retro-adjustments/spec.md#REQ-RETRO-005
     */
    private function computeDelta(string $originalPeriod, string $employeeId, float $correctedGrossMonthlySalary): array
    {
        $payslip = $this->resolveOriginalPayslip($originalPeriod, $employeeId);
        if ($payslip === null) {
            return ['status' => 'failed', 'message' => 'Geen gesealde loonstrook gevonden voor deze medewerker/periode.'];
        }

        $runId = trim((string) ($payslip['payrollRunId'] ?? ''));
        $run   = ($runId === '' ? null : $this->findRunById($runId));
        if ($run === null) {
            return ['status' => 'failed', 'message' => 'De loonrun van de originele loonstrook kon niet worden gevonden.'];
        }

        $status = (string) ($run['status'] ?? '');
        if ($status === 'draft') {
            return ['status' => 'refused-original-draft', 'message' => 'De originele loonrun heeft nog status "draft" -- herbereken deze direct via hrmq:payroll:run --recalculate.'];
        }

        $tableId = 'nl-'.substr($originalPeriod, 0, 4);
        try {
            $tables = TaxTables::load($tableId);
        } catch (\Throwable $e) {
            return [
                'status'  => 'historical-tables-missing',
                'message' => 'historical-tables-missing: herrekenen van '.$originalPeriod.' vereist '.$tableId.'.json -- same-tax-year MVP, multi-jaar is een vervolgwijziging (retro-multi-year-tables).',
            ];
        }

        $employee = $this->findEmployeeById($employeeId);
        if ($employee === null) {
            return ['status' => 'failed', 'message' => 'Medewerker niet gevonden.'];
        }

        $taxTableColor = trim((string) ($employee['taxTableColor'] ?? ''));
        if (in_array($taxTableColor, ['wit', 'groen'], true) === false) {
            return ['status' => 'failed', 'message' => 'Medewerker heeft geen geldige NL tabelkleur (wit/groen).'];
        }

        $contract = $this->coveringContract($employee, $originalPeriod);
        if ($contract === null) {
            return ['status' => 'failed', 'message' => 'Geen contract gevonden dat de originele periode dekt.'];
        }

        $aofTariff     = $this->settingsService->getPayrollAofTariff();
        $whkPercentage = $this->settingsService->getPayrollWhkPercentage($tables->werknemersverzekeringen()['whkDefault']);

        $input = new CalculationInput(
            grossMonthlySalaryCents: (int) round($correctedGrossMonthlySalary * 100),
            taxTableColor: $taxTableColor,
            loonheffingskortingToegepast: (($employee['loonheffingskortingToegepast'] ?? true) === true),
            dateOfBirth: (($employee['dateOfBirth'] ?? null) !== null ? (string) $employee['dateOfBirth'] : null),
            period: $originalPeriod,
            awfTariff: $this->awfTariffFor($contract),
            aofTariff: $aofTariff,
            whkPercentage: $whkPercentage
        );

        $result = $this->calculator->calculate($input, $tables);

        $storedCents = [
            'gross'                   => $this->centsOf($payslip, 'grossPay'),
            'loonheffing'             => $this->centsOf($payslip, 'loonheffing'),
            'net'                     => $this->centsOf($payslip, 'nettoPay'),
            'werknemersverzekeringen' => $this->centsOf($payslip, 'werknemersverzekeringen'),
            'zvw'                     => $this->centsOf($payslip, 'zvw'),
            'volksverzekeringen'      => $this->centsOf($payslip, 'volksverzekeringen'),
            'vakantiegeldReserved'    => $this->centsOf($payslip, 'vakantiegeldReserved'),
        ];

        $deltaCents = [
            'gross'                   => ($result->grossPayCents - $storedCents['gross']),
            'loonheffing'             => ($result->loonheffingCents - $storedCents['loonheffing']),
            'net'                     => ($result->nettoPayCents - $storedCents['net']),
            'werknemersverzekeringen' => ($result->werknemersverzekeringenCents - $storedCents['werknemersverzekeringen']),
            'zvw'                     => ($result->zvwCents - $storedCents['zvw']),
            'volksverzekeringen'      => ($result->volksverzekeringenCents - $storedCents['volksverzekeringen']),
            'vakantiegeldReserved'    => ($result->vakantiegeldReservedCents - $storedCents['vakantiegeldReserved']),
        ];

        $deltaEuros = [];
        foreach ($deltaCents as $key => $cents) {
            $deltaEuros[$key] = $this->euros($cents);
        }

        return [
            'status'               => 'ok',
            'message'              => '',
            'deltaEuros'           => $deltaEuros,
            'engineVersion'        => $tables->id(),
            'originalPayrollRunId' => $runId,
            'originalPayslipId'    => $this->idOf($payslip),
            'administrationId'     => (string) ($run['administrationId'] ?? ''),
        ];

    }//end computeDelta()


    /**
     * The stored, sealed engine Payslip for (originalPeriod, employeeId) --
     * READ ONLY (design.md D1). Only engine-produced payslips (non-null
     * `payrollRunId`) are resolved -- hand-entered payslips carry no
     * traceable run to check for sealing.
     *
     * @param string $originalPeriod Wage period, `YYYY-MM`.
     * @param string $employeeId     The Employee id.
     *
     * @return array<string, mixed>|null
     */
    private function resolveOriginalPayslip(string $originalPeriod, string $employeeId): ?array
    {
        foreach ($this->loadAll('Payslip') as $payslip) {
            if ((string) ($payslip['period'] ?? '') !== $originalPeriod) {
                continue;
            }

            if ((string) ($payslip['employeeId'] ?? '') !== $employeeId) {
                continue;
            }

            if (trim((string) ($payslip['payrollRunId'] ?? '')) === '') {
                continue;
            }

            return $payslip;
        }

        return null;

    }//end resolveOriginalPayslip()


    /**
     * The PayrollRun by id, unscoped (design.md D5's draft-status probe).
     *
     * @param string $runId The PayrollRun id.
     *
     * @return array<string, mixed>|null
     */
    private function findRunById(string $runId): ?array
    {
        foreach ($this->loadAll('PayrollRun') as $run) {
            if ($this->idOf($run) === $runId) {
                return $run;
            }
        }

        return null;

    }//end findRunById()


    /**
     * The Employee by id.
     *
     * @param string $employeeId The Employee id.
     *
     * @return array<string, mixed>|null
     */
    private function findEmployeeById(string $employeeId): ?array
    {
        foreach ($this->loadAll('Employee') as $employee) {
            if ($this->idOf($employee) === $employeeId) {
                return $employee;
            }
        }

        return null;

    }//end findEmployeeById()


    /**
     * The employee's contract covering the original period (the
     * PayrollRunService id/slug/employeeNumber resolution precedent).
     *
     * @param array<string, mixed> $employee       The Employee.
     * @param string                $originalPeriod Wage period, `YYYY-MM`.
     *
     * @return array<string, mixed>|null
     */
    private function coveringContract(array $employee, string $originalPeriod): ?array
    {
        $keys = array_filter(
            [
                $this->idOf($employee),
                (string) ($employee['@self']['slug'] ?? ''),
                trim((string) ($employee['employeeNumber'] ?? '')),
            ],
            static fn(string $key): bool => $key !== ''
        );

        foreach ($this->loadAll('EmploymentContract') as $contract) {
            $key = trim((string) ($contract['employeeId'] ?? ''));
            if (in_array($key, $keys, true) === false) {
                continue;
            }

            if ($this->coversPeriod((string) ($contract['startDate'] ?? ''), (string) ($contract['endDate'] ?? ''), $originalPeriod) === true) {
                return $contract;
            }
        }

        return null;

    }//end coveringContract()


    /**
     * Whether a start/end date range covers the wage period (the
     * PayrollRunService precedent).
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
            $periodStart = new DateTimeImmutable($period.'-01');
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
     * The contract's Awf tariff (`low`/`high`), the PayrollRunService
     * Wab-derived fallback precedent.
     *
     * @param array<string, mixed> $contract The covering EmploymentContract.
     *
     * @return string
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
     * The existing PayrollAdjustment for (originalPeriod, employeeId,
     * correctionRef), or null -- the idempotency probe (design.md D3).
     *
     * @param string $originalPeriod Wage period being corrected, `YYYY-MM`.
     * @param string $employeeId     The Employee id.
     * @param string $correctionRef  The caller-supplied idempotency key.
     *
     * @return array<string, mixed>|null
     */
    private function findAdjustment(string $originalPeriod, string $employeeId, string $correctionRef): ?array
    {
        foreach ($this->loadAll('PayrollAdjustment') as $adjustment) {
            if ((string) ($adjustment['originalPeriod'] ?? '') === $originalPeriod
                && (string) ($adjustment['employeeId'] ?? '') === $employeeId
                && (string) ($adjustment['correctionRef'] ?? '') === $correctionRef
            ) {
                return $adjustment;
            }
        }

        return null;

    }//end findAdjustment()


    /**
     * The PayrollAdjustment by id, unscoped -- `recomputeAdjustment()`'s
     * entry resolve (the `PayrollRunService::recalculateRun()` precedent).
     *
     * @param string $adjustmentId The PayrollAdjustment id.
     *
     * @return array<string, mixed>|null
     */
    private function findAdjustmentById(string $adjustmentId): ?array
    {
        foreach ($this->loadAll('PayrollAdjustment') as $adjustment) {
            if ($this->idOf($adjustment) === $adjustmentId) {
                return $adjustment;
            }
        }

        return null;

    }//end findAdjustmentById()


    /**
     * Resolve the settlement period: the given value when non-blank, else
     * the most recent open draft PayrollRun's period (design.md D4's
     * "current open period" default). Null when neither resolves.
     *
     * @param string|null $given The caller-supplied settlement period, or null.
     *
     * @return string|null
     */
    private function resolveSettlementPeriod(?string $given): ?string
    {
        $given = trim((string) $given);
        if ($given !== '') {
            return $given;
        }

        $latest = null;
        foreach ($this->loadAll('PayrollRun') as $run) {
            if ((string) ($run['status'] ?? '') !== 'draft') {
                continue;
            }

            $period = (string) ($run['period'] ?? '');
            if ($period === '') {
                continue;
            }

            if ($latest === null || $period > $latest) {
                $latest = $period;
            }
        }

        return $latest;

    }//end resolveSettlementPeriod()


    /**
     * The PayrollRun of a given (period, administrationId), any status --
     * used only to stamp `settlementPayrollRunId` when applying (design.md
     * D4). Not required to exist.
     *
     * @param string $period           Wage period, `YYYY-MM`.
     * @param string $administrationId The administration.
     *
     * @return string|null
     */
    private function resolveRunForPeriod(string $period, string $administrationId): ?string
    {
        foreach ($this->loadAll('PayrollRun') as $run) {
            if ((string) ($run['period'] ?? '') === $period
                && (string) ($run['administrationId'] ?? '') === $administrationId
            ) {
                return ($this->idOf($run) === '' ? null : $this->idOf($run));
            }
        }

        return null;

    }//end resolveRunForPeriod()


    /**
     * Build the base outcome array.
     *
     * @param string $adjustmentId   The PayrollAdjustment id ('' when unknown).
     * @param string $originalPeriod The wage period being corrected.
     * @param string $employeeId     The Employee id.
     * @param string $correctionRef  The idempotency key.
     * @param string $status         Outcome status.
     * @param string $message        Human-readable outcome message.
     *
     * @return array<string, mixed>
     */
    private function outcome(string $adjustmentId, string $originalPeriod, string $employeeId, string $correctionRef, string $status, string $message): array
    {
        return [
            'adjustmentId'     => ($adjustmentId === '' ? null : $adjustmentId),
            'originalPeriod'   => $originalPeriod,
            'employeeId'       => $employeeId,
            'correctionRef'    => $correctionRef,
            'status'           => $status,
            'message'          => $message,
            'idempotent'       => false,
            'delta'            => null,
            'engineVersion'    => null,
            'settlementPeriod' => null,
            'settlementLine'   => null,
        ];

    }//end outcome()


    /**
     * A field's value converted to integer cents (round-half-away-from-zero),
     * defensively 0 for a missing/non-numeric value.
     *
     * @param array<string, mixed> $o   Object.
     * @param string                $key Field.
     *
     * @return int
     */
    private function centsOf(array $o, string $key): int
    {
        $value = ($o[$key] ?? null);
        return is_numeric($value) === true ? (int) round(((float) $value) * 100) : 0;

    }//end centsOf()


    /**
     * Convert integer cents to a euro float rounded to 2 decimals.
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
     * Load all objects of a schema (capped), as plain arrays. Degrades
     * gracefully to an empty list when the schema does not exist yet in the
     * register.
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
            $this->logger->warning('RetroAdjustmentService: kon '.$schema.' niet laden: '.$e->getMessage());
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
