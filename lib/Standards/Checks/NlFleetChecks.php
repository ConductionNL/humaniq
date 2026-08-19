<?php

/**
 * NL Fleet Bijtelling Check Provider
 *
 * The one fleet-bijtelling executable check (design.md D5), auto-discovered
 * by `RuleEngine::providers()` with zero manual registration:
 * `nl-bijtelling-auto-privegebruik` (Payslip): vacuous when `carAssignmentId`
 * is null (no company car covered this payslip's period). Else re-derives
 * `monthlyBijtellingCents` from the referenced `CarAssignment.eigenBijdrage`
 * and `Vehicle.cataloguswaarde`/`bijtellingCategorie` -- resolved via
 * `$context['fleet']['carAssignmentsById']` / `$context['fleet']
 * ['vehiclesById']` (`RuleAuditService::audit()`'s `payroll.runsById`
 * precedent) -- using the REQ-FLEET-003 formula, and flags a violation on any
 * cents-mismatch against the recorded `Payslip.bijtelling`. Vacuous also when
 * either reference is dangling (a different, pre-existing class of
 * data-integrity problem, not this rule's job) -- the
 * `NlWageGarnishmentChecks::isFloorRespected()` precedent.
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
 * @spec openspec/changes/fleet-bijtelling/specs/fleet-bijtelling/spec.md#REQ-FLEET-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

use OCA\Hrmq\Payroll\TaxTables;

/**
 * Fleet bijtelling arithmetic-consistency executable check.
 */
final class NlFleetChecks implements CheckProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/fleet-bijtelling/specs/fleet-bijtelling/spec.md#REQ-FLEET-004
	 */
	public static function checks(): array {
		return [
			'Payslip' => [
				'nl-bijtelling-auto-privegebruik' => static fn (array $o, array $context): bool => self::bijtellingMatchesFormula($o, $context),
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
	 * The `nl-bijtelling-auto-privegebruik` predicate (spec.md REQ-FLEET-004,
	 * design.md D5): vacuous when `carAssignmentId` is null or either
	 * reference is dangling; else asserts cents-exact `Payslip.bijtelling ===
	 * recomputed monthly bijtelling` (REQ-FLEET-003's formula).
	 *
	 * @param array<string, mixed> $o The Payslip object.
	 * @param array<string, mixed> $context Evaluation context; reads `fleet.carAssignmentsById` / `fleet.vehiclesById`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/fleet-bijtelling/specs/fleet-bijtelling/spec.md#REQ-FLEET-004
	 */
	private static function bijtellingMatchesFormula(array $o, array $context): bool {
		$carAssignmentId = trim((string)($o['carAssignmentId'] ?? ''));
		if ($carAssignmentId === '') {
			// No company car covered this payslip's period -- out of scope
			// (vacuous pass).
			return true;
		}

		$carAssignment = ($context['fleet']['carAssignmentsById'][$carAssignmentId] ?? null);
		if (is_array($carAssignment) === false) {
			// Dangling reference -- a different, pre-existing class of
			// data-integrity problem, not this rule's job.
			return true;
		}

		$vehicleId = trim((string)($carAssignment['vehicleId'] ?? ''));
		$vehicle = ($context['fleet']['vehiclesById'][$vehicleId] ?? null);
		if (is_array($vehicle) === false) {
			// Dangling Vehicle reference -- same vacuous-on-dangling posture.
			return true;
		}

		$tables = self::tablesFor((string)($o['period'] ?? ''));
		if ($tables === null) {
			// No resolvable tax-year table for this payslip's period -- an
			// infra/traceability concern (nl-engine-table-version's job), not
			// this rule's arithmetic-consistency job. Vacuous pass.
			return true;
		}

		$expectedCents = self::monthlyBijtellingCents($vehicle, $carAssignment, $tables);
		$recordedCents = self::cents($o['bijtelling'] ?? null);

		return $expectedCents === $recordedCents;
	}//end bijtellingMatchesFormula()

	/**
	 * Load the versioned tax-year table for a Payslip's `period` (`YYYY-MM`
	 * -> `nl-{YYYY}`, the `PayrollRunService::generate()` `$tableId`
	 * convention), or null when unparseable/unavailable. Never throws --
	 * table-availability is `nl-engine-table-version`'s job, not this rule's.
	 *
	 * @param string $period Wage period (YYYY-MM).
	 *
	 * @return TaxTables|null
	 */
	private static function tablesFor(string $period): ?TaxTables {
		if (preg_match('/^(\d{4})/', trim($period), $matches) !== 1) {
			return null;
		}

		try {
			return TaxTables::load('nl-' . $matches[1]);
		} catch (\Throwable $e) {
			return null;
		}

	}//end tablesFor()

	/**
	 * Re-derive the monthly bijtelling from a Vehicle + CarAssignment
	 * (REQ-FLEET-003's formula, mirrored from `PayrollRunService::
	 * bijtellingCentsFor()`): `base = cataloguswaarde x standardPercent/100`
	 * for `bijtellingCategorie: standaard`, or the two-tier
	 * `elektrischGeplafonneerd` blend; `monthlyBijtellingCents = max(0,
	 * round(base_cents / 12) - eigenBijdrageCents)`.
	 *
	 * @param array<string, mixed> $vehicle The referenced Vehicle.
	 * @param array<string, mixed> $carAssignment The referenced CarAssignment.
	 * @param TaxTables $tables The tax-year parameter set for this payslip's period.
	 *
	 * @return int The expected monthly bijtelling, in cents.
	 *
	 * @spec openspec/changes/fleet-bijtelling/specs/fleet-bijtelling/spec.md#REQ-FLEET-003
	 */
	private static function monthlyBijtellingCents(array $vehicle, array $carAssignment, TaxTables $tables): int {
		$cataloguswaarde = ($vehicle['cataloguswaarde'] ?? null);
		if (is_numeric($cataloguswaarde) === false) {
			return 0;
		}

		$cataloguswaardeCents = self::cents($cataloguswaarde);

		// The 2026 rate/cap: sourced from `nl-2026.json`'s
		// bijtellingPrivegebruikAuto group (REQ-FLEET-002). The check
		// re-derives from the SAME versioned table data the engine reads, via
		// TaxTables -- never a duplicated literal.
		$bijtelling = $tables->bijtellingPrivegebruikAuto();
		$standardPercent = $bijtelling['standardPercent'];

		// Default: the flat standard-percentage bijtelling. The capped-EV
		// category below overrides it; both branches are pure arithmetic, so
		// initialising here is equivalent to the former if/else.
		$baseCents = (($cataloguswaardeCents * $standardPercent) / 100);

		if ((string)($vehicle['bijtellingCategorie'] ?? '') === 'elektrischGeplafonneerd') {
			$cap = $bijtelling['evReducedCataloguswaardeCapCents'];
			$baseCents = ((min($cataloguswaardeCents, $cap) * $bijtelling['evReducedPercent']) / 100);
			$baseCents += ((max(0, ($cataloguswaardeCents - $cap)) * $standardPercent) / 100);
		}

		$eigenBijdrageCents = self::cents($carAssignment['eigenBijdrage'] ?? 0);

		return max(0, ((int)round($baseCents / 12)) - $eigenBijdrageCents);
	}//end monthlyBijtellingCents()

	/**
	 * Convert a euro-denominated value to integer cents (`round($euros *
	 * 100)`, the `NlWageGarnishmentChecks::cents()` precedent). Non-numeric/null
	 * values convert to 0.
	 *
	 * @param mixed $euros The raw field value.
	 *
	 * @return int
	 */
	private static function cents(mixed $euros): int {
		if (is_numeric($euros) === false) {
			return 0;
		}

		return (int)round(((float)$euros) * 100);
	}//end cents()

}//end class
