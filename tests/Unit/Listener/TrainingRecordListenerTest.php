<?php

/**
 * Unit tests for TrainingRecordListener and TrainingCompetenceWriter.
 *
 * A training registered as attended gets its completion date and validity
 * filled in before it is saved, and once saved grants or extends the
 * employee's competence; a later validity extends it, an earlier one never
 * shortens it, and a training that was not attended grants nothing. Driven
 * through the real HoursRegisterGateway over the shared FakeObjectStore, with
 * OpenRegister's event classes, and every written object validated against
 * its schema in the register fragment.
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
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use DateTime;
use OCA\Humaniq\Listener\TrainingRecordListener;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Service\TrainingCompetenceWriter;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The competence a followed training grants, and the stamps before it is saved.
 */
class TrainingRecordListenerTest extends TestCase {

	private const EMPLOYEE_A = '0127394a-be27-48b4-a592-b6a41774b221';

	private const EMPLOYEE_B = '1b2c3d4e-0000-4000-8000-00000000000b';

	private const EMPLOYEE_C = '1b2c3d4e-0000-4000-8000-00000000000c';

	/**
	 * The fake register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The listener under test.
	 *
	 * @var TrainingRecordListener
	 */
	private TrainingRecordListener $listener;

	/**
	 * Build the listener over the real gateway and writer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		foreach ([self::EMPLOYEE_A, self::EMPLOYEE_B, self::EMPLOYEE_C] as $employee) {
			$this->store->seed('Employee', $employee, ['firstName' => 'Test', 'lastName' => 'Medewerker', 'administrationId' => 'ADM-001']);
		}

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
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-10-01'));

		$this->listener = new TrainingRecordListener(
			gateway: $gateway,
			writer: new TrainingCompetenceWriter($gateway, $time),
			logger: new NullLogger()
		);
	}//end setUp()

	/**
	 * A planned BHV refresher for one employee.
	 *
	 * @param string $employeeId The employee.
	 *
	 * @return array<string, mixed>
	 */
	private function plannedBhv(string $employeeId): array {
		return [
			'employeeId' => $employeeId,
			'title' => 'BHV herhaling',
			'plannedOn' => '2026-10-01',
			'status' => 'gepland',
			'validityMonths' => 12,
			'competenceCode' => 'bhv',
			'source' => 'hr',
		];
	}//end plannedBhv()

	/**
	 * An entity of the TrainingRecord schema.
	 *
	 * @param string               $uuid Object id.
	 * @param array<string, mixed> $data Payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data, string $schema='TrainingRecord'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * Register a planned record as attended or not, the way the lifecycle
	 * transition writes it: the pre-save event, then the post-save event with
	 * the stamped payload.
	 *
	 * @param string $uuid   Object id.
	 * @param string $status The new status.
	 *
	 * @return array<string, mixed> The record as saved.
	 */
	private function register(string $uuid, string $status): array {
		$old = $this->store->state->objects['TrainingRecord'][$uuid];
		$new = array_merge($old, ['status' => $status]);
		$updating = new ObjectUpdatingEvent($this->entity($uuid, $new), $this->entity($uuid, $old));
		$this->listener->handle($updating);
		$saved = array_merge($new, $updating->getModifiedData());
		$this->store->seed('TrainingRecord', $uuid, $saved);
		$this->listener->handle(new ObjectUpdatedEvent($this->entity($uuid, $saved), $this->entity($uuid, $old)));

		return $this->store->state->objects['TrainingRecord'][$uuid];
	}//end register()

	/**
	 * The competences of one employee.
	 *
	 * @param string $employeeId The employee.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function competences(string $employeeId): array {
		return array_values(
			array_filter(
				($this->store->state->objects['EmployeeCompetence'] ?? []),
				static fn (array $row): bool => $row['employeeId'] === $employeeId
			)
		);
	}//end competences()

	/**
	 * Two of three attend the first-aid course: the two get a bhv competence
	 * valid for a year, the third gets none, and every record keeps its status.
	 *
	 * @return void
	 */
	public function testTwoOfThreeAttendTheFirstAidCourse(): void {
		$this->store->seed('TrainingRecord', 'tr-a', $this->plannedBhv(self::EMPLOYEE_A));
		$this->store->seed('TrainingRecord', 'tr-b', $this->plannedBhv(self::EMPLOYEE_B));
		$this->store->seed('TrainingRecord', 'tr-c', $this->plannedBhv(self::EMPLOYEE_C));

		$recordA = $this->register('tr-a', 'gevolgd');
		$this->register('tr-b', 'gevolgd');
		$recordC = $this->register('tr-c', 'niet-gevolgd');

		self::assertSame('2026-10-01', $recordA['completedOn']);
		self::assertSame('2027-10-01', $recordA['validUntil']);
		self::assertSame('ADM-001', $recordA['administrationId']);
		self::assertSame('niet-gevolgd', $recordC['status']);
		self::assertArrayNotHasKey('completedOn', $recordC);

		foreach ([self::EMPLOYEE_A, self::EMPLOYEE_B] as $employee) {
			$held = $this->competences($employee);
			self::assertCount(1, $held);
			self::assertSame('bhv', $held[0]['competenceCode']);
			self::assertSame('2026-10-01', $held[0]['issuedOn']);
			self::assertSame('2027-10-01', $held[0]['validUntil']);
			$payload = $held[0];
			unset($payload['id']);
			self::assertSame([], RegisterSchemaValidator::errors('EmployeeCompetence', $payload), (string)json_encode($payload));
		}

		self::assertSame([], $this->competences(self::EMPLOYEE_C));

		$payload = $recordA;
		unset($payload['id']);
		self::assertSame([], RegisterSchemaValidator::errors('TrainingRecord', $payload), (string)json_encode($payload));
	}//end testTwoOfThreeAttendTheFirstAidCourse()

	/**
	 * A later validity extends the competence the employee already holds.
	 *
	 * @return void
	 */
	public function testALaterValidityExtendsTheCompetence(): void {
		$this->store->seed('EmployeeCompetence', 'comp-a', ['employeeId' => self::EMPLOYEE_A, 'competenceCode' => 'bhv', 'issuedOn' => '2025-10-01', 'validUntil' => '2026-10-15']);
		$this->store->seed('TrainingRecord', 'tr-a', $this->plannedBhv(self::EMPLOYEE_A));

		$this->register('tr-a', 'gevolgd');

		$held = $this->competences(self::EMPLOYEE_A);
		self::assertCount(1, $held, 'The existing competence is extended, not duplicated.');
		self::assertSame('comp-a', $held[0]['id']);
		self::assertSame('2027-10-01', $held[0]['validUntil']);
	}//end testALaterValidityExtendsTheCompetence()

	/**
	 * An earlier validity leaves the competence as it is.
	 *
	 * @return void
	 */
	public function testAnEarlierValidityNeverShortensTheCompetence(): void {
		$this->store->seed('EmployeeCompetence', 'comp-a', ['employeeId' => self::EMPLOYEE_A, 'competenceCode' => 'bhv', 'issuedOn' => '2026-01-01', 'validUntil' => '2028-01-01']);
		$this->store->seed('EmployeeCompetence', 'comp-open', ['employeeId' => self::EMPLOYEE_B, 'competenceCode' => 'bhv', 'issuedOn' => '2020-01-01', 'validUntil' => null]);
		$this->store->seed('TrainingRecord', 'tr-a', $this->plannedBhv(self::EMPLOYEE_A));
		$this->store->seed('TrainingRecord', 'tr-b', $this->plannedBhv(self::EMPLOYEE_B));

		$this->register('tr-a', 'gevolgd');
		$this->register('tr-b', 'gevolgd');

		self::assertSame('2028-01-01', $this->store->state->objects['EmployeeCompetence']['comp-a']['validUntil']);
		self::assertNull($this->store->state->objects['EmployeeCompetence']['comp-open']['validUntil'], 'A competence without an end is not given one.');
		self::assertCount(1, $this->competences(self::EMPLOYEE_A));
		self::assertSame(
			[],
			array_values(array_filter($this->store->state->saves, static fn (array $save): bool => $save['schema'] === 'EmployeeCompetence'))
		);
	}//end testAnEarlierValidityNeverShortensTheCompetence()

	/**
	 * A record created as attended, from HR, grants its competence at once,
	 * and a record of another schema is left alone.
	 *
	 * @return void
	 */
	public function testARecordCreatedAsAttendedGrantsItsCompetence(): void {
		$record = array_merge($this->plannedBhv(self::EMPLOYEE_A), ['status' => 'gevolgd', 'completedOn' => '2026-09-20', 'validUntil' => '2027-09-20']);
		$this->store->seed('TrainingRecord', 'tr-a', $record);

		$this->listener->handle(new ObjectCreatedEvent($this->entity('tr-a', $this->store->state->objects['TrainingRecord']['tr-a'])));
		$this->listener->handle(new ObjectCreatedEvent($this->entity('x', ['employeeId' => self::EMPLOYEE_B, 'status' => 'gevolgd', 'competenceCode' => 'bhv'], 'Expense')));

		$held = $this->competences(self::EMPLOYEE_A);
		self::assertCount(1, $held);
		self::assertSame('2026-09-20', $held[0]['issuedOn']);
		self::assertSame('2027-09-20', $held[0]['validUntil']);
		self::assertSame([], $this->competences(self::EMPLOYEE_B));
	}//end testARecordCreatedAsAttendedGrantsItsCompetence()

}//end class
