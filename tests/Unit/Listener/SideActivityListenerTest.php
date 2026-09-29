<?php

/**
 * Unit tests for SideActivityListener (people-secondment-and-side-activities D3, D4).
 *
 * The listener runs with the real SideActivityRegister and HoursRegisterGateway
 * over the in-memory object store, on the real OpenRegister event classes
 * (stubbed only when OpenRegister is absent); the decision guards are the real
 * NoSelfApprovalGuard and DecisionReasonGuard, and stamped payloads are
 * validated against the SideActivity fragment.
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
 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Lifecycle\DecisionReasonGuard;
use OCA\Humaniq\Lifecycle\NoSelfApprovalGuard;
use OCA\Humaniq\Listener\SideActivityListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\SideActivityRegister;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the side activity register.
 *
 * @spec openspec/specs/secondment-and-side-activities/spec.md#REQ-SEC-003
 */
class SideActivityListenerTest extends TestCase {

	private const INGRID = '4f2b8c1d-6e3a-4d7b-9a1c-0b5e7f2d3c41';

	private FakeObjectStore $store;

	private string $uid = 'ingrid';

	/**
	 * Seed Ingrid de Boer, a civil servant with no report on file.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->store->seed('Employee', self::INGRID, ['firstName' => 'Ingrid', 'lastName' => 'de Boer', 'nextcloudUserId' => 'ingrid', 'publicSectorRegime' => 'ambtenarenwet', 'nevenwerkzaamhedenGemeld' => false, 'administrationId' => 'ADM-001']);
	}//end setUp()

	/**
	 * A civil servant reports a board seat: it is hers, and her record now counts as reported.
	 *
	 * @return void
	 */
	public function testAReportIsStampedAndTicksTheAttestation(): void {
		$report = ['description' => 'Bestuurslid woningcorporatie', 'organisation' => 'Woonstede', 'paid' => true, 'hoursPerWeek' => 2];
		$stamped = $this->create($report);

		self::assertSame(self::INGRID, $stamped['employeeId']);
		self::assertSame('ingrid', $stamped['userId']);
		self::assertSame('ADM-001', $stamped['administrationId']);
		self::assertSame([], RegisterSchemaValidator::errors('SideActivity', array_filter(array_merge($report, $stamped), static fn ($v): bool => $v !== null)));

		$this->saved(array_merge($report, $stamped, ['status' => 'gemeld']));
		self::assertTrue($this->store->state->objects['Employee'][self::INGRID]['nevenwerkzaamhedenGemeld']);
	}//end testAReportIsStampedAndTicksTheAttestation()

	/**
	 * A nil report counts as reported; withdrawing the last report clears it again.
	 *
	 * @return void
	 */
	public function testANilReportCountsAndAWithdrawnOneDoesNot(): void {
		$stamped = $this->create(['noneToReport' => true]);
		$this->saved(array_merge(['noneToReport' => true, 'status' => 'gemeld'], $stamped));
		self::assertTrue($this->store->state->objects['Employee'][self::INGRID]['nevenwerkzaamhedenGemeld']);

		$this->store->state->objects['SideActivity'] = [];
		$this->listener()->handle(new ObjectDeletedEvent($this->entity(array_merge(['noneToReport' => true, 'status' => 'gemeld'], $stamped))));
		self::assertFalse($this->store->state->objects['Employee'][self::INGRID]['nevenwerkzaamhedenGemeld']);
	}//end testANilReportCountsAndAWithdrawnOneDoesNot()

	/**
	 * A report with no activity and no nil tick is refused.
	 *
	 * @return void
	 */
	public function testAnEmptyReportIsRefused(): void {
		$event = new ObjectCreatingEvent($this->entity(['paid' => false]));
		$this->listener()->handle($event);

		self::assertNotSame([], $event->getErrors());
	}//end testAnEmptyReportIsRefused()

	/**
	 * Someone outside HR cannot report for another employee.
	 *
	 * @return void
	 */
	public function testSomeoneElseCannotReportForHer(): void {
		$this->uid = 'colleague';
		$event = new ObjectCreatingEvent($this->entity(['employeeId' => self::INGRID, 'description' => 'Iets']));
		$this->listener()->handle($event);

		self::assertNotSame([], $event->getErrors());
	}//end testSomeoneElseCannotReportForHer()

	/**
	 * The attestation cannot be ticked by hand: it follows the register.
	 *
	 * @return void
	 */
	public function testTheAttestationCannotBeTickedByHand(): void {
		$old = $this->store->state->objects['Employee'][self::INGRID];
		$new = array_merge($old, ['nevenwerkzaamhedenGemeld' => true]);
		$event = new ObjectUpdatingEvent($this->employee($new), $this->employee($old));
		$this->listener()->handle($event);

		self::assertSame(['nevenwerkzaamhedenGemeld' => false], $event->getModifiedData());
	}//end testTheAttestationCannotBeTickedByHand()

	/**
	 * She cannot approve her own report, and a refusal needs a conflict reason.
	 *
	 * @return void
	 */
	public function testTheEmployeeCannotDecideAndARefusalNeedsAReason(): void {
		$activity = array_merge(['description' => 'Adviseur', 'organisation' => 'Leverancier BV', 'status' => 'gemeld'], $this->create(['description' => 'Adviseur', 'organisation' => 'Leverancier BV']));
		$noSelf = new NoSelfApprovalGuard();
		$reason = new DecisionReasonGuard($noSelf);

		self::assertFalse($noSelf->check($activity, 'akkoord', 'ingrid')->isAllowed());
		self::assertTrue($noSelf->check($activity, 'akkoord', 'manager')->isAllowed());
		self::assertFalse($reason->check($activity, 'afwijzen', 'manager')->isAllowed());
		self::assertTrue($reason->check(array_merge($activity, ['decisionReason' => 'Leverancier van de gemeente']), 'afwijzen', 'manager')->isAllowed());
	}//end testTheEmployeeCannotDecideAndARefusalNeedsAReason()

	/**
	 * Run the pre-save listener on a new report.
	 *
	 * @param array<string, mixed> $report The payload.
	 *
	 * @return array<string, mixed> The stamped fields.
	 */
	private function create(array $report): array {
		$event = new ObjectCreatingEvent($this->entity($report));
		$this->listener()->handle($event);
		self::assertSame([], $event->getErrors());

		return $event->getModifiedData();
	}//end create()

	/**
	 * Store a report and run the post-save listener.
	 *
	 * @param array<string, mixed> $report The stored payload.
	 *
	 * @return void
	 */
	private function saved(array $report): void {
		$this->store->seed('SideActivity', 'act-' . count($this->store->state->objects['SideActivity'] ?? []), $report);
		$this->listener()->handle(new ObjectCreatedEvent($this->entity($report)));
	}//end saved()

	/**
	 * A SideActivity entity.
	 *
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('act-x');
		$entity->setSchema('SideActivity');
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * An Employee entity.
	 *
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private function employee(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid(self::INGRID);
		$entity->setSchema('Employee');
		$entity->setObject($data);
		return $entity;
	}//end employee()

	/**
	 * The listener with its real collaborators.
	 *
	 * @return SideActivityListener
	 */
	private function listener(): SideActivityListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $this->store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);
		$groups->method('isInGroup')->willReturnCallback(static fn (string $uid, string $group): bool => $uid === 'hr.user' && $group === HumaniqRoles::HR_GROUP);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($this->uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new SideActivityListener(
			register: new SideActivityRegister(gateway: $gateway, roles: new HumaniqRoles($groups)),
			userSession: $session,
			marker: new InternalWriteMarker(),
			logger: new NullLogger()
		);
	}//end listener()

}//end class
