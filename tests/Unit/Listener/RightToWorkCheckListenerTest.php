<?php

/**
 * Unit tests for RightToWorkCheckListener (people-dossier-completeness D4).
 *
 * The listener runs with the real RightToWorkRecorder, RightToWorkService and
 * HoursRegisterGateway over the in-memory object store, on the real
 * OpenRegister event classes (stubbed only when OpenRegister is absent), and
 * every stamped payload is validated against the RightToWorkCheck fragment.
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
 * @spec openspec/changes/people-dossier-completeness/specs/dossier-completeness/spec.md#REQ-DCP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\RightToWorkCheckListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\RightToWorkRecorder;
use OCA\Humaniq\Service\RightToWorkService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the right-to-work check listener.
 *
 * @spec openspec/changes/people-dossier-completeness/specs/dossier-completeness/spec.md#REQ-DCP-003
 */
class RightToWorkCheckListenerTest extends TestCase {

	private const EMPLOYEE = '0127394a-be27-48b4-a592-b6a41774b221';

	private const ONBOARDING = '9d3f7a52-4c1e-4b8a-9f0d-2e6b1c7a8d90';

	private FakeObjectStore $store;

	private string $uid = 'hr.user';

	/**
	 * Seed one employee and one onboarding case at gegevens_gevalideerd.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->store->seed('Employee', self::EMPLOYEE, ['firstName' => 'Noa', 'lastName' => 'Visser', 'startDate' => '2026-10-01']);
		$this->store->seed('Onboarding', self::ONBOARDING, ['employeeId' => self::EMPLOYEE, 'startDate' => '2026-10-01', 'status' => 'gegevens_gevalideerd', 'widCheckDone' => false]);
	}//end setUp()

	/**
	 * A residence permit without work endorsement fails and leaves the WID check open.
	 *
	 * @return void
	 */
	public function testAResidencePermitWithoutWorkEndorsementFails(): void {
		$check = ['onboardingId' => self::ONBOARDING, 'documentType' => 'verblijfsdocument', 'nationality' => 'SYR', 'documentExpiry' => '2029-05-01', 'endorsement' => 'Arbeid niet toegestaan.'];
		$stamped = $this->create($check);

		self::assertSame('mislukt', $stamped['result']);
		self::assertStringStartsWith('No permission to work', $stamped['reason']);
		self::assertSame(self::EMPLOYEE, $stamped['employeeId']);
		self::assertSame([], RegisterSchemaValidator::errors('RightToWorkCheck', array_filter(array_merge($check, $stamped), static fn ($v): bool => $v !== null)));

		$this->created(array_merge($check, $stamped));
		self::assertFalse($this->store->state->objects['Onboarding'][self::ONBOARDING]['widCheckDone']);
	}//end testAResidencePermitWithoutWorkEndorsementFails()

	/**
	 * A Dutch passport passes and the onboarding checklist shows the WID check done today.
	 *
	 * @return void
	 */
	public function testADutchPassportPassesAndTicksTheWidCheck(): void {
		$check = ['onboardingId' => self::ONBOARDING, 'documentType' => 'paspoort', 'nationality' => 'NLD', 'documentExpiry' => '2031-03-09'];
		$stamped = $this->create($check);

		self::assertSame('geslaagd', $stamped['result']);
		self::assertSame(date('Y-m-d'), $stamped['checkedOn']);
		self::assertSame('hr.user', $stamped['checkedBy']);

		$this->created(array_merge($check, $stamped));
		$onboarding = $this->store->state->objects['Onboarding'][self::ONBOARDING];
		self::assertTrue($onboarding['widCheckDone']);
		self::assertSame(date('Y-m-d'), $onboarding['widCheckDate']);
		self::assertSame('gegevens_gevalideerd', $onboarding['status']);
	}//end testADutchPassportPassesAndTicksTheWidCheck()

	/**
	 * A pasted zone is read, and removed before the save: no document number is stored.
	 *
	 * @return void
	 */
	public function testAPastedZoneIsReadAndNotStored(): void {
		$zone = "P<NLDDE<BRUIJN<<WILLEKE<LISELOTTE<<<<<<<<<<<\nSPECI20142NLD6503101F3103094<<<<<<<<<<<<<<06";
		$stamped = $this->create(['employeeId' => self::EMPLOYEE, 'mrz' => $zone]);

		self::assertArrayHasKey('mrz', $stamped);
		self::assertNull($stamped['mrz']);
		self::assertSame('extractie', $stamped['method']);
		self::assertSame('NLD', $stamped['nationality']);
		self::assertSame('geslaagd', $stamped['result']);
		self::assertStringNotContainsString('SPECI2014', (string)json_encode($stamped));
	}//end testAPastedZoneIsReadAndNotStored()

	/**
	 * A result edited by hand is recomputed by the rule.
	 *
	 * @return void
	 */
	public function testAHandEditedResultIsRecomputed(): void {
		$entity = $this->entity(['employeeId' => self::EMPLOYEE, 'documentType' => 'verblijfsdocument', 'nationality' => 'SYR', 'documentExpiry' => '2029-05-01', 'endorsement' => 'Arbeid niet toegestaan.', 'result' => 'geslaagd', 'checkedOn' => '2026-09-20']);
		$event = new ObjectUpdatingEvent($entity, $entity);
		$this->listener()->handle($event);

		self::assertSame('mislukt', $event->getModifiedData()['result']);
		self::assertSame('2026-09-20', $event->getModifiedData()['checkedOn']);
	}//end testAHandEditedResultIsRecomputed()

	/**
	 * Someone outside HR cannot record a check.
	 *
	 * @return void
	 */
	public function testSomeoneOutsideHrIsRefused(): void {
		$this->uid = 'manager';
		$event = new ObjectCreatingEvent($this->entity(['employeeId' => self::EMPLOYEE, 'documentType' => 'paspoort', 'nationality' => 'NLD', 'documentExpiry' => '2031-03-09']));
		$this->listener()->handle($event);

		self::assertNotSame([], $event->getErrors());
		self::assertSame([], $event->getModifiedData());
	}//end testSomeoneOutsideHrIsRefused()

	/**
	 * A residence document that allows work becomes a personnel document with its expiry.
	 *
	 * @return void
	 */
	public function testAPassingResidenceDocumentIsFiledWithItsExpiry(): void {
		$check = ['onboardingId' => self::ONBOARDING, 'documentType' => 'verblijfsdocument', 'nationality' => 'SYR', 'documentExpiry' => '2029-05-01', 'endorsement' => 'Arbeid vrij toegestaan. TWV niet vereist.'];
		$stamped = $this->create($check);
		$this->created(array_merge($check, $stamped));
		$this->created(array_merge($check, $stamped));

		$documents = array_values($this->store->state->objects['PersonnelDocument'] ?? []);
		self::assertCount(1, $documents);
		self::assertSame('2029-05-01', $documents[0]['validUntil']);
		self::assertSame('verblijfsdocument', $documents[0]['requirementCode']);
		$payload = $documents[0];
		unset($payload['id']);
		self::assertSame([], RegisterSchemaValidator::errors('PersonnelDocument', array_filter($payload, static fn ($v): bool => $v !== null)));
	}//end testAPassingResidenceDocumentIsFiledWithItsExpiry()

	/**
	 * Run the pre-save listener on a new check.
	 *
	 * @param array<string, mixed> $check The payload.
	 *
	 * @return array<string, mixed> The stamped fields.
	 */
	private function create(array $check): array {
		$event = new ObjectCreatingEvent($this->entity($check));
		$this->listener()->handle($event);
		self::assertSame([], $event->getErrors());

		return $event->getModifiedData();
	}//end create()

	/**
	 * Run the post-save listener on a stored check.
	 *
	 * @param array<string, mixed> $check The stored payload.
	 *
	 * @return void
	 */
	private function created(array $check): void {
		$this->listener()->handle(new ObjectCreatedEvent($this->entity($check)));
	}//end created()

	/**
	 * A RightToWorkCheck entity.
	 *
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('rtw-1');
		$entity->setSchema('RightToWorkCheck');
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * The listener with its real collaborators.
	 *
	 * @return RightToWorkCheckListener
	 */
	private function listener(): RightToWorkCheckListener {
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

		return new RightToWorkCheckListener(
			gateway: $gateway,
			recorder: new RightToWorkRecorder(gateway: $gateway, rule: new RightToWorkService()),
			roles: new HumaniqRoles($groups),
			userSession: $session,
			marker: new InternalWriteMarker(),
			logger: new NullLogger()
		);
	}//end listener()

}//end class
