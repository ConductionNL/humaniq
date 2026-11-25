<?php

/**
 * NL Offboarding Check Provider
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Executable checks for the Dutch offboarding rules of the labour/payroll
 * corpus (lib/Standards/rules/labour.json, framework bw7-10), mapped onto the
 * Offboarding object type (offboarding-wizard-mvp): a dismissal-initiated
 * departure must record a transitievergoeding amount before its eindafrekening
 * is ready (nl-offboarding-transitievergoeding, BW 7:673), a completed case
 * must not leave an open leave balance unpaid (nl-offboarding-verlofsaldo-
 * uitbetaling, BW 7:641), a completed case without a getuigschrift is flagged
 * for HR verification (nl-offboarding-getuigschrift, BW 7:656), and a
 * completed case's lastWorkingDay must match the resolved Employee's endDate
 * (nl-offboarding-einddatum-consistentie, BW 7:667).
 *
 * The verlofsaldo and einddatum predicates are cross-object: they read the
 * `context['related']['LeaveBalance']` and `context['related']['Employee']`
 * indexes RuleAuditService::audit() populates in its pre-pass (design.md D3),
 * rather than loading siblings themselves. This provider does NOT implement
 * SeedsObjects: a self-contained sample cannot carry a resolvable `employeeId`
 * cross-reference (the onboarding/pension precedent) — the Offboarding seed
 * data instead lives in lib/Settings/register.d/hr-seed.json (ADR-001).
 *
 * Converted to the table-backed check form (rules-onto-or-decision-tables,
 * next conversion wave): each of the four offboarding rules is declared as an OpenRegister decision table via
 * the ProvidesTables capability. The derivations stay here; the shared
 * Dmn\DecisionTableEvaluator decides. The closure predicate remains
 * available as legacyChecks() -- the parity oracle, NOT registered in the
 * engine -- until staged retirement (REQ-RULE-009).
 *
 * @category Standards
 * @package  OCA\Humaniq\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/offboarding-wizard/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * Dutch offboarding (transitievergoeding / verlofsaldo / getuigschrift /
 * einddatum) executable checks.
 *
 * @spec openspec/specs/offboarding-wizard/spec.md
 */
final class NlOffboardingChecks implements CheckProvider, ProvidesTables {

	use BuildsSatisfiedTables;

	/**
	 * Departure reasons treated as dismissal-initiated (design.md D3):
	 * opzegging-werkgever and einde-contract (non-renewal of a fixed-term
	 * contract is employer-initiated by default under the WAB).
	 *
	 * @var string[]
	 */
	private const DISMISSAL_INITIATED_REASONS = ['opzegging-werkgever', 'einde-contract'];

	/**
	 * Offboarding statuses at/past "eindafrekening ready" — the transitievergoeding
	 * gate applies from here onward (design.md D3).
	 *
	 * @var string[]
	 */
	private const EINDAFREKENING_READY_OR_LATER = ['eindafrekening_gereed', 'afgerond'];

	/**
	 * {@inheritDoc}
	 *
	 * Empty: the check this provider owns is table-declared (see tables()).
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	public static function checks(): array {
		return [];

	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * The four Offboarding rules as derivation + FIRST-hit decision tables,
	 * each gated on the offboarding's own status.
	 *
	 * @return array<string, array<string, array{derive: callable, table: array<string, mixed>}>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 * @spec openspec/specs/offboarding-wizard/spec.md
	 */
	public static function tables(): array {
		return [
			'Offboarding' => [
				'nl-offboarding-transitievergoeding' => [
					'derive' => static fn (array $o): array => [
						'reason' => (string)($o['reason'] ?? ''),
						'status' => (string)($o['status'] ?? ''),
						'bedragRecorded' => (is_numeric($o['transitievergoedingBedrag'] ?? null) === true && (float)$o['transitievergoedingBedrag'] >= 0.0),
					],
					'table' => self::satisfiedTable(
						[['name' => 'reason', 'type' => 'string'], ['name' => 'status', 'type' => 'string'], ['name' => 'bedragRecorded', 'type' => 'boolean']],
						[['id' => 'dismissal-settled-without-bedrag', 'inputEntries' => ['in ("opzegging-werkgever","einde-contract")', 'in ("eindafrekening_gereed","afgerond")', 'false'], 'outputEntries' => [false]]]
					),
				],
				'nl-offboarding-verlofsaldo-uitbetaling' => [
					'derive' => static fn (array $o, array $c): array => [
						'afgerond' => ((string)($o['status'] ?? '') === 'afgerond'),
						'uitbetaald' => (($o['verlofsaldoUitbetaald'] ?? false) === true),
						'openBalanceHours' => self::openBalanceHours($o, $c),
					],
					'table' => self::satisfiedTable(
						[['name' => 'afgerond', 'type' => 'boolean'], ['name' => 'uitbetaald', 'type' => 'boolean'], ['name' => 'openBalanceHours', 'type' => 'number']],
						[['id' => 'closed-with-unpaid-balance', 'inputEntries' => ['true', 'false', '> 0'], 'outputEntries' => [false]]]
					),
				],
				'nl-offboarding-getuigschrift' => [
					'derive' => static fn (array $o): array => [
						'afgerond' => ((string)($o['status'] ?? '') === 'afgerond'),
						'getuigschriftVerstrekt' => (($o['getuigschriftVerstrekt'] ?? false) === true),
					],
					'table' => self::satisfiedTable(
						[['name' => 'afgerond', 'type' => 'boolean'], ['name' => 'getuigschriftVerstrekt', 'type' => 'boolean']],
						[['id' => 'closed-without-getuigschrift', 'inputEntries' => ['true', 'false'], 'outputEntries' => [false]]]
					),
				],
				'nl-offboarding-einddatum-consistentie' => [
					'derive' => static fn (array $o, array $c): array => self::einddatumInputs($o, $c),
					'table' => self::satisfiedTable(
						[['name' => 'afgerond', 'type' => 'boolean'], ['name' => 'employeeResolves', 'type' => 'boolean'], ['name' => 'endDateMatches', 'type' => 'boolean']],
						[
							['id' => 'closed-employee-missing', 'inputEntries' => ['true', 'false', '-'], 'outputEntries' => [false]],
							['id' => 'closed-end-date-differs', 'inputEntries' => ['true', '-', 'false'], 'outputEntries' => [false]],
						]
					),
				],
			],
		];

	}//end tables()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/specs/offboarding-wizard/spec.md
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * True unless `reason` is dismissal-initiated, `status` is at/past
	 * eindafrekening_gereed, and `transitievergoedingBedrag` is not a number
	 * greater than or equal to zero.
	 *
	 * @param array<string, mixed> $o The Offboarding case.
	 *
	 * @return bool
	 */
	private static function transitievergoedingSatisfied(array $o): bool {
		$reason = (string)($o['reason'] ?? '');
		if (in_array($reason, self::DISMISSAL_INITIATED_REASONS, true) === false) {
			return true;
		}

		$status = (string)($o['status'] ?? '');
		if (in_array($status, self::EINDAFREKENING_READY_OR_LATER, true) === false) {
			return true;
		}

		$bedrag = $o['transitievergoedingBedrag'] ?? null;

		return is_numeric($bedrag) === true && (float)$bedrag >= 0.0;
	}//end transitievergoedingSatisfied()

	/**
	 * True unless `status` is afgerond, `verlofsaldoUitbetaald` is not true,
	 * and the resolved employee's open leave balance (sum of
	 * max(0, entitledHours + bovenwettelijkHours - usedHours) over their
	 * LeaveBalance rows) is greater than zero. When no LeaveBalance row
	 * resolves for the employee the check is skipped (not fail-closed —
	 * balance rows are optional MVP data, design.md D3).
	 *
	 * @param array<string, mixed> $o The Offboarding case.
	 * @param array<string, mixed> $c Evaluation context (carries `related`).
	 *
	 * @return bool
	 */
	private static function verlofsaldoSatisfied(array $o, array $c): bool {
		if ((string)($o['status'] ?? '') !== 'afgerond') {
			return true;
		}

		if (($o['verlofsaldoUitbetaald'] ?? false) === true) {
			return true;
		}

		$employeeId = (string)($o['employeeId'] ?? '');
		$rows = self::relatedLeaveBalancesByEmployeeId($c)[$employeeId] ?? null;
		if (is_array($rows) === false || empty($rows) === true) {
			return true;
		}

		$openBalance = 0.0;
		foreach ($rows as $row) {
			$remaining = ((float)($row['entitledHours'] ?? 0)) + ((float)($row['bovenwettelijkHours'] ?? 0)) - ((float)($row['usedHours'] ?? 0));
			$openBalance += max(0.0, $remaining);
		}

		return $openBalance <= 0.0;
	}//end verlofsaldoSatisfied()

	/**
	 * True unless `status` is afgerond and `getuigschriftVerstrekt` is not
	 * true. Advisory (recommended severity) — the MVP has no "requested"
	 * field, so this flags every completed case without one.
	 *
	 * @param array<string, mixed> $o The Offboarding case.
	 *
	 * @return bool
	 */
	private static function hasGetuigschriftSatisfied(array $o): bool {
		if ((string)($o['status'] ?? '') !== 'afgerond') {
			return true;
		}

		return ($o['getuigschriftVerstrekt'] ?? false) === true;
	}//end hasGetuigschriftSatisfied()

	/**
	 * True unless `status` is afgerond and the resolved Employee's `endDate`
	 * does not equal `lastWorkingDay` (a missing/empty `endDate` counts as a
	 * mismatch). Fail-closed when `employeeId` does not resolve at afgerond.
	 *
	 * @param array<string, mixed> $o The Offboarding case.
	 * @param array<string, mixed> $c Evaluation context (carries `related`).
	 *
	 * @return bool
	 */
	private static function einddatumSatisfied(array $o, array $c): bool {
		if ((string)($o['status'] ?? '') !== 'afgerond') {
			return true;
		}

		$employeeId = (string)($o['employeeId'] ?? '');
		$employee = self::relatedEmployeesById($c)[$employeeId] ?? null;
		if (is_array($employee) === false) {
			return false;
		}

		$endDate = trim((string)($employee['endDate'] ?? ''));
		$lastWorkingDay = trim((string)($o['lastWorkingDay'] ?? ''));

		return $endDate !== '' && $endDate === $lastWorkingDay;
	}//end einddatumSatisfied()

	/**
	 * The `related.Employee.byId` index from the context, or an empty array
	 * when the pre-pass has not populated it.
	 *
	 * @param array<string, mixed> $c Evaluation context.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function relatedEmployeesById(array $c): array {
		$byId = ($c['related']['Employee']['byId'] ?? []);
		return is_array($byId) === true ? $byId : [];
	}//end relatedEmployeesById()

	/**
	 * The `related.LeaveBalance.byEmployeeId` index from the context, or an
	 * empty array when the pre-pass has not populated it.
	 *
	 * @param array<string, mixed> $c Evaluation context.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function relatedLeaveBalancesByEmployeeId(array $c): array {
		$byEmployeeId = ($c['related']['LeaveBalance']['byEmployeeId'] ?? []);
		return is_array($byEmployeeId) === true ? $byEmployeeId : [];
	}//end relatedLeaveBalancesByEmployeeId()

	/**
	 * The employee's open leave balance in hours: per LeaveBalance row the
	 * entitled plus bovenwettelijk hours minus used, never below zero. Zero
	 * when no rows resolve.
	 *
	 * @param array<string, mixed> $o The Offboarding.
	 * @param array<string, mixed> $c Evaluation context; reads the LeaveBalance index.
	 *
	 * @return float
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function openBalanceHours(array $o, array $c): float {
		$rows = (self::relatedLeaveBalancesByEmployeeId($c)[(string)($o['employeeId'] ?? '')] ?? null);
		if (is_array($rows) === false) {
			return 0.0;
		}

		$open = 0.0;
		foreach ($rows as $row) {
			$open += max(0.0, ((float)($row['entitledHours'] ?? 0)) + ((float)($row['bovenwettelijkHours'] ?? 0)) - ((float)($row['usedHours'] ?? 0)));
		}

		return $open;

	}//end openBalanceHours()

	/**
	 * The end-date table's inputs: whether the offboarding is closed, its
	 * employee resolves, and the employee's end date is the last working day.
	 *
	 * @param array<string, mixed> $o The Offboarding.
	 * @param array<string, mixed> $c Evaluation context; reads the Employee index.
	 *
	 * @return array{afgerond: bool, employeeResolves: bool, endDateMatches: bool}
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
	 */
	private static function einddatumInputs(array $o, array $c): array {
		$employee = (self::relatedEmployeesById($c)[(string)($o['employeeId'] ?? '')] ?? null);
		$endDate = (is_array($employee) === true) ? trim((string)($employee['endDate'] ?? '')) : '';

		return [
			'afgerond' => ((string)($o['status'] ?? '') === 'afgerond'),
			'employeeResolves' => is_array($employee),
			'endDateMatches' => ($endDate !== '' && $endDate === trim((string)($o['lastWorkingDay'] ?? ''))),
		];

	}//end einddatumInputs()

	/**
	 * The four offboarding closures from before the tables, kept to compare:
	 * NlOffboardingTableParityTest runs them beside tables(); the engine never
	 * sees them, and they go once the OpenRegister audit run agrees.
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-009
	 * @spec openspec/specs/offboarding-wizard/spec.md
	 */
	public static function legacyChecks(): array {
		return [
			'Offboarding' => [
				// BW art. 7:673 — transitievergoeding presence for
				// dismissal-initiated departures at/past eindafrekening_gereed.
				'nl-offboarding-transitievergoeding' => static fn (array $o): bool => self::transitievergoedingSatisfied($o),
				// BW art. 7:641 — open leave balance paid out at afgerond.
				'nl-offboarding-verlofsaldo-uitbetaling' => static fn (array $o, array $c): bool => self::verlofsaldoSatisfied($o, $c),
				// BW art. 7:656 — getuigschrift provided on request (advisory).
				'nl-offboarding-getuigschrift' => static fn (array $o): bool => self::hasGetuigschriftSatisfied($o),
				// BW art. 7:667 — Employee.endDate matches lastWorkingDay at afgerond.
				'nl-offboarding-einddatum-consistentie' => static fn (array $o, array $c): bool => self::einddatumSatisfied($o, $c),
			],
		];

	}//end legacyChecks()

}//end class
