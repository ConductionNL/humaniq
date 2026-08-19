<?php

/**
 * Vocabulary
 *
 * The one place that names every op in the DSL's closed step vocabulary
 * (jurisdiction-packs design.md D3). Wiring the vocabulary in a single class
 * keeps `PackInterpreter` and `PackValidator` decoupled from the individual
 * ops, and makes "the vocabulary is closed" a fact you can read in one file.
 *
 * The escape-hatch handler allow-list is injected rather than built here, so a
 * caller can construct an interpreter with a different (still compile-time)
 * allow-list — for tests. It defaults to the shipped registry, which is EMPTY
 * (design.md D9: hrmq registers zero handlers; NL needs none).
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

use OCA\Hrmq\Payroll\Dsl\Ops\BracketOp;
use OCA\Hrmq\Payroll\Dsl\Ops\CappedRateOp;
use OCA\Hrmq\Payroll\Dsl\Ops\ClampOp;
use OCA\Hrmq\Payroll\Dsl\Ops\ExprOp;
use OCA\Hrmq\Payroll\Dsl\Ops\MatchOp;
use OCA\Hrmq\Payroll\Dsl\Ops\OpRegistry;
use OCA\Hrmq\Payroll\Dsl\Ops\PhpStepOp;
use OCA\Hrmq\Payroll\Dsl\Ops\PiecewiseAccrueOp;
use OCA\Hrmq\Payroll\Dsl\Ops\QuantizeOp;
use OCA\Hrmq\Payroll\Dsl\Ops\RateOp;
use OCA\Hrmq\Payroll\Dsl\Ops\TaperOp;
use OCA\Hrmq\Payroll\StepHandlerRegistry;

/**
 * Builds and holds the closed DSL vocabulary.
 */
final class Vocabulary {

	/**
	 * The reference resolver.
	 *
	 * @var RefResolver
	 */
	private readonly RefResolver $refs;

	/**
	 * The rounding-modifier applier.
	 *
	 * @var Rounder
	 */
	private readonly Rounder $rounder;

	/**
	 * The closed-grammar expression evaluator.
	 *
	 * @var ExprEvaluator
	 */
	private readonly ExprEvaluator $expr;

	/**
	 * The predicate evaluator.
	 *
	 * @var PredicateEvaluator
	 */
	private readonly PredicateEvaluator $predicates;

	/**
	 * The closed step-op vocabulary.
	 *
	 * @var OpRegistry
	 */
	private readonly OpRegistry $ops;

	/**
	 * @param StepHandlerRegistry|null $handlers The escape-hatch allow-list (defaults to the shipped, empty one).
	 */
	public function __construct(?StepHandlerRegistry $handlers = null) {
		$this->refs = new RefResolver();
		$this->rounder = new Rounder();
		$this->expr = new ExprEvaluator();
		$this->predicates = new PredicateEvaluator($this->refs);

		$this->ops = new OpRegistry(
			[
				new RateOp($this->refs),
				new CappedRateOp($this->refs),
				new BracketOp($this->refs),
				new TaperOp($this->refs),
				new PiecewiseAccrueOp($this->refs, $this->rounder),
				new QuantizeOp($this->refs),
				new ClampOp($this->refs),
				new MatchOp($this->refs),
				new ExprOp($this->refs, $this->expr),
				new PhpStepOp($this->refs, ($handlers ?? new StepHandlerRegistry())),
			]
		);

	}//end __construct()

	/**
	 * The closed step-op vocabulary.
	 *
	 * @return OpRegistry
	 */
	public function ops(): OpRegistry {
		return $this->ops;
	}//end ops()

	/**
	 * The reference resolver.
	 *
	 * @return RefResolver
	 */
	public function refs(): RefResolver {
		return $this->refs;
	}//end refs()

	/**
	 * The predicate evaluator.
	 *
	 * @return PredicateEvaluator
	 */
	public function predicates(): PredicateEvaluator {
		return $this->predicates;
	}//end predicates()

	/**
	 * The rounding-modifier applier.
	 *
	 * @return Rounder
	 */
	public function rounder(): Rounder {
		return $this->rounder;
	}//end rounder()

	/**
	 * The closed-grammar expression evaluator.
	 *
	 * @return ExprEvaluator
	 */
	public function expr(): ExprEvaluator {
		return $this->expr;
	}//end expr()

}//end class
