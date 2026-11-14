<?php

/**
 * Unit tests for OpRegistry — the closed step vocabulary.
 *
 * The registry IS the vocabulary (REQ-JP-002): a name it does not carry is
 * refused, naming the op and listing what is allowed. These tests pin that
 * refusal and the name-keyed construction, with stub ops so the assertions
 * are about the registry and nothing else.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll\Dsl\Ops
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

namespace OCA\Humaniq\Tests\Unit\Payroll\Dsl\Ops;

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\Dsl\Ops\OpRegistry;
use OCA\Humaniq\Payroll\Dsl\Ops\StepOpInterface;
use OCA\Humaniq\Payroll\Dsl\StepContext;
use PHPUnit\Framework\TestCase;

/**
 * Registry construction, lookup and refusal.
 *
 * @covers \OCA\Humaniq\Payroll\Dsl\Ops\OpRegistry
 * @uses   \OCA\Humaniq\Payroll\Dsl\DslException
 */
class OpRegistryTest extends TestCase {

	/**
	 * The vocabulary is keyed by each op's own declared name, in the order the
	 * ops were handed in.
	 *
	 * @return void
	 */
	public function testTheVocabularyIsKeyedByEachOpsDeclaredName(): void {
		$registry = new OpRegistry([$this->op('rate'), $this->op('clamp')]);

		$this->assertSame(['rate', 'clamp'], $registry->names());
		$this->assertTrue($registry->has('rate'));
		$this->assertTrue($registry->has('clamp'));

	}//end testTheVocabularyIsKeyedByEachOpsDeclaredName()

	/**
	 * `get()` hands back the very instance that was registered, so an op's
	 * injected collaborators survive the lookup.
	 *
	 * @return void
	 */
	public function testGetReturnsTheRegisteredInstanceItself(): void {
		$rate = $this->op('rate');
		$registry = new OpRegistry([$rate, $this->op('clamp')]);

		$this->assertSame($rate, $registry->get('rate'));

	}//end testGetReturnsTheRegisteredInstanceItself()

	/**
	 * An op outside the vocabulary is refused, naming the op and listing the
	 * allowed names (REQ-JP-002) — never silently skipped.
	 *
	 * @return void
	 */
	public function testAnUnregisteredOpIsRefusedNamingTheAllowedSet(): void {
		$registry = new OpRegistry([$this->op('rate'), $this->op('clamp')]);

		$this->assertFalse($registry->has('shellOut'));

		try {
			$registry->get('shellOut');
			$this->fail('an unknown op must be refused');
		} catch (DslException $e) {
			$this->assertStringContainsString('onbekende op "shellOut"', $e->getMessage());
			$this->assertStringContainsString('rate, clamp', $e->getMessage());
		}

	}//end testAnUnregisteredOpIsRefusedNamingTheAllowedSet()

	/**
	 * Two ops declaring the same name collapse to one entry, the last one
	 * winning — the registry is a map, not a list.
	 *
	 * @return void
	 */
	public function testALaterOpWithTheSameNameReplacesTheEarlierOne(): void {
		$second = $this->op('rate');
		$registry = new OpRegistry([$this->op('rate'), $second]);

		$this->assertSame(['rate'], $registry->names());
		$this->assertSame($second, $registry->get('rate'));

	}//end testALaterOpWithTheSameNameReplacesTheEarlierOne()

	/**
	 * An empty vocabulary carries nothing and refuses everything.
	 *
	 * @return void
	 */
	public function testAnEmptyVocabularyRefusesEveryName(): void {
		$registry = new OpRegistry([]);

		$this->assertSame([], $registry->names());
		$this->assertFalse($registry->has('rate'));

		$this->expectException(DslException::class);
		$registry->get('rate');

	}//end testAnEmptyVocabularyRefusesEveryName()

	/**
	 * A stub op carrying only a name.
	 *
	 * @param string $name The declared op name.
	 *
	 * @return StepOpInterface
	 */
	private function op(string $name): StepOpInterface {
		return new class($name) implements StepOpInterface {

			/**
			 * @param string $name The declared op name.
			 */
			public function __construct(private readonly string $name) {

			}//end __construct()

			/**
			 * {@inheritDoc}
			 *
			 * @return string
			 */
			public function name(): string {
				return $this->name;
			}//end name()

			/**
			 * {@inheritDoc}
			 *
			 * @param array<string, mixed> $spec The declared spec.
			 * @param StepContext $ctx The run context.
			 *
			 * @return mixed
			 */
			public function evaluate(array $spec, StepContext $ctx): mixed {
				return 0;
			}//end evaluate()

		};

	}//end op()

}//end class
