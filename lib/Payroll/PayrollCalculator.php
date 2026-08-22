<?php

/**
 * Payroll Calculator
 *
 * The gross-to-net façade (jurisdiction-packs design.md D10). Resolves the
 * jurisdiction pack for the run, delegates the entire chain to the pure
 * `PackInterpreter`, and maps the interpreter's output back onto
 * `CalculationResult`'s 18 fields.
 *
 * **This class no longer knows any Dutch payroll rules.** Until the
 * jurisdiction-packs change it was 130 lines of numbered NL steps —
 * vakantiegeld reservering, tabelloon floor-to-multiple, schijventarief
 * bracket pick, AHK/ARK/OUK heffingskortingen with a tapered min-chain,
 * whole-euro floors, the informative volksverzekeringen split, a capped Zvw
 * levy, four capped employer premiums, and a netto line that was literally
 * `tvl - loonheffing`. All of it now lives in
 * `lib/Standards/packs/nl-2026.pack.json` as declarative configuration, and
 * a second country onboards by uploading a pack rather than by shipping PHP
 * (ADR-101).
 *
 * The private helpers that carried that logic — `arkChain()`,
 * `selectBracket()`, `isAowAge()`, `schijvenSet()`, `floorEuroCents()`,
 * `ceilEuroCents()`, `round5Cents()` and `round2Cents()` — are DELETED, their
 * behaviour absorbed into interpreter ops (`piecewiseAccrue`, `bracket`, the
 * `ageReached`/`yearOf` predicates, `match`, and the `round` modifier).
 * Deleting them is the proof: if any had survived, some NL logic stayed in PHP.
 *
 * **The public contract is unchanged** (REQ-JP-007):
 * `calculate(CalculationInput, TaxTables): CalculationResult`, same signature,
 * same 18 result fields. `PayrollRunService`, `RetroAdjustmentService`,
 * `ProformaPayslipService`, `NlRetroChecks`, `PayrollCalculatorTest` and
 * `BalancingInvariantTest` all call it exactly as before.
 *
 * **Netto is not computed here.** `CalculationResult::$nettoPayCents` comes
 * from the interpreter's incidence fold (`gross - sum(reduces-net)`), which
 * names no jurisdiction. For NL it yields `tvl - loonheffing` — today's step
 * 10, bit-for-bit — as an emergent consequence of the NL pack declaring every
 * employer charge `employer-cost` (design.md D2).
 *
 * Zero Nextcloud dependencies are preserved throughout `lib/Payroll/`: no
 * container, no clock, no IO beyond the `TaxTables` instance passed in and the
 * bundled pack file the resolver reads (the `TaxTables::load()` precedent).
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
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-002
 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-001
 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-002
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

use OCA\Humaniq\Payroll\Dsl\PackInterpreter;
use OCA\Humaniq\Payroll\Dsl\PackRunResult;

/**
 * Thin façade over the pack interpreter; holds no jurisdiction rules.
 */
final class PayrollCalculator {

	/**
	 * The pack resolver.
	 *
	 * @var PackRepository
	 */
	private readonly PackRepository $packs;

	/**
	 * The pure chain interpreter.
	 *
	 * @var PackInterpreter
	 */
	private readonly PackInterpreter $interpreter;

	/**
	 * The DTO-to-pack input boundary mapper.
	 *
	 * @var CalculationInputMapper
	 */
	private readonly CalculationInputMapper $mapper;

	/**
	 * Every dependency defaults, so `new PayrollCalculator()` keeps working
	 * for the pure call sites that construct it directly (e.g. `NlRetroChecks`)
	 * while the container can still inject a resolver wired to uploaded packs.
	 *
	 * @param PackRepository|null $packs The pack resolver.
	 * @param PackInterpreter|null $interpreter The chain interpreter.
	 * @param CalculationInputMapper|null $mapper The boundary mapper.
	 */
	public function __construct(
		?PackRepository $packs = null,
		?PackInterpreter $interpreter = null,
		?CalculationInputMapper $mapper = null,
	) {
		$this->packs = ($packs ?? new PackRepository());
		$this->interpreter = ($interpreter ?? new PackInterpreter());
		$this->mapper = ($mapper ?? new CalculationInputMapper());

	}//end __construct()

	/**
	 * Compute the full gross-to-net component breakdown for one employee in
	 * one wage period, through the run's jurisdiction pack.
	 *
	 * @param CalculationInput $in The calculation input.
	 * @param TaxTables $t The tax-year parameter set the pack's `@table.*` refs resolve against.
	 *
	 * @return CalculationResult
	 *
	 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-001
	 * @spec openspec/changes/payroll-core-engine/specs/payroll-core-engine/spec.md#REQ-PCE-002
	 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-001
	 * @spec openspec/specs/dga-payroll-mode/spec.md#REQ-DGA-002
	 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-007
	 */
	public function calculate(CalculationInput $in, TaxTables $t): CalculationResult {
		$pack = $this->packs->resolve($in->jurisdiction, $in->period);
		$out = $this->interpreter->run($this->mapper->toPackInputs($in), $pack, $t, $in->period);

		return $this->resultFrom($out);
	}//end calculate()

	/**
	 * Map the interpreter's output onto `CalculationResult`'s 18 fields.
	 *
	 * `nettoPayCents` and `employerChargesCents` are the interpreter's
	 * incidence folds — this method reads them, it does not compute them
	 * (REQ-JP-003).
	 *
	 * @param PackRunResult $out The pack run's output.
	 *
	 * @return CalculationResult
	 *
	 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-003
	 */
	private function resultFrom(PackRunResult $out): CalculationResult {
		return new CalculationResult(
			grossPayCents: $out->gross(),
			loonheffingCents: $out->cents('loonheffing'),
			arbeidskortingCents: $out->cents('arbeidskorting'),
			volksverzekeringenCents: $out->cents('volksverzekeringen'),
			zvwCents: $out->cents('zvw'),
			zvwMode: 'werkgeversheffing',
			zvwRate: (float)$out->binding('zvwRate'),
			appliedTaxRate: (float)$out->step('appliedTaxRate'),
			nettoPayCents: $out->net(),
			vakantiegeldReservedCents: $out->cents('vakantiegeld'),
			vakantiegeldRate: (float)$out->binding('vakantiegeldRate'),
			awfCents: $out->cents('awf'),
			aofCents: $out->cents('aof'),
			wkoCents: $out->cents('wko'),
			whkCents: $out->cents('whk'),
			werknemersverzekeringenCents: $out->cents('werknemersverzekeringen'),
			employerChargesCents: $out->employerCharges(),
			aboveLmax: (bool)$out->binding('aboveLmax')
		);

	}//end resultFrom()

}//end class
