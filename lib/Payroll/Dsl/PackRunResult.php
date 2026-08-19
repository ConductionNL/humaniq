<?php

/**
 * Pack Run Result
 *
 * One pack execution's output (jurisdiction-packs design.md D1/D2): every
 * step's amount, every binding's value, the DERIVED net and employer charges,
 * and the provenance of every table leaf the run touched.
 *
 * `net()` and `employerCharges()` are folds over declared incidence, computed
 * by `PackInterpreter` — they are not steps, and no pack authored them
 * (REQ-JP-003).
 *
 * @category Payroll
 * @package  OCA\Hrmq\Payroll\Dsl
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-003
 */

declare(strict_types=1);

namespace OCA\Hrmq\Payroll\Dsl;

/**
 * The output of one pack run.
 */
final class PackRunResult {

	/**
	 * @param array<string, int|float> $steps Every step's amount, by id.
	 * @param array<string, mixed> $bindings Every binding's value, by id.
	 * @param int $gross The gross base the incidence fold subtracted from.
	 * @param int $net The DERIVED net: gross - sum(reduces-net).
	 * @param int $employerCharges The DERIVED employer charges: sum(employer-cost).
	 * @param array<string, array<string, mixed>> $provenance Provenance of every table leaf this run resolved.
	 */
	public function __construct(
		private readonly array $steps,
		private readonly array $bindings,
		private readonly int $gross,
		private readonly int $net,
		private readonly int $employerCharges,
		private readonly array $provenance,
	) {

	}//end __construct()

	/**
	 * One step's amount.
	 *
	 * @param string $id The step id.
	 *
	 * @return int|float
	 *
	 * @throws DslException When the pack declared no such step.
	 */
	public function step(string $id): int|float {
		if (array_key_exists($id, $this->steps) === false) {
			throw new DslException('Pack: het pack declareert geen stap "' . $id . '".');
		}

		return $this->steps[$id];
	}//end step()

	/**
	 * One step's amount as integer cents.
	 *
	 * @param string $id The step id.
	 *
	 * @return int
	 */
	public function cents(string $id): int {
		return (int)$this->step($id);
	}//end cents()

	/**
	 * One binding's value.
	 *
	 * @param string $id The binding id.
	 *
	 * @return mixed
	 *
	 * @throws DslException When the pack declared no such binding.
	 */
	public function binding(string $id): mixed {
		if (array_key_exists($id, $this->bindings) === false) {
			throw new DslException('Pack: het pack declareert geen binding "' . $id . '".');
		}

		return $this->bindings[$id];
	}//end binding()

	/**
	 * The gross base, in cents.
	 *
	 * @return int
	 */
	public function gross(): int {
		return $this->gross;
	}//end gross()

	/**
	 * The derived net, in cents — `gross - sum(reduces-net)`.
	 *
	 * @return int
	 */
	public function net(): int {
		return $this->net;
	}//end net()

	/**
	 * The derived employer charges, in cents — `sum(employer-cost)`.
	 *
	 * @return int
	 */
	public function employerCharges(): int {
		return $this->employerCharges;
	}//end employerCharges()

	/**
	 * Every step amount, by id.
	 *
	 * @return array<string, int|float>
	 */
	public function allSteps(): array {
		return $this->steps;
	}//end allSteps()

	/**
	 * Provenance of every table leaf this run resolved.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function provenance(): array {
		return $this->provenance;
	}//end provenance()

	/**
	 * The leaves this run resolved that are unverified or placeholders — the
	 * provenance a run must stamp so downstream sees a stand-in rather than
	 * assuming engine truth (design.md D11 gate 6).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function unverifiedProvenance(): array {
		$flagged = [];
		foreach ($this->provenance as $leaf) {
			if ($leaf['verified'] === false || $leaf['placeholder'] === true) {
				$flagged[] = $leaf;
			}
		}

		return $flagged;
	}//end unverifiedProvenance()

}//end class
