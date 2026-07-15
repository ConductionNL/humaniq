<?php

/**
 * WKR (Werkkostenregeling) Assessment Service
 *
 * Rolls the cross-object fiscale loonsom (Σ Payslip.grossPay) and the
 * administration's WkrDeclarations into an idempotent, per-(administrationId,
 * year) `WkrAssessment` (wkr-administration design.md D5): reads the SAME
 * aggregate `RuleAuditService::buildWkrContext()` builds (its own `loadAll`
 * pass over Payslip/PayrollRun/WkrDeclaration — same numbers, same year
 * derivation, same administration resolution), computes the available vrije
 * ruimte / used / excess / eindheffingDue / status via
 * `NlWkrChecks::availableVrijeRuimteCents()` (the single source of the tranche
 * arithmetic both the check and this service share, so they can never
 * disagree), and upserts the `WkrAssessment` keyed on (administrationId, year)
 * through OpenRegister's ObjectService — the `PayrollMutationService::persist()`
 * probe-then-upsert idiom.
 *
 * This service is PURE and read-only over Payslip/PayrollRun/WkrDeclaration:
 * it never constructs `PayrollCalculator`, never re-derives a payslip's
 * withholding, and never writes anything except the one idempotent
 * `WkrAssessment` per (administrationId, year) — the fiscale loonsom is Σ
 * already-persisted `grossPay`, so the assessment cannot drift from the
 * payroll engine (design.md D5, the PayrollMutationService "reads and
 * subtracts, never recomputes" discipline). All monetary arithmetic is
 * integer cents; euros only at the read/write boundary (the
 * PayrollMutationService cents<->euros convention).
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
 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-003
 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-005
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

use OCA\Hrmq\Standards\Checks\NlWkrChecks;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Cross-object loonsom -> vrije-ruimte roll-up, upserted as an idempotent
 * WkrAssessment per (administrationId, year).
 */
class WkrService
{

    /**
     * Max objects loaded per type.
     *
     * @var int
     */
    private const LIMIT = 10000;

    /**
     * The tables id the assessment stamps as `engineVersion` — the only NL
     * table shipped today (the `NlWkrChecks::tables()` precedent).
     *
     * @var string
     */
    private const ENGINE_VERSION = 'nl-2026';


    /**
     * @param ContainerInterface $container       DI container for lazy ObjectService resolution.
     * @param SettingsService    $settingsService Register slug source.
     * @param LoggerInterface    $logger          Logger.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {

    }//end __construct()


    /**
     * Compute and idempotently upsert the WkrAssessment for one
     * (administrationId, year) pair (spec.md REQ-WKR-003). Reads the
     * cross-object fiscale loonsom + vrije-ruimte-used aggregate, computes the
     * available vrije ruimte / excess / eindheffingDue / status via
     * `NlWkrChecks`'s shared tranche arithmetic, and upserts the persisted
     * WkrAssessment keyed on (administrationId, year) — recomputing for the
     * same pair updates the existing record in place, never duplicates.
     *
     * @param string $administrationId The administration to assess.
     * @param int    $year             The fiscal year to assess.
     *
     * @return array<string, mixed> Outcome: {status, message, assessment}.
     *
     * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-003
     */
    public function assess(string $administrationId, int $year): array
    {
        $administrationId = trim($administrationId);
        if ($administrationId === '') {
            return $this->outcome('failed', 'administrationId is verplicht.');
        }

        if ($year <= 0) {
            return $this->outcome('failed', 'year is verplicht en moet een positief jaartal zijn.');
        }

        $aggregate = ($this->buildAggregate()[$administrationId][$year] ?? [
            'loonsom'             => 0.0,
            'payslipWkrUsed'      => 0.0,
            'vrijeRuimteDeclared' => 0.0,
            'eindheffingDeclared' => 0.0,
        ]);

        $loonsomCents = self::cents($aggregate['loonsom']);
        $usedCents    = (self::cents($aggregate['payslipWkrUsed']) + self::cents($aggregate['vrijeRuimteDeclared']));

        $availableCents = NlWkrChecks::availableVrijeRuimteCents($loonsomCents);
        $excessCents    = max(0, ($usedCents - $availableCents));
        $eindheffingCents = (int) round($excessCents * (NlWkrChecks::eindheffingPercent() / 100));
        $remainingCents = max(0, ($availableCents - $usedCents));
        $status         = ($excessCents > 0) ? 'eindheffing-verschuldigd' : 'binnen-vrije-ruimte';

        $payload = [
            'administrationId'    => $administrationId,
            'year'                => $year,
            'fiscaleLoonsom'      => self::euros($loonsomCents),
            'vrijeRuimte'         => self::euros($availableCents),
            'vrijeRuimteUsed'     => self::euros($usedCents),
            'vrijeRuimteRemaining' => self::euros($remainingCents),
            'excess'              => self::euros($excessCents),
            'eindheffingRate'     => ($excessCents > 0) ? NlWkrChecks::eindheffingPercent() : null,
            'eindheffingDue'      => self::euros($eindheffingCents),
            'status'              => $status,
            'engineVersion'       => self::ENGINE_VERSION,
            'assessedAt'          => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $existing = $this->findExistingAssessment($administrationId, $year);

        try {
            $saved = $this->toArray(
                $this->objectService()->saveObject(
                    object: $payload,
                    register: $this->register(),
                    schema: 'WkrAssessment',
                    uuid: ($existing === null ? null : $this->idOf($existing)),
                    _rbac: false,
                    _multitenancy: false
                )
            );
        } catch (\Throwable $e) {
            $this->logger->error('WkrService: kon WkrAssessment niet opslaan: '.$e->getMessage());
            return $this->outcome('failed', 'Opslaan van de WKR-beoordeling is mislukt: '.$e->getMessage());
        }

        return $this->outcome('ok', 'WKR-beoordeling berekend.', $saved);

    }//end assess()


    /**
     * `--all` path (spec.md REQ-WKR-005): assess every distinct
     * (administrationId, year) pair found across the payslips.
     *
     * @return array<int, array<string, mixed>> One outcome per (administrationId, year) pair.
     *
     * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-005
     */
    public function assessAll(): array
    {
        $outcomes = [];
        foreach ($this->buildAggregate() as $administrationId => $byYear) {
            foreach (array_keys($byYear) as $year) {
                $outcomes[] = array_merge(
                    ['administrationId' => $administrationId, 'year' => $year],
                    $this->assess($administrationId, (int) $year)
                );
            }
        }

        return $outcomes;

    }//end assessAll()


    /**
     * Build the SAME per-(administrationId, year) fiscale-loonsom + vrije-
     * ruimte-used aggregate `RuleAuditService::buildWkrContext()` builds (its
     * own `loadAll` pass, design.md D5 — "reads the same cross-object
     * aggregate"): resolves each Payslip's effective administrationId (own
     * field, else via `payrollRunId` -> PayrollRun.administrationId), derives
     * its year from `period`, and sums declarations by category.
     *
     * @return array<string, array<int, array<string, float>>>
     */
    private function buildAggregate(): array
    {
        $runsById = [];
        foreach ($this->loadAll('PayrollRun') as $run) {
            $id = $this->idOf($run);
            if ($id !== '') {
                $runsById[$id] = (string) ($run['administrationId'] ?? '');
            }
        }

        $aggregate = [];

        foreach ($this->loadAll('Payslip') as $payslip) {
            $administrationId = trim((string) ($payslip['administrationId'] ?? ''));
            if ($administrationId === '') {
                $runId            = (string) ($payslip['payrollRunId'] ?? '');
                $administrationId = trim((string) ($runsById[$runId] ?? ''));
            }

            if ($administrationId === '') {
                continue;
            }

            $year = self::yearFromPeriod((string) ($payslip['period'] ?? ''));
            if ($year === null) {
                continue;
            }

            $bucket                        = &$aggregate[$administrationId][$year];
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

            $bucket                         = &$aggregate[$administrationId][$year];
            $bucket['loonsom']              = ($bucket['loonsom'] ?? 0.0);
            $bucket['payslipWkrUsed']       = ($bucket['payslipWkrUsed'] ?? 0.0);
            $bucket['vrijeRuimteDeclared']  = ($bucket['vrijeRuimteDeclared'] ?? 0.0);
            $bucket['eindheffingDeclared']  = ($bucket['eindheffingDeclared'] ?? 0.0);

            if ($category === 'vrije-ruimte') {
                $bucket['vrijeRuimteDeclared'] += ((float) ($declaration['amount'] ?? 0));
            } elseif ($category === 'eindheffing') {
                $bucket['eindheffingDeclared'] += ((float) ($declaration['amount'] ?? 0));
            }

            unset($bucket);
        }//end foreach

        return $aggregate;

    }//end buildAggregate()


    /**
     * Derive a calendar year from a wage period (`YYYY-MM`/`YYYY-Pnn`) — the
     * leading four-digit year prefix (the `RuleAuditService::yearFromPeriod`
     * precedent). Returns null when unparseable.
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
     * The existing WkrAssessment for (administrationId, year), or null — the
     * idempotency probe (design.md D5, the PayrollMutationService::
     * findExistingReport precedent).
     *
     * @param string $administrationId The administration.
     * @param int    $year             The fiscal year.
     *
     * @return array<string, mixed>|null
     */
    private function findExistingAssessment(string $administrationId, int $year): ?array
    {
        foreach ($this->loadAll('WkrAssessment') as $candidate) {
            if ((string) ($candidate['administrationId'] ?? '') === $administrationId
                && (int) ($candidate['year'] ?? 0) === $year
            ) {
                return $candidate;
            }
        }

        return null;

    }//end findExistingAssessment()


    /**
     * Build the base outcome array.
     *
     * @param string                    $status     Outcome status (ok/failed).
     * @param string                    $message    Human-readable outcome message.
     * @param array<string, mixed>|null $assessment The saved WkrAssessment, when successful.
     *
     * @return array<string, mixed>
     */
    private function outcome(string $status, string $message, ?array $assessment=null): array
    {
        return ['status' => $status, 'message' => $message, 'assessment' => $assessment];

    }//end outcome()


    /**
     * Convert a euro-denominated value to integer cents (`round($euros *
     * 100)`, the PayrollMutationService read-time boundary). Non-numeric/null
     * values convert to 0.
     *
     * @param mixed $euros The raw value.
     *
     * @return int
     */
    private static function cents(mixed $euros): int
    {
        if (is_numeric($euros) === false) {
            return 0;
        }

        return (int) round(((float) $euros) * 100);

    }//end cents()


    /**
     * Convert integer cents to a euro float rounded to 2 decimals — the
     * write-time boundary (the PayrollMutationService::euros() precedent).
     *
     * @param int $cents The cents amount.
     *
     * @return float
     */
    private static function euros(int $cents): float
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
            $this->logger->warning('WkrService: kon '.$schema.' niet laden: '.$e->getMessage());
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
