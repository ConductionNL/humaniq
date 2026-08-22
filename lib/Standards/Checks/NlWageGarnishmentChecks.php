<?php

/**
 * NL Loonbeslag (Wage Garnishment) Check Provider
 *
 * The two loonbeslag executable checks (design.md D6), auto-discovered by
 * `RuleEngine::providers()` with zero manual registration:
 *
 * - `nl-loonbeslag-beslagvrije-voet-floor` (Payslip): vacuous when
 *   `loonbeslagId` is null (no garnishment on this payslip). Else resolves
 *   the referenced Loonbeslag via `$context['payroll']['loonbeslagenById']`
 *   (`RuleAuditService::buildPayrollContext()`'s `runsById` precedent) and
 *   asserts cents-exact `nettoPay >= Loonbeslag.beslagvrijeVoet` -- `>=` not
 *   `=`, since the employee may legitimately keep more than the floor when
 *   the order's `orderedAmount` was smaller than the available headroom.
 *   Vacuous also when the reference is dangling (a different, pre-existing
 *   class of data-integrity problem, not this rule's job).
 * - `nl-loonbeslag-single-active` (Loonbeslag): vacuous when the record
 *   itself is not `actief`, or the employee has zero or one `actief`
 *   Loonbeslag. Else flags every Loonbeslag in an overlapping-effective-range
 *   group of two or more `actief` records for the same `employeeId` -- the
 *   MVP's single-active-beslag assumption (design.md D4), made
 *   machine-checkable rather than a silent doc note.
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
 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-002
 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-005
 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

/**
 * Loonbeslag floor-enforcement + single-active-beslag executable checks.
 */
final class NlWageGarnishmentChecks implements CheckProvider, SeedsObjects {

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-002
	 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-005
	 */
	public static function checks(): array {
		return [
			'Payslip' => [
				'nl-loonbeslag-beslagvrije-voet-floor' => static fn (array $o, array $context): bool => self::isFloorRespected($o, $context),
			],
			'Loonbeslag' => [
				'nl-loonbeslag-single-active' => static fn (array $o, array $context): bool => self::isSingleActive($o, $context),
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
	 * One seeded `actief` Loonbeslag for the existing seeded anchor employee
	 * (`EMP-NL-0001`, 3.800/wit/permanent -- the `NlPayrollChecks::seedObjects()`
	 * anchor whose engine-computed nettoPay is €3.081,17, design.md Seed
	 * Data): `orderedAmount` €800,00 exceeds the headroom above
	 * `beslagvrijeVoet` €2.950,00, exercising the CLAMPED branch of the floor
	 * formula rather than the trivial unclamped case.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 *
	 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-001
	 */
	public static function seedObjects(): array {
		return [
			'Loonbeslag' => [
				[
					'employeeId' => 'EMP-NL-0001',
					'creditor' => 'Gerechtsdeurwaarderskantoor Van Dijk',
					'dossierRef' => 'GDW-2026-00123',
					'totalClaim' => 4200.00,
					'orderedAmount' => 800.00,
					'beslagvrijeVoet' => 2950.00,
					'status' => 'actief',
					'effectiveFrom' => '2026-01-01',
					'effectiveTo' => null,
					'activatedBy' => 'admin',
					'activatedAt' => '2026-01-02T09:00:00Z',
				],
			],
		];

	}//end seedObjects()

	/**
	 * The `nl-loonbeslag-beslagvrije-voet-floor` predicate (spec.md
	 * REQ-BESLAG-002/-007, design.md D6): vacuous when `loonbeslagId` is null
	 * or the reference is dangling; else asserts cents-exact `nettoPay >=
	 * beslagvrijeVoet`.
	 *
	 * @param array<string, mixed> $o The Payslip object.
	 * @param array<string, mixed> $context Evaluation context; reads `payroll.loonbeslagenById`.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-002
	 */
	private static function isFloorRespected(array $o, array $context): bool {
		$loonbeslagId = trim((string)($o['loonbeslagId'] ?? ''));
		if ($loonbeslagId === '') {
			// No garnishment on this payslip -- out of scope (vacuous pass).
			return true;
		}

		$loonbeslag = ($context['payroll']['loonbeslagenById'][$loonbeslagId] ?? null);
		if (is_array($loonbeslag) === false) {
			// Dangling reference -- a different, pre-existing class of
			// data-integrity problem, not this rule's job.
			return true;
		}

		$nettoPayCents = self::cents($o['nettoPay'] ?? null);
		$voetCents = self::cents($loonbeslag['beslagvrijeVoet'] ?? null);

		return $nettoPayCents >= $voetCents;
	}//end isFloorRespected()

	/**
	 * The `nl-loonbeslag-single-active` predicate (spec.md REQ-BESLAG-005/
	 * -007, design.md D4): vacuous when the record itself is not `actief`, or
	 * no OTHER `actief` Loonbeslag for the same employee overlaps its
	 * effective range; else a violation for every record in the overlapping
	 * group.
	 *
	 * @param array<string, mixed> $o The Loonbeslag object.
	 * @param array<string, mixed> $context Evaluation context; reads `payroll.loonbeslagenById`.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/loonbeslag/spec.md#REQ-BESLAG-005
	 */
	private static function isSingleActive(array $o, array $context): bool {
		if ((string)($o['status'] ?? '') !== 'actief') {
			// Only actief records can conflict for the single-active-beslag
			// MVP assumption.
			return true;
		}

		$employeeId = trim((string)($o['employeeId'] ?? ''));
		if ($employeeId === '') {
			return true;
		}

		$selfId = trim((string)($o['id'] ?? $o['@self']['id'] ?? ''));

		foreach ((array)($context['payroll']['loonbeslagenById'] ?? []) as $id => $other) {
			if ((string)$id === $selfId) {
				continue;
			}

			if (is_array($other) === false || (string)($other['status'] ?? '') !== 'actief') {
				continue;
			}

			if (trim((string)($other['employeeId'] ?? '')) !== $employeeId) {
				continue;
			}

			if (self::rangesOverlap($o, $other) === true) {
				return false;
			}
		}

		return true;
	}//end isSingleActive()

	/**
	 * Whether two Loonbeslag records' effective ranges overlap: a null
	 * `effectiveTo` is treated as open-ended (never before any other range's
	 * start).
	 *
	 * @param array<string, mixed> $a One Loonbeslag.
	 * @param array<string, mixed> $b The other Loonbeslag.
	 *
	 * @return bool
	 */
	private static function rangesOverlap(array $a, array $b): bool {
		[$aStart, $aEnd] = self::rangeBounds($a);
		[$bStart, $bEnd] = self::rangeBounds($b);

		return $aStart <= $bEnd && $bStart <= $aEnd;
	}//end rangesOverlap()

	/**
	 * A Loonbeslag's [effectiveFrom, effectiveTo] range as unix timestamps,
	 * an open/unparseable `effectiveTo` mapped to `PHP_INT_MAX` and an
	 * unparseable `effectiveFrom` mapped to `0` (defensively wide, never
	 * narrow -- an unparseable date must never hide a real overlap).
	 *
	 * @param array<string, mixed> $range The Loonbeslag.
	 *
	 * @return array{0: int, 1: int}
	 */
	private static function rangeBounds(array $range): array {
		$from = strtotime((string)($range['effectiveFrom'] ?? ''));

		$toRaw = trim((string)($range['effectiveTo'] ?? ''));
		$to = $toRaw === '' ? PHP_INT_MAX : strtotime($toRaw);

		return [
			($from === false ? 0 : $from),
			($to === false ? PHP_INT_MAX : $to),
		];

	}//end rangeBounds()

	/**
	 * Convert a euro-denominated value to integer cents (`round($euros *
	 * 100)`, the `NlWkrChecks::cents()` precedent). Non-numeric/null values
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

}//end class
