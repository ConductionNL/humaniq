<?php

/**
 * TimesheetProcessStampListener unit tests
 *
 * The pre-save Timesheet inertness + lifecycle stamping (hours-process-
 * redesign Decision 4): create-sanitise, update-inertness (client
 * process-field input restored from stored), every status edge
 * (submit/approve/reject/reopen), the reject-edge rejectionReason
 * allowlist, identity-cache re-derivation, and the internal-writer marker's
 * aggregate pass-through (which still keeps process fields inert).
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
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/humaniq-timesheet-approval/spec.md#Requirement:-Process-fields-are-server-stamped-and-inert-to-client-input
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/mss-team-scope/spec.md#Requirement:-The-approval-carrying-schemas-SHALL-gain-an-optional-denormalized-managerUserId-scoping-property-(REQ-MSS-001)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\TimesheetProcessStampListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Inertness, edge stamping and marker behaviour of the Timesheet hook.
 */
class TimesheetProcessStampListenerTest extends TestCase {

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
	 * @var TimesheetProcessStampListener
	 */
	private TimesheetProcessStampListener $listener;

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
		$this->store->seed('Employee', 'employee-manager', [
			'nextcloudUserId' => 'manager1',
		]);
		$this->store->seed('OrgAssignment', 'assignment-jansen', [
			'employeeId' => 'employee-jansen',
			'orgUnitId' => 'unit-dev',
			'endDate' => '',
		]);
		$this->store->seed('OrgUnit', 'unit-dev', [
			'managerId' => 'employee-manager',
			'costCenter' => 'CC-100',
		]);

		$container = new FakeContainer([
			'OCA\OpenRegister\Service\ObjectService' => $this->store,
			'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
		]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('hrmq');

		$this->listener = new TimesheetProcessStampListener(
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
	 * Build a Timesheet pre-save entity.
	 *
	 * @param array<string, mixed> $payload The payload.
	 * @param string|null $uuid Optional uuid.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function timesheetEntity(array $payload, ?string $uuid = null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema('Timesheet');
		$entity->setObject($payload);
		if ($uuid !== null) {
			$entity->setUuid($uuid);
		}

		return $entity;
	}//end timesheetEntity()

	/**
	 * Run an update through the listener and return the modified data.
	 *
	 * @param array<string, mixed> $incoming The incoming payload.
	 * @param array<string, mixed> $stored The stored payload.
	 *
	 * @return array<string, mixed> The modified data.
	 */
	private function update(array $incoming, array $stored): array {
		$event = new ObjectUpdatingEvent(
			$this->timesheetEntity($incoming, 'ts-1'),
			$this->timesheetEntity($stored, 'ts-1')
		);
		$this->listener->handle($event);
		$this->assertFalse($event->isPropagationStopped());

		return $event->getModifiedData();
	}//end update()

	/**
	 * CREATE: whatever the client sent, the object starts at draft with all
	 * process fields empty, and the identity caches come from the chain —
	 * hand-typing an approval on create approves nothing.
	 *
	 * @return void
	 */
	public function testCreateForcesDraftAndDerivesCaches(): void {
		$event = new ObjectCreatingEvent($this->timesheetEntity([
			'employeeId' => 'employee-jansen',
			'period' => '2026-05',
			'status' => 'approved',
			'submittedAt' => '2026-05-01T00:00:00Z',
			'approvedBy' => 'attacker',
			'approvedAt' => '2026-05-01T00:00:00Z',
			'rejectionReason' => 'x',
			'userId' => 'someone-else',
			'managerUserId' => 'someone-else',
		]));
		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$modified = $event->getModifiedData();
		$this->assertSame('draft', $modified['status']);
		$this->assertNull($modified['submittedAt']);
		$this->assertNull($modified['approvedBy']);
		$this->assertNull($modified['approvedAt']);
		$this->assertNull($modified['rejectionReason']);
		$this->assertSame('admin', $modified['userId'], 'REQ-MHS-002: the chain wins, client input is inert.');
		$this->assertSame('manager1', $modified['managerUserId'], 'REQ-MSS-001: the org chain wins.');
		$this->assertSame('ADM-001', $modified['administrationId']);
	}//end testCreateForcesDraftAndDerivesCaches()

	/**
	 * UPDATE without a status edge: hand-written approval fields are
	 * restored from the stored object — the write persists, the tampering
	 * does not (the "hand-writing an approval approves nothing" scenario).
	 *
	 * @return void
	 */
	public function testClientProcessFieldsAreInertWithoutAnEdge(): void {
		$stored = [
			'employeeId' => 'employee-jansen',
			'period' => '2026-05',
			'status' => 'submitted',
			'submittedAt' => '2026-06-01T08:15:00Z',
			'approvedBy' => null,
			'approvedAt' => null,
			'rejectionReason' => null,
			'hours' => 152.0,
			'entryCount' => 3,
		];
		$modified = $this->update(
			array_merge($stored, [
				'approvedBy' => 'attacker',
				'approvedAt' => '2026-06-02T00:00:00Z',
				'rejectionReason' => 'rides along',
				'hours' => 999.0,
				'entryCount' => 99,
			]),
			$stored
		);

		$this->assertNull($modified['approvedBy'], 'The stored (empty) approval survives the tamper.');
		$this->assertNull($modified['approvedAt']);
		$this->assertNull($modified['rejectionReason'], 'The reason cannot ride a non-reject write.');
		$this->assertSame('2026-06-01T08:15:00Z', $modified['submittedAt']);
		$this->assertSame(152.0, $modified['hours'], 'Aggregates are inert to clients.');
		$this->assertSame(3, $modified['entryCount']);
	}//end testClientProcessFieldsAreInertWithoutAnEdge()

	/**
	 * The submit edge stamps submittedAt and clears the approval fields —
	 * from draft AND from rejected (re-submission clears the old verdict).
	 *
	 * @return void
	 */
	public function testSubmitEdgeStampsSubmittedAt(): void {
		$stored = [
			'employeeId' => 'employee-jansen',
			'period' => '2026-05',
			'status' => 'rejected',
			'submittedAt' => '2026-06-01T08:15:00Z',
			'approvedBy' => 'manager-pietersen',
			'approvedAt' => '2026-06-02T14:00:00Z',
			'rejectionReason' => 'te veel uren',
		];
		$modified = $this->update(array_merge($stored, ['status' => 'submitted']), $stored);

		$this->assertNotEmpty($modified['submittedAt']);
		$this->assertNotSame('2026-06-01T08:15:00Z', $modified['submittedAt'], 'A fresh submission stamps a fresh timestamp.');
		$this->assertNull($modified['approvedBy'], 'Re-submission clears the previous verdict.');
		$this->assertNull($modified['approvedAt']);
		$this->assertNull($modified['rejectionReason']);
	}//end testSubmitEdgeStampsSubmittedAt()

	/**
	 * The approve edge stamps the ACTING user and a timestamp on the
	 * carrying write — the provenance the CloudEvent then observes.
	 *
	 * @return void
	 */
	public function testApproveEdgeStampsActingUser(): void {
		$stored = [
			'employeeId' => 'employee-jansen',
			'period' => '2026-05',
			'status' => 'submitted',
			'submittedAt' => '2026-06-01T08:15:00Z',
			'approvedBy' => null,
			'approvedAt' => null,
		];
		$modified = $this->update(array_merge($stored, ['status' => 'approved', 'approvedBy' => 'attacker']), $stored);

		$this->assertSame('manager1', $modified['approvedBy'], 'The SESSION user is stamped, never the payload.');
		$this->assertNotEmpty($modified['approvedAt']);
		$this->assertSame('2026-06-01T08:15:00Z', $modified['submittedAt'], 'Approval keeps the submission stamp.');
	}//end testApproveEdgeStampsActingUser()

	/**
	 * The reject edge stamps the verdict AND accepts the incoming
	 * rejectionReason — the single allowlisted client-supplied process
	 * value (the D1 transition-input contract's humaniq half).
	 *
	 * @return void
	 */
	public function testRejectEdgeAcceptsTheReason(): void {
		$stored = [
			'employeeId' => 'employee-jansen',
			'period' => '2026-05',
			'status' => 'submitted',
			'submittedAt' => '2026-06-01T08:15:00Z',
			'approvedBy' => null,
			'approvedAt' => null,
			'rejectionReason' => null,
		];
		$modified = $this->update(
			array_merge($stored, ['status' => 'rejected', 'rejectionReason' => 'Uren kloppen niet met aanwezigheid']),
			$stored
		);

		$this->assertSame('manager1', $modified['approvedBy']);
		$this->assertNotEmpty($modified['approvedAt']);
		$this->assertSame('Uren kloppen niet met aanwezigheid', $modified['rejectionReason']);
	}//end testRejectEdgeAcceptsTheReason()

	/**
	 * The reopen edge clears all four process fields.
	 *
	 * @return void
	 */
	public function testReopenEdgeClearsProcessFields(): void {
		$stored = [
			'employeeId' => 'employee-jansen',
			'period' => '2026-05',
			'status' => 'approved',
			'submittedAt' => '2026-06-01T08:15:00Z',
			'approvedBy' => 'manager1',
			'approvedAt' => '2026-06-02T10:30:00Z',
			'rejectionReason' => null,
		];
		$modified = $this->update(array_merge($stored, ['status' => 'draft']), $stored);

		$this->assertNull($modified['submittedAt']);
		$this->assertNull($modified['approvedBy']);
		$this->assertNull($modified['approvedAt']);
		$this->assertNull($modified['rejectionReason']);
	}//end testReopenEdgeClearsProcessFields()

	/**
	 * Under the internal-writer marker the AGGREGATES pass through (the
	 * recompute's whole point) while the process fields stay inert — an
	 * internal writer may maintain totals, never fabricate approvals.
	 *
	 * @return void
	 */
	public function testMarkerLetsAggregatesThroughButNotProcessFields(): void {
		$stored = [
			'employeeId' => 'employee-jansen',
			'period' => '2026-05',
			'status' => 'submitted',
			'submittedAt' => '2026-06-01T08:15:00Z',
			'approvedBy' => null,
			'hours' => 8.0,
			'entryCount' => 1,
		];
		$incoming = array_merge($stored, [
			'hours' => 12.0,
			'entryCount' => 2,
			'approvedBy' => 'should-not-stick',
		]);

		$modified = $this->marker->runInternal(function () use ($incoming, $stored): array {
			return $this->update($incoming, $stored);
		});

		$this->assertArrayNotHasKey('hours', $modified, 'Under the marker the aggregate values pass through untouched.');
		$this->assertArrayNotHasKey('entryCount', $modified);
		$this->assertNull($modified['approvedBy'], 'Process fields stay inert even for internal writes.');
	}//end testMarkerLetsAggregatesThroughButNotProcessFields()

	/**
	 * An update whose stored state cannot be established is refused —
	 * without it the inertness guarantee would be a guess (fail closed).
	 *
	 * @return void
	 */
	public function testUpdateWithoutResolvableStoredStateIsRefused(): void {
		$event = new ObjectUpdatingEvent(
			$this->timesheetEntity(['employeeId' => 'employee-jansen', 'period' => '2026-05'], 'ts-unknown'),
			null
		);
		$this->listener->handle($event);

		$this->assertTrue($event->isPropagationStopped());
	}//end testUpdateWithoutResolvableStoredStateIsRefused()

	/**
	 * A non-Timesheet schema is ignored entirely.
	 *
	 * @return void
	 */
	public function testForeignSchemaIsIgnored(): void {
		$entity = new ObjectEntity();
		$entity->setSchema('TimeEntry');
		$entity->setObject(['startedAt' => '2026-05-04T09:00:00Z']);

		$event = new ObjectCreatingEvent($entity);
		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getModifiedData());
	}//end testForeignSchemaIsIgnored()

	/**
	 * Event types this listener does not stamp — a foreign event class and
	 * an UPDATE of a foreign schema — pass through untouched.
	 *
	 * @return void
	 */
	public function testForeignEventTypesAndForeignUpdatesAreIgnored(): void {
		// Neither creating nor updating: not this listener's subject.
		$foreign = new class extends \OCP\EventDispatcher\Event {
		};
		$this->listener->handle($foreign);

		// An update of a non-Timesheet schema: the update arm's own gate.
		$entity = new ObjectEntity();
		$entity->setSchema('TimeEntry');
		$entity->setObject(['startedAt' => '2026-05-04T09:00:00Z']);
		$event = new ObjectUpdatingEvent($entity, $entity);
		$this->listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame([], $event->getModifiedData());
	}//end testForeignEventTypesAndForeignUpdatesAreIgnored()

	/**
	 * An update that omits employeeId derives the identity caches from the
	 * STORED employee link — omitting a field must never detach the caches.
	 *
	 * @return void
	 */
	public function testUpdateWithoutIncomingEmployeeIdDerivesFromTheStoredOne(): void {
		$modified = $this->update(
			['period' => '2026-05', 'status' => 'draft', 'description' => 'bijgewerkt'],
			['employeeId' => 'employee-jansen', 'period' => '2026-05', 'status' => 'draft']
		);

		$this->assertSame('admin', $modified['userId'], 'Derived from the STORED employeeId.');
		$this->assertSame('ADM-001', $modified['administrationId']);
	}//end testUpdateWithoutIncomingEmployeeIdDerivesFromTheStoredOne()

	/**
	 * A create without any employee link keeps the caches empty (nothing to
	 * derive from) — and still forces draft.
	 *
	 * @return void
	 */
	public function testCreateWithoutEmployeeIdKeepsEmptyCaches(): void {
		$event = new ObjectCreatingEvent($this->timesheetEntity([
			'period' => '2026-05',
			'userId' => 'attacker',
		]));
		$this->listener->handle($event);

		$modified = $event->getModifiedData();
		$this->assertSame('draft', $modified['status']);
		$this->assertNull($modified['userId'], 'No employee link: the cache is NULL, never the client value.');
		$this->assertNull($modified['managerUserId']);
	}//end testCreateWithoutEmployeeIdKeepsEmptyCaches()

	/**
	 * A DANGLING employee link keeps the stored caches — an unresolvable
	 * Employee and an infra failure are indistinguishable here, and wiping
	 * possibly-valid values on a flake would be worse (REQ-MHS-002).
	 *
	 * @return void
	 */
	public function testDanglingEmployeeLinkKeepsStoredCaches(): void {
		$modified = $this->update(
			['employeeId' => 'employee-gone', 'period' => '2026-05', 'status' => 'draft'],
			[
				'employeeId' => 'employee-gone',
				'period' => '2026-05',
				'status' => 'draft',
				'userId' => 'previous-user',
				'managerUserId' => 'previous-manager',
				'administrationId' => 'ADM-009',
			]
		);

		$this->assertSame('previous-user', $modified['userId']);
		$this->assertSame('previous-manager', $modified['managerUserId']);
		$this->assertSame('ADM-009', $modified['administrationId']);
	}//end testDanglingEmployeeLinkKeepsStoredCaches()

	/**
	 * A THROWING chain lookup during cache derivation is logged and the
	 * fallback values are kept — while a failure OUTSIDE the derive (the
	 * stored-state load) fails closed with the generic refusal.
	 *
	 * @return void
	 */
	public function testChainFailureKeepsFallbackAndStoredLoadFailureRefuses(): void {
		$gateway = $this->createMock(HoursRegisterGateway::class);
		$gateway->method('resolveSchemaSlug')->willReturn('timesheet');
		$gateway->method('findObjectData')->willThrowException(new \RuntimeException('register down'));

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);

		$listener = new TimesheetProcessStampListener(
			gateway: $gateway,
			userSession: $session,
			marker: new InternalWriteMarker(),
			logger: new NullLogger()
		);

		// CREATE: the throwing lookup happens INSIDE deriveIdentityCaches —
		// logged, fallback kept, the write itself goes through as draft.
		$event = new ObjectCreatingEvent($this->timesheetEntity([
			'employeeId' => 'employee-jansen',
			'period' => '2026-05',
		]));
		$listener->handle($event);
		$this->assertFalse($event->isPropagationStopped());
		$this->assertSame('draft', $event->getModifiedData()['status']);
		$this->assertNull($event->getModifiedData()['userId']);

		// UPDATE without a resolvable old object: the stored-state load
		// throws OUTSIDE the derive — fail closed.
		$event = new ObjectUpdatingEvent(
			$this->timesheetEntity(['employeeId' => 'employee-jansen', 'period' => '2026-05'], 'ts-1'),
			null
		);
		$listener->handle($event);
		$this->assertTrue($event->isPropagationStopped());
		$this->assertStringContainsString('niet worden verwerkt', (string)$event->getErrors()['message']);
	}//end testChainFailureKeepsFallbackAndStoredLoadFailureRefuses()

}//end class
