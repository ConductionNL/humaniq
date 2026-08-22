<?php

/**
 * Pack Interpreter
 *
 * The small, pure engine that executes a jurisdiction pack's declared chain
 * (jurisdiction-packs design.md D1/D2).
 *
 * **Purity (design.md D1, REQ-JP-002/008).** No container, no clock, no IO,
 * no network — the only external data are the injected `TaxTables` and the
 * supplied inputs, and the period is a supplied string. The same
 * `(inputs, pack, tables, period)` always yields byte-identical integer-cents
 * output. This is the exact discipline `PayrollCalculator` held at HEAD, and
 * it is why `lib/Payroll/` carries zero Nextcloud dependencies and stays
 * portable outside Nextcloud.
 *
 * **Termination (REQ-JP-008).** Bindings and steps are each evaluated exactly
 * once, in declared order, and may reference only earlier ones. There is no
 * loop, no recursion and no function definition in the DSL, so every pack is a
 * finite DAG evaluated once and evaluation always terminates.
 *
 * **Incidence: net is a FOLD, never a step (design.md D2, REQ-JP-003).** The
 * least portable line at HEAD was step 10 — `$nettoPayCents = ($tvl - $loonheffingCents)`
 * — a true statement about the Netherlands, not about payroll. Here every step
 * declares what its amount DOES to the payslip, and this class derives:
 *
 *     net             = gross - sum(amount where incidence == 'reduces-net')
 *     employerCharges =         sum(amount where incidence == 'employer-cost')
 *
 * For NL that yields `tvl - loonheffing` bit-for-bit — but as an EMERGENT
 * CONSEQUENCE of NL's employer steps honestly declaring `employer-cost`, not
 * as a rule baked into this interpreter. Grep this file for `zvw`, `awf` or
 * `loonheffing` and you will find nothing: there is no jurisdiction-specific
 * net rule here. A country whose pension contribution reduces net declares
 * `reduces-net` and the fold does the rest, with no interpreter change.
 *
 * `reserve` (NL: vakantiegeld) touches neither net nor employer cost — it is
 * accrued now and paid later, and folding it into either would misstate one of
 * them.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll\Dsl
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-003
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll\Dsl;

use OCA\Humaniq\Payroll\JurisdictionPack;
use OCA\Humaniq\Payroll\TaxTables;

/**
 * Executes a pack's declared chain and derives net from incidence.
 */
final class PackInterpreter {

	/**
	 * Subtracted from gross to reach net.
	 *
	 * @var string
	 */
	public const REDUCES_NET = 'reduces-net';

	/**
	 * Paid by the employer; never touches net.
	 *
	 * @var string
	 */
	public const EMPLOYER_COST = 'employer-cost';

	/**
	 * Reported on the payslip; no cash effect.
	 *
	 * @var string
	 */
	public const INFORMATIVE = 'informative';

	/**
	 * Accrued now, paid out later; not cash this period.
	 *
	 * @var string
	 */
	public const RESERVE = 'reserve';

	/**
	 * The complete, closed incidence vocabulary.
	 *
	 * @var array<int, string>
	 */
	public const INCIDENCES = [self::REDUCES_NET, self::EMPLOYER_COST, self::INFORMATIVE, self::RESERVE];

	/**
	 * @param Vocabulary $vocab The closed DSL vocabulary.
	 */
	public function __construct(
		private readonly Vocabulary $vocab = new Vocabulary(),
	) {

	}//end __construct()

	/**
	 * Execute a pack's chain.
	 *
	 * @param array<string, mixed> $inputs The supplied inputs, in the pack's own input vocabulary.
	 * @param JurisdictionPack $pack The pack to execute.
	 * @param TaxTables $tables The injected tax-year parameter set.
	 * @param string $period The wage period, `YYYY-MM` (supplied, never a clock read).
	 *
	 * @return PackRunResult
	 *
	 * @throws DslException When the pack or the inputs are invalid.
	 *
	 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-002
	 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-003
	 */
	public function run(array $inputs, JurisdictionPack $pack, TaxTables $tables, string $period): PackRunResult {
		$ctx = new StepContext($this->inputs($inputs, $pack), $tables, $period, $pack->meta());

		foreach ($pack->bindings() as $binding) {
			$ctx->setBinding((string)$binding['id'], $this->binding($binding, $ctx));
		}

		foreach ($pack->steps() as $step) {
			$ctx->setStep((string)$step['id'], $this->step($step, $ctx));
		}

		return $this->fold($pack, $ctx);
	}//end run()

	/**
	 * Derive net and employer charges by folding declared incidence. This is
	 * the whole of the "net" rule: it names no jurisdiction and no step.
	 *
	 * @param JurisdictionPack $pack The pack.
	 * @param StepContext $ctx The completed run context.
	 *
	 * @return PackRunResult
	 *
	 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-003
	 */
	private function fold(JurisdictionPack $pack, StepContext $ctx): PackRunResult {
		$gross = (int)$this->vocab->refs()->resolve($pack->grossRef(), $ctx);
		$net = $gross;
		$employerCharges = 0;

		foreach ($pack->steps() as $step) {
			$incidence = (string)$step['incidence'];
			$amount = (int)$ctx->step((string)$step['id']);

			if ($incidence === self::REDUCES_NET) {
				$net -= $amount;
			}

			if ($incidence === self::EMPLOYER_COST) {
				$employerCharges += $amount;
			}
		}

		return new PackRunResult(
			$ctx->allSteps(),
			$ctx->allBindings(),
			$gross,
			$net,
			$employerCharges,
			$ctx->allProvenance()
		);

	}//end fold()

	/**
	 * Evaluate one binding — a named intermediate with NO incidence (it is not
	 * money out).
	 *
	 * @param array<string, mixed> $binding The declared binding.
	 * @param StepContext $ctx The run context.
	 *
	 * @return mixed
	 *
	 * @throws DslException When the binding is malformed.
	 */
	private function binding(array $binding, StepContext $ctx): mixed {
		$using = ($binding['using'] ?? null);
		if (is_array($using) === false) {
			throw new DslException('Pack: binding "' . ($binding['id'] ?? '?') . '" mist het veld "using".');
		}

		$value = $this->apply($using, $ctx);
		$round = ($binding['round'] ?? null);

		// A binding may hold a boolean or a string (NL's `aow` gate, its
		// `schijvenSet` selection), so the rounding modifier is applied only
		// when one is actually declared — and then the value must be a number.
		if ($round === null) {
			return $value;
		}

		return $this->vocab->rounder()->apply($this->numeric($value, 'binding ' . ($binding['id'] ?? '?')), $round);
	}//end binding()

	/**
	 * Evaluate one step. A step whose `when` gate is false yields 0 WITHOUT
	 * resolving its params — so a step gated off may reference parameters that
	 * do not exist in its inapplicable column (NL's OUK is AOW-age only).
	 *
	 * @param array<string, mixed> $step The declared step.
	 * @param StepContext $ctx The run context.
	 *
	 * @return int|float
	 */
	private function step(array $step, StepContext $ctx): int|float {
		if (array_key_exists('when', $step) === true && $this->vocab->predicates()->truthy($step['when'], $ctx) === false) {
			return 0;
		}

		$value = $this->apply($step, $ctx);

		return $this->vocab->rounder()->apply($this->numeric($value, (string)$step['id']), ($step['round'] ?? null));
	}//end step()

	/**
	 * Dispatch a spec to its op, or to the predicate vocabulary.
	 *
	 * @param array<string, mixed> $spec The declared spec.
	 * @param StepContext $ctx The run context.
	 *
	 * @return mixed
	 *
	 * @throws DslException When the op is in neither vocabulary.
	 */
	private function apply(array $spec, StepContext $ctx): mixed {
		$op = (string)($spec['op'] ?? '');

		if ($this->vocab->ops()->has($op) === true) {
			return $this->vocab->ops()->get($op)->evaluate($spec, $ctx);
		}

		if (in_array($op, $this->vocab->predicates()->vocabulary(), true) === true) {
			return $this->vocab->predicates()->evaluate($spec, $ctx);
		}

		throw new DslException(
			'Pack: onbekende op "' . $op . '" (stap-ops: ' . implode(', ', $this->vocab->ops()->names()) . '; predicaten: ' . implode(', ', $this->vocab->predicates()->vocabulary()) . ').'
		);

	}//end apply()

	/**
	 * Guard that a step produced a number — a step is money (or a rate), never
	 * a string or a boolean.
	 *
	 * @param mixed $value The evaluated value.
	 * @param string $id The step id (for errors).
	 *
	 * @return int|float
	 *
	 * @throws DslException When the step produced a non-number.
	 */
	private function numeric(mixed $value, string $id): int|float {
		if (is_int($value) === true || is_float($value) === true) {
			return $value;
		}

		if (is_bool($value) === true) {
			throw new DslException('Pack: stap "' . $id . '" levert een boolean op; een stap moet een bedrag opleveren (gebruik een binding).');
		}

		throw new DslException('Pack: stap "' . $id . '" levert geen getal op.');
	}//end numeric()

	/**
	 * Validate the supplied inputs against the pack's declared input contract
	 * (design.md D6), applying declared defaults.
	 *
	 * @param array<string, mixed> $supplied The supplied inputs.
	 * @param JurisdictionPack $pack The pack.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws DslException When a required input is missing or ill-typed.
	 */
	private function inputs(array $supplied, JurisdictionPack $pack): array {
		$resolved = [];

		foreach ($pack->inputs() as $name => $declared) {
			$present = array_key_exists($name, $supplied);

			if ($present === false && array_key_exists('default', $declared) === true) {
				$resolved[$name] = $declared['default'];
				continue;
			}

			if ($present === false) {
				if (($declared['required'] ?? false) === true) {
					throw new DslException('Pack: verplichte invoer "' . $name . '" ontbreekt.');
				}

				$resolved[$name] = null;
				continue;
			}

			$resolved[$name] = $this->coerce($supplied[$name], (array)$declared, (string)$name);
		}

		return $resolved;
	}//end inputs()

	/**
	 * Coerce and check one supplied input against its declaration.
	 *
	 * @param mixed $value The supplied value.
	 * @param array<string, mixed> $declared The declaration.
	 * @param string $name The input name.
	 *
	 * @return mixed
	 *
	 * @throws DslException When the value violates the declaration.
	 */
	private function coerce(mixed $value, array $declared, string $name): mixed {
		$type = (string)($declared['type'] ?? 'string');

		if ($value === null) {
			if (($declared['nullable'] ?? false) === true) {
				return null;
			}

			throw new DslException('Pack: invoer "' . $name . '" mag niet null zijn.');
		}

		if ($type === 'enum') {
			$values = (array)($declared['values'] ?? []);
			if (in_array($value, $values, true) === false) {
				throw new DslException('Pack: invoer "' . $name . '" moet één van [' . implode(', ', $values) . '] zijn, kreeg "' . (string)$value . '".');
			}

			return $value;
		}

		return match ($type) {
			'cents' => (int)$value,
			'int' => (int)$value,
			'percent' => (float)$value,
			'boolean' => (bool)$value,
			'date' => (string)$value,
			default => (string)$value,
		};

	}//end coerce()

}//end class
