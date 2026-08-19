<?php

/**
 * NL WKR (Werkkostenregeling) Administration Check Provider
 *
 * The administration-level executable check the wkr-administration change adds
 * on top of the pre-existing per-payslip WKR predicates in NlPayrollChecks
 * (nl-wkr-vrije-ruimte / nl-wkr-eindheffing-80, unchanged by this provider):
 * `nl-wkr-eindheffing-exposure`, keyed to the persisted `WkrAssessment` object
 * (design.md D1/D4). The predicate is a pure `fn(array $assessment, array
 * $context): bool` — it reads the cross-object loonsom/used aggregate from
 * `$context['wkr'][administrationId][year]` (built once per audit by
 * RuleAuditService::buildWkrContext(), the buildPayrollContext() precedent),
 * recomputes the available vrije ruimte from the `wkr` table group (the
 * NlEngineChecks construction-time-glob precedent — TaxTables is loaded ONCE,
 * no per-object IO), and asserts the assessment recorded the excess/eindheffing
 * whenever used exceeds available. Vacuous when the cross-object aggregate is
 * absent (an administration/year with no payslips is out of scope).
 *
 * Because the predicate is keyed to a real, persisted, audit-loaded object
 * type, it is reached by `occ hrmq:rules:audit` (and by every
 * `hrmq:wkr:assess`-produced assessment) with no bespoke caller — the
 * capability has a caller by construction (no orphaned-write defect).
 *
 * Also implements SeedsObjects: one WkrDeclaration + one WkrAssessment
 * (design.md Seed Data), consistent with the REQ-WKR-003 spec scenario
 * (fiscale loonsom €200.000 -> vrije ruimte €4.000,00), so a fresh environment
 * demonstrates the happy path (used well within available) with zero
 * violations. The dev-container gate supersedes these illustrative figures
 * with a live recompute via `occ hrmq:wkr:assess` (the assessment is
 * idempotent, so re-running converges it to the true register data).
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
 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-004
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

use OCA\Hrmq\Payroll\TaxTables;

/**
 * Administration-level WKR eindheffing-exposure executable check.
 */
final class NlWkrChecks implements CheckProvider, SeedsObjects {

	/**
	 * Memoised TaxTables instance (construction-time glob precedent —
	 * NlEngineChecks — no per-object IO).
	 *
	 * @var TaxTables|null
	 */
	private static ?TaxTables $tables = null;

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-004
	 */
	public static function checks(): array {
		return [
			'WkrAssessment' => [
				'nl-wkr-eindheffing-exposure' => static fn (array $o, array $context): bool => self::isExposureRecorded($o, $context),
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
	 * {@inheritDoc}
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 *
	 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-001
	 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-003
	 */
	public static function seedObjects(): array {
		return [
			'WkrDeclaration' => [
				[
					'administrationId' => 'ADM-001',
					'year' => 2026,
					'date' => '2026-06-15',
					'description' => 'Personeelsuitje juni',
					'amount' => 300.00,
					'wkrCategory' => 'vrije-ruimte',
					'employeeId' => null,
					'sourceReference' => 'INV-2026-0612',
				],
			],
			'WkrAssessment' => [
				[
					'administrationId' => 'ADM-001',
					'year' => 2026,
					'fiscaleLoonsom' => 200000.00,
					'vrijeRuimte' => 4000.00,
					'vrijeRuimteUsed' => 300.00,
					'vrijeRuimteRemaining' => 3700.00,
					'excess' => 0.00,
					'eindheffingRate' => null,
					'eindheffingDue' => 0.00,
					'status' => 'binnen-vrije-ruimte',
					'engineVersion' => 'nl-2026',
					'assessedAt' => '2026-07-15T00:00:00Z',
				],
			],
		];

	}//end seedObjects()

	/**
	 * The `nl-wkr-eindheffing-exposure` predicate (spec.md REQ-WKR-004,
	 * design.md D4): vacuous when the cross-object aggregate for this
	 * assessment's (administrationId, year) is absent; recomputes the
	 * available vrije ruimte from the `wkr` table group; satisfied when
	 * `used <= available`; when `used > available`, satisfied only if the
	 * assessment itself recorded the exposure (status, excess, eindheffingRate
	 * 80, eindheffingDue all cents-consistent).
	 *
	 * @param array<string, mixed> $o The WkrAssessment object.
	 * @param array<string, mixed> $context Evaluation context; reads `wkr[administrationId][year]`.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-004
	 */
	private static function isExposureRecorded(array $o, array $context): bool {
		$administrationId = trim((string)($o['administrationId'] ?? ''));
		$year = (int)($o['year'] ?? 0);

		$aggregate = ($context['wkr'][$administrationId][$year] ?? null);
		if (is_array($aggregate) === false) {
			// No payslips/declarations resolve for this administration/year —
			// out of scope (vacuous pass), design.md D4.
			return true;
		}

		$loonsomCents = self::cents($aggregate['loonsom'] ?? 0);
		$usedCents = (self::cents($aggregate['payslipWkrUsed'] ?? 0) + self::cents($aggregate['vrijeRuimteDeclared'] ?? 0));

		$availableCents = self::availableVrijeRuimteCents($loonsomCents);

		if ($usedCents <= $availableCents) {
			// Within budget — no exposure to record.
			return true;
		}

		$expectedExcessCents = ($usedCents - $availableCents);

		return (string)($o['status'] ?? '') === 'eindheffing-verschuldigd'
			&& self::cents($o['excess'] ?? null) === $expectedExcessCents
			&& self::ratesEqual((float)($o['eindheffingRate'] ?? 0), (float)self::eindheffingPercent())
			&& self::cents($o['eindheffingDue'] ?? null) === (int)round($expectedExcessCents * (self::eindheffingPercent() / 100));

	}//end isExposureRecorded()

	/**
	 * The available vrije ruimte in integer cents for a given fiscale-loonsom
	 * (cents), computed from the `wkr` table group tranche percentages/grens
	 * (design.md D4/spec.md REQ-WKR-002/-WKR-003):
	 * `loonsom <= grens ? loonsom * t1% : grens * t1% + (loonsom - grens) * t2%`.
	 *
	 * @param int $loonsomCents Fiscale loonsom in integer cents.
	 *
	 * @return int Available vrije ruimte in integer cents.
	 *
	 * @spec openspec/changes/wkr-administration/specs/wkr-administration/spec.md#REQ-WKR-002
	 */
	public static function availableVrijeRuimteCents(int $loonsomCents): int {
		$wkr = self::tables()->wkr();
		$t1Percent = $wkr['tranche1Percent'];
		$grensCents = $wkr['tranche1GrensCents'];
		$t2Percent = $wkr['tranche2Percent'];

		if ($loonsomCents <= $grensCents) {
			return (int)round($loonsomCents * ($t1Percent / 100));
		}

		$tranche1 = (int)round($grensCents * ($t1Percent / 100));
		$tranche2 = (int)round(($loonsomCents - $grensCents) * ($t2Percent / 100));

		return ($tranche1 + $tranche2);
	}//end availableVrijeRuimteCents()

	/**
	 * The eindheffing percentage from the `wkr` table group.
	 *
	 * @return float
	 */
	public static function eindheffingPercent(): float {
		return self::tables()->wkr()['eindheffingPercent'];
	}//end eindheffingPercent()

	/**
	 * Memoised TaxTables instance, loaded from `RuleCatalogue::VERSION`'s
	 * corresponding tax-year id ("nl-2026" — the only NL table shipped today).
	 * Falls back to a defensive default set (never throws) so a malformed/
	 * missing table degrades the predicate to its built-in fallback rather
	 * than crashing the audit (the NlEngineChecks resilience precedent).
	 *
	 * @return TaxTables
	 */
	private static function tables(): TaxTables {
		if (self::$tables === null) {
			self::$tables = TaxTables::load('nl-2026');
		}

		return self::$tables;
	}//end tables()

	/**
	 * Reset the memoised TaxTables instance (test hook).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$tables = null;

	}//end reset()

	/**
	 * Convert a euro-denominated value to integer cents (`round($euros * 100)`,
	 * the PayrollMutationService read-time boundary). Non-numeric/null values
	 * convert to 0.
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

	/**
	 * Compare two rate percentages at basis-point precision (the
	 * NlPayrollChecks::ratesEqual precedent).
	 *
	 * @param float $a Left rate.
	 * @param float $b Right rate.
	 *
	 * @return bool
	 */
	private static function ratesEqual(float $a, float $b): bool {
		return (int)round($a * 100) === (int)round($b * 100);
	}//end ratesEqual()

}//end class
