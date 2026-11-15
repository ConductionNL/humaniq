<?php

/**
 * Unit tests for RefResolver — the `@namespace.path` reference grammar.
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll\Dsl;

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\Dsl\RefResolver;
use OCA\Humaniq\Payroll\Dsl\StepContext;
use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * Each namespace, dynamic path segments, provenance stamping, and every refusal.
 *
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-002
 */
class RefResolverTest extends TestCase {

	/**
	 * A literal is passed through; only an `@`-string is a reference.
	 *
	 * @return void
	 */
	public function testALiteralIsNotAReference(): void {
		$refs = new RefResolver();
		$ctx = $this->context();

		$this->assertFalse($refs->isRef(12));
		$this->assertFalse($refs->isRef('gross'));
		$this->assertTrue($refs->isRef('@input.gross'));
		$this->assertSame(12, $refs->value(12, $ctx));
		$this->assertSame('gross', $refs->value('gross', $ctx));
		$this->assertSame(250000, $refs->value('@input.gross', $ctx));

	}//end testALiteralIsNotAReference()

	/**
	 * Every non-table namespace resolves its one segment from the context.
	 *
	 * @return void
	 */
	public function testEachNamespaceResolves(): void {
		$refs = new RefResolver();
		$ctx = $this->context();
		$ctx->setBinding('aow', true);
		$ctx->setStep('tax', 4200);

		$this->assertTrue($refs->resolve('@binding.aow', $ctx));
		$this->assertSame(4200, $refs->resolve('@step.tax', $ctx));
		$this->assertSame('ZZ', $refs->resolve('@pack.jurisdiction', $ctx));
		$this->assertSame(2024, $refs->resolve('@period.year', $ctx));
		$this->assertSame('2024-02-29', $refs->resolve('@period.lastDay', $ctx), 'leap-year February');

	}//end testEachNamespaceResolves()

	/**
	 * A table leaf is unwrapped, converted with `:cents`, and its provenance
	 * stamped on the context; a bare value carries none.
	 *
	 * @return void
	 */
	public function testATableLeafIsUnwrappedAndStampsProvenance(): void {
		$refs = new RefResolver();
		$ctx = $this->context();

		$this->assertSame(0.25, $refs->resolve('@table.loonheffing.rate', $ctx));
		$this->assertSame(1235, $refs->resolve('@table.loonheffing.amount:cents', $ctx));
		$this->assertSame(['loonheffing.rate'], array_keys($ctx->allProvenance()));
		$this->assertSame('S1', $ctx->allProvenance()['loonheffing.rate']['source']);

	}//end testATableLeafIsUnwrappedAndStampsProvenance()

	/**
	 * A bracketed segment is a literal key, or — when it is itself a reference
	 * — the stringified value of that reference.
	 *
	 * @return void
	 */
	public function testDynamicSegmentsSelectByLiteralOrReference(): void {
		$refs = new RefResolver();
		$ctx = $this->context();
		$ctx->setBinding('aow', false);
		$ctx->setBinding('set', 'b');

		$this->assertSame(10, $refs->resolve('@table.loonheffing.byAow[true]', $ctx));
		$this->assertSame(20, $refs->resolve('@table.loonheffing.byAow[@binding.aow]', $ctx));
		$this->assertSame(2, $refs->resolve('@table.loonheffing.sets[@binding.set]', $ctx));
		$this->assertSame(7, $refs->resolve('@table.loonheffing.byYear[@period.year].x', $ctx));

	}//end testDynamicSegmentsSelectByLiteralOrReference()

	/**
	 * Each malformed reference is refused with its exact message.
	 *
	 * @param string $ref     The reference.
	 * @param string $message The expected message.
	 *
	 * @return void
	 *
	 * @dataProvider malformedRefs
	 */
	public function testAMalformedReferenceIsRejected(string $ref, string $message): void {
		$ctx = $this->context();
		$ctx->setBinding('rate', 0.5);

		$this->expectException(DslException::class);
		$this->expectExceptionMessage($message);

		(new RefResolver())->resolve($ref, $ctx);

	}//end testAMalformedReferenceIsRejected()

	/**
	 * Malformed references and their refusals.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function malformedRefs(): array {
		return [
			'unknown namespace' => [
				'@env.HOME',
				'Pack: onbekende verwijzings-namespace in "@env.HOME" (toegestaan: @input, @table, @step, @binding, @period, @pack).',
			],
			'empty reference' => ['@', 'Pack: onbekende verwijzings-namespace in "@"'],
			'cents off table' => [
				'@input.gross:cents',
				'Pack: het ":cents"-achtervoegsel geldt alleen voor @table-verwijzingen, niet voor "@input.gross:cents".',
			],
			'two segments' => ['@input.gross.monthly', 'Pack: verwijzing "@input.gross.monthly" moet precies één segment na @input hebben.'],
			'unknown period field' => [
				'@period.month',
				'Pack: onbekend periodeveld "@period.month" (toegestaan: @period.year, @period.lastDay).',
			],
			'missing table leaf' => [
				'@table.loonheffing.nope',
				'Pack: onbekende tabelverwijzing "@table.loonheffing.nope" (TaxTables (zz-test): ontbrekende parameter "loonheffing.nope".).',
			],
			'unbalanced bracket' => ['@table.loonheffing.byAow[true', 'Pack: ongebalanceerde "[" in verwijzing "@table.loonheffing.byAow[true".'],
			'float index' => [
				'@table.loonheffing.sets[@binding.rate]',
				'Pack: een dynamische padverwijzing moet een string, int of bool opleveren.',
			],
		];

	}//end malformedRefs()

	/**
	 * A period that is not `YYYY-MM` cannot back `@period.*`.
	 *
	 * @return void
	 */
	public function testAnUnreadablePeriodIsRejected(): void {
		$ctx = new StepContext([], $this->tables(), 'februari', []);

		$this->expectException(DslException::class);
		$this->expectExceptionMessage('Pack: onleesbare periode "februari" bij verwijzing "@period.year".');

		(new RefResolver())->resolve('@period.year', $ctx);

	}//end testAnUnreadablePeriodIsRejected()

	/**
	 * A context over a small synthetic table set, period 2024-02.
	 *
	 * @return StepContext
	 */
	private function context(): StepContext {
		return new StepContext(['gross' => 250000], $this->tables(), '2024-02', ['jurisdiction' => 'ZZ']);

	}//end context()

	/**
	 * A synthetic table set with one provenance leaf and some keyed maps.
	 *
	 * @return TaxTables
	 */
	private function tables(): TaxTables {
		$parameters = array_fill_keys(TaxTables::REQUIRED_GROUPS, []);
		$parameters['loonheffing'] = [
			'rate' => ['value' => 0.25, 'source' => 'S1', 'verified' => true],
			'amount' => 12.345,
			'byAow' => ['true' => 10, 'false' => 20],
			'sets' => ['a' => 1, 'b' => 2],
			'byYear' => ['2024' => ['x' => 7]],
		];

		return TaxTables::fromDocument(['id' => 'zz-test', 'parameters' => $parameters], 'test');

	}//end tables()

}//end class
