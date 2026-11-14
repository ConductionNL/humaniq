<?php

/**
 * Pack declaration message tests
 *
 * Pins the exact refusals of PackValidator's declaration gates (op, keys,
 * handler and references) for bindings, steps and the gross reference, so a
 * pack author keeps reading the same label and reason.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\JurisdictionPack;
use OCA\Humaniq\Payroll\PackValidator;
use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * The declaration gates' messages.
 */
class PackDeclarationMessagesTest extends TestCase {

	/**
	 * Each malformed declaration is refused with its own message.
	 *
	 * @param array<string, mixed> $overrides The pack fields that break it.
	 * @param string               $message   The exact refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refusals
	 */
	public function testEachDeclarationRefusal(array $overrides, string $message): void {
		try {
			(new PackValidator())->validate($this->pack($overrides), TaxTables::load('nl-2026'));
			self::fail('The pack was accepted.');
		} catch (DslException $e) {
			self::assertSame($message, $e->getMessage());
		}
	}

	/**
	 * The refusals.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusals(): array {
		$step = ['id' => 'tax', 'op' => 'expr', 'incidence' => 'reduces-net', 'expression' => '@input.gross * 10 / 100'];
		return [
			'binding with incidence' => [
				['bindings' => [['id' => 'b1', 'incidence' => 'reduces-net', 'using' => ['op' => 'expr', 'expression' => '1']]]],
				'Pack: binding "b1" declareert incidence; alleen stappen doen dat (een binding is geen geld).',
			],
			'binding without using' => [
				['bindings' => [['id' => 'b1']]],
				'Pack: binding "b1" mist het veld "using".',
			],
			'derive as op' => [
				['bindings' => [['id' => 'b1', 'using' => ['op' => 'derive']]]],
				'Pack: binding "b1" gebruikt "derive" als op; "derive" hoort op de binding zelf, de berekening staat in "using".',
			],
			'unknown key on a binding' => [
				['bindings' => [['id' => 'b1', 'using' => ['op' => 'rate', 'base' => '@input.gross', 'rate' => 0.1, 'class' => 'X']]]],
				'Pack: binding "b1" declareert het onbekende veld "class" voor op "rate". Een pack levert uitsluitend data — nooit code, een class-pad of een callable.',
			],
			'phpStep without handler' => [
				['steps' => [['id' => 'tax', 'op' => 'phpStep', 'incidence' => 'reduces-net']]],
				'Pack: stap "tax" gebruikt op "phpStep" maar declareert geen "handler"-naam.',
			],
			'unknown namespace' => [
				['steps' => [['incidence' => 'reduces-net', 'expression' => '@nope.gross * 1'] + $step]],
				'Pack: stap "tax" gebruikt de onbekende verwijzing "@nope.gross" (toegestaan: @' . implode(', @', \OCA\Humaniq\Payroll\Dsl\RefResolver::NAMESPACES) . ').',
			],
			'a later binding in a step' => [
				['steps' => [['expression' => '@binding.later * 1'] + $step]],
				'Pack: stap "tax" verwijst naar "@binding.later", die niet eerder is gedeclareerd. Een stap mag alleen naar eerdere stappen/bindings verwijzen (geen forward refs, dus geen cycles).',
			],
			'undeclared gross reference' => [
				['grossRef' => '@step.missing'],
				'Pack: "grossRef" verwijst naar "@step.missing", die niet eerder is gedeclareerd. Een stap mag alleen naar eerdere stappen/bindings verwijzen (geen forward refs, dus geen cycles).',
			],
			'unknown table leaf as gross reference' => [
				['grossRef' => '@table.nope'],
				'Pack: "grossRef" verwijst naar de onbekende tabelwaarde "@table.nope" (TaxTables (nl-2026): ontbrekende parameter "nope".).',
			],
		];
	}

	/**
	 * A minimal, valid uploaded pack with fields overridden.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 *
	 * @return JurisdictionPack
	 */
	private function pack(array $overrides): JurisdictionPack {
		$data = array_merge(
			[
				'id' => 'zz-2026',
				'jurisdiction' => 'ZZ',
				'taxYear' => 2026,
				'packVersion' => '1.0.0',
				'dslVersion' => '1.0',
				'tables' => 'nl-2026',
				'currency' => 'EUR',
				'grossRef' => '@input.gross',
				'inputs' => ['gross' => ['type' => 'cents', 'required' => true]],
				'bindings' => [],
				'steps' => [['id' => 'tax', 'op' => 'expr', 'incidence' => 'reduces-net', 'expression' => '@input.gross * 10 / 100', 'round' => ['mode' => 'nearest', 'unit' => 'cent']]],
				'selfTest' => ['vectors' => [['period' => '2026-02', 'input' => ['gross' => 100000], 'expected' => ['@net' => 90000]]]],
			],
			$overrides
		);

		return new JurisdictionPack($data, JurisdictionPack::ORIGIN_UPLOADED);
	}
}
