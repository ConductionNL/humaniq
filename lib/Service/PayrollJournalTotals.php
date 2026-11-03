<?php

/**
 * Payroll journal totals
 *
 * Reads the totals of one PayrollRun in integer cents and says why they
 * cannot make a balanced payroll journal, if they cannot.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The totals of one run, as the GL post books them.
 *
 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-002
 */
final class PayrollJournalTotals {

	/**
	 * The totals every run carries; reimbursements are optional.
	 *
	 * @var list<string>
	 */
	private const REQUIRED = ['totalGross', 'totalEmployerCharges', 'totalLoonheffing', 'totalNet'];

	/**
	 * @param array<string, mixed> $run The PayrollRun.
	 *
	 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-002
	 */
	public function __construct(
		private readonly array $run,
	) {
	}//end __construct()

	/**
	 * Why the totals cannot make a journal, or null: a missing or
	 * non-numeric total, a negative total, or nothing on the debit side.
	 *
	 * @return string|null The diagnostic for the failed GL post.
	 *
	 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-002
	 */
	public function refusal(): ?string {
		return ($this->missing() ?? $this->unpostable());
	}//end refusal()

	/**
	 * A total in integer cents; 0 when the field is absent or not numeric.
	 *
	 * @param string $field The total field.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-002
	 */
	public function cents(string $field): int {
		if (is_numeric($this->run[$field] ?? null) === false) {
			return 0;
		}

		return (int)round(((float)$this->run[$field]) * 100);
	}//end cents()

	/**
	 * The first required total that is missing or not numeric, as a diagnostic.
	 *
	 * @return string|null
	 */
	private function missing(): ?string {
		foreach (self::REQUIRED as $field) {
			if (isset($this->run[$field]) === false || is_numeric($this->run[$field]) === false) {
				return sprintf('PayrollRun-veld "%s" ontbreekt of is niet numeriek.', $field);
			}
		}

		return null;
	}//end missing()

	/**
	 * Refuses a negative total and totals whose gross, employer charges and
	 * reimbursements add up to zero: either would post an empty or
	 * unbalanced journal.
	 *
	 * @return string|null
	 */
	private function unpostable(): ?string {
		foreach ([...self::REQUIRED, 'totalReimbursements'] as $field) {
			$cents = $this->cents($field);
			if ($cents < 0) {
				return sprintf('Negatief runtotaal: PayrollRun-veld "%s" is %.2f; een loonjournaalpost boekt geen negatieve bedragen.', $field, ($cents / 100));
			}
		}

		if (($this->cents('totalGross') + $this->cents('totalEmployerCharges') + $this->cents('totalReimbursements')) === 0) {
			return 'Runtotalen zijn nul: bruto, werkgeverslasten en declaraties samen 0,00; er is geen loonjournaalpost te boeken.';
		}

		return null;
	}//end unpostable()
}//end class
