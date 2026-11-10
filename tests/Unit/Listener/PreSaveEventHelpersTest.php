<?php

/**
 * Characterisation tests for the refusal and entity helpers the pre-save
 * listeners repeat.
 *
 * Pins, for every listener that carries them: a refusal sets the message as
 * the event's errors and stops propagation, and does nothing at all on an
 * event that cannot carry both; the entity of a creating/created event is its
 * object, of an updating/updated event the new object, and of any other event
 * nothing.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Listener
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
 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\ChangeRequestListener;
use OCA\Humaniq\Listener\CostAllocationGuardListener;
use OCA\Humaniq\Listener\ResourceBookingOverlapListener;
use OCA\Humaniq\Listener\TimeEntryStampListener;
use OCA\Humaniq\Listener\TimeEstimateListener;
use OCA\Humaniq\Listener\TrainingRecordListener;
use OCA\Humaniq\Listener\WorkingPatternOverlapListener;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The repeated pre-save helpers behave the same in every listener.
 *
 * @spec openspec/specs/payroll-cost-allocation/spec.md#REQ-PCA-001
 */
class PreSaveEventHelpersTest extends TestCase {

	/**
	 * The listeners with a refuse() helper.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function refusing(): array {
		return [
			'cost allocation' => [CostAllocationGuardListener::class],
			'resource booking' => [ResourceBookingOverlapListener::class],
			'time entry' => [TimeEntryStampListener::class],
			'time estimate' => [TimeEstimateListener::class],
			'working pattern' => [WorkingPatternOverlapListener::class],
		];

	}//end refusing()

	/**
	 * The listeners with an entityOf() helper.
	 *
	 * @return array<string, array{0: class-string}>
	 */
	public static function resolving(): array {
		return [
			'change request' => [ChangeRequestListener::class],
			'training record' => [TrainingRecordListener::class],
		];

	}//end resolving()

	/**
	 * @param class-string $listener The listener.
	 *
	 * @return void
	 *
	 * @dataProvider refusing
	 */
	public function testRefuse(string $listener): void {
		$full = new class {

			/**
			 * What happened, in order.
			 *
			 * @var list<mixed>
			 */
			public array $calls = [];

			/**
			 * @param array<string, mixed> $errors The errors.
			 *
			 * @return void
			 */
			public function setErrors(array $errors): void {
				$this->calls[] = $errors;
			}

			/**
			 * @return void
			 */
			public function stopPropagation(): void {
				$this->calls[] = 'stopped';
			}
		};
		$this->call($listener, 'refuse', [$full, 'Nee.']);
		$this->assertSame([['message' => 'Nee.'], 'stopped'], $full->calls);

		$errorsOnly = new class {

			/**
			 * What happened.
			 *
			 * @var list<mixed>
			 */
			public array $calls = [];

			/**
			 * @param array<string, mixed> $errors The errors.
			 *
			 * @return void
			 */
			public function setErrors(array $errors): void {
				$this->calls[] = $errors;
			}
		};
		$this->call($listener, 'refuse', [$errorsOnly, 'Nee.']);
		$this->assertSame([], $errorsOnly->calls);

		$stopOnly = new class {

			/**
			 * What happened.
			 *
			 * @var list<mixed>
			 */
			public array $calls = [];

			/**
			 * @return void
			 */
			public function stopPropagation(): void {
				$this->calls[] = 'stopped';
			}
		};
		$this->call($listener, 'refuse', [$stopOnly, 'Nee.']);
		$this->assertSame([], $stopOnly->calls);

	}//end testRefuse()

	/**
	 * @param class-string $listener The listener.
	 *
	 * @return void
	 *
	 * @dataProvider resolving
	 */
	public function testEntityOf(string $listener): void {
		$new = new ObjectEntity();
		$old = new ObjectEntity();

		$this->assertSame($new, $this->call($listener, 'entityOf', [new ObjectCreatingEvent($new)]));
		$this->assertSame($new, $this->call($listener, 'entityOf', [new ObjectCreatedEvent($new)]));
		$this->assertSame($new, $this->call($listener, 'entityOf', [new ObjectUpdatingEvent($new, $old)]));
		$this->assertSame($new, $this->call($listener, 'entityOf', [new ObjectUpdatedEvent($new, $old)]));
		$this->assertNull($this->call($listener, 'entityOf', [new Event()]));

	}//end testEntityOf()

	/**
	 * Call a private helper on a listener built without its collaborators.
	 *
	 * @param class-string      $listener  The listener.
	 * @param string            $method    The helper.
	 * @param array<int, mixed> $arguments The arguments.
	 *
	 * @return mixed
	 */
	private function call(string $listener, string $method, array $arguments): mixed {
		$instance = (new ReflectionClass($listener))->newInstanceWithoutConstructor();

		return (new ReflectionMethod($instance, $method))->invokeArgs($instance, $arguments);

	}//end call()
}//end class
