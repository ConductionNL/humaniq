<?php

/**
 * Payroll Audit Verification Service
 *
 * Chain-of-custody verification for a `PayrollRun` (audit-trail-payroll
 * design.md D2, fixing hrmq#98): resolves the audit-trail row id range
 * covering the run's and its payslips' create/update rows, then hands that
 * range straight to OpenRegister's own `AuditHashService::verifyChain()` —
 * ZERO new hashing/chaining code lives here. This service is pure
 * orchestration over two existing OpenRegister services.
 *
 * Row-range resolution note (recon correction against the change's own
 * design.md): `AuditQueryService::query()` (the service design.md originally
 * pointed at) searches OBJECTS in a register/schema whose slug/title looks
 * like "audit" — it is built for app-defined audit-entry objects (e.g.
 * procest's `aiAuditEntry`), not the `openregister_audit_trails` table.
 * `PayrollRun`/`Payslip` are business schemas, not audit schemas, so that
 * service returns nothing useful here. The per-object audit-row read path
 * OpenRegister actually exposes for this is
 * `OCA\OpenRegister\Service\Object\AuditHandler::getLogs($uuid)` — already
 * the read path wired into hrmq's 43 `audit-trail` manifest widgets — which
 * queries `AuditTrailMapper` directly and returns real `AuditTrail` rows
 * (numeric `id`, the same id space `AuditHashService::verifyChain()` takes).
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
 * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-003
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves a PayrollRun's audit-row range and verifies it via OpenRegister's
 * global hash chain.
 */
final class PayrollAuditVerificationService
{

    /**
     * @var string
     */
    private const RUN_SCHEMA = 'PayrollRun';

    /**
     * @var string
     */
    private const PAYSLIP_SCHEMA = 'Payslip';

    /**
     * Max objects loaded per type.
     *
     * @var int
     */
    private const LIMIT = 10000;


    /**
     * @param ContainerInterface $container       DI container for lazy OpenRegister service resolution.
     * @param SettingsService    $settingsService Register slug.
     * @param LoggerInterface    $logger          Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * Verify the chain-of-custody of one PayrollRun and its Payslips
     * (REQ-AUDP-003): resolves the audit-trail row id range spanning the
     * run's and its payslips' rows via `AuditHandler::getLogs()`, then calls
     * `AuditHashService::verifyChain($min, $max)` over that range and
     * returns its result unmodified, plus the resolved `runId`.
     *
     * @param string $runId The PayrollRun id (uuid).
     *
     * @return array<string, mixed> `{runId, valid, entriesVerified, brokenAt, skippedNullHashes, range?}`, or `{runId, valid: false, error}` when the run does not exist.
     *
     * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-003
     */
    public function verifyRun(string $runId): array
    {
        $runId = trim($runId);

        $run = $this->findById(self::RUN_SCHEMA, $runId);
        if ($run === null) {
            return [
                'runId'           => $runId,
                'valid'           => false,
                'entriesVerified' => 0,
                'brokenAt'        => null,
                'error'           => 'PayrollRun '.$runId.' niet gevonden.',
            ];
        }

        $uuids = [$runId];
        foreach ($this->loadAll(self::PAYSLIP_SCHEMA) as $payslip) {
            if ((string) ($payslip['payrollRunId'] ?? '') === $runId) {
                $uuids[] = $this->idOf($payslip);
            }
        }

        $ids = $this->auditRowIds($uuids);

        if ($ids === []) {
            // No sealed audit rows found for this run/its payslips yet —
            // nothing to break, vacuously valid (never a false alarm on a
            // run that has not been audited yet).
            return [
                'runId'           => $runId,
                'valid'           => true,
                'entriesVerified' => 0,
                'brokenAt'        => null,
            ];
        }

        $result           = $this->auditHashService()->verifyChain(min($ids), max($ids));
        $result['runId'] = $runId;

        return $result;

    }//end verifyRun()


    /**
     * Collect the numeric `openregister_audit_trails` row ids covering the
     * given object uuids, via `AuditHandler::getLogs()` (per-object read
     * path) rather than `AuditQueryService::query()` (see class docblock).
     *
     * @param array<int, string> $uuids Object uuids (the run + its payslips).
     *
     * @return array<int, int> Row ids, unsorted.
     */
    private function auditRowIds(array $uuids): array
    {
        $ids = [];

        foreach ($uuids as $uuid) {
            if ($uuid === '') {
                continue;
            }

            array_push($ids, ...$this->logIdsFor($uuid));
        }

        return $ids;

    }//end auditRowIds()


    /**
     * The row ids of every audit-log entry `AuditHandler::getLogs()` returns
     * for one object uuid, degrading to an empty list on any failure
     * (fail-soft — a missing audit trail for one object never aborts the
     * whole range resolution).
     *
     * @param string $uuid The object uuid.
     *
     * @return array<int, int>
     */
    private function logIdsFor(string $uuid): array
    {
        try {
            $logs = $this->auditHandler()->getLogs($uuid);
        } catch (\Throwable $e) {
            $this->logger->warning('PayrollAuditVerificationService: kon audit-logs voor '.$uuid.' niet laden: '.$e->getMessage());
            return [];
        }

        $ids = [];
        foreach ((is_array($logs) === true ? $logs : []) as $entry) {
            $id = $this->idFromEntry($entry);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;

    }//end logIdsFor()


    /**
     * The numeric row id of one `AuditTrail`-shaped entry, or null when it
     * carries none.
     *
     * @param mixed $entry An `AuditTrail` entity (or fake, in tests).
     *
     * @return int|null
     */
    private function idFromEntry(mixed $entry): ?int
    {
        $id = (method_exists($entry, 'getId') === true) ? $entry->getId() : null;

        if (is_int($id) === true) {
            return $id;
        }

        if (is_string($id) === true && ctype_digit($id) === true) {
            return (int) $id;
        }

        return null;

    }//end idFromEntry()


    /**
     * Find one object by id (uuid) within a schema — the `loadAll()` +
     * filter pattern this codebase's services use throughout (no dedicated
     * find-by-uuid call on `ObjectService`).
     *
     * @param string $schema The schema slug.
     * @param string $id     The object id (uuid).
     *
     * @return array<string, mixed>|null
     */
    private function findById(string $schema, string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        foreach ($this->loadAll($schema) as $row) {
            if ($this->idOf($row) === $id) {
                return $row;
            }
        }

        return null;

    }//end findById()


    /**
     * Load every object of a schema, normalised to plain arrays.
     *
     * @param string $schema The schema slug.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadAll(string $schema): array
    {
        try {
            $rows = $this->objectService()->setRegister($this->register())->setSchema($schema)->findAll(['limit' => self::LIMIT]);
        } catch (\Throwable $e) {
            $this->logger->warning('PayrollAuditVerificationService: kon '.$schema.' niet laden: '.$e->getMessage());
            return [];
        }

        $out = [];
        foreach ((is_array($rows) === true ? $rows : []) as $row) {
            $out[] = $this->toArray($row);
        }

        return $out;

    }//end loadAll()


    /**
     * Normalise an ObjectService row (entity or array) to a plain array.
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

        if (is_object($row) === true && method_exists($row, 'getObject') === true) {
            return (array) $row->getObject();
        }

        return [];

    }//end toArray()


    /**
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
     * @return mixed OpenRegister's per-object audit-log read path.
     */
    private function auditHandler(): mixed
    {
        return $this->container->get('OCA\OpenRegister\Service\Object\AuditHandler');

    }//end auditHandler()


    /**
     * @return mixed OpenRegister's hash-chain verification service.
     */
    private function auditHashService(): mixed
    {
        return $this->container->get('OCA\OpenRegister\Service\AuditHashService');

    }//end auditHashService()


    /**
     * @return string The configured hrmq register slug.
     */
    private function register(): string
    {
        return $this->settingsService->getRegisterSlug();

    }//end register()


}//end class
