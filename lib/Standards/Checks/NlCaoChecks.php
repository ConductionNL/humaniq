<?php

/**
 * NL CAO (collective-labour-agreement) Check Provider
 *
 * Executable checks for the two below-CAO-minimum rules the `cao-library` change
 * added to the corpus (lib/Standards/rules/payroll.json /
 * lib/Standards/rules/labour.json), both `CaoRegistry`-backed and
 * placeholder-aware (design.md D4/D5):
 *
 * - `nl-cao-minimumloon-schaal` (EmploymentContract): vacuous when the contract
 *   names no CAO/scale, or `CaoRegistry::minMaandloonCents()` returns null
 *   (unknown CAO/scale, or the CAO's payScales figure is unverified/placeholder).
 *   Otherwise the owning employee's `grossMonthlySalary` — resolved via the
 *   `cao.employeesById` audit context (the `payroll.runsById` precedent,
 *   RuleAuditService::audit()) — must be at least the CAO minimum for that scale.
 * - `nl-cao-verlof-minimum` (LeaveBalance): vacuous when the balance is not the
 *   statutory annual leave type, when no CAO resolves for the employee (the
 *   `cao.caoByEmployeeId` audit context), or when `CaoRegistry::minLeaveHours()`
 *   returns null (unverified/placeholder). Otherwise `entitledHours +
 *   bovenwettelijkHours` must be at least the CAO minimum for the balance's
 *   `contractHoursPerWeek`. NOTE: the LeaveBalance schema's `leaveType` enum
 *   (lib/Settings/register.d/hr-leave.json) is `[holiday, sick, unpaid, special,
 *   care, parental]` — there is no literal `vakantie` value on this schema (the
 *   spec.md REQ-CAO-004 prose uses the Dutch term); `holiday` IS the statutory
 *   annual leave type and is what this predicate scopes to.
 *
 * Also implements `SeedsObjects` + `UpsertsObjects` (design.md D7/REQ-CAO-006):
 * projects one read-only `Cao` object per `CaoRegistry::availableCaos()` entry,
 * upserted by the natural `caoId` key (NOT the OpenRegister object id) so
 * re-seeding after a corpus edit converges the display objects instead of
 * silently going stale after the first seed run.
 *
 * @category Standards
 * @package  OCA\Hrmq\Standards\Checks
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
 * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-003
 * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-004
 * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

use OCA\Hrmq\Standards\CaoRegistry;

/**
 * Below-CAO-minimum (pay scale + leave) executable checks, plus the CAO
 * reference-object seed.
 */
final class NlCaoChecks implements CheckProvider, SeedsObjects, UpsertsObjects
{

    /**
     * The statutory annual leave type value on the LeaveBalance schema
     * (`leaveType` enum) that `nl-cao-verlof-minimum` scopes to.
     *
     * @var string
     */
    private const ANNUAL_LEAVE_TYPE = 'holiday';


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, callable>>
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-003
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-004
     */
    public static function checks(): array
    {
        return [
            'EmploymentContract' => [
                'nl-cao-minimumloon-schaal' => static fn(array $o, array $context): bool => self::minimumloonSchaalSatisfied($o, $context),
            ],
            'LeaveBalance'       => [
                'nl-cao-verlof-minimum' => static fn(array $o, array $context): bool => self::verlofMinimumSatisfied($o, $context),
            ],
        ];

    }//end checks()


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, mixed>>
     */
    public static function seedSpec(): array
    {
        return [];

    }//end seedSpec()


    /**
     * {@inheritDoc}
     *
     * One `Cao` object per `CaoRegistry::availableCaos()` entry, keyed on the
     * natural `caoId` field (design.md D7). Values are read straight from
     * `CaoRegistry::get()` on every call — no local caching of the projection
     * — so re-seeding after a corpus edit converges the objects (REQ-CAO-006).
     *
     * @return array<string, array<int, array<string, mixed>>>
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-006
     */
    public static function seedObjects(): array
    {
        $rows = [];
        foreach (CaoRegistry::availableCaos() as $caoId => $summary) {
            $cao = CaoRegistry::get($caoId);
            if ($cao === null) {
                continue;
            }

            $rows[] = [
                'caoId'                    => $caoId,
                'name'                     => $summary['name'],
                'sector'                   => $summary['sector'],
                'version'                  => $summary['version'],
                'effectiveDate'            => $summary['effectiveDate'],
                'payScales'                => self::leafValue($cao['payScales'] ?? null),
                'payScalesVerified'        => self::leafVerified($cao['payScales'] ?? null),
                'allowances'               => self::leafValue($cao['allowances'] ?? null),
                'leaveEntitlement'         => self::leafValue($cao['leaveEntitlement'] ?? null),
                'leaveEntitlementVerified' => self::leafVerified($cao['leaveEntitlement'] ?? null),
                'workingTime'              => self::leafValue($cao['workingTime'] ?? null),
                'source'                   => self::sourcesSummary((array) ($cao['basedOn'] ?? [])),
            ];
        }

        return ['Cao' => $rows];

    }//end seedObjects()


    /**
     * {@inheritDoc}
     *
     * @return array<string, string>
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-006
     */
    public static function upsertKeys(): array
    {
        return ['Cao' => 'caoId'];

    }//end upsertKeys()


    /**
     * The `nl-cao-minimumloon-schaal` predicate (spec.md REQ-CAO-003): vacuous
     * pass when `cao`/`caoSchaal` is absent or `CaoRegistry::minMaandloonCents`
     * resolves null (unknown CAO/scale, or unverified/placeholder); also
     * vacuous when the owning employee cannot be resolved or carries no
     * numeric `grossMonthlySalary` (nothing decidable — mirrors the
     * `NlEngineChecks` "unresolvable sibling -> vacuous" precedent). Otherwise
     * requires round(grossMonthlySalary x 100) >= minMaandloonCents.
     *
     * @param array<string, mixed> $o       The EmploymentContract.
     * @param array<string, mixed> $context Evaluation context; reads `cao.employeesById`.
     *
     * @return bool
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-003
     */
    private static function minimumloonSchaalSatisfied(array $o, array $context): bool
    {
        $caoId  = trim((string) ($o['cao'] ?? ''));
        $schaal = trim((string) ($o['caoSchaal'] ?? ''));
        if ($caoId === '' || $schaal === '') {
            // No CAO/scale named on this contract -- out of scope.
            return true;
        }

        $minCents = CaoRegistry::minMaandloonCents($caoId, $schaal);
        if ($minCents === null) {
            // Unknown CAO/scale, or the figure is unverified/placeholder -- advisory.
            return true;
        }

        $employeeId = trim((string) ($o['employeeId'] ?? ''));
        $employee   = ($context['cao']['employeesById'][$employeeId] ?? null);
        if (is_array($employee) === false) {
            // Unresolvable employee -- nothing decidable from this object alone.
            return true;
        }

        $salary = ($employee['grossMonthlySalary'] ?? null);
        if (is_numeric($salary) === false) {
            return true;
        }

        $salaryCents = (int) round(((float) $salary) * 100);
        return $salaryCents >= $minCents;

    }//end minimumloonSchaalSatisfied()


    /**
     * The `nl-cao-verlof-minimum` predicate (spec.md REQ-CAO-004): vacuous
     * pass when `leaveType` is not the statutory annual type (`holiday` on
     * this schema -- see class docblock), when no CAO resolves for the
     * employee, when `contractHoursPerWeek` is absent, or when
     * `CaoRegistry::minLeaveHours` resolves null (unverified/placeholder).
     * Otherwise requires entitledHours + bovenwettelijkHours >=
     * minLeaveHours(cao, contractHoursPerWeek).
     *
     * @param array<string, mixed> $o       The LeaveBalance.
     * @param array<string, mixed> $context Evaluation context; reads `cao.caoByEmployeeId`.
     *
     * @return bool
     *
     * @spec openspec/changes/cao-library/specs/cao-library/spec.md#REQ-CAO-004
     */
    private static function verlofMinimumSatisfied(array $o, array $context): bool
    {
        if ((string) ($o['leaveType'] ?? '') !== self::ANNUAL_LEAVE_TYPE) {
            // Not the statutory annual leave type -- out of scope.
            return true;
        }

        $employeeId = trim((string) ($o['employeeId'] ?? ''));
        $caoId      = trim((string) ($context['cao']['caoByEmployeeId'][$employeeId] ?? ''));
        if ($caoId === '') {
            // No CAO resolves for this employee's active contract -- out of scope.
            return true;
        }

        $contractHoursPerWeek = ($o['contractHoursPerWeek'] ?? null);
        if ($contractHoursPerWeek === null || $contractHoursPerWeek === '' || is_numeric($contractHoursPerWeek) === false) {
            // Not decidable from this object alone (matches the sibling
            // nl-verlof-wettelijk-minimum vacuous-pass discipline).
            return true;
        }

        $minHours = CaoRegistry::minLeaveHours($caoId, (float) $contractHoursPerWeek);
        if ($minHours === null) {
            // Unknown CAO, or the leave figure is unverified/placeholder -- advisory.
            return true;
        }

        $granted = ((float) ($o['entitledHours'] ?? 0) + (float) ($o['bovenwettelijkHours'] ?? 0));
        return $granted >= $minHours;

    }//end verlofMinimumSatisfied()


    /**
     * The `value` of a `{value, source, verified}` leaf, or an empty array
     * when the leaf is absent/malformed.
     *
     * @param mixed $leaf The candidate leaf.
     *
     * @return array<string, mixed>
     */
    private static function leafValue(mixed $leaf): array
    {
        if (is_array($leaf) === false || is_array($leaf['value'] ?? null) === false) {
            return [];
        }

        return $leaf['value'];

    }//end leafValue()


    /**
     * Whether a `{value, source, verified}` leaf is hard-verified (i.e.
     * `verified === true` and not `placeholder: true`) -- surfaced on the
     * seeded `Cao` object so the reference page can show maintainers which
     * figures still need confirming against the CAO-tekst.
     *
     * @param mixed $leaf The candidate leaf.
     *
     * @return bool
     */
    private static function leafVerified(mixed $leaf): bool
    {
        if (is_array($leaf) === false) {
            return false;
        }

        return ($leaf['verified'] ?? false) === true && ($leaf['placeholder'] ?? false) !== true;

    }//end leafVerified()


    /**
     * A human-readable one-line summary of a CAO's `basedOn` citations.
     *
     * @param array<int, array<string, mixed>> $basedOn The CAO's `basedOn` list.
     *
     * @return string
     */
    private static function sourcesSummary(array $basedOn): string
    {
        $docs = [];
        foreach ($basedOn as $entry) {
            if (is_array($entry) === true && trim((string) ($entry['doc'] ?? '')) !== '') {
                $docs[] = (string) $entry['doc'];
            }
        }

        return implode('; ', $docs);

    }//end sourcesSummary()


}//end class
