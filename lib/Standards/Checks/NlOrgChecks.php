<?php

/**
 * NL Organisational Structure Check Provider
 *
 * Executable checks for the two hr-org-core organisational-integrity rules of
 * the labour corpus (lib/Standards/rules/labour.json), mapped onto the
 * OrgUnit / OrgAssignment object types (org-chart-basic):
 * `nl-org-assignment-consistency` — an active OrgAssignment (no endDate, or
 * an endDate not yet in the past) must reference an existing OrgUnit whose
 * `active` is true, and `startDate <= endDate` must hold whenever `endDate`
 * is present; and `nl-org-unit-cycle` — an OrgUnit's `parentUnitId` ancestor
 * chain must be acyclic, including a unit parented to itself.
 *
 * Both predicates are cross-object: they read the `context['related']
 * ['OrgUnit']['byId']` index `RuleAuditService::buildRelatedContext()`
 * populates in its pre-pass (an `{id, parentUnitId, active}` map keyed by id)
 * rather than re-querying the register. This provider does NOT implement
 * SeedsObjects: the seed OrgUnit/OrgAssignment hierarchy — including the one
 * deliberately date-inconsistent assignment that exercises
 * nl-org-assignment-consistency — lives in lib/Settings/register.d/hr-seed.json
 * (ADR-001), the NlPensionFilingChecks precedent for cross-referencing
 * providers.
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
 * @spec openspec/changes/org-chart-basic/specs/org-chart-basic/spec.md#REQ-ORG-005
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

/**
 * Organisational-structure integrity executable checks (assignment
 * consistency + unit-cycle freedom).
 */
final class NlOrgChecks implements CheckProvider
{


    /**
     * {@inheritDoc}
     *
     * @return array<string, array<string, callable>>
     */
    public static function checks(): array
    {
        return [
            'OrgAssignment' => [
                // Administration-integrity control — coherent dates + the
                // placement must resolve to an active unit while it is itself
                // active.
                'nl-org-assignment-consistency' => static fn(array $o, array $c): bool => self::assignmentConsistent($o, $c),
            ],
            'OrgUnit'       => [
                // Administration-integrity control — the parentUnitId chain
                // must never re-enter a unit already walked.
                'nl-org-unit-cycle' => static fn(array $o, array $c): bool => self::unitCycleFree($o, $c),
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
     * True when the assignment's dates are coherent (no endDate, or endDate
     * on/after startDate) AND, while the assignment is itself active (no
     * endDate, or endDate not yet in the past), its `orgUnitId` resolves in
     * the context's OrgUnit index to a unit with `active: true`. Fail-closed
     * on a dangling or missing `orgUnitId` while active. A coherently-dated,
     * already-ended placement is never checked against the unit's current
     * `active` flag — historical placements may point at retired units.
     *
     * @param array<string, mixed> $o The OrgAssignment.
     * @param array<string, mixed> $c Evaluation context (carries `related`).
     *
     * @return bool
     */
    private static function assignmentConsistent(array $o, array $c): bool
    {
        $startDate = trim((string) ($o['startDate'] ?? ''));
        $endDate   = trim((string) ($o['endDate'] ?? ''));

        if ($endDate !== '') {
            $start = strtotime($startDate);
            $end   = strtotime($endDate);
            if ($start !== false && $end !== false && $end < $start) {
                return false;
            }
        }

        if (self::isCurrentlyActive($endDate) === false) {
            // A coherently-dated, already-ended placement — historical
            // placements may point at a retired unit.
            return true;
        }

        $orgUnitId = trim((string) ($o['orgUnitId'] ?? ''));
        if ($orgUnitId === '') {
            return false;
        }

        $unit = (self::relatedOrgUnitsById($c)[$orgUnitId] ?? null);
        if (is_array($unit) === false) {
            return false;
        }

        return ($unit['active'] ?? false) === true;

    }//end assignmentConsistent()


    /**
     * True when the placement is current — no `endDate`, or an `endDate` on
     * or after today (the audit run date).
     *
     * @param string $endDate The (already-trimmed) `endDate` value, or ''.
     *
     * @return bool
     */
    private static function isCurrentlyActive(string $endDate): bool
    {
        if ($endDate === '') {
            return true;
        }

        $end = strtotime($endDate);
        if ($end === false) {
            return true;
        }

        $today = (new \DateTimeImmutable('today'))->getTimestamp();
        return $end >= $today;

    }//end isCurrentlyActive()


    /**
     * True when this OrgUnit's `parentUnitId` ancestor chain never re-enters
     * a unit already visited (a visited-set parent walk, no depth-limit
     * heuristic) — including a unit parented to itself. A dangling parent
     * (not present in the context's OrgUnit index) simply ends the walk
     * without a violation: a missing node, not a cycle.
     *
     * @param array<string, mixed> $o The OrgUnit.
     * @param array<string, mixed> $c Evaluation context (carries `related`).
     *
     * @return bool
     */
    private static function unitCycleFree(array $o, array $c): bool
    {
        $ownId    = trim((string) ($o['id'] ?? $o['@self']['id'] ?? ''));
        $parentId = trim((string) ($o['parentUnitId'] ?? ''));
        if ($parentId === '') {
            return true;
        }

        $byId    = self::relatedOrgUnitsById($c);
        $visited = [];
        if ($ownId !== '') {
            $visited[$ownId] = true;
        }

        $current = $parentId;
        while ($current !== '') {
            if (isset($visited[$current]) === true) {
                return false;
            }

            $visited[$current] = true;

            $unit = ($byId[$current] ?? null);
            if (is_array($unit) === false) {
                // Dangling parent — a missing-node problem, not a cycle.
                return true;
            }

            $current = trim((string) ($unit['parentUnitId'] ?? ''));
        }

        return true;

    }//end unitCycleFree()


    /**
     * The `related.OrgUnit.byId` index from the context, or an empty array
     * when the pre-pass has not populated it (e.g. the schema is not yet
     * imported).
     *
     * @param array<string, mixed> $c Evaluation context.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function relatedOrgUnitsById(array $c): array
    {
        $byId = ($c['related']['OrgUnit']['byId'] ?? []);
        return is_array($byId) === true ? $byId : [];

    }//end relatedOrgUnitsById()


}//end class
