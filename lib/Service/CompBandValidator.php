<?php

/**
 * Comp Band Validator
 *
 * The `comp-adjustment-within-band` predicate, evaluated against an ALREADY
 * RESOLVED SalaryBand: refuses when the band could not be loaded, when its
 * `[minSalary, maxSalary]` is not numeric, or when the proposed salary sits
 * outside that range.
 *
 * Split out of `CompAdjustmentService` because that class had reached its
 * PHPMD `ExcessiveClassComplexity` budget, and this predicate is the part
 * that is genuinely separable: it is a pure decision over two integers and a
 * band, with no OpenRegister reach and no writes. The RESOLUTION of the band
 * (`findById('SalaryBand', ...)`) deliberately stays in the service, which
 * owns every OpenRegister lookup — this class never learns the store exists.
 * Same motivation as `ReceiptExtractionRepository`, opposite direction: there
 * the plumbing moved out, here the decision did.
 *
 * @category Service
 * @package  OCA\Hrmq\Service
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
 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
 */

declare(strict_types=1);

namespace OCA\Hrmq\Service;

/**
 * Decides whether a proposed salary sits within a resolved SalaryBand.
 */
class CompBandValidator {

	/**
	 * Evaluate the within-band predicate.
	 *
	 * @param array<string, mixed>|null $band The resolved SalaryBand, or null when it could not be loaded.
	 * @param int $proposedSalaryCents The proposed salary, in integer cents.
	 *
	 * @return array{status: string, message: string}|null Null when within band; an outcome fragment otherwise.
	 *
	 * @spec openspec/changes/comp-cycles/specs/comp-cycles/spec.md#REQ-COMP-007
	 */
	public function evaluate(?array $band, int $proposedSalaryCents): ?array {
		if ($band === null) {
			return [
				'status' => 'refused-band-unresolvable',
				'message' => 'De gekoppelde salarisschaal kon niet worden geladen; effectueren is geweigerd.',
			];
		}

		$minSalary = ($band['minSalary'] ?? null);
		$maxSalary = ($band['maxSalary'] ?? null);
		if (is_numeric($minSalary) === false || is_numeric($maxSalary) === false) {
			return [
				'status' => 'refused-band-unresolvable',
				'message' => 'De gekoppelde salarisschaal heeft geen geldig min/max-bereik; effectueren is geweigerd.',
			];
		}

		if ($proposedSalaryCents < (int)$minSalary || $proposedSalaryCents > (int)$maxSalary) {
			return [
				'status' => 'refused-out-of-band',
				'message' => 'Het voorgestelde salaris valt buiten de schaal (' . ((int)$minSalary) . '-' . ((int)$maxSalary) . ' cent); effectueren is geweigerd.',
			];
		}

		return null;
	}//end evaluate()

}//end class
