<?php

/**
 * Unit tests for PayrollRunApprovedGuard.
 *
 * Pins the fail-closed contract of the `controleren` lifecycle guard
 * (pension-filing-upa-mvp): approved/posted/paid referenced runs allow,
 * everything else (draft run, empty reference, dangling reference, a run
 * that fails to load) denies.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Lifecycle
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
 * @spec openspec/specs/pension-filing-upa-mvp/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Lifecycle;

use OCA\Humaniq\Lifecycle\PayrollRunApprovedGuard;
use OCA\Humaniq\Tests\Unit\Support\FakeSlugResolver;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for PayrollRunApprovedGuard.
 *
 * @spec openspec/specs/pension-filing-upa-mvp/spec.md
 */
class PayrollRunApprovedGuardTest extends TestCase {

	/**
	 * Build a guard whose lazily-resolved ObjectService::find() returns
	 * $runResult for any lookup (a fake collaborator, not a fake of the
	 * guard's own decision logic under test).
	 *
	 * @param mixed $runResult The value ObjectService::find() should return.
	 *
	 * @return PayrollRunApprovedGuard
	 */
	private function guardWithRun(mixed $runResult): PayrollRunApprovedGuard {
		$objectService = new class($runResult) {

			/**
			 * @param mixed $runResult Value to return from find().
			 */
			public function __construct(
				private readonly mixed $runResult,
			) {

			}//end __construct()

			/**
			 * @param string $id Object id.
			 * @param ObjectFindOptions|null $options The register and schema the object is read from.
			 *
			 * @return mixed
			 */
			public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
				return $this->runResult;
			}//end find()

		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('humaniq');

		return new PayrollRunApprovedGuard($container, $appConfig);
	}//end guardWithRun()

	/**
	 * A guard whose ObjectService::find() throws (simulates a load failure).
	 *
	 * @return PayrollRunApprovedGuard
	 */
	private function guardThatThrows(): PayrollRunApprovedGuard {
		$objectService = new class {

			/**
			 * @param string $id Object id.
			 * @param ObjectFindOptions|null $options The register and schema the object is read from.
			 *
			 * @return mixed
			 *
			 * @throws \RuntimeException Always.
			 */
			public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
				throw new \RuntimeException('register unavailable');
			}//end find()

		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('humaniq');

		return new PayrollRunApprovedGuard($container, $appConfig);
	}//end guardThatThrows()

	/**
	 * @return void
	 */
	public function testApprovedRunAllows(): void {
		$guard = $this->guardWithRun(['id' => 'run-1', 'status' => 'approved']);
		$result = $guard->check(['payrollRunId' => 'run-1'], 'controleren', 'alice');

		$this->assertTrue($result->isAllowed());

	}//end testApprovedRunAllows()

	/**
	 * @return void
	 */
	public function testPostedRunAllows(): void {
		$guard = $this->guardWithRun(['id' => 'run-1', 'status' => 'posted']);
		$result = $guard->check(['payrollRunId' => 'run-1'], 'controleren', 'alice');

		$this->assertTrue($result->isAllowed());

	}//end testPostedRunAllows()

	/**
	 * @return void
	 */
	public function testPaidRunAllows(): void {
		$guard = $this->guardWithRun(['id' => 'run-1', 'status' => 'paid']);
		$result = $guard->check(['payrollRunId' => 'run-1'], 'controleren', 'alice');

		$this->assertTrue($result->isAllowed());

	}//end testPaidRunAllows()

	/**
	 * @return void
	 */
	public function testDraftRunDenies(): void {
		$guard = $this->guardWithRun(['id' => 'run-1', 'status' => 'draft']);
		$result = $guard->check(['payrollRunId' => 'run-1'], 'controleren', 'alice');

		$this->assertFalse($result->isAllowed());
		$this->assertStringContainsString('draft', (string)$result->getMessage());

	}//end testDraftRunDenies()

	/**
	 * @return void
	 */
	public function testEmptyReferenceDenies(): void {
		$guard = $this->guardWithRun(['id' => 'run-1', 'status' => 'approved']);
		$result = $guard->check(['payrollRunId' => ''], 'controleren', 'alice');

		$this->assertFalse($result->isAllowed());

	}//end testEmptyReferenceDenies()

	/**
	 * @return void
	 */
	public function testMissingReferenceKeyDenies(): void {
		$guard = $this->guardWithRun(['id' => 'run-1', 'status' => 'approved']);
		$result = $guard->check([], 'controleren', 'alice');

		$this->assertFalse($result->isAllowed());

	}//end testMissingReferenceKeyDenies()

	/**
	 * @return void
	 */
	public function testDanglingReferenceDenies(): void {
		$guard = $this->guardWithRun(null);
		$result = $guard->check(['payrollRunId' => 'no-such-run'], 'controleren', 'alice');

		$this->assertFalse($result->isAllowed());

	}//end testDanglingReferenceDenies()

	/**
	 * @return void
	 */
	public function testLoadFailureDenies(): void {
		$guard = $this->guardThatThrows();
		$result = $guard->check(['payrollRunId' => 'run-1'], 'controleren', 'alice');

		$this->assertFalse($result->isAllowed());

	}//end testLoadFailureDenies()

	/**
	 * An instance with no humaniq register DENIES, and says why.
	 *
	 * The direction is the point. This guard already fails closed on every
	 * other branch, and an unreadable register must not become the one way
	 * through. Before the fix it failed closed for the WRONG reason: the guard
	 * asked for the canonical `humaniq` register, an unmigrated instance
	 * carries it as `hrmq`, the read matched nothing, and a pensioenaangifte
	 * whose payroll run was approved was refused with "de gekoppelde loonrun
	 * bestaat niet". Denied either way, but only one of the two tells an admin
	 * what to fix. See ConductionNL/openregister#3579.
	 *
	 * @return void
	 */
	public function testDeniesWhenThisInstanceHasNoHumaniqRegister(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new FakeSlugResolver([]));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$guard = new PayrollRunApprovedGuard($container, $appConfig);
		$result = $guard->check(['payrollRunId' => 'run-1'], 'controleren', 'user-1');

		$this->assertFalse($result->isAllowed(), 'An unreadable register must fail CLOSED.');
		$this->assertStringContainsString(
			'humaniq-register is niet gevonden',
			$result->getMessage(),
			'The denial must name the register, not a phantom payroll-run status.'
		);
	}//end testDeniesWhenThisInstanceHasNoHumaniqRegister()

	/**
	 * An unmigrated instance still carrying `hrmq` is READ, not refused.
	 *
	 * The mirror of the test above, and the half that would otherwise go
	 * unnoticed: failing closed everywhere is easy, and useless. The guard has
	 * to allow the transition it is there to allow.
	 *
	 * @return void
	 */
	public function testReadsTheHrmqRegisterOnAnUnmigratedInstance(): void {
		$seen = new \stdClass();
		$seen->register = null;

		$objectService = new class($seen) {

			/**
			 * @param \stdClass $seen Recorder.
			 */
			public function __construct(
				private readonly \stdClass $seen,
			) {

			}//end __construct()

			/**
			 * @param string $id Object id.
			 * @param ObjectFindOptions|null $options The register and schema the object is read from.
			 *
			 * @return mixed
			 */
			public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): mixed {
				$register = (string)($options?->register ?? '');
				$this->seen->register = $register;
				return ['status' => 'approved'];
			}//end find()

		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($objectService) {
				if ($id === 'OCA\OpenRegister\Service\ObjectService') {
					return $objectService;
				}

				return new FakeSlugResolver(['hrmq']);
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');

		$guard = new PayrollRunApprovedGuard($container, $appConfig);
		$result = $guard->check(['payrollRunId' => 'run-1'], 'controleren', 'user-1');

		$this->assertSame('hrmq', $seen->register, 'The read must use the slug this instance actually carries.');
		$this->assertTrue($result->isAllowed());
	}//end testReadsTheHrmqRegisterOnAnUnmigratedInstance()

}//end class
