<?php

/**
 * Expression Evaluator
 *
 * The `expr` op's grammar (jurisdiction-packs design.md D3, ADR-101 decision 2):
 * a **closed, total arithmetic calculator** — `+ - * /`, the functions
 * `min max abs round floor ceil`, parentheses, references and numeric
 * literals. Nothing else.
 *
 * **This grammar is deliberately not extensible.** There are no loops, no
 * recursion, no function definitions, no variable assignment, no string
 * operations, no IO, no clock, no host callbacks. Every expression is a finite
 * tree evaluated exactly once, so evaluation always terminates (REQ-JP-008).
 * An unknown identifier is rejected — the function list below is the entire
 * vocabulary.
 *
 * Widening this grammar into a general language would void the trust model
 * that lets hrmq execute an uploaded pack at all: "config, not code" is only
 * true while `expr` cannot express computation the validator cannot bound.
 * ADR-101 forbids widening it, and names where the pressure will come from
 * (VCR — cumulative year-to-date recalculation, which needs cross-period
 * state the DSL cannot express by construction). That goes to the named
 * escape hatch or to a future ADR; it does NOT come here.
 *
 * Parsing is separate from evaluation so `PackValidator` can check an
 * expression's grammar and nesting depth at UPLOAD time without a context and
 * without executing anything (design.md D11 gates 2 + 8).
 *
 * Reference segments inside an expression may not contain `-`, which is
 * unambiguously the subtraction operator here (`@step.x1 - @step.ahk`).
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-008
 */

declare(strict_types=1);

namespace OCA\Hrmq\Payroll\Dsl;

/**
 * Parses and evaluates the closed, total `expr` arithmetic grammar.
 */
final class ExprEvaluator {

	/**
	 * The complete function vocabulary. Adding to this list widens the
	 * grammar and is forbidden by ADR-101 decision 2.
	 *
	 * @var array<string, array{min: int, max: int}>
	 */
	public const FUNCTIONS = [
		'min' => ['min' => 2, 'max' => 2],
		'max' => ['min' => 2, 'max' => 2],
		'abs' => ['min' => 1, 'max' => 1],
		'round' => ['min' => 1, 'max' => 2],
		'floor' => ['min' => 1, 'max' => 1],
		'ceil' => ['min' => 1, 'max' => 1],
	];

	/**
	 * The maximum expression nesting depth (REQ-JP-008 — a declared bound the
	 * validator enforces, so a pack cannot nest its way into a stack overflow).
	 *
	 * @var int
	 */
	public const MAX_DEPTH = 32;

	/**
	 * The maximum raw expression length, in characters (REQ-JP-008).
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 2000;

	/**
	 * The parsed token stream for the expression currently being parsed.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $tokens = [];

	/**
	 * The parser's cursor into the token stream.
	 *
	 * @var int
	 */
	private int $cursor = 0;

	/**
	 * Parse an expression into an evaluable tree, enforcing the grammar and
	 * the depth bound. Executes nothing.
	 *
	 * @param string $expression The declared expression.
	 *
	 * @return array<string, mixed> The parsed tree.
	 *
	 * @throws DslException When the expression is malformed, uses an unknown
	 *                      identifier, or exceeds the depth bound.
	 *
	 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-008
	 */
	public function parse(string $expression): array {
		if (strlen($expression) > self::MAX_LENGTH) {
			throw new DslException('Pack: expressie overschrijdt de maximale lengte van ' . self::MAX_LENGTH . ' tekens.');
		}

		$this->tokens = $this->tokenize($expression);
		$this->cursor = 0;

		if ($this->tokens === []) {
			throw new DslException('Pack: lege expressie.');
		}

		$tree = $this->parseSum(1);

		if ($this->cursor < count($this->tokens)) {
			throw new DslException('Pack: onverwacht vervolg in expressie "' . $expression . '".');
		}

		return $tree;
	}//end parse()

	/**
	 * Collect every reference used by a parsed tree (so the validator can
	 * resolve them at upload time).
	 *
	 * @param array<string, mixed> $tree The parsed tree.
	 *
	 * @return array<int, string>
	 */
	public function refsOf(array $tree): array {
		if ($tree['t'] === 'ref') {
			return [(string)$tree['v']];
		}

		$refs = [];
		foreach (($tree['a'] ?? []) as $child) {
			$refs = array_merge($refs, $this->refsOf($child));
		}

		return $refs;
	}//end refsOf()

	/**
	 * Evaluate a parsed tree against a run context.
	 *
	 * @param array<string, mixed> $tree The parsed tree.
	 * @param StepContext $ctx The run context.
	 * @param RefResolver $refs The reference resolver.
	 *
	 * @return int|float
	 *
	 * @throws DslException When a reference does not resolve to a number.
	 */
	public function evaluate(array $tree, StepContext $ctx, RefResolver $refs): int|float {
		return match ($tree['t']) {
			'num' => $tree['v'],
			'ref' => $this->numeric($refs->resolve((string)$tree['v'], $ctx), (string)$tree['v']),
			'neg' => (0 - $this->evaluate($tree['a'][0], $ctx, $refs)),
			'bin' => $this->binary((string)$tree['op'], $this->evaluate($tree['a'][0], $ctx, $refs), $this->evaluate($tree['a'][1], $ctx, $refs)),
			default => $this->call($tree, $ctx, $refs),
		};

	}//end evaluate()

	/**
	 * Apply a binary arithmetic operator, preserving PHP's native int/float
	 * semantics (the HEAD calculator's exact arithmetic).
	 *
	 * @param string $op The operator.
	 * @param int|float $left The left operand.
	 * @param int|float $right The right operand.
	 *
	 * @return int|float
	 *
	 * @throws DslException On division by zero.
	 */
	private function binary(string $op, int|float $left, int|float $right): int|float {
		if ($op === '/' && (float)$right === 0.0) {
			throw new DslException('Pack: deling door nul in een expressie.');
		}

		return match ($op) {
			'+' => ($left + $right),
			'-' => ($left - $right),
			'*' => ($left * $right),
			default => ($left / $right),
		};

	}//end binary()

	/**
	 * Apply one of the six allow-listed functions.
	 *
	 * @param array<string, mixed> $tree The call node.
	 * @param StepContext $ctx The run context.
	 * @param RefResolver $refs The reference resolver.
	 *
	 * @return int|float
	 */
	private function call(array $tree, StepContext $ctx, RefResolver $refs): int|float {
		$args = [];
		foreach ($tree['a'] as $argument) {
			$args[] = $this->evaluate($argument, $ctx, $refs);
		}

		return match ((string)$tree['f']) {
			'min' => min($args[0], $args[1]),
			'max' => max($args[0], $args[1]),
			'abs' => abs($args[0]),
			'floor' => floor($args[0]),
			'ceil' => ceil($args[0]),
			default => round($args[0], (int)($args[1] ?? 0)),
		};

	}//end call()

	/**
	 * Guard that a resolved reference is a number — the arithmetic grammar has
	 * no string or boolean operations.
	 *
	 * @param mixed $value The resolved value.
	 * @param string $ref The reference (for errors).
	 *
	 * @return int|float
	 *
	 * @throws DslException When the value is not numeric.
	 */
	private function numeric(mixed $value, string $ref): int|float {
		if (is_int($value) === true || is_float($value) === true) {
			return $value;
		}

		throw new DslException('Pack: verwijzing "' . $ref . '" levert geen getal op en kan niet in een expressie worden gebruikt.');
	}//end numeric()

	/**
	 * `sum := product (('+'|'-') product)*`
	 *
	 * @param int $depth The current nesting depth.
	 *
	 * @return array<string, mixed>
	 */
	private function parseSum(int $depth): array {
		$node = $this->parseProduct($depth);

		while ($this->peekOperator('+') === true || $this->peekOperator('-') === true) {
			$op = (string)$this->tokens[$this->cursor]['v'];
			$this->cursor++;
			$node = [
				't' => 'bin',
				'op' => $op,
				'a' => [$node, $this->parseProduct($depth)],
			];
		}

		return $node;
	}//end parseSum()

	/**
	 * `product := unary (('*'|'/') unary)*`
	 *
	 * @param int $depth The current nesting depth.
	 *
	 * @return array<string, mixed>
	 */
	private function parseProduct(int $depth): array {
		$node = $this->parseUnary($depth);

		while ($this->peekOperator('*') === true || $this->peekOperator('/') === true) {
			$op = (string)$this->tokens[$this->cursor]['v'];
			$this->cursor++;
			$node = [
				't' => 'bin',
				'op' => $op,
				'a' => [$node, $this->parseUnary($depth)],
			];
		}

		return $node;
	}//end parseProduct()

	/**
	 * `unary := '-' unary | primary`
	 *
	 * @param int $depth The current nesting depth.
	 *
	 * @return array<string, mixed>
	 */
	private function parseUnary(int $depth): array {
		if ($this->peekOperator('-') === true) {
			$this->cursor++;
			return [
				't' => 'neg',
				'a' => [$this->parseUnary($depth)],
			];
		}

		return $this->parsePrimary($depth);
	}//end parseUnary()

	/**
	 * `primary := number | ref | call | '(' sum ')'`
	 *
	 * @param int $depth The current nesting depth.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws DslException When the depth bound is exceeded or the token is unexpected.
	 */
	private function parsePrimary(int $depth): array {
		if ($depth > self::MAX_DEPTH) {
			throw new DslException('Pack: expressie overschrijdt de maximale nestdiepte van ' . self::MAX_DEPTH . '.');
		}

		$token = ($this->tokens[$this->cursor] ?? null);
		if ($token === null) {
			throw new DslException('Pack: onverwacht einde van expressie.');
		}

		$this->cursor++;

		if ($token['t'] === 'num' || $token['t'] === 'ref') {
			return $token;
		}

		if ($token['t'] === 'ident') {
			return $this->parseCall((string)$token['v'], $depth);
		}

		if ($token['t'] === 'op' && $token['v'] === '(') {
			$node = $this->parseSum(($depth + 1));
			$this->expect(')');
			return $node;
		}

		throw new DslException('Pack: onverwacht teken "' . $token['v'] . '" in expressie.');
	}//end parsePrimary()

	/**
	 * `call := ident '(' sum (',' sum)* ')'`
	 *
	 * @param string $name The function name.
	 * @param int $depth The current nesting depth.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws DslException When the function is unknown or misapplied.
	 */
	private function parseCall(string $name, int $depth): array {
		if (array_key_exists($name, self::FUNCTIONS) === false) {
			throw new DslException('Pack: onbekende functie "' . $name . '" in een expressie (toegestaan: ' . implode(', ', array_keys(self::FUNCTIONS)) . ').');
		}

		$this->expect('(');

		$args = [$this->parseSum(($depth + 1))];
		while ($this->peekOperator(',') === true) {
			$this->cursor++;
			$args[] = $this->parseSum(($depth + 1));
		}

		$this->expect(')');

		$arity = self::FUNCTIONS[$name];
		if (count($args) < $arity['min'] || count($args) > $arity['max']) {
			throw new DslException('Pack: functie "' . $name . '" verwacht ' . $arity['min'] . '-' . $arity['max'] . ' argumenten, kreeg ' . count($args) . '.');
		}

		return [
			't' => 'call',
			'f' => $name,
			'a' => $args,
		];

	}//end parseCall()

	/**
	 * Whether the cursor sits on a given operator token.
	 *
	 * @param string $op The operator.
	 *
	 * @return bool
	 */
	private function peekOperator(string $op): bool {
		$token = ($this->tokens[$this->cursor] ?? null);

		return ($token !== null && $token['t'] === 'op' && $token['v'] === $op);
	}//end peekOperator()

	/**
	 * Consume an expected operator token.
	 *
	 * @param string $op The operator.
	 *
	 * @return void
	 *
	 * @throws DslException When the token is not present.
	 */
	private function expect(string $op): void {
		if ($this->peekOperator($op) === false) {
			throw new DslException('Pack: "' . $op . '" verwacht in expressie.');
		}

		$this->cursor++;

	}//end expect()

	/**
	 * Split an expression into tokens: numbers, references, identifiers and
	 * the fixed operator set. Any other character is rejected — that rejection
	 * is what keeps the grammar closed.
	 *
	 * @param string $expression The declared expression.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws DslException When an unexpected character appears.
	 */
	private function tokenize(string $expression): array {
		$tokens = [];
		$index = 0;
		$length = strlen($expression);

		while ($index < $length) {
			$char = $expression[$index];

			if (trim($char) === '') {
				$index++;
				continue;
			}

			if (str_contains('+-*/(),', $char) === true) {
				$tokens[] = [
					't' => 'op',
					'v' => $char,
				];
				$index++;
				continue;
			}

			if ($char === '@') {
				$tokens[] = [
					't' => 'ref',
					'v' => $this->readRef($expression, $index),
				];
				continue;
			}

			if (ctype_digit($char) === true) {
				$tokens[] = [
					't' => 'num',
					'v' => $this->readNumber($expression, $index),
				];
				continue;
			}

			if (ctype_alpha($char) === true) {
				$tokens[] = [
					't' => 'ident',
					'v' => $this->readIdent($expression, $index),
				];
				continue;
			}

			throw new DslException('Pack: onverwacht teken "' . $char . '" in expressie "' . $expression . '".');
		}//end while

		return $tokens;
	}//end tokenize()

	/**
	 * Read a reference token, including any `[...]` index groups and the
	 * `:cents` cast suffix.
	 *
	 * @param string $expression The expression.
	 * @param int $index The cursor, advanced past the token.
	 *
	 * @return string
	 *
	 * @throws DslException When a bracket group is unbalanced.
	 */
	private function readRef(string $expression, int &$index): string {
		$start = $index;
		$length = strlen($expression);
		$index++;

		while ($index < $length) {
			$char = $expression[$index];

			if (ctype_alnum($char) === true || $char === '_' || $char === '.') {
				$index++;
				continue;
			}

			if ($char === '[') {
				$close = strpos($expression, ']', $index);
				if ($close === false) {
					throw new DslException('Pack: ongebalanceerde "[" in een expressie-verwijzing.');
				}

				$index = ($close + 1);
				continue;
			}

			if (substr($expression, $index, 6) === ':cents') {
				$index += 6;
				continue;
			}

			break;
		}//end while

		return substr($expression, $start, ($index - $start));
	}//end readRef()

	/**
	 * Read a numeric literal.
	 *
	 * @param string $expression The expression.
	 * @param int $index The cursor, advanced past the token.
	 *
	 * @return int|float
	 */
	private function readNumber(string $expression, int &$index): int|float {
		$start = $index;
		$length = strlen($expression);

		while ($index < $length && (ctype_digit($expression[$index]) === true || $expression[$index] === '.')) {
			$index++;
		}

		$raw = substr($expression, $start, ($index - $start));

		if (str_contains($raw, '.') === true) {
			return (float)$raw;
		}

		return (int)$raw;
	}//end readNumber()

	/**
	 * Read an identifier (a function name).
	 *
	 * @param string $expression The expression.
	 * @param int $index The cursor, advanced past the token.
	 *
	 * @return string
	 */
	private function readIdent(string $expression, int &$index): string {
		$start = $index;
		$length = strlen($expression);

		while ($index < $length && ctype_alpha($expression[$index]) === true) {
			$index++;
		}

		return substr($expression, $start, ($index - $start));
	}//end readIdent()

}//end class
