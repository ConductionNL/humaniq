<?php

/**
 * Unit tests for PredicateEvaluator — the closed `when` / binding predicate set.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll\Dsl
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

namespace OCA\Humaniq\Tests\Unit\Payroll\Dsl;

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\Dsl\PredicateEvaluator;
use OCA\Humaniq\Payroll\Dsl\RefResolver;
use OCA\Humaniq\Payroll\Dsl\StepContext;
use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * Comparisons, boolean folds, the date predicates and their refusals.
 *
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-002
 */
class PredicateEvaluatorTest extends TestCase {

	/**
	 * Every comparison, on resolved references and literals.
	 *
	 * @return void
	 */
	public function testComparisonsResolveBothSides(): void {
		$cases = [
			['eq', 5, 5, true],
			['eq', 5, 5.0, false],
			['ne', 5, 6, true],
			['lt', 4, 5, true],
			['lt', 5, 5, false],
			['lte', 5, 5, true],
			['gt', 6, 5, true],
			['gte', 4, 5, false],
		];

		$got = [];
		foreach ($cases as [$op, $left, $right]) {
			$got[] = $this->evaluate(['op' => $op, 'left' => $left, 'right' => $right]);
		}

		$this->assertSame(array_column($cases, 3), $got);
		$this->assertTrue($this->evaluate(['op' => 'gt', 'left' => '@input.gross', 'right' => 100000]));

	}//end testComparisonsResolveBothSides()

	/**
	 * `and` / `or` fold their operands; `not` negates one.
	 *
	 * @return void
	 */
	public function testBooleanFolds(): void {
		$true = ['op' => 'eq', 'left' => 1, 'right' => 1];
		$false = ['op' => 'eq', 'left' => 1, 'right' => 2];

		$this->assertTrue($this->evaluate(['op' => 'and', 'of' => [$true, $true]]));
		$this->assertFalse($this->evaluate(['op' => 'and', 'of' => [$true, $false]]));
		$this->assertTrue($this->evaluate(['op' => 'or', 'of' => [$false, $true]]));
		$this->assertFalse($this->evaluate(['op' => 'or', 'of' => [$false, $false]]));
		$this->assertTrue($this->evaluate(['op' => 'not', 'of' => $false]));
		$this->assertTrue($this->evaluate(['op' => 'not', 'of' => '@input.aow']), 'a false reference negates to true');
		$this->assertFalse($this->evaluate(['op' => 'not', 'of' => '@input.gross']), 'a non-zero amount is truthy');

	}//end testBooleanFolds()

	/**
	 * `ageReached` at day and month granularity, and false for an absent or
	 * unreadable birth date.
	 *
	 * @return void
	 */
	public function testAgeReached(): void {
		$spec = static function (string $dob, string $granularity): array {
			return [
				'op' => 'ageReached',
				'dob' => $dob,
				'years' => 67,
				'asOf' => '2026-02-01',
				'granularity' => $granularity,
			];
		};

		$this->assertTrue($this->evaluate($spec('1959-02-01', 'day')));
		$this->assertFalse($this->evaluate($spec('1959-02-02', 'day')));
		$this->assertTrue($this->evaluate($spec('1959-02-28', 'month')), 'same month counts at month granularity');
		$this->assertFalse($this->evaluate($spec('1959-03-01', 'month')));
		$this->assertFalse($this->evaluate($spec('', 'day')));
		$this->assertFalse($this->evaluate($spec('not a date', 'day')));

	}//end testAgeReached()

	/**
	 * `yearOf` reads a date's year and falls back to a declared default for
	 * an empty or unreadable date — never to the clock.
	 *
	 * @return void
	 */
	public function testYearOfFallsBackToTheDeclaredDefault(): void {
		$this->assertSame(1990, $this->evaluate(['op' => 'yearOf', 'value' => '1990-07-14']));
		$this->assertSame(1900, $this->evaluate(['op' => 'yearOf', 'value' => '', 'default' => 1900]));
		$this->assertSame(1901, $this->evaluate(['op' => 'yearOf', 'value' => 'garbage', 'default' => 1901]));

	}//end testYearOfFallsBackToTheDeclaredDefault()

	/**
	 * Without a default, an unreadable date is an error that keeps its cause.
	 *
	 * @return void
	 */
	public function testYearOfWithoutDefaultRejectsAnUnreadableDate(): void {
		try {
			$this->evaluate(['op' => 'yearOf', 'value' => 'garbage']);
			$this->fail('expected a DslException');
		} catch (DslException $e) {
			$this->assertSame('Pack: onleesbare of ontbrekende datum voor "yearOf" en geen "default" gedeclareerd.', $e->getMessage());
			$this->assertInstanceOf(\Exception::class, $e->getPrevious());
		}

	}//end testYearOfWithoutDefaultRejectsAnUnreadableDate()

	/**
	 * Each malformed predicate is refused with its exact message.
	 *
	 * @param array<string, mixed> $spec    The predicate.
	 * @param string               $message The expected message.
	 *
	 * @return void
	 *
	 * @dataProvider malformedPredicates
	 */
	public function testAMalformedPredicateIsRejected(array $spec, string $message): void {
		$this->expectException(DslException::class);
		$this->expectExceptionMessage($message);

		$this->evaluate($spec);

	}//end testAMalformedPredicateIsRejected()

	/**
	 * Malformed predicates and their refusals.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function malformedPredicates(): array {
		return [
			'missing of' => [['op' => 'not'], 'Pack: predicaat "not" mist het veld "of".'],
			'empty of' => [['op' => 'and', 'of' => []], 'Pack: predicaat "and" verwacht een niet-lege lijst in "of".'],
			'scalar of' => [['op' => 'or', 'of' => true], 'Pack: predicaat "or" verwacht een niet-lege lijst in "of".'],
			'unknown op' => [
				['op' => 'between'],
				'Pack: onbekend predicaat "between" (toegestaan: eq, ne, lt, lte, gt, gte, and, or, not, ageReached, yearOf).',
			],
		];

	}//end malformedPredicates()

	/**
	 * Evaluate a predicate against gross 250000 and a false `aow` input.
	 *
	 * @param array<string, mixed> $spec The predicate.
	 *
	 * @return mixed
	 */
	private function evaluate(array $spec): mixed {
		$ctx = new StepContext(['gross' => 250000, 'aow' => false], TaxTables::load('nl-2026'), '2026-02', []);

		return (new PredicateEvaluator(new RefResolver()))->evaluate($spec, $ctx);

	}//end evaluate()

}//end class
