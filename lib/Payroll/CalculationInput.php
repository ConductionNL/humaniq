<?php

/**
 * Calculation Input
 *
 * The pure-value input to `PayrollCalculator::calculate()` (design.md D1):
 * the employee's payroll-relevant fields, the contract's Awf tariff, the wage
 * period, and the employer-level settings the tables file cannot know
 * (Aof classification, Whk percentage). Zero Nextcloud dependencies.
 *
 * `$verzekeringsplichtig` (dga-payroll-mode design.md D1) is additive and
 * defaults to `true`, so every pre-existing named-argument call site is
 * unaffected: `false` (a DGA — director-major-shareholder, not
 * verzekeringsplichtig for the werknemersverzekeringen, Wfsv art. 6 lid 1
 * sub d) gates `PayrollCalculator::calculate()` step 9 to zero
 * Awf/Aof/Wko/Whk while every other component stays computed exactly as
 * for `true`.
 *
 * @category Payroll
 * @package  OCA\Hrmq\Payroll
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
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-001
 */

declare(strict_types=1);

namespace OCA\Hrmq\Payroll;

use InvalidArgumentException;

/**
 * Immutable input to one gross-to-net calculation.
 */
final class CalculationInput
{


    /**
     * @param int         $grossMonthlySalaryCents      The fixed monthly gross salary, in integer cents (`tvl`).
     * @param string      $taxTableColor                `wit` or `groen`.
     * @param bool        $loonheffingskortingToegepast Whether the employee elected the loonheffingskorting.
     * @param string|null $dateOfBirth                  ISO-8601 date of birth, or null when unknown (treated as below-AOW).
     * @param string      $period                        Wage period, `YYYY-MM`.
     * @param string      $awfTariff                     `low` or `high` (from the covering EmploymentContract).
     * @param string      $aofTariff                     `laag` or `hoog` (employer-level config).
     * @param float       $whkPercentage                 The employer's Whk percentage (percentage scale, e.g. `1.52`).
     * @param bool        $verzekeringsplichtig          Whether the employee is verzekeringsplichtig for the werknemersverzekeringen (dga-payroll-mode). `false` for a DGA — zeroes Awf/Aof/Wko/Whk; every other component is unaffected.
     * @param string      $jurisdiction                  The ISO 3166-1 alpha-2 jurisdiction whose pack computes this wage (jurisdiction-packs). Additive and defaults to `NL`, so every pre-existing named-argument call site is unaffected.
     *
     * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
     * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-001
     * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-001
     */
    public function __construct(
        public readonly int $grossMonthlySalaryCents,
        public readonly string $taxTableColor,
        public readonly bool $loonheffingskortingToegepast,
        public readonly ?string $dateOfBirth,
        public readonly string $period,
        public readonly string $awfTariff,
        public readonly string $aofTariff,
        public readonly float $whkPercentage,
        public readonly bool $verzekeringsplichtig=true,
        public readonly string $jurisdiction='NL',
    ) {

    }//end __construct()


    /**
     * All ten public readonly properties as a plain array, keyed by name.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-001
     */
    public function toArray(): array
    {
        return [
            'grossMonthlySalaryCents'      => $this->grossMonthlySalaryCents,
            'taxTableColor'                => $this->taxTableColor,
            'loonheffingskortingToegepast' => $this->loonheffingskortingToegepast,
            'dateOfBirth'                  => $this->dateOfBirth,
            'period'                       => $this->period,
            'awfTariff'                    => $this->awfTariff,
            'aofTariff'                    => $this->aofTariff,
            'whkPercentage'                => $this->whkPercentage,
            'verzekeringsplichtig'         => $this->verzekeringsplichtig,
            'jurisdiction'                 => $this->jurisdiction,
        ];

    }//end toArray()


    /**
     * The canonical-JSON serialization of this instance (audit-trail-payroll
     * design.md D1): sorted keys, no whitespace — the
     * `AuditHashService::getCanonicalJson()` precedent for "canonical" in
     * this codebase, applied to a value object instead of an audit row. This
     * is the exact string `PayrollRunService::generate()` stamps onto
     * `Payslip.engineInputSnapshot` (REQ-AUDP-001).
     *
     * @return string
     *
     * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-001
     */
    public function toCanonicalJson(): string
    {
        $data = $this->toArray();
        ksort($data);

        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    }//end toCanonicalJson()


    /**
     * Reconstruct a `CalculationInput` from a decoded
     * `Payslip.engineInputSnapshot` (the `toCanonicalJson()` inverse) — the
     * `occ hrmq:payroll:reproduce` entry point (REQ-AUDP-002): recomputing a
     * sealed payslip from ITS OWN stored snapshot, never from the live
     * Employee/EmploymentContract state.
     *
     * @param string $json A `toCanonicalJson()`-produced string (or any JSON object carrying the same ten keys).
     *
     * @return self
     *
     * @throws InvalidArgumentException When `$json` does not decode to a JSON object.
     *
     * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-002
     */
    public static function fromCanonicalJson(string $json): self
    {
        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || is_array($decoded) === false) {
            throw new InvalidArgumentException('CalculationInput: engineInputSnapshot is not valid JSON ('.json_last_error_msg().').');
        }

        return self::fromDecoded($decoded);

    }//end fromCanonicalJson()


    /**
     * Reconstruct a `CalculationInput` from an ALREADY-decoded
     * `engineInputSnapshot` array — live-verified against 8080: OpenRegister's
     * `MagicMapper::rowToObjectEntity()` (`lib/Db/MagicMapper.php`, "Decode
     * JSON values") blanket `json_decode()`s any string column value that
     * happens to parse as valid JSON, REGARDLESS of the schema's declared
     * `type: string` — so every read of `engineInputSnapshot` through
     * `ObjectService` (the only path this app uses) comes back as a PHP
     * array, never the raw string `toCanonicalJson()` wrote. Only a
     * direct-SQL read (or a hand-built test fixture, pre-audit-trail-payroll
     * `NlEngineChecksTest` style) ever sees the literal JSON string, which is
     * why `fromCanonicalJson()` (string input) still exists and is kept for
     * that case, delegating here.
     *
     * @param array<string, mixed> $decoded The decoded snapshot fields.
     *
     * @return self
     *
     * @spec openspec/changes/audit-trail-payroll/specs/audit-trail-payroll/spec.md#REQ-AUDP-002
     */
    public static function fromDecoded(array $decoded): self
    {
        return new self(
            grossMonthlySalaryCents: (int) ($decoded['grossMonthlySalaryCents'] ?? 0),
            taxTableColor: (string) ($decoded['taxTableColor'] ?? ''),
            loonheffingskortingToegepast: (($decoded['loonheffingskortingToegepast'] ?? false) === true),
            dateOfBirth: (($decoded['dateOfBirth'] ?? null) !== null ? (string) $decoded['dateOfBirth'] : null),
            period: (string) ($decoded['period'] ?? ''),
            awfTariff: (string) ($decoded['awfTariff'] ?? ''),
            aofTariff: (string) ($decoded['aofTariff'] ?? ''),
            whkPercentage: (float) ($decoded['whkPercentage'] ?? 0.0),
            verzekeringsplichtig: (($decoded['verzekeringsplichtig'] ?? true) === true),
            jurisdiction: (string) ($decoded['jurisdiction'] ?? 'NL'),
        );

    }//end fromDecoded()


}//end class
