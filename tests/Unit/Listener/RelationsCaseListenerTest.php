<?php

/**
 * RelationsCaseListener tests
 *
 * An employee relations case is stamped with the employee's account, the
 * manager's account and the administration, which the schema's authorization
 * matches on; a closed case gets its retention date two years after closing
 * unless HR set one (people-employee-relations-cases D2, D3).
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
 * @spec openspec/specs/employee-relations-cases/spec.md#REQ-ERC-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\RelationsCaseListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Stamps and the retention date on a relations case.
 */
class RelationsCaseListenerTest extends TestCase {

	/**
	 * The register double.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * Seed Noa Visser, her unit and its manager.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->store->seed('Employee', '6b1f7c2e-3d4a-4e5b-8c6d-7e8f9a0b1c2d', ['firstName' => 'Noa', 'lastName' => 'Visser', 'nextcloudUserId' => 'noa', 'administrationId' => 'ADM-001']);
		$this->store->seed('Employee', 'emp-lead', ['firstName' => 'Kees', 'lastName' => 'de Leider', 'nextcloudUserId' => 'kees']);
		$this->store->seed('OrgAssignment', 'as-1', ['employeeId' => '6b1f7c2e-3d4a-4e5b-8c6d-7e8f9a0b1c2d', 'orgUnitId' => 'unit-1', 'endDate' => '']);
		$this->store->seed('OrgUnit', 'unit-1', ['managerId' => 'emp-lead']);
	}//end setUp()

	/**
	 * A new case carries the accounts its authorization matches on.
	 *
	 * @return void
	 */
	public function testANewCaseIsStampedWithTheAccountsItsAuthorizationReads(): void {
		$case = ['employeeId' => '6b1f7c2e-3d4a-4e5b-8c6d-7e8f9a0b1c2d', 'kind' => 'schriftelijke-waarschuwing', 'openedOn' => '2026-03-02', 'facts' => 'Drie keer te laat in maart.'];
		$event = new ObjectCreatingEvent($this->entity($case));
		$this->listener()->handle($event);

		self::assertSame(['userId' => 'noa', 'managerUserId' => 'kees', 'administrationId' => 'ADM-001'], $event->getModifiedData());
		self::assertSame([], RegisterSchemaValidator::errors('EmployeeRelationsCase', array_merge($case, $event->getModifiedData())));
	}//end testANewCaseIsStampedWithTheAccountsItsAuthorizationReads()

	/**
	 * A hand-set account is put back: the subject cannot be swapped for another reader.
	 *
	 * @return void
	 */
	public function testAHandSetAccountIsPutBack(): void {
		$case = ['employeeId' => '6b1f7c2e-3d4a-4e5b-8c6d-7e8f9a0b1c2d', 'kind' => 'klacht', 'openedOn' => '2026-03-02', 'userId' => 'someone-else', 'managerUserId' => 'someone-else'];
		$event = new ObjectUpdatingEvent($this->entity($case), $this->entity($case));
		$this->listener()->handle($event);

		self::assertSame('noa', $event->getModifiedData()['userId']);
		self::assertSame('kees', $event->getModifiedData()['managerUserId']);
	}//end testAHandSetAccountIsPutBack()

	/**
	 * Scenario: closing sets the retention date two years on, unless HR set one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/employee-relations-cases/spec.md#REQ-ERC-003
	 */
	public function testClosingSetsTheRetentionDateTwoYearsOn(): void {
		$closed = ['employeeId' => '6b1f7c2e-3d4a-4e5b-8c6d-7e8f9a0b1c2d', 'kind' => 'schriftelijke-waarschuwing', 'openedOn' => '2026-03-02', 'status' => 'afgesloten', 'outcome' => 'Waarschuwing gegeven.', 'closedOn' => '2026-03-20'];
		$event = new ObjectUpdatingEvent($this->entity($closed), $this->entity(array_merge($closed, ['status' => 'in-behandeling'])));
		$this->listener()->handle($event);
		self::assertSame('2028-03-20', $event->getModifiedData()['retainedUntil']);
		self::assertSame([], RegisterSchemaValidator::errors('EmployeeRelationsCase', array_merge($closed, $event->getModifiedData())));

		$kept = array_merge($closed, ['retainedUntil' => '2031-01-01']);
		$event = new ObjectUpdatingEvent($this->entity($kept), $this->entity($kept));
		$this->listener()->handle($event);
		self::assertArrayNotHasKey('retainedUntil', $event->getModifiedData());

		$open = array_merge($closed, ['status' => 'in-behandeling']);
		$event = new ObjectUpdatingEvent($this->entity($open), $this->entity($open));
		$this->listener()->handle($event);
		self::assertArrayNotHasKey('retainedUntil', $event->getModifiedData());
	}//end testClosingSetsTheRetentionDateTwoYearsOn()

	/**
	 * Another schema, or humaniq's own write, is left alone.
	 *
	 * @return void
	 */
	public function testOtherSchemasAndInternalWritesAreLeftAlone(): void {
		$entity = $this->entity(['employeeId' => '6b1f7c2e-3d4a-4e5b-8c6d-7e8f9a0b1c2d']);
		$entity->setSchema('SideActivity');
		$event = new ObjectCreatingEvent($entity);
		$this->listener()->handle($event);
		self::assertSame([], $event->getModifiedData());

		$marker = new InternalWriteMarker();
		$event = new ObjectCreatingEvent($this->entity(['employeeId' => '6b1f7c2e-3d4a-4e5b-8c6d-7e8f9a0b1c2d']));
		$marker->runInternal(fn () => $this->listener($marker)->handle($event));
		self::assertSame([], $event->getModifiedData());
	}//end testOtherSchemasAndInternalWritesAreLeftAlone()

	/**
	 * A relations case entity.
	 *
	 * @param array<string, mixed> $data The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('case-1');
		$entity->setSchema('EmployeeRelationsCase');
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * The listener with its real gateway.
	 *
	 * @param InternalWriteMarker|null $marker The marker to share.
	 *
	 * @return RelationsCaseListener
	 */
	private function listener(?InternalWriteMarker $marker=null): RelationsCaseListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');

		return new RelationsCaseListener(
			gateway: new HoursRegisterGateway(
				container: new FakeContainer([
					'OCA\OpenRegister\Service\ObjectService' => $this->store,
					'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
				]),
				settingsService: $settings,
				orgResolution: new OrgResolutionService()
			),
			marker: ($marker ?? new InternalWriteMarker()),
			logger: new NullLogger()
		);
	}//end listener()

}//end class
