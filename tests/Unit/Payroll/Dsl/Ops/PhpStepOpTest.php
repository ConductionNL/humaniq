<?php

/**
 * Unit tests for PhpStepOp — the named escape hatch.
 *
 * The point of this op is what it does NOT do: it reads a handler NAME and a
 * data-only params map, and resolves the name against the compile-time
 * allow-list. There is no path here that loads a class, evaluates a string or
 * invokes a pack-supplied callable, and an unresolvable handler throws rather
 * than skipping the step — a skipped step would silently under-tax someone.
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll\Dsl\Ops;

use OCA\Humaniq\Payroll\Dsl\DslException;
use OCA\Humaniq\Payroll\Dsl\Ops\PhpStepOp;
use OCA\Humaniq\Payroll\Dsl\RefResolver;
use OCA\Humaniq\Payroll\Dsl\StepContext;
use OCA\Humaniq\Payroll\JurisdictionStepHandlerInterface;
use OCA\Humaniq\Payroll\StepHandlerRegistry;
use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;

/**
 * `phpStep(handler, params?)`.
 *
 * @covers \OCA\Humaniq\Payroll\Dsl\Ops\PhpStepOp
 * @uses   \OCA\Humaniq\Payroll\Dsl\Ops\AbstractOp
 * @uses   \OCA\Humaniq\Payroll\Dsl\RefResolver
 * @uses   \OCA\Humaniq\Payroll\Dsl\StepContext
 * @uses   \OCA\Humaniq\Payroll\Dsl\DslException
 * @uses   \OCA\Humaniq\Payroll\StepHandlerRegistry
 * @uses   \OCA\Humaniq\Payroll\TaxTables
 */
class PhpStepOpTest extends TestCase {

	/**
	 * The op declares the name a pack writes.
	 *
	 * @return void
	 */
	public function testTheOpDeclaresItsPackName(): void {
		$this->assertSame('phpStep', $this->op()->name());

	}//end testTheOpDeclaresItsPackName()

	/**
	 * The allow-listed handler is invoked with the declared params and the run
	 * context, and its amount is the step's amount.
	 *
	 * @return void
	 */
	public function testTheAllowListedHandlerIsInvokedWithTheDeclaredParamsAndContext(): void {
		$handler = $this->handler('arkChain');
		$ctx = $this->context();

		$amount = $this->op($handler)->evaluate(
			[
				'handler' => 'arkChain',
				'params'  => ['grondslag' => 4557600],
			],
			$ctx
		);

		$this->assertSame(1234, $amount);
		$this->assertSame([['grondslag' => 4557600]], $handler->calls, 'the params map is handed through unchanged');
		$this->assertSame([$ctx], $handler->contexts, 'and so is the run context itself');

	}//end testTheAllowListedHandlerIsInvokedWithTheDeclaredParamsAndContext()

	/**
	 * Params are optional: a handler that needs none is called with an empty
	 * map rather than being refused.
	 *
	 * @return void
	 */
	public function testParamsAreOptionalAndDefaultToAnEmptyMap(): void {
		$handler = $this->handler('arkChain');

		$this->op($handler)->evaluate(['handler' => 'arkChain'], $this->context());

		$this->assertSame([[]], $handler->calls);

	}//end testParamsAreOptionalAndDefaultToAnEmptyMap()

	/**
	 * A handler name outside the allow-list is refused — never skipped, and
	 * never resolved as a class path.
	 *
	 * @return void
	 */
	public function testAHandlerOutsideTheAllowListIsRefusedNotSkipped(): void {
		$this->expectException(DslException::class);

		$this->op($this->handler('arkChain'))->evaluate(
			['handler' => 'OCA\Humaniq\Payroll\StepHandlerRegistry'],
			$this->context()
		);

	}//end testAHandlerOutsideTheAllowListIsRefusedNotSkipped()

	/**
	 * A handler field that is absent, blank or not a string is refused,
	 * naming the op and the param.
	 *
	 * @return void
	 */
	public function testAnAbsentOrNonStringHandlerIsRefused(): void {
		foreach ([[], ['handler' => ''], ['handler' => '   '], ['handler' => 42], ['handler' => null]] as $spec) {
			try {
				$this->op()->evaluate($spec, $this->context());
				$this->fail('a malformed handler must be refused');
			} catch (DslException $e) {
				$this->assertStringContainsString('op "phpStep"', $e->getMessage());
				$this->assertStringContainsString('"handler"', $e->getMessage());
			}
		}

	}//end testAnAbsentOrNonStringHandlerIsRefused()

	/**
	 * `params` must be a map: a scalar is refused before the handler is
	 * reached, so a handler never sees a non-array params argument.
	 *
	 * @return void
	 */
	public function testNonMapParamsAreRefusedBeforeTheHandlerIsReached(): void {
		$handler = $this->handler('arkChain');

		try {
			$this->op($handler)->evaluate(
				[
					'handler' => 'arkChain',
					'params'  => 'grondslag',
				],
				$this->context()
			);
			$this->fail('scalar params must be refused');
		} catch (DslException $e) {
			$this->assertStringContainsString('"params"', $e->getMessage());
		}

		$this->assertSame([], $handler->calls, 'the handler was never called');

	}//end testNonMapParamsAreRefusedBeforeTheHandlerIsReached()

	/**
	 * The op under test, over an allow-list holding the given handlers.
	 *
	 * @param JurisdictionStepHandlerInterface ...$handlers The allow-listed handlers.
	 *
	 * @return PhpStepOp
	 */
	private function op(JurisdictionStepHandlerInterface ...$handlers): PhpStepOp {
		return new PhpStepOp(new RefResolver(), new StepHandlerRegistry($handlers));
	}//end op()

	/**
	 * A recording stub handler.
	 *
	 * @param string $name The allow-list name.
	 *
	 * @return JurisdictionStepHandlerInterface
	 */
	private function handler(string $name): JurisdictionStepHandlerInterface {
		return new class($name) implements JurisdictionStepHandlerInterface {

			/**
			 * The params of every call, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * The context of every call, in order.
			 *
			 * @var array<int, StepContext>
			 */
			public array $contexts = [];

			/**
			 * @param string $name The allow-list name.
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
			 * @param array<string, mixed> $params The declared params.
			 * @param StepContext $ctx The run context.
			 *
			 * @return int|float
			 */
			public function handle(array $params, StepContext $ctx): int|float {
				$this->calls[] = $params;
				$this->contexts[] = $ctx;

				return 1234;
			}//end handle()

		};

	}//end handler()

	/**
	 * A run context.
	 *
	 * @return StepContext
	 */
	private function context(): StepContext {
		return new StepContext(
			['gross' => 100000],
			TaxTables::load('nl-2026'),
			'2026-02',
			['currency' => 'EUR']
		);

	}//end context()

}//end class
