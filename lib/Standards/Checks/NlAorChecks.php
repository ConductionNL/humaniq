<?php

/**
 * NL Ambtenarenrecht (AOR) Check Provider
 *
 * Executable checks for the two ambtenarenwet-2017 corpus rules
 * (lib/Standards/rules/labour.json, framework `ambtenarenwet-2017`), both
 * anchored on `Employee` -- the two obligations that survived Wnra
 * normalization for every ambtenaar (aor-ambtenarenrecht design.md D2/D3):
 *
 * - `nl-ambtenaar-eed-vereist` (Employee, mandatory): vacuous whenever
 *   `publicSectorRegime` is null (an ordinary private-sector employee, the
 *   overwhelming majority of the population); else violates when
 *   `ambtseedAfgelegdOp` is empty -- a presence-only check (the
 *   `gebruikelijkloonJustification` MVP precedent), the oath ceremony's
 *   content is never validated (Ambtenarenwet 2017 art. 5).
 * - `nl-ambtenaar-nevenwerkzaamheden-melding` (Employee, mandatory): vacuous
 *   whenever `publicSectorRegime` is null; else violates when
 *   `nevenwerkzaamhedenGemeld` is not `true` -- a presence-only attestation,
 *   the disclosed content is never validated (Ambtenarenwet 2017 art. 9).
 *
 * Both predicates are object-local -- no cross-object context needed, the
 * `hr-signals` object-local shape. This provider does NOT implement
 * SeedsObjects: the three demonstrating Employee seeds (one `genormaliseerd`
 * clean, one `ambtenarenwet` clean, one `ambtenarenwet` missing the eed) live
 * declaratively in lib/Settings/register.d/hr-seed.json (ADR-001), the same
 * pattern NlSignalChecks documents for an intentional-violation demonstration.
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
 * @spec openspec/changes/aor-ambtenarenrecht/specs/aor-ambtenarenrecht/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Standards\Checks;

/**
 * The two ambtenarenwet-2017 presence-only obligations that survived Wnra.
 */
final class NlAorChecks implements CheckProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 *
	 * @spec openspec/changes/aor-ambtenarenrecht/specs/aor-ambtenarenrecht/spec.md
	 */
	public static function checks(): array {
		return [
			'Employee' => [
				// Ambtenarenwet 2017 art. 5 -- the ambtseed/-belofte must be
				// recorded for every ambtenaar.
				'nl-ambtenaar-eed-vereist' => static fn (array $object): bool => self::eedAfgelegd($object),
				// Ambtenarenwet 2017 art. 9 -- the nevenwerkzaamheden
				// integrity-disclosure attestation must be on file.
				'nl-ambtenaar-nevenwerkzaamheden-melding' => static fn (array $object): bool => self::nevenwerkzaamhedenGemeld($object),
			],
		];

	}//end checks()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 *
	 * @spec openspec/changes/aor-ambtenarenrecht/specs/aor-ambtenarenrecht/spec.md
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * True unless this employee is an ambtenaar (a non-null
	 * `publicSectorRegime`, one of an enumerated set of live regimes).
	 * A null/absent/empty regime is an ordinary private-sector employee for
	 * whom the ambtenaar obligations carry no meaning.
	 *
	 * @param array<string, mixed> $object The Employee object.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/aor-ambtenarenrecht/specs/aor-ambtenarenrecht/spec.md
	 */
	private static function isAmbtenaar(array $object): bool {
		return trim((string)($object['publicSectorRegime'] ?? '')) !== '';
	}//end isAmbtenaar()

	/**
	 * `nl-ambtenaar-eed-vereist` predicate (spec.md REQ-AOR-002): vacuous when
	 * `publicSectorRegime` is null (a private-sector employee, the majority of
	 * the population, must never fire this rule); otherwise satisfied only when
	 * a non-empty `ambtseedAfgelegdOp` date is on file. Presence-only -- the
	 * content or validity of the oath ceremony is never verified (the
	 * `nl-gebruikelijkloon-norm` MVP precedent).
	 *
	 * @param array<string, mixed> $object The Employee object.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/aor-ambtenarenrecht/specs/aor-ambtenarenrecht/spec.md#REQ-AOR-002
	 */
	private static function eedAfgelegd(array $object): bool {
		if (self::isAmbtenaar($object) === false) {
			// Vacuous: not an ambtenaar, the ambtseed obligation does not apply.
			return true;
		}

		return trim((string)($object['ambtseedAfgelegdOp'] ?? '')) !== '';
	}//end eedAfgelegd()

	/**
	 * `nl-ambtenaar-nevenwerkzaamheden-melding` predicate (spec.md
	 * REQ-AOR-003): vacuous when `publicSectorRegime` is null; otherwise
	 * satisfied only when the `nevenwerkzaamhedenGemeld` attestation is exactly
	 * `true`. Presence-only -- the disclosed content is never validated.
	 *
	 * @param array<string, mixed> $object The Employee object.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/aor-ambtenarenrecht/specs/aor-ambtenarenrecht/spec.md#REQ-AOR-003
	 */
	private static function nevenwerkzaamhedenGemeld(array $object): bool {
		if (self::isAmbtenaar($object) === false) {
			// Vacuous: not an ambtenaar, the disclosure obligation does not apply.
			return true;
		}

		return ($object['nevenwerkzaamhedenGemeld'] ?? false) === true;
	}//end nevenwerkzaamhedenGemeld()

}//end class
