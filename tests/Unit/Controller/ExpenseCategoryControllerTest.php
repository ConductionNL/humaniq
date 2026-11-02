<?php

/**
 * ExpenseCategoryController unit tests
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Controller
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

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\ExpenseCategoryController;
use OCA\Humaniq\Service\ExpenseCategoryPolicy;
use OCA\Humaniq\Settings\HumaniqAdmin;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Reading and saving the category switches.
 *
 * @covers \OCA\Humaniq\Controller\ExpenseCategoryController
 * @uses   \OCA\Humaniq\Service\ExpenseCategoryPolicy
 */
class ExpenseCategoryControllerTest extends TestCase {

	/**
	 * The stored app config values, by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * The controller over an in-memory app config.
	 *
	 * @return ExpenseCategoryController
	 */
	private function controller(): ExpenseCategoryController {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$key] = $value;
				return true;
			}
		);

		return new ExpenseCategoryController($this->createMock(IRequest::class), new ExpenseCategoryPolicy($appConfig));
	}//end controller()

	/**
	 * Saving the categories that are on is read back.
	 *
	 * @return void
	 */
	public function testAnAdminSavesAndAnyoneReads(): void {
		$saved = $this->controller()->update(['travel', 'accommodation', 'supplies', 'software', 'other']);
		self::assertSame(200, $saved->getStatus());

		$read = $this->controller()->index();
		self::assertSame(200, $read->getStatus());
		$byId = array_column($read->getData()['categories'], 'allowed', 'id');
		self::assertSame(['travel' => true, 'accommodation' => true, 'meals' => false, 'supplies' => true, 'software' => true, 'training' => false, 'other' => true], $byId);
		self::assertSame($read->getData(), $saved->getData());
	}//end testAnAdminSavesAndAnyoneReads()

	/**
	 * An unknown category, or a body that is not a list, is refused with 400
	 * and nothing is stored.
	 *
	 * @return void
	 */
	public function testAnUnknownCategoryIsRefused(): void {
		$unknown = $this->controller()->update(['travel', 'yachts']);
		self::assertSame(400, $unknown->getStatus());
		self::assertStringContainsString('yachts', $unknown->getData()['message']);

		$notAList = $this->controller()->update(['travel', ['meals']]);
		self::assertSame(400, $notAList->getStatus());
		self::assertSame([], $this->config);
	}//end testAnUnknownCategoryIsRefused()

	/**
	 * Reading is open to every signed-in user; saving is an admin setting.
	 *
	 * @return void
	 */
	public function testOnlyAnAdminMaySave(): void {
		$update = new \ReflectionMethod(ExpenseCategoryController::class, 'update');
		$attributes = $update->getAttributes(AuthorizedAdminSetting::class);
		self::assertCount(1, $attributes);
		self::assertSame([HumaniqAdmin::class], $attributes[0]->getArguments());
		self::assertSame([], $update->getAttributes(NoAdminRequired::class));

		$index = new \ReflectionMethod(ExpenseCategoryController::class, 'index');
		self::assertCount(1, $index->getAttributes(NoAdminRequired::class));
	}//end testOnlyAnAdminMaySave()

}//end class
