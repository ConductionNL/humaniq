<?php

/**
 * TimeEntryStampListener unit tests
 *
 * The pre-save TimeEntry stamp + mutability guard (hours-process-redesign
 * Decisions 3 + 5): self-service employee resolution, hours derivation with
 * refusal of impossible spans, timesheet find-or-create on the month grain,
 * identity/cost-centre stamps, the locked-parent refusal (create, update,
 * delete), the submitted-parent conflict message, and the internal-writer
 * marker exemption.
 *
 * @category Test
 * @package  OCA\Hrmq\Tests\Unit\Listener
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
 * @spec openspec/changes/hrmq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-hrmq-captures-time-entries-under-a-submit→approve-lifecycle-(REQ-TEC-001)
 * @spec openspec/changes/hrmq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-Entries-of-a-submitted-or-approved-timesheet-are-immutable-(REQ-TEC-005)
 */

declare(strict_types=1);

namespace OCA\Hrmq\Tests\Unit\Listener;

use OCA\Hrmq\Listener\TimeEntryStampListener;
use OCA\Hrmq\Service\HoursRegisterGateway;
use OCA\Hrmq\Service\InternalWriteMarker;
use OCA\Hrmq\Service\OrgResolutionService;
use OCA\Hrmq\Service\SettingsService;
use OCA\Hrmq\Tests\Unit\Support\FakeContainer;
use OCA\Hrmq\Tests\Unit\Support\FakeObjectStore;
use OCA\Hrmq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Stamping, refusal and marker behaviour of the TimeEntry pre-save hook.
 */
class TimeEntryStampListenerTest extends TestCase {

	/**
	 * The in-memory register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The internal-writer marker shared with the listener.
	 *
	 * @var InternalWriteMarker
	 */
	private InternalWriteMarker $marker;

	/**
	 * The subject.
	 *
	 * @var TimeEntryStampListener
	 */
	private TimeEntryStampListener $listener;

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->marker = new InternalWriteMarker();

		$this->store->seed('Employee', 'employee-jansen', [
			'nextcloudUserId' => 'admin',
			'administrationId' => 'ADM-001',
		]);
		$this->store->seed('OrgAssignment', 'assignment-jansen', [
			'employeeId' => 'employee-jansen',
			'orgUnitId' => 'unit-dev',
			'endDate' => '',
		]);
		$this->store->seed('OrgUnit', 'unit-dev', [
			'managerId' => 'employee-manager',
			'costCenter' => 'CC-100',
			'active' => true,
		]);

		$container = new FakeContainer([
			'OCA\OpenRegister\Service\ObjectService' => $this->store,
			'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
		]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');

		$this->listener = new TimeEntryStampListener(
			gateway: new HoursRegisterGateway(
				container: $container,
				settingsService: $settings,
				orgResolution: new OrgResolutionService()
			),
			userSession: $session,
			marker: $this->marker,
			logger: new NullLogger()
		);
	}//end setUp()

	/**
	 * Build a TimeEntry pre-save entity.
	 *
	 * @param array<string, mixed> $payload The payload.
	 * @param string|null $uuid Optional uuid.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entryEntity(array $payload, ?string $uuid = null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema('TimeEntry');
		$entity->setObject($payload);
		if ($uuid !== null) {
			$entity->setUuid($uuid);
		}

		return $entity;
	}//end entryEntity()

	/**
	 * Self-service create: no identity fields on the form — the listener
	 * resolves the acting user's own Employee, derives hours from the span,
	 * stamps userId/administrationId/costCenter, creates the draft
	 * timesheet for the booking's month, and defaults origin to manual.
	 *
	 * @return void
	 */
	public function testSelfServiceCreateStampsEverything(): void {
		$event = new ObjectCreatingEvent($this->entryEntity([
			'startedAt' => '2026-05-04T09:00:00Z',
			'endedAt' => '2026-05-04T17:30:00Z',
			'breakMinutes' => 30,
			'description' => 'Projectwerk',
		]));

		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$modified = $event->getModifiedData();
		$this->assertSame('employee-jansen', $modified['employeeId']);
		$this->assertSame(8.0, $modified['hours'], 'REQ-TEC-001: 09:00-17:30 minus 30 min break = 8.00.');
		$this->assertSame('admin', $modified['userId']);
		$this->assertSame('ADM-001', $modified['administrationId']);
		$this->assertSame('CC-100', $modified['costCenter']);
		$this->assertSame('manual', $modified['origin']);

		// The parent timesheet was created as a draft for the booking's month.
		$timesheetId = (string)$modified['timesheetId'];
		$this->assertNotSame('', $timesheetId);
		$timesheet = $this->store->state->objects['Timesheet'][$timesheetId];
		$this->assertSame('2026-05', $timesheet['period']);
		$this->assertSame('draft', $timesheet['status']);
		$this->assertSame('employee-jansen', $timesheet['employeeId']);
	}//end testSelfServiceCreateStampsEverything()

	/**
	 * An existing draft timesheet for the month is reused — never a second
	 * one for the same period.
	 *
	 * @return void
	 */
	public function testCreateReusesTheExistingDraftTimesheet(): void {
		$this->store->seed('Timesheet', 'ts-draft', [
			'employeeId' => 'employee-jansen',
			'period' => '2026-05',
			'status' => 'draft',
		]);

		$event = new ObjectCreatingEvent($this->entryEntity([
			'startedAt' => '2026-05-06T08:00:00Z',
			'endedAt' => '2026-05-06T12:00:00Z',
		]));
		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame('ts-draft', $event->getModifiedData()['timesheetId']);
		$this->assertCount(1, $this->store->state->objects['Timesheet'], 'No second timesheet may be created.');
	}//end testCreateReusesTheExistingDraftTimesheet()

	/**
	 * An impossible span is refused with a structured message: end before
	 * start, and a break swallowing the whole span (REQ-TEC-001).
	 *
	 * @return void
	 */
	public function testImpossibleSpansAreRefused(): void {
		$event = new ObjectCreatingEvent($this->entryEntity([
			'startedAt' => '2026-05-04T17:00:00Z',
			'endedAt' => '2026-05-04T09:00:00Z',
		]));
		$this->listener->handle($event);
		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('eindtijd', (string)$event->getErrors()['message']);

		$event = new ObjectCreatingEvent($this->entryEntity([
			'startedAt' => '2026-05-04T09:00:00Z',
			'endedAt' => '2026-05-04T10:00:00Z',
			'breakMinutes' => 90,
		]));
		$this->listener->handle($event);
		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('pauze', (string)$event->getErrors()['message']);
	}//end testImpossibleSpansAreRefused()

	/**
	 * HR entry: an explicit employeeId is taken as given and ITS identity is
	 * stamped — not the acting user's.
	 *
	 * @return void
	 */
	public function testExplicitEmployeeIdWins(): void {
		$this->store->seed('Employee', 'employee-devries', [
			'nextcloudUserId' => 'devries',
			'administrationId' => 'ADM-002',
		]);

		$event = new ObjectCreatingEvent($this->entryEntity([
			'employeeId' => 'employee-devries',
			'startedAt' => '2026-05-04T09:00:00Z',
			'endedAt' => '2026-05-04T11:00:00Z',
		]));
		$this->listener->handle($event);

		$modified = $event->getModifiedData();
		$this->assertSame('employee-devries', $modified['employeeId']);
		$this->assertSame('devries', $modified['userId'], 'REQ-MHS-002: userId re-derives from the employee link.');
		$this->assertSame('ADM-002', $modified['administrationId']);
		$this->assertNull($modified['costCenter'], 'No org chain for devries — never guessed.');
	}//end testExplicitEmployeeIdWins()

	/**
	 * Booking into a period whose ONLY timesheet is submitted is refused
	 * with the reopen message — never silently booked into a second one.
	 *
	 * @return void
	 */
	public function testSubmittedPeriodConflictIsRefused(): void {
		$this->store->seed('Timesheet', 'ts-submitted', [
			'employeeId' => 'employee-jansen',
			'period' => '2026-05',
			'status' => 'submitted',
		]);

		$event = new ObjectCreatingEvent($this->entryEntity([
			'startedAt' => '2026-05-06T08:00:00Z',
			'endedAt' => '2026-05-06T12:00:00Z',
		]));
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('heropenen', (string)$event->getErrors()['message']);
		$this->assertCount(1, $this->store->state->objects['Timesheet'], 'No shadow timesheet may be created.');
	}//end testSubmittedPeriodConflictIsRefused()

	/**
	 * Editing or deleting an entry of a locked (submitted/approved) parent
	 * is refused; a draft parent accepts both (REQ-TEC-005).
	 *
	 * @return void
	 */
	public function testLockedParentRefusesUpdateAndDelete(): void {
		$this->store->seed('Timesheet', 'ts-approved', [
			'employeeId' => 'employee-jansen',
			'period' => '2026-04',
			'status' => 'approved',
		]);
		$this->store->seed('Timesheet', 'ts-open', [
			'employeeId' => 'employee-jansen',
			'period' => '2026-05',
			'status' => 'rejected',
		]);

		$storedEntry = [
			'employeeId' => 'employee-jansen',
			'timesheetId' => 'ts-approved',
			'startedAt' => '2026-04-01T09:00:00Z',
			'endedAt' => '2026-04-01T17:00:00Z',
		];

		// Update under a locked parent.
		$old = $this->entryEntity($storedEntry, 'entry-1');
		$new = $this->entryEntity(array_merge($storedEntry, ['description' => 'aangepast']), 'entry-1');
		$event = new ObjectUpdatingEvent($new, $old);
		$this->listener->handle($event);
		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('heropenen', (string)$event->getErrors()['message']);

		// Delete under a locked parent.
		$event = new ObjectDeletingEvent($this->entryEntity($storedEntry, 'entry-1'));
		$this->listener->handle($event);
		$this->assertTrue($event->isPropagationStopped());

		// The same operations under a rejected (mutable) parent pass.
		$openEntry = array_merge($storedEntry, ['timesheetId' => 'ts-open', 'startedAt' => '2026-05-01T09:00:00Z', 'endedAt' => '2026-05-01T17:00:00Z']);
		$event = new ObjectUpdatingEvent(
			$this->entryEntity(array_merge($openEntry, ['description' => 'ok']), 'entry-2'),
			$this->entryEntity($openEntry, 'entry-2')
		);
		$this->listener->handle($event);
		$this->assertFalse($event->isPropagationStopped());

		$event = new ObjectDeletingEvent($this->entryEntity($openEntry, 'entry-2'));
		$this->listener->handle($event);
		$this->assertFalse($event->isPropagationStopped());
	}//end testLockedParentRefusesUpdateAndDelete()

	/**
	 * Under the internal-writer marker the listener stands aside entirely:
	 * no refusal (even for a locked parent) and no stamping — the internal
	 * writer owns its values (migration synthesis, REQ-TEC-005 exemption).
	 *
	 * @return void
	 */
	public function testInternalWriterMarkerExemptsTheWrite(): void {
		$this->store->seed('Timesheet', 'ts-approved', [
			'employeeId' => 'employee-jansen',
			'period' => '2026-04',
			'status' => 'approved',
		]);

		$event = new ObjectCreatingEvent($this->entryEntity([
			'employeeId' => 'employee-jansen',
			'timesheetId' => 'ts-approved',
			'startedAt' => '2026-04-01T00:00:00Z',
			'endedAt' => '2026-04-01T08:00:00Z',
			'origin' => 'migration',
		]));

		$this->marker->runInternal(function () use ($event): void {
			$this->listener->handle($event);
		});

		$this->assertFalse($event->isPropagationStopped(), 'The marker path must admit the migration write.');
		$this->assertSame([], $event->getModifiedData(), 'The internal writer owns its values — no stamping.');
	}//end testInternalWriterMarkerExemptsTheWrite()

	/**
	 * No linked Employee for the acting user: the write is refused, not
	 * half-stamped (a userId-less entry vanishes from every @me page).
	 *
	 * @return void
	 */
	public function testUnlinkedAccountIsRefused(): void {
		unset($this->store->state->objects['Employee']);

		$event = new ObjectCreatingEvent($this->entryEntity([
			'startedAt' => '2026-05-04T09:00:00Z',
			'endedAt' => '2026-05-04T17:00:00Z',
		]));
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('medewerker', (string)$event->getErrors()['message']);
	}//end testUnlinkedAccountIsRefused()

	/**
	 * A non-TimeEntry schema is ignored entirely — the aggregate/no-loop
	 * property depends on this gate.
	 *
	 * @return void
	 */
	public function testForeignSchemaIsIgnored(): void {
		$entity = new ObjectEntity();
		$entity->setSchema('Timesheet');
		$entity->setObject(['period' => '2026-05']);

		$event = new ObjectCreatingEvent($entity);
		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getModifiedData());
	}//end testForeignSchemaIsIgnored()

}//end class
