<?php

/**
 * Unit tests for AnnouncementService and AnnouncementConfirmationListener
 * (self-service-announcements-and-digest D1, D2).
 *
 * Both run with the real HoursRegisterGateway and UnitMembership over the
 * in-memory object store, on the real OpenRegister event class; confirmation
 * payloads are validated against the AnnouncementConfirmation fragment.
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
 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Listener\AnnouncementConfirmationListener;
use OCA\Humaniq\Service\AnnouncementService;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\UnitMembership;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-001
 */
class AnnouncementServiceTest extends TestCase {

	private const TODAY = '2026-10-05';

	private FakeObjectStore $store;

	/**
	 * Two departments under one directorate, three employees.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->store->seed('OrgUnit', 'unit-dir', ['name' => 'Directie', 'type' => 'afdeling', 'administrationId' => 'ADM-001']);
		$this->store->seed('OrgUnit', 'unit-bz', ['name' => 'Burgerzaken', 'type' => 'afdeling', 'parentUnitId' => 'unit-dir', 'administrationId' => 'ADM-001']);
		$this->store->seed('OrgUnit', 'unit-bz-balie', ['name' => 'Balie', 'type' => 'team', 'parentUnitId' => 'unit-bz', 'administrationId' => 'ADM-001']);
		$this->store->seed('OrgUnit', 'unit-fin', ['name' => 'Financien', 'type' => 'afdeling', 'parentUnitId' => 'unit-dir', 'administrationId' => 'ADM-001']);
		$this->employee('emp-anna', 'anna', 'Anna', 'Bakker', 'unit-bz-balie');
		$this->employee('emp-bram', 'bram', 'Bram', 'Visser', 'unit-fin');
		$this->employee('emp-cor', 'cor', 'Cor', 'Smit', 'unit-bz');
		$this->store->seed('Employee', 'emp-left', ['firstName' => 'Oud', 'lastName' => 'Collega', 'nextcloudUserId' => 'oud', 'administrationId' => 'ADM-001', 'endDate' => '2026-01-31']);
		$this->store->seed('Announcement', 'ann-all', ['title' => 'Nieuwe declaratieregeling 2027', 'body' => 'Lees de regeling.', 'audience' => 'administration', 'orgUnitIds' => [], 'requiresConfirmation' => true, 'status' => 'gepubliceerd', 'administrationId' => 'ADM-001']);
		$this->store->seed('Announcement', 'ann-bz', ['title' => 'Balie dicht op vrijdag', 'body' => 'Alleen Burgerzaken.', 'audience' => 'orgUnits', 'orgUnitIds' => ['unit-bz'], 'requiresConfirmation' => false, 'status' => 'gepubliceerd', 'administrationId' => 'ADM-001']);
		$this->store->seed('Announcement', 'ann-draft', ['title' => 'Concept', 'body' => 'Nog niet.', 'audience' => 'administration', 'status' => 'concept', 'administrationId' => 'ADM-001']);
		$this->store->seed('Announcement', 'ann-over', ['title' => 'Kerstsluiting 2025', 'body' => 'Voorbij.', 'audience' => 'administration', 'publishUntil' => '2026-01-02', 'status' => 'gepubliceerd', 'administrationId' => 'ADM-001']);
		$this->store->seed('Announcement', 'ann-later', ['title' => 'Kerstsluiting 2026', 'body' => 'Straks.', 'audience' => 'administration', 'publishFrom' => '2026-12-01', 'status' => 'gepubliceerd', 'administrationId' => 'ADM-001']);
	}//end setUp()

	/**
	 * Scenario: a new expense policy reaches everyone; a team announcement
	 * reaches the team and its sub-teams, not another department.
	 *
	 * @return void
	 */
	public function testAnEmployeeSeesWhatIsPublishedToThemToday(): void {
		self::assertSame(['ann-all', 'ann-bz'], $this->ids($this->service()->mine('anna', self::TODAY)), 'A member of a team under Burgerzaken sees the Burgerzaken announcement.');
		self::assertSame(['ann-all', 'ann-bz'], $this->ids($this->service()->mine('cor', self::TODAY)));
		self::assertSame(['ann-all'], $this->ids($this->service()->mine('bram', self::TODAY)), 'Financien does not see the Burgerzaken announcement.');
		self::assertSame([], $this->service()->mine('nobody', self::TODAY), 'A user without an employee record sees nothing.');
	}//end testAnEmployeeSeesWhatIsPublishedToThemToday()

	/**
	 * A row carries what the page shows, and the caller's own confirmation.
	 *
	 * @return void
	 */
	public function testARowCarriesTheCallersConfirmation(): void {
		$rows = $this->service()->mine('anna', self::TODAY);
		self::assertSame('Nieuwe declaratieregeling 2027', $rows[0]['title']);
		self::assertTrue($rows[0]['requiresConfirmation']);
		self::assertFalse($rows[0]['confirmed']);

		$this->service()->confirm('ann-all', 'anna', '2026-10-05T09:00:00+00:00');
		$rows = $this->service()->mine('anna', self::TODAY);
		self::assertTrue($rows[0]['confirmed']);
		self::assertSame('2026-10-05T09:00:00+00:00', $rows[0]['confirmedAt']);
	}//end testARowCarriesTheCallersConfirmation()

	/**
	 * A confirmation is stored once, placed on the employee, and fits the
	 * AnnouncementConfirmation schema; a second one is refused.
	 *
	 * @return void
	 */
	public function testAnEmployeeConfirmsOnce(): void {
		$first = $this->service()->confirm('ann-all', 'anna', '2026-10-05T09:00:00+00:00');
		self::assertSame(201, $first['status']);
		$stored = array_values($this->store->state->objects['AnnouncementConfirmation']);
		self::assertCount(1, $stored);
		self::assertSame('emp-anna', $stored[0]['employeeId']);
		self::assertSame('anna', $stored[0]['userId']);
		$payload = $this->store->state->saves[0]['payload'];
		unset($payload['id']);
		self::assertSame([], RegisterSchemaValidator::errors('AnnouncementConfirmation', array_merge($payload, ['announcementId' => '5f0c2d1e-7a3b-4c8d-9e1f-2a3b4c5d6e7f', 'employeeId' => '6a1d3e2f-8b4c-4d9e-8f20-3b4c5d6e7f80'])));

		$second = $this->service()->confirm('ann-all', 'anna', '2026-10-06T09:00:00+00:00');
		self::assertSame(409, $second['status']);
		self::assertCount(1, $this->store->state->objects['AnnouncementConfirmation']);
	}//end testAnEmployeeConfirmsOnce()

	/**
	 * Only an announcement the caller sees, and that asks for it, is confirmed.
	 *
	 * @return void
	 */
	public function testAConfirmationOutsideTheAudienceIsRefused(): void {
		self::assertSame(404, $this->service()->confirm('ann-bz', 'bram', '2026-10-05T09:00:00+00:00')['status'], 'Financien cannot confirm a Burgerzaken announcement.');
		self::assertSame(404, $this->service()->confirm('ann-draft', 'anna', '2026-10-05T09:00:00+00:00')['status']);
		self::assertSame(409, $this->service()->confirm('ann-bz', 'anna', '2026-10-05T09:00:00+00:00')['status'], 'An announcement that does not ask for confirmation is not confirmed.');
		self::assertArrayNotHasKey('AnnouncementConfirmation', $this->store->state->objects);
	}//end testAConfirmationOutsideTheAudienceIsRefused()

	/**
	 * Scenario: HR follows up on unread policies.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-002
	 */
	public function testHrSeesWhoHasNotConfirmed(): void {
		$this->service()->confirm('ann-all', 'bram', '2026-10-05T09:00:00+00:00');

		$overview = $this->service()->overview('ann-all', self::TODAY);
		self::assertSame(3, $overview['total'], 'The employee who left is not in the audience.');
		self::assertSame(1, $overview['confirmed']);
		self::assertSame(['Anna Bakker', 'Cor Smit', 'Bram Visser'], array_column($overview['rows'], 'name'), 'Who has not confirmed comes first.');
		self::assertSame([false, false, true], array_column($overview['rows'], 'confirmed'));

		$team = $this->service()->overview('ann-bz', self::TODAY);
		self::assertSame(['Anna Bakker', 'Cor Smit'], array_column($team['rows'], 'name'));
		self::assertNull($this->service()->overview('ann-missing', self::TODAY));
	}//end testHrSeesWhoHasNotConfirmed()

	/**
	 * A confirmation created past the endpoint is placed on the caller, and a
	 * second one for the same employee and announcement is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-002
	 */
	public function testTheListenerRefusesASecondConfirmation(): void {
		$event = new ObjectCreatingEvent($this->confirmation(['announcementId' => 'ann-all', 'confirmedAt' => '2026-10-05T09:00:00+00:00']));
		$this->listener('anna')->handle($event);
		self::assertSame([], $event->getErrors());
		self::assertSame('emp-anna', $event->getModifiedData()['employeeId']);
		self::assertSame('anna', $event->getModifiedData()['userId']);

		$this->store->seed('AnnouncementConfirmation', 'conf-1', ['announcementId' => 'ann-all', 'employeeId' => 'emp-anna', 'userId' => 'anna', 'confirmedAt' => '2026-10-05T09:00:00+00:00']);
		$again = new ObjectCreatingEvent($this->confirmation(['announcementId' => 'ann-all', 'confirmedAt' => '2026-10-06T09:00:00+00:00']));
		$this->listener('anna')->handle($again);
		self::assertNotSame([], $again->getErrors());
	}//end testTheListenerRefusesASecondConfirmation()

	/**
	 * Seed an employee with an assignment active today.
	 *
	 * @param string $id     The employee id.
	 * @param string $uid    The Nextcloud account.
	 * @param string $first  The first name.
	 * @param string $last   The last name.
	 * @param string $unitId The unit.
	 *
	 * @return void
	 */
	private function employee(string $id, string $uid, string $first, string $last, string $unitId): void {
		$this->store->seed('Employee', $id, ['firstName' => $first, 'lastName' => $last, 'nextcloudUserId' => $uid, 'administrationId' => 'ADM-001']);
		$this->store->seed('OrgAssignment', 'asg-' . $id, ['employeeId' => $id, 'orgUnitId' => $unitId, 'startDate' => '2024-01-01', 'administrationId' => 'ADM-001']);
	}//end employee()

	/**
	 * The ids of listed rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 *
	 * @return array<int, string>
	 */
	private function ids(array $rows): array {
		return array_column($rows, 'id');
	}//end ids()

	/**
	 * A confirmation entity.
	 *
	 * @param array<string, mixed> $data The data.
	 *
	 * @return ObjectEntity
	 */
	private function confirmation(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('conf-x');
		$entity->setSchema('AnnouncementConfirmation');
		$entity->setObject($data);
		return $entity;
	}//end confirmation()

	/**
	 * The service over the fake store.
	 *
	 * @return AnnouncementService
	 */
	private function service(): AnnouncementService {
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

		return new AnnouncementService(gateway: $gateway, membership: new UnitMembership(), orgResolution: new OrgResolutionService());
	}//end service()

	/**
	 * The listener for a caller.
	 *
	 * @param string $uid The caller.
	 *
	 * @return AnnouncementConfirmationListener
	 */
	private function listener(string $uid): AnnouncementConfirmationListener {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new AnnouncementConfirmationListener(
			announcements: $this->service(),
			userSession: $session,
			marker: new InternalWriteMarker(),
			logger: new NullLogger()
		);
	}//end listener()

}//end class
