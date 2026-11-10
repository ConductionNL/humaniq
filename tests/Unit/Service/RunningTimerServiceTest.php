<?php

/**
 * RunningTimerService unit tests
 *
 * The one-timer-per-user rule and the resolve-from-the-caller guard, which are
 * the only two reasons this service exists. Everything else about a time entry
 * is decided declaratively or by the stamping listener.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-user-has-at-most-one-running-timer
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Listener\HoursWriteRefusedException;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\RunningTimerService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Starting, stopping and resolving one caller's timer.
 */
class RunningTimerServiceTest extends TestCase {

	/**
	 * The gateway, mocked so the register's rows are the test's own.
	 *
	 * @var HoursRegisterGateway&MockObject
	 */
	private HoursRegisterGateway $gateway;

	/**
	 * The subject.
	 *
	 * @var RunningTimerService
	 */
	private RunningTimerService $timers;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->gateway = $this->createMock(HoursRegisterGateway::class);
		$this->timers = new RunningTimerService($this->gateway);
	}//end setUp()

	/**
	 * An entry with an end is a finished booking, not a running timer, even
	 * when it carries the timer marker.
	 *
	 * @return void
	 */
	public function testAFinishedTimerEntryIsNotRunning(): void {
		$this->gateway->method('findFiltered')->willReturn(
			[['id' => 'a', 'startedAt' => '2026-09-10T09:00:00+00:00', 'endedAt' => '2026-09-10T10:00:00+00:00']]
		);

		$this->assertNull($this->timers->running('alice'));
	}//end testAFinishedTimerEntryIsNotRunning()

	/**
	 * A blank caller reads nothing, an end of only spaces is no end, and
	 * a row without a start sorts last.
	 *
	 * @return void
	 */
	public function testABlankCallerAndABlankEnd(): void {
		$this->gateway->expects($this->once())->method('findFiltered')
			->with($this->anything(), $this->callback(static fn (array $filters): bool => $filters['userId'] === 'alice'))
			->willReturn(
				[
					['id' => 'no-start'],
					['id' => 'spaces', 'startedAt' => '2026-09-10T08:00:00+00:00', 'endedAt' => '  '],
					['id' => 'done', 'startedAt' => '2026-09-10T11:00:00+00:00', 'endedAt' => '2026-09-10T12:00:00+00:00'],
				]
			);

		$this->assertNull($this->timers->running('   '));
		$this->assertSame('spaces', $this->timers->running(' alice ')['id']);
	}//end testABlankCallerAndABlankEnd()

	/**
	 * An entry with no end is the running one.
	 *
	 * @return void
	 */
	public function testAnEntryWithNoEndIsRunning(): void {
		$this->gateway->method('findFiltered')->willReturn(
			[['id' => 'a', 'startedAt' => '2026-09-10T09:00:00+00:00']]
		);

		$this->assertSame('a', $this->timers->running('alice')['id']);
	}//end testAnEntryWithNoEndIsRunning()

	/**
	 * The query is scoped to the CALLER and to the timer marker, so a running
	 * entry belonging to someone else is never a candidate.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-user-has-at-most-one-running-timer
	 */
	public function testTheQueryIsScopedToTheCallerAndTheTimerMarker(): void {
		$this->gateway->expects($this->once())
			->method('findFiltered')
			->with(
				RunningTimerService::TIMEENTRY_SLUG,
				['userId' => 'alice', 'origin' => RunningTimerService::ORIGIN_TIMER]
			)
			->willReturn([]);

		$this->assertNull($this->timers->running('alice'));
	}//end testTheQueryIsScopedToTheCallerAndTheTimerMarker()

	/**
	 * Several open rows, which only a pre-guard install can hold, resolve to
	 * the most recently started one rather than to whichever came back first.
	 *
	 * @return void
	 */
	public function testSeveralOpenRowsResolveToTheNewest(): void {
		$this->gateway->method('findFiltered')->willReturn(
			[
				['id' => 'older', 'startedAt' => '2026-09-10T08:00:00+00:00'],
				['id' => 'newer', 'startedAt' => '2026-09-10T11:00:00+00:00'],
			]
		);

		$this->assertSame('newer', $this->timers->running('alice')['id']);
	}//end testSeveralOpenRowsResolveToTheNewest()

	/**
	 * A register that cannot be read is refused rather than reported as "no
	 * timer running", which would offer a start and write a second open row.
	 *
	 * @return void
	 */
	public function testAnUnreadableRegisterIsRefusedRatherThanReadAsIdle(): void {
		$this->gateway->method('findFiltered')->willThrowException(new RuntimeException('register down'));

		$this->expectException(HoursWriteRefusedException::class);

		$this->timers->running('alice');
	}//end testAnUnreadableRegisterIsRefusedRatherThanReadAsIdle()

	/**
	 * Starting writes the moment, the marker and the host object's reference,
	 * and nothing an employee could have typed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-running-timer-survives-leaving-the-page
	 */
	public function testStartWritesTheMarkerAndTheObjectReference(): void {
		$this->gateway->method('findFiltered')->willReturn([]);

		$written = null;
		$this->gateway->expects($this->once())
			->method('save')
			->willReturnCallback(
				function (array $payload, string $schema, ?string $uuid = null) use (&$written): object {
					$written = $payload;
					$this->assertSame(RunningTimerService::TIMEENTRY_SLUG, $schema);
					$this->assertNull($uuid, 'A start CREATES a row; passing a uuid would overwrite one.');

					return (object)[];
				}
			);

		$result = $this->timers->start('alice', 'dossiq:case', 'case-uuid');

		$this->assertSame(RunningTimerService::STATUS_RUNNING, $result['status']);
		$this->assertSame(RunningTimerService::ORIGIN_TIMER, $written['origin']);
		$this->assertSame('dossiq:case', $written['domainObjectType']);
		$this->assertSame('case-uuid', $written['domainObjectRef']);
		$this->assertArrayNotHasKey('endedAt', $written, 'A running timer has no end, and an omitted key is not a null one.');
		$this->assertArrayNotHasKey('employeeId', $written, 'The employee is stamped by the listener, never sent by the caller.');
	}//end testStartWritesTheMarkerAndTheObjectReference()

	/**
	 * A second start writes nothing and names the object already being timed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-user-has-at-most-one-running-timer
	 */
	public function testASecondStartIsRefusedAndWritesNothing(): void {
		$this->gateway->method('findFiltered')->willReturn(
			[['id' => 'a', 'startedAt' => '2026-09-10T09:00:00+00:00', 'domainObjectRef' => 'other-case']]
		);
		$this->gateway->expects($this->never())->method('save');

		$result = $this->timers->start('alice', 'dossiq:case', 'this-case');

		$this->assertSame(RunningTimerService::STATUS_ALREADY_RUNNING, $result['status']);
		$this->assertSame('other-case', $result['entry']['domainObjectRef']);
	}//end testASecondStartIsRefusedAndWritesNothing()

	/**
	 * A start without both halves of the object reference writes nothing: a
	 * bare uuid nobody can resolve is worse than no reference.
	 *
	 * @return void
	 */
	public function testAStartWithHalfAReferenceIsRefused(): void {
		$this->gateway->expects($this->never())->method('save');

		$this->assertSame(
			RunningTimerService::STATUS_INVALID,
			$this->timers->start('alice', 'dossiq:case', '')['status']
		);
	}//end testAStartWithHalfAReferenceIsRefused()

	/**
	 * Stopping writes the end onto the row that is already there, keeping its
	 * uuid, so the entry has been one row throughout.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-a-running-timer-survives-leaving-the-page
	 */
	public function testStopWritesTheEndOntoTheSameRow(): void {
		$this->gateway->method('findFiltered')->willReturn(
			[
				[
					'id' => 'entry-uuid',
					'startedAt' => '2026-09-10T09:00:00+00:00',
					'domainObjectRef' => 'case-uuid',
					'@self' => ['id' => 'entry-uuid'],
				],
			]
		);

		$written = null;
		$writtenUuid = null;
		$this->gateway->expects($this->once())
			->method('save')
			->willReturnCallback(
				function (array $payload, string $schema, ?string $uuid = null) use (&$written, &$writtenUuid): object {
					$written = $payload;
					$writtenUuid = $uuid;

					return (object)[];
				}
			);

		$result = $this->timers->stop('alice');

		$this->assertSame(RunningTimerService::STATUS_STOPPED, $result['status']);
		$this->assertSame('entry-uuid', $writtenUuid);
		$this->assertNotEmpty($written['endedAt']);
		$this->assertSame('case-uuid', $written['domainObjectRef'], 'The stop must not lose the object the hours belong to.');
		$this->assertArrayNotHasKey('@self', $written, 'The register metadata is not part of the payload.');
	}//end testStopWritesTheEndOntoTheSameRow()

	/**
	 * Stopping with nothing running writes nothing and says so.
	 *
	 * @return void
	 */
	public function testStopWithNothingRunningWritesNothing(): void {
		$this->gateway->method('findFiltered')->willReturn([]);
		$this->gateway->expects($this->never())->method('save');

		$this->assertSame(RunningTimerService::STATUS_NONE, $this->timers->stop('alice')['status']);
	}//end testStopWithNothingRunningWritesNothing()

}//end class
