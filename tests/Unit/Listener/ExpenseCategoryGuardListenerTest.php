<?php

/**
 * ExpenseCategoryGuardListener unit tests
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
 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\ExpenseCategoryGuardListener;
use OCA\Humaniq\Service\ExpenseCategoryPolicy;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\OrgResolutionService;
use OCA\Humaniq\Service\SettingsService;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use OCA\Humaniq\Tests\Unit\Support\FakeObjectStore;
use OCA\Humaniq\Tests\Unit\Support\FakeSchemaMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Claims in a switched-off category are refused.
 *
 * @covers \OCA\Humaniq\Listener\ExpenseCategoryGuardListener
 * @uses   \OCA\Humaniq\Service\ExpenseCategoryPolicy
 * @uses   \OCA\Humaniq\Service\HoursRegisterGateway
 * @uses   \OCA\Humaniq\Service\InternalWriteMarker
 */
class ExpenseCategoryGuardListenerTest extends TestCase {

	/**
	 * The write marker the listener reads.
	 *
	 * @var InternalWriteMarker
	 */
	private InternalWriteMarker $marker;

	/**
	 * Set up the marker.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->marker = new InternalWriteMarker();
	}//end setUp()

	/**
	 * The listener with meals and software switched off.
	 *
	 * @return ExpenseCategoryGuardListener
	 */
	private function listener(): ExpenseCategoryGuardListener {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($key === 'expense_categories_disabled' ? '["meals","software"]' : $default)
		);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('humaniq');
		$gateway = new HoursRegisterGateway(
			container: new FakeContainer([
				'OCA\OpenRegister\Service\ObjectService' => new FakeObjectStore(),
				'OCA\OpenRegister\Db\SchemaMapper' => new FakeSchemaMapper(),
			]),
			settingsService: $settings,
			orgResolution: new OrgResolutionService()
		);

		return new ExpenseCategoryGuardListener(gateway: $gateway, policy: new ExpenseCategoryPolicy($appConfig), marker: $this->marker);
	}//end listener()

	/**
	 * An entity of a schema.
	 *
	 * @param string $schema The schema.
	 * @param array<string, mixed> $data The object.
	 *
	 * @return ObjectEntity
	 */
	private function entity(string $schema, array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('ex-1');
		$entity->setSchema($schema);
		$entity->setObject($data);
		return $entity;
	}//end entity()

	/**
	 * Whether an update from one stored claim to a saved one is refused.
	 *
	 * @param array<string, mixed> $stored The stored claim.
	 * @param array<string, mixed> $saved The saved claim.
	 *
	 * @return bool
	 */
	private function updateRefused(array $stored, array $saved): bool {
		$event = new ObjectUpdatingEvent($this->entity('Expense', $saved), $this->entity('Expense', $stored));
		$this->listener()->handle($event);

		return $event->isPropagationStopped();
	}//end updateRefused()

	/**
	 * A create in a switched-off category is refused with a message; one in
	 * a category that is on is not.
	 *
	 * @return void
	 */
	public function testACreateInASwitchedOffCategoryIsRefused(): void {
		$refused = new ObjectCreatingEvent($this->entity('Expense', ['category' => ' software ', 'status' => 'draft']));
		$this->listener()->handle($refused);
		self::assertTrue($refused->isPropagationStopped());
		self::assertSame(['message' => 'Declaraties in deze categorie zijn uitgeschakeld. Kies een andere categorie.'], $refused->getErrors());

		$allowed = new ObjectCreatingEvent($this->entity('Expense', ['category' => 'travel', 'status' => 'draft']));
		$this->listener()->handle($allowed);
		self::assertFalse($allowed->isPropagationStopped());
		self::assertSame([], $allowed->getErrors());

		$shouted = new ObjectCreatingEvent($this->entity('Expense', ['category' => 'MEALS', 'status' => 'draft']));
		$this->listener()->handle($shouted);
		self::assertTrue($shouted->isPropagationStopped());

		$unknown = new ObjectCreatingEvent($this->entity('Expense', ['category' => 'yachts', 'status' => 'draft']));
		$this->listener()->handle($unknown);
		self::assertTrue($unknown->isPropagationStopped());

		$none = new ObjectCreatingEvent($this->entity('Expense', ['category' => ' ', 'status' => 'draft']));
		$this->listener()->handle($none);
		self::assertFalse($none->isPropagationStopped());

		$approved = new ObjectCreatingEvent($this->entity('Expense', ['category' => 'meals', 'status' => 'approved']));
		$this->listener()->handle($approved);
		self::assertTrue($approved->isPropagationStopped());
	}//end testACreateInASwitchedOffCategoryIsRefused()

	/**
	 * Changing a claim's category to one that is off is refused.
	 *
	 * @return void
	 */
	public function testAChangeToASwitchedOffCategoryIsRefused(): void {
		$draft = ['category' => 'travel', 'status' => 'draft'];

		self::assertTrue($this->updateRefused($draft, array_merge($draft, ['category' => 'meals'])));
		self::assertFalse($this->updateRefused($draft, array_merge($draft, ['category' => 'training'])));
	}//end testAChangeToASwitchedOffCategoryIsRefused()

	/**
	 * A submit in a switched-off category is refused, a resubmit too; the
	 * approve, reject and reimburse of a submitted claim are not, and an edit
	 * that keeps the category is not.
	 *
	 * @return void
	 */
	public function testASubmitIsRefusedAndADecisionIsNot(): void {
		$meals = ['category' => 'meals'];

		self::assertTrue($this->updateRefused($meals + ['status' => 'draft'], $meals + ['status' => 'submitted']));
		self::assertTrue($this->updateRefused($meals + ['status' => 'rejected'], $meals + ['status' => ' submitted ']));
		self::assertFalse($this->updateRefused($meals + ['status' => 'submitted'], $meals + ['status' => 'approved']));
		self::assertFalse($this->updateRefused($meals + ['status' => 'approved'], $meals + ['status' => 'submitted']));
		self::assertTrue($this->updateRefused($meals + ['status' => ''], $meals + ['status' => 'submitted']));
		self::assertFalse($this->updateRefused($meals + ['status' => 'submitted'], $meals + ['status' => 'rejected']));
		self::assertFalse($this->updateRefused($meals + ['status' => 'approved'], $meals + ['status' => 'reimbursed']));
		self::assertFalse($this->updateRefused($meals + ['status' => 'submitted'], $meals + ['status' => 'submitted', 'title' => 'x']));
		self::assertFalse($this->updateRefused($meals + ['status' => 'draft'], $meals + ['status' => 'draft', 'title' => 'x']));
	}//end testASubmitIsRefusedAndADecisionIsNot()

	/**
	 * humaniq's own writes and other schemas pass.
	 *
	 * @return void
	 */
	public function testOwnWritesAndOtherSchemasPass(): void {
		$internal = new ObjectCreatingEvent($this->entity('Expense', ['category' => 'meals', 'status' => 'draft']));
		$this->marker->runInternal(fn () => $this->listener()->handle($internal));
		self::assertFalse($internal->isPropagationStopped());

		$other = new ObjectCreatingEvent($this->entity('Asset', ['category' => 'meals']));
		$this->listener()->handle($other);
		self::assertFalse($other->isPropagationStopped());
	}//end testOwnWritesAndOtherSchemasPass()

	/**
	 * A post-save event is not judged.
	 *
	 * @return void
	 */
	public function testOtherEventsPass(): void {
		$this->listener()->handle(new ObjectCreatedEvent($this->entity('Expense', ['category' => 'meals', 'status' => 'draft'])));
		$this->addToAssertionCount(1);
	}//end testOtherEventsPass()

}//end class
