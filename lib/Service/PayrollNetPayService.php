<?php

/**
 * Payroll Net Pay Service
 *
 * Turns one payable PayrollRun (status approved/posted, design.md D4) into a
 * draft shillinq `PaymentRun` -- one SEPA credit-transfer line per payslip,
 * netto-loon to each employee's IBAN -- via OpenRegister's ObjectService, same
 * instance, never HTTP. hrmq holds no SEPA/bank-file machinery of its own
 * (design.md D1): the only artefact this service writes on the hrmq side is a
 * `PayrollPaymentBatch` record logging the handoff; the payment batch itself
 * is created as a shillinq `PaymentRun`, and everything from approval onward
 * (RBAC `controller` approve gate, pain.001 generation, export, CAMT.053
 * reconciliation) stays behind shillinq's own lifecycle.
 *
 * Availability is duck-typed (ADR-046 philosophy, mirroring
 * `PayrollGLPostService`): when shillinq is not installed, or its register/
 * schema cannot be resolved, the attempt is recorded `skipped-no-shillinq` and
 * the referenced PayrollRun stays payable so a later `occ hrmq:netpay:run`
 * retries (design.md D7). hrmq carries zero composer/info.xml dependency on
 * shillinq.
 *
 * Line collection is fail-closed (design.md D2): any payslip whose employee
 * cannot be resolved, whose IBAN is missing, or whose nettoPay is missing/
 * non-numeric/negative fails the WHOLE batch -- no partial batch ships.
 *
 * Idempotency is enforced in two layers (design.md D6): at most one
 * PayrollPaymentBatch in `{pending, created}` per run (service pre-check), and
 * the deterministic `runNumber` (`HRMQ-NETPAY-{period}-{administrationId}`) is
 * probed in shillinq before creating, so a crash between create and record
 * update adopts the existing PaymentRun instead of double-creating.
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
 * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use DateTimeImmutable;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Collects payslip net-pay lines and hands them off to shillinq as a draft
 * SEPA PaymentRun, one per payable PayrollRun.
 */
class PayrollNetPayService
{


    /**
     * The app id shillinq registers under (IAppManager::isInstalled probe).
     *
     * @var string
     */
    private const SHILLINQ_APP_ID = 'shillinq';

    /**
     * The shillinq register/schema the payment run is written to.
     *
     * @var string
     */
    private const SHILLINQ_REGISTER = 'shillinq';

    /**
     * @var string
     */
    private const SHILLINQ_SCHEMA = 'PaymentRun';

    /**
     * This app's own net-pay batch schema.
     *
     * @var string
     */
    private const BATCH_SCHEMA = 'PayrollPaymentBatch';

    /**
     * PayrollRun statuses that make a run payable (design.md D4): GL posting
     * and net pay are order-independent leaves.
     *
     * @var string[]
     */
    private const PAYABLE_STATUSES = ['approved', 'posted'];

    /**
     * PayrollPaymentBatch statuses that count as "active" for the
     * at-most-one-per-run invariant (design.md D6).
     *
     * @var string[]
     */
    private const ACTIVE_STATUSES = ['pending', 'created'];


    /**
     * @param ContainerInterface $container       DI container for lazy ObjectService resolution.
     * @param IAppManager        $appManager      To duck-type-probe shillinq's presence.
     * @param SettingsService    $settingsService Register slug + net-pay config.
     * @param LoggerInterface    $logger          Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly IAppManager $appManager,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * Process every payable PayrollRun (optionally period-filtered) -- the
     * MVP trigger's entry point.
     *
     * @param string|null $period Only process runs for this wage period (YYYY-MM), or null for all.
     *
     * @return array<int, array<string, mixed>> One outcome array per selected run.
     *
     * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-007
     */
    public function processPayableRuns(?string $period=null): array
    {
        $results = [];
        foreach ($this->payableRuns($period) as $run) {
            $results[] = $this->processRun($run);
        }

        return $results;

    }//end processPayableRuns()


    /**
     * Process a single PayrollRun: idempotency pre-check, duck-typed
     * availability probe, fail-closed line collection, shillinq PaymentRun
     * creation (or adoption via the runNumber probe). hrmq writes NOTHING
     * back to the PayrollRun (design.md D4).
     *
     * @param array<string, mixed> $run The PayrollRun object.
     *
     * @return array<string, mixed> Outcome: {runId, status, message, batchId, paymentRunId}.
     *
     * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-003
     * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-004
     * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-005
     * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-006
     */
    public function processRun(array $run): array
    {
        $runId            = $this->idOf($run);
        $period           = trim((string) ($run['period'] ?? ''));
        $administrationId = trim((string) ($run['administrationId'] ?? ''));

        if ($runId === '' || $period === '' || $administrationId === '') {
            return $this->outcome($runId, 'failed', 'PayrollRun mist id/period/administrationId; kan niet worden verwerkt.');
        }

        $runNumber = sprintf('HRMQ-NETPAY-%s-%s', $period, $administrationId);

        $active = $this->activeBatchForRun($runId);
        if ($active !== null && (string) ($active['status'] ?? '') === 'created') {
            return $this->outcome($runId, 'created', 'Al klaargezet in shillinq (idempotent no-op).', $active, (string) ($active['shillinqPaymentRunRef'] ?? null));
        }

        if ($active !== null && (string) ($active['status'] ?? '') === 'pending') {
            $recovered = $this->recoverStalePending($active, $runId, $runNumber);
            if ($recovered !== null) {
                return $recovered;
            }
            // Stale pending record marked failed (superseded); fall through to a fresh attempt.
        }

        if ($this->shillinqAvailable() === false) {
            $batch = $this->createBatch(
                [
                    'payrollRunId' => $runId,
                    'period'       => $period,
                    'status'       => 'skipped-no-shillinq',
                    'errorMessage' => 'Shillinq is niet geïnstalleerd of het PaymentRun-register is niet beschikbaar; de loonrun blijft betaalbaar voor een latere poging.',
                ]
            );

            return $this->outcome($runId, 'skipped-no-shillinq', (string) ($batch['errorMessage'] ?? ''), $batch);
        }

        $collected = $this->collectLines($run);
        if ($collected['error'] !== null) {
            $batch = $this->createBatch(
                [
                    'payrollRunId' => $runId,
                    'period'       => $period,
                    'status'       => 'failed',
                    'errorMessage' => $collected['error'],
                ]
            );

            return $this->outcome($runId, 'failed', (string) $collected['error'], $batch);
        }

        try {
            $paymentRunId = $this->createOrAdoptPaymentRun($runNumber, $period, $administrationId, $collected['lines'], (float) $collected['totalAmount']);
        } catch (\Throwable $e) {
            $batch = $this->createBatch(
                [
                    'payrollRunId' => $runId,
                    'period'       => $period,
                    'status'       => 'failed',
                    'errorMessage' => 'Aanmaken van de shillinq PaymentRun is mislukt: '.$e->getMessage(),
                ]
            );

            return $this->outcome($runId, 'failed', (string) $batch['errorMessage'], $batch);
        }

        $batch = $this->createBatch(
            [
                'payrollRunId'          => $runId,
                'period'                => $period,
                'status'                => 'created',
                'shillinqPaymentRunRef' => $paymentRunId,
                'runNumber'             => $runNumber,
                'totalAmount'           => $collected['totalAmount'],
                'lineCount'             => $collected['lineCount'],
                'createdAt'             => gmdate('Y-m-d\TH:i:s\Z'),
            ]
        );

        return $this->outcome($runId, 'created', 'Klaargezet in shillinq als PaymentRun '.$runNumber.'.', $batch, $paymentRunId);

    }//end processRun()


    /**
     * Collect one payment line per payable payslip in the run's period,
     * fail-closed on any line error (design.md D2). Pure/side-effect-free
     * (reads only), directly unit-testable with a mocked ObjectService.
     *
     * @param array<string, mixed> $run The PayrollRun object.
     *
     * @return array<string, mixed> {lines, error, totalAmount, lineCount}.
     *
     * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-003
     */
    public function collectLines(array $run): array
    {
        $period = trim((string) ($run['period'] ?? ''));

        [$byId, $bySlug, $byNumber] = $this->employeeIndex();

        $lines       = [];
        $errors      = [];
        $totalCents  = 0;
        $anyPayslips = false;

        foreach ($this->payslipsForPeriod($period) as $payslip) {
            $anyPayslips = true;
            $payslipId   = $this->idOf($payslip);

            $nettoPay = ($payslip['nettoPay'] ?? null);
            if (is_numeric($nettoPay) === false) {
                $errors[] = sprintf('Loonstrook %s: nettoPay ontbreekt of is niet numeriek.', $this->label($payslipId));
                continue;
            }

            $amount = (float) $nettoPay;
            if ($amount < 0.0) {
                $errors[] = sprintf('Loonstrook %s: nettoPay is negatief.', $this->label($payslipId));
                continue;
            }

            if ($amount === 0.0) {
                // Zero-nettoPay payslips are excluded, not an error (design.md D2).
                continue;
            }

            $employeeKey = trim((string) ($payslip['employeeId'] ?? ''));
            $employee    = $this->resolveEmployee($employeeKey, $byId, $bySlug, $byNumber);
            if ($employee === null) {
                $errors[] = sprintf('Loonstrook %s: werknemer "%s" niet gevonden.', $this->label($payslipId), $employeeKey);
                continue;
            }

            $iban = trim((string) ($employee['iban'] ?? ''));
            if ($iban === '') {
                $errors[] = sprintf(
                    'Loonstrook %s: werknemer %s heeft geen IBAN.',
                    $this->label($payslipId),
                    $this->employeeLabel($employee)
                );
                continue;
            }

            $cents       = (int) round($amount * 100);
            $totalCents += $cents;

            $lines[] = [
                'payeeId'         => $this->idOf($employee),
                'payeeName'       => $this->payeeName($employee),
                'creditorIban'    => $iban,
                'amount'          => round(($cents / 100), 2),
                'remittanceInfo'  => 'Salaris '.$period,
                'apTransactionRef' => $payslipId,
            ];
        }//end foreach

        if ($errors !== []) {
            return $this->collectFailure(implode(' ', $errors));
        }

        if ($lines === []) {
            $message = $anyPayslips === true
                ? sprintf('Geen te betalen loonstroken voor periode %s (alle bedragen zijn nul).', $period)
                : sprintf('Geen loonstroken gevonden voor periode %s.', $period);

            return $this->collectFailure($message);
        }

        return [
            'lines'       => $lines,
            'error'       => null,
            'totalAmount' => round(($totalCents / 100), 2),
            'lineCount'   => count($lines),
        ];

    }//end collectLines()


    /**
     * Build a `failed` collectLines() result shape.
     *
     * @param string $message The diagnostic error message.
     *
     * @return array<string, mixed>
     */
    private function collectFailure(string $message): array
    {
        return [
            'lines'       => [],
            'error'       => $message,
            'totalAmount' => null,
            'lineCount'   => null,
        ];

    }//end collectFailure()


    /**
     * Resolve a stale `pending` PayrollPaymentBatch via the deterministic
     * runNumber probe (design.md D6, crash-recovery): a prior invocation
     * crashed after marking `pending` but before recording the outcome. If
     * shillinq already carries a PaymentRun for this runNumber, adopt it as
     * `created`; otherwise mark the stale record `failed` (superseded) and
     * return null so the caller starts a fresh attempt.
     *
     * @param array<string, mixed> $stalePending The stale `pending` PayrollPaymentBatch.
     * @param string               $runId        The PayrollRun id being processed.
     * @param string               $runNumber    The deterministic idempotency key.
     *
     * @return array<string, mixed>|null The outcome when adopted, or null to retry fresh.
     *
     * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-006
     */
    private function recoverStalePending(array $stalePending, string $runId, string $runNumber): ?array
    {
        if ($this->shillinqAvailable() === false) {
            return null;
        }

        $adopted = $this->findPaymentRunByNumber($runNumber);
        if ($adopted === null) {
            $this->saveBatch($stalePending, ['status' => 'failed', 'errorMessage' => 'Verlopen pending-poging vervangen; geen bijbehorende shillinq PaymentRun gevonden.']);
            return null;
        }

        $paymentRunId = $this->idOf($adopted);
        $batch        = $this->saveBatch(
            $stalePending,
            [
                'status'                => 'created',
                'shillinqPaymentRunRef' => $paymentRunId,
                'runNumber'             => $runNumber,
                'createdAt'             => gmdate('Y-m-d\TH:i:s\Z'),
                'errorMessage'          => null,
            ]
        );

        return $this->outcome($runId, 'created', 'Bestaande shillinq PaymentRun overgenomen (crash-recovery).', $batch, $paymentRunId);

    }//end recoverStalePending()


    /**
     * Find an existing shillinq PaymentRun by runNumber (idempotency probe,
     * design.md D6), or create it when none exists yet.
     *
     * @param string            $runNumber        Deterministic idempotency key.
     * @param string            $period           Wage period (YYYY-MM).
     * @param string            $administrationId Administration/employer id, passed through verbatim.
     * @param array<int, mixed> $lines            The collected payment lines.
     * @param float             $totalAmount      Sum of the line amounts.
     *
     * @return string The shillinq PaymentRun id (adopted or newly created).
     *
     * @spec openspec/changes/payroll-sepa-netpay-shillinq/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-004
     */
    private function createOrAdoptPaymentRun(string $runNumber, string $period, string $administrationId, array $lines, float $totalAmount): string
    {
        $adopted = $this->findPaymentRunByNumber($runNumber);
        if ($adopted !== null) {
            return $this->idOf($adopted);
        }

        $payload = [
            'runNumber'        => $runNumber,
            'administrationId' => $administrationId,
            'executionDate'    => $this->executionDate($period),
            'status'           => 'draft',
            'lifecycleState'   => 'draft',
            'currency'         => 'EUR',
            'totalAmount'      => $totalAmount,
            'paymentLines'     => $lines,
        ];

        $debtorIban = trim($this->settingsService->getNetPayDebtorIban());
        if ($debtorIban !== '') {
            $payload['debtorAccountIban'] = $debtorIban;
        }

        $created = $this->objectService()->saveObject(
            object: $payload,
            register: self::SHILLINQ_REGISTER,
            schema: self::SHILLINQ_SCHEMA,
            _rbac: false,
            _multitenancy: false
        );

        return $this->idOf($this->toArray($created));

    }//end createOrAdoptPaymentRun()


    /**
     * The shillinq PaymentRun `executionDate`: the configured
     * `netpay_execution_day` of the wage-period month, clamped to the
     * period's last day (design.md D5, the customary Dutch salary date).
     *
     * @param string $period Wage period in YYYY-MM.
     *
     * @return string The execution date (Y-m-d).
     */
    private function executionDate(string $period): string
    {
        try {
            $date = new DateTimeImmutable($period.'-01');
        } catch (\Throwable $e) {
            // Defensive fallback; upstream schema validation guards the YYYY-MM shape.
            return $period.'-25';
        }

        $lastDay = (int) $date->format('t');
        $day     = min($this->settingsService->getNetPayExecutionDay(), $lastDay);

        $execution = $date->setDate((int) $date->format('Y'), (int) $date->format('m'), $day);
        return $execution->format('Y-m-d');

    }//end executionDate()


    /**
     * Duck-typed shillinq availability probe (design.md D7): shillinq must be
     * installed AND its PaymentRun register/schema must resolve.
     *
     * @return bool
     */
    private function shillinqAvailable(): bool
    {
        if ($this->appManager->isInstalled(self::SHILLINQ_APP_ID) === false) {
            return false;
        }

        try {
            $this->objectService()->setRegister(self::SHILLINQ_REGISTER)->setSchema(self::SHILLINQ_SCHEMA)->findAll(['limit' => 1]);
        } catch (\Throwable $e) {
            return false;
        }

        return true;

    }//end shillinqAvailable()


    /**
     * Search shillinq for an existing PaymentRun with the given runNumber
     * (the idempotency probe, design.md D6). Never throws -- a lookup failure
     * is treated as "not found" by the caller's fall-through logic.
     *
     * @param string $runNumber The deterministic idempotency key.
     *
     * @return array<string, mixed>|null
     */
    private function findPaymentRunByNumber(string $runNumber): ?array
    {
        try {
            $rows = $this->objectService()->setRegister(self::SHILLINQ_REGISTER)->setSchema(self::SHILLINQ_SCHEMA)->findAll(['limit' => 10000]);
        } catch (\Throwable $e) {
            $this->logger->warning('PayrollNetPayService: kon shillinq PaymentRun niet doorzoeken: '.$e->getMessage());
            return null;
        }

        foreach ($this->normaliseRows($rows) as $row) {
            if ((string) ($row['runNumber'] ?? '') === $runNumber) {
                return $row;
            }
        }

        return null;

    }//end findPaymentRunByNumber()


    /**
     * The PayrollPaymentBatch rows referencing a given PayrollRun.
     *
     * @param string $runId The PayrollRun id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function batchesForRun(string $runId): array
    {
        try {
            $rows = $this->objectService()->setRegister($this->register())->setSchema(self::BATCH_SCHEMA)->findAll(['limit' => 10000]);
        } catch (\Throwable $e) {
            $this->logger->warning('PayrollNetPayService: kon PayrollPaymentBatch niet laden: '.$e->getMessage());
            return [];
        }

        $out = [];
        foreach ($this->normaliseRows($rows) as $row) {
            if ((string) ($row['payrollRunId'] ?? '') === $runId) {
                $out[] = $row;
            }
        }

        return $out;

    }//end batchesForRun()


    /**
     * The active (pending/created) PayrollPaymentBatch for a run, if any --
     * the at-most-one-per-run invariant (design.md D6).
     *
     * @param string $runId The PayrollRun id.
     *
     * @return array<string, mixed>|null
     */
    private function activeBatchForRun(string $runId): ?array
    {
        foreach ($this->batchesForRun($runId) as $row) {
            if (in_array((string) ($row['status'] ?? ''), self::ACTIVE_STATUSES, true) === true) {
                return $row;
            }
        }

        return null;

    }//end activeBatchForRun()


    /**
     * Create a new PayrollPaymentBatch.
     *
     * @param array<string, mixed> $fields The object fields.
     *
     * @return array<string, mixed> The created object, normalised to an array.
     */
    private function createBatch(array $fields): array
    {
        $created = $this->objectService()->saveObject(
            object: $fields,
            register: $this->register(),
            schema: self::BATCH_SCHEMA,
            _rbac: false,
            _multitenancy: false
        );

        return $this->toArray($created);

    }//end createBatch()


    /**
     * Update an existing PayrollPaymentBatch by merging $fields onto it.
     *
     * @param array<string, mixed> $existing The current PayrollPaymentBatch.
     * @param array<string, mixed> $fields   The fields to overwrite.
     *
     * @return array<string, mixed> The saved object, normalised to an array.
     */
    private function saveBatch(array $existing, array $fields): array
    {
        $id = $this->idOf($existing);

        $payload = array_merge($existing, $fields);
        unset($payload['@self']);

        $saved = $this->objectService()->saveObject(
            object: $payload,
            register: $this->register(),
            schema: self::BATCH_SCHEMA,
            uuid: ($id === '' ? null : $id),
            _rbac: false,
            _multitenancy: false
        );

        return $this->toArray($saved);

    }//end saveBatch()


    /**
     * The payable PayrollRun objects (design.md D4: status approved/posted,
     * optionally period-filtered).
     *
     * @param string|null $period Only runs for this wage period (YYYY-MM), or null.
     *
     * @return array<int, array<string, mixed>>
     */
    private function payableRuns(?string $period): array
    {
        try {
            $rows = $this->objectService()->setRegister($this->register())->setSchema('PayrollRun')->findAll(['limit' => 10000]);
        } catch (\Throwable $e) {
            $this->logger->warning('PayrollNetPayService: kon PayrollRun niet laden: '.$e->getMessage());
            return [];
        }

        $out = [];
        foreach ($this->normaliseRows($rows) as $run) {
            if (in_array((string) ($run['status'] ?? ''), self::PAYABLE_STATUSES, true) === false) {
                continue;
            }

            if ($period !== null && $period !== '' && (string) ($run['period'] ?? '') !== $period) {
                continue;
            }

            $out[] = $run;
        }

        return $out;

    }//end payableRuns()


    /**
     * The Payslip objects for a given wage period.
     *
     * @param string $period Wage period (YYYY-MM).
     *
     * @return array<int, array<string, mixed>>
     */
    private function payslipsForPeriod(string $period): array
    {
        try {
            $rows = $this->objectService()->setRegister($this->register())->setSchema('Payslip')->findAll(['limit' => 10000]);
        } catch (\Throwable $e) {
            $this->logger->warning('PayrollNetPayService: kon Payslip niet laden: '.$e->getMessage());
            return [];
        }

        $out = [];
        foreach ($this->normaliseRows($rows) as $payslip) {
            if ((string) ($payslip['period'] ?? '') === $period) {
                $out[] = $payslip;
            }
        }

        return $out;

    }//end payslipsForPeriod()


    /**
     * Build the Employee resolution indexes -- by object id, by slug, and by
     * employeeNumber -- covering both seed conventions (design.md Context).
     *
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>, 2: array<string, array<string, mixed>>}
     */
    private function employeeIndex(): array
    {
        try {
            $rows = $this->objectService()->setRegister($this->register())->setSchema('Employee')->findAll(['limit' => 10000]);
        } catch (\Throwable $e) {
            $this->logger->warning('PayrollNetPayService: kon Employee niet laden: '.$e->getMessage());
            return [[], [], []];
        }

        $byId     = [];
        $bySlug   = [];
        $byNumber = [];
        foreach ($this->normaliseRows($rows) as $employee) {
            $id = $this->idOf($employee);
            if ($id !== '') {
                $byId[$id] = $employee;
            }

            $slug = $this->slugOf($employee);
            if ($slug !== '') {
                $bySlug[$slug] = $employee;
            }

            $number = trim((string) ($employee['employeeNumber'] ?? ''));
            if ($number !== '') {
                $byNumber[$number] = $employee;
            }
        }

        return [$byId, $bySlug, $byNumber];

    }//end employeeIndex()


    /**
     * Resolve a payslip's employeeId reference against the id/slug/
     * employeeNumber indexes, in that order (design.md D2).
     *
     * @param string                              $key      The payslip's employeeId value.
     * @param array<string, array<string, mixed>> $byId     Employees keyed by object id.
     * @param array<string, array<string, mixed>> $bySlug   Employees keyed by slug.
     * @param array<string, array<string, mixed>> $byNumber Employees keyed by employeeNumber.
     *
     * @return array<string, mixed>|null
     */
    private function resolveEmployee(string $key, array $byId, array $bySlug, array $byNumber): ?array
    {
        if ($key === '') {
            return null;
        }

        return $byId[$key] ?? $bySlug[$key] ?? $byNumber[$key] ?? null;

    }//end resolveEmployee()


    /**
     * The name printed as Cdtr/Nm on the SEPA credit transfer: `tenaamstelling`
     * when set, else `firstName + " " + lastName` (design.md D2).
     *
     * @param array<string, mixed> $employee The resolved Employee.
     *
     * @return string
     */
    private function payeeName(array $employee): string
    {
        $tenaamstelling = trim((string) ($employee['tenaamstelling'] ?? ''));
        if ($tenaamstelling !== '') {
            return $tenaamstelling;
        }

        $name = trim(trim((string) ($employee['firstName'] ?? '')).' '.trim((string) ($employee['lastName'] ?? '')));
        return $name;

    }//end payeeName()


    /**
     * A human label for an Employee in diagnostics: tenaamstelling/name when
     * resolvable, else the raw key.
     *
     * @param array<string, mixed> $employee The resolved Employee.
     *
     * @return string
     */
    private function employeeLabel(array $employee): string
    {
        $name = $this->payeeName($employee);
        return $name !== '' ? $name : $this->label($this->idOf($employee));

    }//end employeeLabel()


    /**
     * A human label for an id used in diagnostics ("onbekend" when empty).
     *
     * @param string $id The id.
     *
     * @return string
     */
    private function label(string $id): string
    {
        return $id === '' ? 'onbekend' : $id;

    }//end label()


    /**
     * Build the outcome array returned by processRun()/processPayableRuns().
     *
     * @param string                     $runId        The PayrollRun id.
     * @param string                     $status       The outcome status.
     * @param string                     $message      A human-readable outcome message.
     * @param array<string, mixed>|null $batch        The PayrollPaymentBatch record, if any.
     * @param string|null                $paymentRunId The shillinq PaymentRun id, if any.
     *
     * @return array<string, mixed>
     */
    private function outcome(string $runId, string $status, string $message, ?array $batch=null, ?string $paymentRunId=null): array
    {
        $batchId = null;
        if ($batch !== null) {
            $batchId = $this->idOf($batch);
            $batchId = ($batchId === '' ? null : $batchId);
        }

        return [
            'runId'        => $runId,
            'status'       => $status,
            'message'      => $message,
            'batchId'      => $batchId,
            'paymentRunId' => ($paymentRunId === '' ? null : $paymentRunId),
        ];

    }//end outcome()


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
            $out[] = $this->toArray($row);
        }

        return $out;

    }//end normaliseRows()


    /**
     * Normalise a single ObjectService row (entity or array) to an array.
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
     * The object id of a row (entity or array), falling back to `@self.id`.
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
     * The slug of a row (entity or array), read from `@self.slug`.
     *
     * @param array<string, mixed> $row The row.
     *
     * @return string
     */
    private function slugOf(array $row): string
    {
        return (string) ($row['@self']['slug'] ?? $row['slug'] ?? '');

    }//end slugOf()


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
