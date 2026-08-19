<?php

/**
 * NL Fleet Bijtelling Check Provider
 *
 * Two fleet-bijtelling executable checks, auto-discovered by
 * `RuleEngine::providers()` with zero manual registration:
 *
 * - `nl-bijtelling-auto-privegebruik` (Payslip, design.md D5): vacuous when
 *   `assetAssignmentId` is null (no company car covered this payslip's
 *   period). Else re-derives `monthlyBijtellingCents` from the referenced
 *   `AssetAssignment.employeeContribution` and `Asset.listPrice`/
 *   `companyCarTaxCategory` -- resolved via
 *   `$context['related']['AssetAssignment']['byId']` /
 *   `$context['related']['Asset']['byId']` (`RuleAuditService::
 *   buildRelatedContext()`'s `payroll.runsById` precedent) -- using the
 *   REQ-FLEET-003 formula, and flags a violation on any cents-mismatch
 *   against the recorded `Payslip.bijtelling`. Vacuous also when either
 *   reference is dangling (a different, pre-existing class of
 *   data-integrity problem, not this rule's job) -- the
 *   `NlWageGarnishmentChecks::isFloorRespected()` precedent.
 * - `nl-asset-voertuig-fiscale-velden-compleet` (Asset, hrmq-asset-fleet-merge
 *   design.md D2): fires when `category === "vehicle"` and any of
 *   `listPrice`/`fuelType`/`companyCarTaxCategory` is absent. Audit-time
 *   replacement for the write-time guard the retired `Vehicle` schema's own
 *   unconditional `required` used to provide -- a JSON Schema
 *   `allOf`/`if`/`then` conditional-required cannot work here
 *   (`Schema::getSchemaObject()` never emits composition keywords to the
 *   validator, measured).
 *
 * hrmq-asset-fleet-merge: `Vehicle`/`CarAssignment` (hr-fleet.json) retired
 * into `Asset`/`AssetAssignment` -- the `context['fleet']` dedicated index
 * this predicate used to read is gone (`RuleAuditService::buildFleetContext()`
 * removed, design.md D4); the general `related.Asset.byId`/
 * `related.AssetAssignment.byId` indexes carry everything this predicate
 * needs instead.
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
 * @spec openspec/changes/hrmq-asset-fleet-merge/specs/fleet-bijtelling/spec.md#REQ-FLEET-004
 * @spec openspec/changes/hrmq-asset-fleet-merge/specs/asset-management/spec.md#REQ-AST-001
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

use OCA\Hrmq\Payroll\TaxTables;

/**
 * Fleet bijtelling arithmetic-consistency + vehicle-fiscal-completeness
 * executable checks.
 */
final class NlFleetChecks implements CheckProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/fleet-bijtelling/specs/fleet-bijtelling/spec.md#REQ-FLEET-004
	 * @spec openspec/changes/hrmq-asset-fleet-merge/specs/asset-management/spec.md#REQ-AST-001
	 */
	public static function checks(): array {
		return [
			'Payslip' => [
				'nl-bijtelling-auto-privegebruik' => static fn (array $o, array $context): bool => self::bijtellingMatchesFormula($o, $context),
			],
			'Asset' => [
				'nl-asset-voertuig-fiscale-velden-compleet' => static fn (array $o, array $context): bool => self::vehicleFiscalFieldsComplete($o),
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
	 * design.md D5): vacuous when `assetAssignmentId` is null or either
	 * reference is dangling; else asserts cents-exact `Payslip.bijtelling ===
	 * recomputed monthly bijtelling` (REQ-FLEET-003's formula).
	 *
	 * @param array<string, mixed> $o The Payslip object.
	 * @param array<string, mixed> $context Evaluation context; reads `related.AssetAssignment.byId` / `related.Asset.byId`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/hrmq-asset-fleet-merge/specs/fleet-bijtelling/spec.md#REQ-FLEET-004
	 */
	private static function bijtellingMatchesFormula(array $o, array $context): bool {
		$assetAssignmentId = trim((string)($o['assetAssignmentId'] ?? ''));
		if ($assetAssignmentId === '') {
			// No company car covered this payslip's period -- out of scope
			// (vacuous pass).
			return true;
		}

		$assetAssignment = ($context['related']['AssetAssignment']['byId'][$assetAssignmentId] ?? null);
		if (is_array($assetAssignment) === false) {
			// Dangling reference -- a different, pre-existing class of
			// data-integrity problem, not this rule's job.
			return true;
		}

		$assetId = trim((string)($assetAssignment['assetId'] ?? ''));
		$asset = ($context['related']['Asset']['byId'][$assetId] ?? null);
		if (is_array($asset) === false) {
			// Dangling Asset reference -- same vacuous-on-dangling posture.
			return true;
		}

		$tables = self::tablesFor((string)($o['period'] ?? ''));
		if ($tables === null) {
			// No resolvable tax-year table for this payslip's period -- an
			// infra/traceability concern (nl-engine-table-version's job), not
			// this rule's arithmetic-consistency job. Vacuous pass.
			return true;
		}

		$expectedCents = self::monthlyBijtellingCents($asset, $assetAssignment, $tables);
		$recordedCents = self::cents($o['bijtelling'] ?? null);

		return $expectedCents === $recordedCents;
	}//end bijtellingMatchesFormula()

	/**
	 * The `nl-asset-voertuig-fiscale-velden-compleet` predicate
	 * (hrmq-asset-fleet-merge design.md D2, spec.md REQ-AST-001): violates
	 * when `category === "vehicle"` and any of `listPrice`/`fuelType`/
	 * `companyCarTaxCategory` is absent (null, missing, or an empty string).
	 * Vacuously satisfied for every non-`vehicle` category -- the three
	 * fields are meaningless there and are never required.
	 *
	 * @param array<string, mixed> $o The Asset object.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/hrmq-asset-fleet-merge/specs/asset-management/spec.md#REQ-AST-001
	 */
	private static function vehicleFiscalFieldsComplete(array $o): bool {
		if ((string)($o['category'] ?? '') !== 'vehicle') {
			return true;
		}

		foreach (['listPrice', 'fuelType', 'companyCarTaxCategory'] as $field) {
			if (array_key_exists($field, $o) === false || $o[$field] === null || trim((string)$o[$field]) === '') {
				return false;
			}
		}

		return true;
	}//end vehicleFiscalFieldsComplete()

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
	 * Re-derive the monthly bijtelling from an Asset + AssetAssignment
	 * (REQ-FLEET-003's formula, mirrored from `PayrollRunService::
	 * bijtellingCentsFor()`): `base = listPrice x standardPercent/100` for
	 * `companyCarTaxCategory: standard`, or the two-tier `evReducedCapped`
	 * blend; `monthlyBijtellingCents = max(0, round(base_cents / 12) -
	 * employeeContributionCents)`.
	 *
	 * @param array<string, mixed> $asset The referenced (category: vehicle) Asset.
	 * @param array<string, mixed> $assetAssignment The referenced AssetAssignment.
	 * @param TaxTables $tables The tax-year parameter set for this payslip's period.
	 *
	 * @return int The expected monthly bijtelling, in cents.
	 *
	 * @spec openspec/changes/hrmq-asset-fleet-merge/specs/fleet-bijtelling/spec.md#REQ-FLEET-003
	 */
	private static function monthlyBijtellingCents(array $asset, array $assetAssignment, TaxTables $tables): int {
		$listPrice = ($asset['listPrice'] ?? null);
		if (is_numeric($listPrice) === false) {
			return 0;
		}

		$listPriceCents = self::cents($listPrice);

		// The 2026 rate/cap: sourced from `nl-2026.json`'s
		// bijtellingPrivegebruikAuto group (REQ-FLEET-002). The check
		// re-derives from the SAME versioned table data the engine reads, via
		// TaxTables -- never a duplicated literal.
		$bijtelling = $tables->bijtellingPrivegebruikAuto();
		$standardPercent = $bijtelling['standardPercent'];

		// Default: the flat standard-percentage bijtelling. The capped-EV
		// category below overrides it; both branches are pure arithmetic, so
		// initialising here is equivalent to the former if/else.
		$baseCents = (($listPriceCents * $standardPercent) / 100);

		if ((string)($asset['companyCarTaxCategory'] ?? '') === 'evReducedCapped') {
			$cap = $bijtelling['evReducedCataloguswaardeCapCents'];
			$baseCents = ((min($listPriceCents, $cap) * $bijtelling['evReducedPercent']) / 100);
			$baseCents += ((max(0, ($listPriceCents - $cap)) * $standardPercent) / 100);
		}

		$employeeContributionCents = self::cents($assetAssignment['employeeContribution'] ?? 0);

		return max(0, ((int)round($baseCents / 12)) - $employeeContributionCents);
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
