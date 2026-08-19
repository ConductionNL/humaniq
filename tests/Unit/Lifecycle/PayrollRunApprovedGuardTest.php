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
 * @package  OCA\Hrmq\Tests\Unit\Lifecycle
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
 * @spec openspec/changes/pension-filing-upa-mvp/specs/pension-filing-upa-mvp/spec.md
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Lifecycle;

use OCA\Hrmq\Lifecycle\PayrollRunApprovedGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Tests for PayrollRunApprovedGuard.
 *
 * @spec openspec/changes/pension-filing-upa-mvp/specs/pension-filing-upa-mvp/spec.md
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
			 * @param string $register Register slug.
			 * @param string $schema Schema name.
			 *
			 * @return mixed
			 */
			public function find(string $id, string $register, string $schema): mixed {
				return $this->runResult;
			}//end find()

		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')
			->with('OCA\OpenRegister\Service\ObjectService')
			->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('hrmq');

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
			 * @param string $register Register slug.
			 * @param string $schema Schema name.
			 *
			 * @return mixed
			 *
			 * @throws \RuntimeException Always.
			 */
			public function find(string $id, string $register, string $schema): mixed {
				throw new \RuntimeException('register unavailable');
			}//end find()

		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objectService);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('hrmq');

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

}//end class
