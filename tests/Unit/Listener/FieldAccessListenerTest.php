<?php

/**
 * Tests for FieldAccessListener and FieldReadAccess.
 *
 * The rules themselves are OpenRegister's: these tests stand in for its
 * PropertyRbacHandler with a double that hides a fixed list of fields, and
 * check what humaniq does with that answer. That OpenRegister's handler
 * answers the declared rules as intended was checked against the real class
 * (see the change's design, "Verification against OpenRegister").
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
 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\FieldAccessListener;
use OCA\Humaniq\Service\FieldReadAccess;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Stamps the subject uids and keeps protected values a save would wipe.
 */
class FieldAccessListenerTest extends TestCase {

	private const EMPLOYEE = '0127394a-be27-48b4-a592-b6a41774b221';

	private const REVIEWER = '9a1f0c2e-5b7d-4e8a-9c3b-1d2e3f4a5b6c';

	/**
	 * The fake register.
	 *
	 * @var FakeObjectStore
	 */
	private FakeObjectStore $store;

	/**
	 * Seed an employee and a reviewer.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new FakeObjectStore();
		$this->store->seed('Employee', self::EMPLOYEE, ['firstName' => 'Anna', 'lastName' => 'Visser', 'nextcloudUserId' => 'a.visser']);
		$this->store->seed('Employee', self::REVIEWER, ['firstName' => 'Kees', 'lastName' => 'de Boer', 'nextcloudUserId' => 'k.deboer']);
	}//end setUp()

	/**
	 * The listener over a register where the caller cannot read $hidden, or
	 * where OpenRegister cannot be asked when $hidden is null.
	 *
	 * @param list<string>|null $hidden The governed fields the caller cannot read.
	 *
	 * @return FieldAccessListener
	 */
	private function listener(?array $hidden): FieldAccessListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$schemas = new class {

			/**
			 * A schema whose slug is its id, with the governed fields humaniq declares.
			 *
			 * @param mixed $id The schema id.
			 *
			 * @return object
			 */
			public function find(mixed $id): object {
				return new class((string)$id) {

					/**
					 * @param string $slug The slug.
					 */
					public function __construct(private string $slug) {
					}

					/**
					 * @return string
					 */
					public function getSlug(): string {
						return $this->slug;
					}

					/**
					 * @return array<string, array<string, mixed>>
					 */
					public function getPropertiesWithAuthorization(): array {
						return array_fill_keys(['bsn', 'iban', 'grossMonthlySalary', 'dateOfBirth', 'hourlyWage', 'afspraken', 'rating'], ['read' => ['humaniq-hr']]);
					}
				};
			}
		};
		$entries = [
			'OCA\OpenRegister\Service\ObjectService' => $this->store,
			'OCA\OpenRegister\Db\SchemaMapper' => $schemas,
		];
		if ($hidden !== null) {
			$entries['OCA\OpenRegister\Service\PropertyRbacHandler'] = new class($hidden) {

				/**
				 * @param list<string> $hidden The fields the caller cannot read.
				 */
				public function __construct(private array $hidden) {
				}

				/**
				 * @param object               $schema   The schema.
				 * @param string               $property The property.
				 * @param array<string, mixed> $object   The object.
				 *
				 * @return bool
				 */
				public function canReadProperty(object $schema, string $property, array $object): bool {
					return in_array($property, $this->hidden, true) === false;
				}
			};
		}

		$container = new FakeContainer($entries);
		$gateway = new HoursRegisterGateway(container: $container, settingsService: $settings, orgResolution: new OrgResolutionService());
		return new FieldAccessListener(gateway: $gateway, access: new FieldReadAccess($container, new NullLogger()));
	}//end listener()

	/**
	 * An entity.
	 *
	 * @param string               $schema The schema.
	 * @param array<string, mixed> $data   The payload.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('11111111-2222-4333-8444-555555555555');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * REQ-RFA-002: a manager who opens an employee, changes the first name
	 * and saves it does not erase the BSN, bank account and salary the page
	 * never showed them.
	 *
	 * @return void
	 */
	public function testAManagerSavingAnEmployeeKeepsWhatTheyWereNotShown(): void {
		$old = ['firstName' => 'Anna', 'bsn' => '123456782', 'iban' => 'NL91ABNA0417164300', 'grossMonthlySalary' => 3800.0, 'nextcloudUserId' => 'a.visser'];
		$new = ['firstName' => 'Annie', 'bsn' => null, 'iban' => null, 'grossMonthlySalary' => null, 'nextcloudUserId' => 'a.visser'];
		$event = new ObjectUpdatingEvent($this->entity('Employee', $new), $this->entity('Employee', $old));

		$this->listener(['bsn', 'iban', 'grossMonthlySalary', 'dateOfBirth'])->handle($event);

		self::assertSame(['bsn' => '123456782', 'iban' => 'NL91ABNA0417164300', 'grossMonthlySalary' => 3800.0], $event->getModifiedData());
	}//end testAManagerSavingAnEmployeeKeepsWhatTheyWereNotShown()

	/**
	 * HR, who can read the bank account, may clear it; and a field that is
	 * not governed is cleared by anyone who may write the object.
	 *
	 * @return void
	 */
	public function testAReaderMayClearAField(): void {
		$old = ['firstName' => 'Anna', 'iban' => 'NL91ABNA0417164300', 'endDate' => '2026-12-31'];
		$new = ['firstName' => 'Anna', 'iban' => null, 'endDate' => null];
		$event = new ObjectUpdatingEvent($this->entity('Employee', $new), $this->entity('Employee', $old));

		$this->listener([])->handle($event);

		self::assertSame([], $event->getModifiedData());
	}//end testAReaderMayClearAField()

	/**
	 * When OpenRegister cannot be asked, every emptied value is kept.
	 *
	 * @return void
	 */
	public function testWhenOpenRegisterCannotBeAskedEveryEmptiedValueIsKept(): void {
		$old = ['firstName' => 'Anna', 'iban' => 'NL91ABNA0417164300'];
		$new = ['firstName' => 'Anna'];
		$event = new ObjectUpdatingEvent($this->entity('Employee', $new), $this->entity('Employee', $old));

		$this->listener(null)->handle($event);

		self::assertSame(['iban' => 'NL91ABNA0417164300'], $event->getModifiedData());
	}//end testWhenOpenRegisterCannotBeAskedEveryEmptiedValueIsKept()

	/**
	 * REQ-RFA-002: a new contract carries the employee's account, so the
	 * employee can read their own hourly wage.
	 *
	 * @return void
	 */
	public function testANewContractIsStampedWithTheEmployeesAccount(): void {
		$event = new ObjectCreatingEvent($this->entity('EmploymentContract', ['employeeId' => self::EMPLOYEE, 'hourlyWage' => 24.5]));

		$this->listener([])->handle($event);

		self::assertSame(['userId' => 'a.visser'], $event->getModifiedData());
	}//end testANewContractIsStampedWithTheEmployeesAccount()

	/**
	 * REQ-RFA-003: a review carries its reviewer's account, follows a change
	 * of reviewer, and loses it when the reviewer is removed.
	 *
	 * @return void
	 */
	public function testAReviewCarriesItsReviewersAccount(): void {
		$old = ['employeeId' => self::EMPLOYEE, 'userId' => 'a.visser', 'reviewerId' => null, 'status' => 'concept'];
		$new = ['employeeId' => self::EMPLOYEE, 'userId' => 'a.visser', 'reviewerId' => self::REVIEWER, 'status' => 'concept'];
		$event = new ObjectUpdatingEvent($this->entity('PerformanceReview', $new), $this->entity('PerformanceReview', $old));
		$this->listener([])->handle($event);
		self::assertSame(['reviewerUserId' => 'k.deboer'], $event->getModifiedData());

		$removed = new ObjectUpdatingEvent($this->entity('PerformanceReview', array_merge($new, ['reviewerId' => null])), $this->entity('PerformanceReview', $new));
		$this->listener([])->handle($removed);
		self::assertSame(['reviewerUserId' => null], $removed->getModifiedData());
	}//end testAReviewCarriesItsReviewersAccount()

	/**
	 * REQ-RFA-003: HR saving a review's status does not erase the agreements
	 * and rating it cannot read, and the listener keeps what another
	 * listener already set.
	 *
	 * @return void
	 */
	public function testHrSavingAReviewKeepsItsContent(): void {
		$old = ['employeeId' => self::EMPLOYEE, 'reviewerId' => self::REVIEWER, 'reviewerUserId' => 'k.deboer', 'status' => 'besproken', 'afspraken' => 'Cursus plannen', 'rating' => 'goed'];
		$new = ['employeeId' => self::EMPLOYEE, 'reviewerId' => self::REVIEWER, 'reviewerUserId' => 'k.deboer', 'status' => 'vastgesteld', 'afspraken' => null, 'rating' => null];
		$event = new ObjectUpdatingEvent($this->entity('PerformanceReview', $new), $this->entity('PerformanceReview', $old));
		$event->setModifiedData(['vastgesteldDoor' => 'hr.demo']);

		$this->listener(['afspraken', 'rating'])->handle($event);

		self::assertSame(['vastgesteldDoor' => 'hr.demo', 'afspraken' => 'Cursus plannen', 'rating' => 'goed', 'reviewerUserId' => 'k.deboer'], $event->getModifiedData());
	}//end testHrSavingAReviewKeepsItsContent()

}//end class
