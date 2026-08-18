<?php

/**
 * Step Context
 *
 * The interpreter's per-run value store (jurisdiction-packs design.md D1):
 * the validated input map, the injected `TaxTables`, the run period, the pack
 * metadata, and the bindings/steps resolved so far.
 *
 * Purity discipline (design.md D1, REQ-JP-002/REQ-JP-008): no container, no
 * clock, no IO, no network — the ONLY external data are the injected tax
 * tables and the supplied inputs. The period is a supplied string, never read
 * from a clock, so the same `(input, pack, tables, period)` always yields
 * byte-identical output.
 *
 * A step may reference only steps and bindings declared EARLIER: this store
 * grows strictly forward, so a forward reference cannot resolve at runtime —
 * and `PackValidator` rejects it at upload long before that (design.md D11
 * gate 3).
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-002
 */

declare(strict_types=1);

namespace OCA\Hrmq\Payroll\Dsl;

use OCA\Hrmq\Payroll\TaxTables;

/**
 * The pure per-run binding/step store handed to every op.
 */
final class StepContext {

	/**
	 * Resolved bindings, by id.
	 *
	 * @var array<string, mixed>
	 */
	private array $bindings = [];

	/**
	 * Resolved step amounts, by id.
	 *
	 * @var array<string, int|float>
	 */
	private array $steps = [];

	/**
	 * Provenance of every table leaf this run resolved, keyed by leaf path.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $provenance = [];

	/**
	 * @param array<string, mixed> $inputs The validated input map, in the pack's own input vocabulary.
	 * @param TaxTables $tables The injected tax-year parameter set.
	 * @param string $period The wage period, `YYYY-MM` (supplied, never read from a clock).
	 * @param array<string, mixed> $meta The pack metadata (`currency`, `taxYear`, ...).
	 */
	public function __construct(
		private readonly array $inputs,
		private readonly TaxTables $tables,
		private readonly string $period,
		private readonly array $meta,
	) {

	}//end __construct()

	/**
	 * A declared input's value.
	 *
	 * @param string $name The input name.
	 *
	 * @return mixed
	 *
	 * @throws DslException When the input was not supplied or declared.
	 */
	public function input(string $name): mixed {
		if (array_key_exists($name, $this->inputs) === false) {
			throw new DslException('Pack: onbekende invoer "@input.' . $name . '".');
		}

		return $this->inputs[$name];
	}//end input()

	/**
	 * The injected tax tables.
	 *
	 * @return TaxTables
	 */
	public function tables(): TaxTables {
		return $this->tables;
	}//end tables()

	/**
	 * The run period, `YYYY-MM`.
	 *
	 * @return string
	 */
	public function period(): string {
		return $this->period;
	}//end period()

	/**
	 * A pack metadata field (`@pack.*`).
	 *
	 * @param string $name The metadata field name.
	 *
	 * @return mixed
	 *
	 * @throws DslException When the field is not declared.
	 */
	public function meta(string $name): mixed {
		if (array_key_exists($name, $this->meta) === false) {
			throw new DslException('Pack: onbekend pack-veld "@pack.' . $name . '".');
		}

		return $this->meta[$name];
	}//end meta()

	/**
	 * An earlier binding's value.
	 *
	 * @param string $id The binding id.
	 *
	 * @return mixed
	 *
	 * @throws DslException When the binding was not declared earlier.
	 */
	public function binding(string $id): mixed {
		if (array_key_exists($id, $this->bindings) === false) {
			throw new DslException('Pack: verwijzing naar niet-eerder-gedeclareerde binding "@binding.' . $id . '".');
		}

		return $this->bindings[$id];
	}//end binding()

	/**
	 * An earlier step's amount.
	 *
	 * @param string $id The step id.
	 *
	 * @return int|float
	 *
	 * @throws DslException When the step was not declared earlier.
	 */
	public function step(string $id): int|float {
		if (array_key_exists($id, $this->steps) === false) {
			throw new DslException('Pack: verwijzing naar niet-eerder-gedeclareerde stap "@step.' . $id . '".');
		}

		return $this->steps[$id];
	}//end step()

	/**
	 * Record a resolved binding.
	 *
	 * @param string $id The binding id.
	 * @param mixed $value The resolved value.
	 *
	 * @return void
	 */
	public function setBinding(string $id, mixed $value): void {
		$this->bindings[$id] = $value;

	}//end setBinding()

	/**
	 * Record a resolved step amount.
	 *
	 * @param string $id The step id.
	 * @param int|float $value The resolved amount.
	 *
	 * @return void
	 */
	public function setStep(string $id, int|float $value): void {
		$this->steps[$id] = $value;

	}//end setStep()

	/**
	 * Record the provenance of a table leaf this run resolved (design.md D11
	 * gate 6: `verified: false` / `placeholder: true` do not block, they are
	 * stamped onto the run).
	 *
	 * @param array<string, mixed> $leaf The leaf's provenance stamp.
	 *
	 * @return void
	 */
	public function setProvenance(array $leaf): void {
		$this->provenance[(string)$leaf['path']] = $leaf;

	}//end setProvenance()

	/**
	 * Every binding resolved so far.
	 *
	 * @return array<string, mixed>
	 */
	public function allBindings(): array {
		return $this->bindings;
	}//end allBindings()

	/**
	 * Every step amount resolved so far.
	 *
	 * @return array<string, int|float>
	 */
	public function allSteps(): array {
		return $this->steps;
	}//end allSteps()

	/**
	 * The provenance of every table leaf this run resolved.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function allProvenance(): array {
		return $this->provenance;
	}//end allProvenance()

}//end class
