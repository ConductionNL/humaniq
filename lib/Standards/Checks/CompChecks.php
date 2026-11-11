<?php

/**
 * Comp Check Provider
 *
 * Executable check for the compensation-review-cycles corpus rule
 * (lib/Standards/rules/labour.json, comp-cycles design.md D7):
 * `comp-adjustment-within-band` — a proposed/approved/effective
 * CompAdjustment's `proposedSalary` must sit within its target SalaryBand's
 * `[minSalary, maxSalary]` range. Vacuous when the adjustment targets no band
 * (`targetBandId` null, the `payScalesVerified` advisory-until-confirmed
 * precedent) or is still `draft` (nothing proposed yet). The band is resolved
 * via the `comp.salaryBandsById` audit context (the `cao.employeesById` /
 * `payroll.runsById` enrichment precedent, RuleAuditService::audit()) rather
 * than per-object IO, so the predicate stays a pure
 * `fn(array $object, array $context): bool`.
 *
 * @category Standards
 * @package  OCA\Humaniq\Standards\Checks
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
 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * Within-band executable check for compensation-review adjustments.
 */
final class CompChecks implements CheckProvider {

	/**
	 * CompAdjustment statuses the within-band rule applies to — a still-draft
	 * proposal has nothing decided yet (design.md D7).
	 *
	 * @var string[]
	 */
	private const APPLICABLE_STATUSES = ['proposed', 'approved', 'effective'];

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-007
	 */
	public static function checks(): array {
		return [
			'CompAdjustment' => [
				'comp-adjustment-within-band' => static fn (array $o, array $context): bool => self::withinBandSatisfied($o, $context),
			],
		];

	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * The `comp-adjustment-within-band` predicate (spec.md REQ-COMP-007):
	 * vacuous when the adjustment is still `draft`, when `targetBandId` is
	 * absent, or when the referenced SalaryBand cannot be resolved (nothing
	 * decidable from this object alone — the `NlCaoChecks` "unresolvable
	 * sibling -> vacuous" precedent). Otherwise requires
	 * `minSalary <= proposedSalary <= maxSalary`.
	 *
	 * @param array<string, mixed> $o The CompAdjustment.
	 * @param array<string, mixed> $context Evaluation context; reads `comp.salaryBandsById`.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/comp-cycles/spec.md#REQ-COMP-007
	 */
	private static function withinBandSatisfied(array $o, array $context): bool {
		$band = self::targetBand(o: $o, context: $context);
		if ($band === null) {
			// Still draft, no band targeted, or an unresolvable band -- out of
			// scope or not decidable from this object alone (vacuous).
			return true;
		}

		$salaries = self::salaries(o: $o, band: $band);

		return $salaries === null || ($salaries[0] >= $salaries[1] && $salaries[0] <= $salaries[2]);
	}//end withinBandSatisfied()

	/**
	 * The band a proposed or later adjustment targets, or null when it is still draft, targets none, or the band is unresolvable.
	 *
	 * @param array<string, mixed> $o The CompAdjustment.
	 * @param array<string, mixed> $context The evaluation context.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function targetBand(array $o, array $context): array|null {
		if (in_array((string)($o['status'] ?? ''), self::APPLICABLE_STATUSES, true) === false) {
			return null;
		}

		$bandId = trim((string)($o['targetBandId'] ?? ''));
		if ($bandId === '') {
			return null;
		}

		$band = ($context['comp']['salaryBandsById'][$bandId] ?? null);

		return is_array($band) === true ? $band : null;
	}//end targetBand()

	/**
	 * The proposed salary and the band's minimum and maximum, or null when any is not numeric.
	 *
	 * @param array<string, mixed> $o The CompAdjustment.
	 * @param array<string, mixed> $band The targeted band.
	 *
	 * @return array{0: float, 1: float, 2: float}|null
	 */
	private static function salaries(array $o, array $band): array|null {
		$proposedSalary = ($o['proposedSalary'] ?? null);
		$minSalary = ($band['minSalary'] ?? null);
		$maxSalary = ($band['maxSalary'] ?? null);
		if (is_numeric($proposedSalary) === false || is_numeric($minSalary) === false || is_numeric($maxSalary) === false) {
			return null;
		}

		return [(float)$proposedSalary, (float)$minSalary, (float)$maxSalary];
	}//end salaries()

}//end class
