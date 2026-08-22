<?php

/**
 * Calculation Input Mapper
 *
 * The boundary between humaniq's `CalculationInput` DTO and a pack's own declared
 * input vocabulary (jurisdiction-packs design.md D6/D10).
 *
 * **The awfTariff seam, written down rather than papered over.**
 * `CalculationInput::$awfTariff` is `low|high` while the tables' keys are
 * `laag|hoog` — at HEAD `PayrollCalculator` bridged that with an inline PHP
 * ternary (`$input->awfTariff === 'high' ? ... : ...`). That mismatch is an
 * accident of humaniq's own DTO, not a fact about Dutch payroll, and **a pack
 * must not inherit it**: the NL pack's vocabulary matches its own tables
 * (`laag|hoog`) and knows nothing about `low|high`. So the mapping happens
 * HERE, at the boundary, exactly once — `CalculationInput`'s public contract
 * is untouched and the pack stays clean.
 *
 * Every mapping below is defensive in the same direction as HEAD: anything
 * that is not the exact positive token maps to the default, reproducing the
 * HEAD ternaries (`=== 'high'`, `=== 'hoog'`, `!== 'groen'`) bit-for-bit
 * rather than rejecting an unexpected value the old chain silently tolerated.
 *
 * This mapper is shared by `PayrollCalculator` (the runtime façade) and
 * `PackValidator` (the `$fixture` self-test vector runner), so the two can
 * never drift.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

/**
 * Maps a `CalculationInput` into a pack's declared input vocabulary.
 */
final class CalculationInputMapper {

	/**
	 * Map humaniq's DTO onto the pack's input contract.
	 *
	 * @param CalculationInput $input The calculation input.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-007
	 * @spec openspec/changes/30-procent-regeling/specs/30-procent-regeling/spec.md#REQ-30P-003
	 */
	public function toPackInputs(CalculationInput $input): array {
		return [
			'gross' => $input->grossMonthlySalaryCents,
			'taxTableColor' => ($input->taxTableColor === 'groen' ? 'groen' : 'wit'),
			'loonheffingskortingToegepast' => $input->loonheffingskortingToegepast,
			'dateOfBirth' => $input->dateOfBirth,
			'awfTariff' => ($input->awfTariff === 'high' ? 'hoog' : 'laag'),
			'aofTariff' => ($input->aofTariff === 'hoog' ? 'hoog' : 'laag'),
			'whkPercentage' => $input->whkPercentage,
			'verzekeringsplichtig' => $input->verzekeringsplichtig,
			'thirtyPercentRulingRate' => $input->thirtyPercentRulingRate,
		];

	}//end toPackInputs()

}//end class
