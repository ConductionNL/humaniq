<?php

/**
 * Unit tests for the employee change request flow.
 *
 * ChangeRequestListener, ChangeRequestService, ChangeApprovalRules and
 * EmployeeGuardedFieldListener together: a bank account change waits for its
 * approver and is applied once on approval, an address change with no
 * approver applies at once from the employee's own request, a stale request
 * is not applied, a rejection needs a reason, a request cannot smuggle in a
 * field its kind does not cover, and a guarded field cannot be saved around
 * the request (fail closed when the rules cannot be read). Driven through the
 * real HoursRegisterGateway over the shared FakeObjectStore, with
 * OpenRegister's event classes, and every write validated against its schema
 * in the register fragments.
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
 * @spec openspec/specs/employee-change-approval/spec.md#REQ-ECR-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use DateTime;
use OCA\Humaniq\Listener\ChangeRequestListener;
use OCA\Humaniq\Listener\EmployeeGuardedFieldListener;
use OCA\Humaniq\Service\ChangeApprovalRules;
use OCA\Humaniq\Service\ChangeRequestApplier;
use OCA\Humaniq\Service\ChangeRequestService;
use OCA\Humaniq\Service\FieldReadAccess;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\Humaniq\Tests\Unit\Support\RegisterSchemaValidator;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Change requests, from the request to the employee record.
 */
class EmployeeChangeRequestFlowTest extends TestCase {

	private const EMPLOYEE = '0127394a-be27-48b4-a592-b6a41774b221';

	/**
	 * The fake register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * The signed-in user.
	 *
	 * @var string
	 */
	private string $uid = 'hr.user';

	/**
	 * Marks humaniq's own writes.
	 *
	 * @var InternalWriteMarker
	 */
	private InternalWriteMarker $marker;

	/**
	 * The request listener.
	 *
	 * @var ChangeRequestListener
	 */
	private ChangeRequestListener $requests;

	/**
	 * The employee field guard.
	 *
	 * @var EmployeeGuardedFieldListener
	 */
	private EmployeeGuardedFieldListener $guard;

	/**
	 * Seed an employee, the five default rules and wire the real services.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->seedWorld($this->store);
		$this->wire($this->store);
	}//end setUp()

	/**
	 * Seed the employee and the rules.
	 *
	 * @param FakeObjectStore $store The store.
	 *
	 * @return void
	 */
	private function seedWorld(FakeObjectStore $store): void {
		$store->seed(
			'Employee',
			self::EMPLOYEE,
			[
				'firstName' => 'Anna', 'lastName' => 'Visser', 'nextcloudUserId' => 'a.visser', 'administrationId' => 'ADM-001',
				'iban' => 'NL91ABNA0417164300', 'grossMonthlySalary' => 3800.0, 'straat' => 'Oudegracht', 'huisnummer' => '12',
				'postcode' => '3511 AB', 'woonplaats' => 'Utrecht', 'land' => 'NL',
			]
		);
		$fragment = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/register.d/hr-change-requests.json'), true);
		foreach ($fragment['components']['objects'] as $object) {
			if ($object['@self']['schema'] === 'ChangeApprovalRule') {
				$rule = $object;
				unset($rule['@self']);
				$store->seed('ChangeApprovalRule', $object['@self']['slug'], $rule);
			}
		}
	}//end seedWorld()

	/**
	 * Wire the listeners over a store.
	 *
	 * @param FakeObjectStore $store The store.
	 *
	 * @return void
	 */
	private function wire(FakeObjectStore $store): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => $store,
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			function (): IUser {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($this->uid);
				return $user;
			}
		);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-09-29T10:00:00+00:00'));
		$this->marker = new InternalWriteMarker();
		$rules = new ChangeApprovalRules($gateway);
		$service = new ChangeRequestService(gateway: $gateway, rules: $rules, userSession: $session, time: $time);
		$applier = new ChangeRequestApplier(gateway: $gateway, marker: $this->marker, time: $time);
		$this->requests = new ChangeRequestListener(gateway: $gateway, service: $service, applier: $applier, logger: new NullLogger());
		$this->guard = new EmployeeGuardedFieldListener(gateway: $gateway, rules: $rules, marker: $this->marker, logger: new NullLogger(), access: new FieldReadAccess(new FakeContainer([]), new NullLogger()));
	}//end wire()

	/**
	 * An entity.
	 *
	 * @param string               $uuid   Id.
	 * @param array<string, mixed> $data   Payload.
	 * @param string               $schema Schema.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $uuid, array $data, string $schema='EmployeeChangeRequest'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * Create a request the way OpenRegister does: pre-save, save, post-save.
	 *
	 * @param string               $uuid    Id.
	 * @param array<string, mixed> $request Payload.
	 *
	 * @return ObjectCreatingEvent The pre-save event.
	 */
	private function create(string $uuid, array $request): ObjectCreatingEvent {
		$creating = new ObjectCreatingEvent($this->entity($uuid, $request));
		$this->requests->handle($creating);
		if ($creating->isPropagationStopped() === true) {
			return $creating;
		}

		$this->store->seed('EmployeeChangeRequest', $uuid, array_merge($request, $creating->getModifiedData()));
		$this->requests->handle(new ObjectCreatedEvent($this->entity($uuid, $this->store->state->objects['EmployeeChangeRequest'][$uuid])));

		return $creating;
	}//end create()

	/**
	 * Move a request the way a transition does.
	 *
	 * @param string               $uuid   Id.
	 * @param array<string, mixed> $change The changed fields.
	 *
	 * @return ObjectUpdatingEvent The pre-save event.
	 */
	private function update(string $uuid, array $change): ObjectUpdatingEvent {
		$old = $this->store->state->objects['EmployeeChangeRequest'][$uuid];
		$new = array_merge($old, $change);
		$updating = new ObjectUpdatingEvent($this->entity($uuid, $new), $this->entity($uuid, $old));
		$this->requests->handle($updating);
		if ($updating->isPropagationStopped() === true) {
			return $updating;
		}

		$this->store->seed('EmployeeChangeRequest', $uuid, array_merge($new, $updating->getModifiedData()));
		$this->requests->handle(new ObjectUpdatedEvent($this->entity($uuid, $this->store->state->objects['EmployeeChangeRequest'][$uuid]), $this->entity($uuid, $old)));

		return $updating;
	}//end update()

	/**
	 * The stored employee.
	 *
	 * @return array<string, mixed>
	 */
	private function employee(): array {
		return $this->store->state->objects['Employee'][self::EMPLOYEE];
	}//end employee()

	/**
	 * The stored request, validated against its schema.
	 *
	 * @param string $uuid Id.
	 *
	 * @return array<string, mixed>
	 */
	private function request(string $uuid): array {
		$request = $this->store->state->objects['EmployeeChangeRequest'][$uuid];
		$payload = $request;
		unset($payload['id']);
		self::assertSame([], RegisterSchemaValidator::errors('EmployeeChangeRequest', $payload), (string)json_encode($payload));
		return $request;
	}//end request()

	/**
	 * A bank account change waits for HR, then is applied once on approval.
	 *
	 * @return void
	 */
	public function testABankAccountChangeWaitsForHrAndIsAppliedOnce(): void {
		$this->uid = 'a.visser';
		$this->create('req-bank', ['changeKind' => 'bankrekening', 'iban' => 'NL02RABO0123456789']);

		$waiting = $this->request('req-bank');
		self::assertSame('ingediend', $waiting['status']);
		self::assertSame(self::EMPLOYEE, $waiting['employeeId']);
		self::assertSame('hr', $waiting['approverRole']);
		self::assertSame(['iban' => 'NL02RABO0123456789'], $waiting['changes']);
		self::assertSame(['iban' => 'NL91ABNA0417164300'], $waiting['previousValues']);
		self::assertSame('a.visser', $waiting['userId']);
		self::assertSame('a.visser', $waiting['requestedBy']);
		self::assertSame('ADM-001', $waiting['administrationId']);
		self::assertSame('NL91ABNA0417164300', $this->employee()['iban'], 'The IBAN waits for the approver.');

		$this->uid = 'hr.user';
		$this->update('req-bank', ['status' => 'goedgekeurd']);

		$approved = $this->request('req-bank');
		self::assertSame('NL02RABO0123456789', $this->employee()['iban']);
		self::assertSame('hr.user', $approved['decidedBy']);
		self::assertSame('2026-09-29T10:00:00+00:00', $approved['decidedAt']);
		self::assertSame('2026-09-29T10:00:00+00:00', $approved['appliedAt']);

		$saves = count($this->store->state->saves);
		$this->requests->handle(new ObjectUpdatedEvent($this->entity('req-bank', $approved), $this->entity('req-bank', $approved)));
		self::assertCount($saves, $this->store->state->saves, 'An applied request is not applied again.');
	}//end testABankAccountChangeWaitsForHrAndIsAppliedOnce()

	/**
	 * An address change by the employee applies at once, with the old address on record.
	 *
	 * @return void
	 */
	public function testAnAddressChangeAppliesAtOnce(): void {
		$this->uid = 'a.visser';
		$this->create('req-adres', ['changeKind' => 'adres', 'straat' => 'Stationsplein', 'huisnummer' => '1', 'postcode' => '1234 AB', 'woonplaats' => 'Amersfoort']);

		$request = $this->request('req-adres');
		self::assertSame('goedgekeurd', $request['status']);
		self::assertSame('none', $request['approverRole']);
		self::assertSame(['straat' => 'Oudegracht', 'huisnummer' => '12', 'postcode' => '3511 AB', 'woonplaats' => 'Utrecht'], $request['previousValues']);
		self::assertSame('Stationsplein', $this->employee()['straat']);
		self::assertSame('Amersfoort', $this->employee()['woonplaats']);
		self::assertNotNull($request['appliedAt']);
	}//end testAnAddressChangeAppliesAtOnce()

	/**
	 * A request whose values changed since it was made is not applied.
	 *
	 * @return void
	 */
	public function testAStaleRequestIsNotApplied(): void {
		$this->uid = 'a.visser';
		$this->create('req-bank', ['changeKind' => 'bankrekening', 'iban' => 'NL02RABO0123456789']);
		$this->store->seed('Employee', self::EMPLOYEE, array_merge($this->employee(), ['iban' => 'NL44INGB0001234567']));

		$this->uid = 'hr.user';
		$this->update('req-bank', ['status' => 'goedgekeurd']);

		self::assertSame('NL44INGB0001234567', $this->employee()['iban']);
		$request = $this->request('req-bank');
		self::assertNull($request['appliedAt'] ?? null);
		self::assertStringContainsString('iban', (string)$request['applyError']);
	}//end testAStaleRequestIsNotApplied()

	/**
	 * A rejection needs a reason and writes nothing to the employee.
	 *
	 * @return void
	 */
	public function testARejectionNeedsAReasonAndWritesNothing(): void {
		$this->uid = 'a.visser';
		$this->create('req-bank', ['changeKind' => 'bankrekening', 'iban' => 'NL02RABO0123456789']);

		$this->uid = 'hr.user';
		self::assertTrue($this->update('req-bank', ['status' => 'afgewezen'])->isPropagationStopped());
		$this->update('req-bank', ['status' => 'afgewezen', 'rejectionReason' => 'Rekening staat niet op naam van de medewerker.']);

		self::assertSame('afgewezen', $this->request('req-bank')['status']);
		self::assertSame('NL91ABNA0417164300', $this->employee()['iban']);
	}//end testARejectionNeedsAReasonAndWritesNothing()

	/**
	 * A request cannot carry a field its kind does not cover, or have no employee.
	 *
	 * @return void
	 */
	public function testARequestOutsideItsKindIsRefused(): void {
		$this->uid = 'a.visser';
		self::assertTrue($this->create('req-sneaky', ['changeKind' => 'adres', 'straat' => 'Laan 1', 'changes' => ['grossMonthlySalary' => 9000]])->isPropagationStopped());

		$this->uid = 'nobody';
		self::assertTrue($this->create('req-orphan', ['changeKind' => 'adres', 'straat' => 'Laan 1'])->isPropagationStopped());
		self::assertArrayNotHasKey('EmployeeChangeRequest', $this->store->state->objects);
	}//end testARequestOutsideItsKindIsRefused()

	/**
	 * Editing the salary directly is refused with the kind named; an unguarded
	 * field and humaniq's own write are saved.
	 *
	 * @return void
	 */
	public function testAGuardedFieldCannotBeSavedAroundTheRequest(): void {
		$old = $this->employee();

		$salary = new ObjectUpdatingEvent($this->entity(self::EMPLOYEE, array_merge($old, ['grossMonthlySalary' => 9000.0]), 'Employee'), $this->entity(self::EMPLOYEE, $old, 'Employee'));
		$this->guard->handle($salary);
		self::assertTrue($salary->isPropagationStopped());
		self::assertStringContainsString('salaris', strtolower((string)json_encode($salary->getErrors())));

		$sameSalary = new ObjectUpdatingEvent($this->entity(self::EMPLOYEE, array_merge($old, ['grossMonthlySalary' => 3800, 'endDate' => '']), 'Employee'), $this->entity(self::EMPLOYEE, $old, 'Employee'));
		$this->guard->handle($sameSalary);
		self::assertFalse($sameSalary->isPropagationStopped(), 'The same salary sent back as an integer, and an empty end date, are not a change.');

		$a1 = new ObjectUpdatingEvent($this->entity(self::EMPLOYEE, array_merge($old, ['a1CertificateNumber' => 'A1-2026-001']), 'Employee'), $this->entity(self::EMPLOYEE, $old, 'Employee'));
		$this->guard->handle($a1);
		self::assertFalse($a1->isPropagationStopped());

		$address = new ObjectUpdatingEvent($this->entity(self::EMPLOYEE, array_merge($old, ['straat' => 'Laan 1']), 'Employee'), $this->entity(self::EMPLOYEE, $old, 'Employee'));
		$this->guard->handle($address);
		self::assertFalse($address->isPropagationStopped(), 'A kind without an approver is not guarded.');

		$internal = new ObjectUpdatingEvent($this->entity(self::EMPLOYEE, array_merge($old, ['iban' => 'NL02RABO0123456789']), 'Employee'), $this->entity(self::EMPLOYEE, $old, 'Employee'));
		$this->marker->runInternal(fn () => $this->guard->handle($internal));
		self::assertFalse($internal->isPropagationStopped());
	}//end testAGuardedFieldCannotBeSavedAroundTheRequest()

	/**
	 * REQ-RFA-002: a bank account and salary the writer was never shown
	 * arrive empty on a save; that is no change to guard, and the field
	 * access listener carries the stored values forward.
	 *
	 * @return void
	 */
	public function testAFieldTheWriterWasNotShownIsNoChange(): void {
		$old = $this->employee();
		// OpenRegister fills every property the save omits with null.
		$new = array_merge($old, ['a1CertificateNumber' => 'A1-2026-001', 'iban' => null, 'grossMonthlySalary' => null]);

		$event = new ObjectUpdatingEvent($this->entity(self::EMPLOYEE, $new, 'Employee'), $this->entity(self::EMPLOYEE, $old, 'Employee'));
		$this->guard->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAFieldTheWriterWasNotShownIsNoChange()

	/**
	 * When the rules cannot be read, a change to an employee is refused.
	 *
	 * @return void
	 */
	public function testUnreadableRulesRefuseTheWrite(): void {
		$broken = new class extends FakeObjectStore {
			/**
			 * @var string
			 */
			private string $asked = '';

			/**
			 * @param mixed $schema Schema.
			 *
			 * @return FakeObjectStore
			 */
			public function setSchema(mixed $schema): FakeObjectStore {
				$this->asked = (string)$schema;
				return parent::setSchema($schema);
			}//end setSchema()

			/**
			 * @param array<string, mixed> $config        Config.
			 * @param bool                 $_rbac         Rbac.
			 * @param bool                 $_multitenancy Tenancy.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config=[], bool $_rbac=true, bool $_multitenancy=true): array {
				if ($this->asked === 'ChangeApprovalRule') {
					throw new \RuntimeException('database gone');
				}

				return parent::findAll($config, $_rbac, $_multitenancy);
			}//end findAll()
		};
		$this->seedWorld($broken);
		$this->wire($broken);
		$old = $broken->state->objects['Employee'][self::EMPLOYEE];

		$event = new ObjectUpdatingEvent($this->entity(self::EMPLOYEE, array_merge($old, ['a1CertificateNumber' => 'A1']), 'Employee'), $this->entity(self::EMPLOYEE, $old, 'Employee'));
		$this->guard->handle($event);

		self::assertTrue($event->isPropagationStopped());
	}//end testUnreadableRulesRefuseTheWrite()

}//end class
