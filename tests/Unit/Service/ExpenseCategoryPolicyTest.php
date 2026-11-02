<?php

/**
 * ExpenseCategoryPolicy unit tests
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
 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Humaniq\Service\ExpenseCategoryPolicy;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Which expense categories are on.
 *
 * @covers \OCA\Humaniq\Service\ExpenseCategoryPolicy
 */
class ExpenseCategoryPolicyTest extends TestCase {

	/**
	 * The stored app config values, by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * A policy over an in-memory app config.
	 *
	 * @return ExpenseCategoryPolicy
	 */
	private function policy(): ExpenseCategoryPolicy {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($app === 'humaniq' ? ($this->config[$key] ?? $default) : $default)
		);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->config[$app . ':' . $key] = $value;
				if ($app === 'humaniq') {
					$this->config[$key] = $value;
				}

				return true;
			}
		);

		return new ExpenseCategoryPolicy($appConfig);
	}//end policy()

	/**
	 * Every category is on until an admin switches it off.
	 *
	 * @return void
	 */
	public function testEveryCategoryIsOnByDefault(): void {
		$policy = $this->policy();

		self::assertSame(
			[
				['id' => 'travel', 'allowed' => true],
				['id' => 'accommodation', 'allowed' => true],
				['id' => 'meals', 'allowed' => true],
				['id' => 'supplies', 'allowed' => true],
				['id' => 'software', 'allowed' => true],
				['id' => 'training', 'allowed' => true],
				['id' => 'other', 'allowed' => true],
			],
			$policy->categories()
		);
		self::assertTrue($policy->isAllowed('software'));
	}//end testEveryCategoryIsOnByDefault()

	/**
	 * Saving the categories that are on stores the ones that are off, and
	 * reading follows it.
	 *
	 * @return void
	 */
	public function testSavingStoresTheCategoriesThatAreOff(): void {
		$policy = $this->policy();
		$policy->saveAllowed(['travel', 'accommodation', 'supplies', 'software', 'other', 'travel']);

		self::assertSame('["meals","training"]', $this->config['humaniq:expense_categories_disabled']);
		self::assertFalse($policy->isAllowed('meals'));
		self::assertFalse($policy->isAllowed(' training '));
		self::assertTrue($policy->isAllowed('travel'));
		self::assertSame(['id' => 'meals', 'allowed' => false], $policy->categories()[2]);
	}//end testSavingStoresTheCategoriesThatAreOff()

	/**
	 * An unknown category is refused and nothing is stored.
	 *
	 * @return void
	 */
	public function testAnUnknownCategoryIsRefused(): void {
		$policy = $this->policy();

		try {
			$policy->saveAllowed(['travel', 'yachts']);
			self::fail('An unknown category was accepted.');
		} catch (InvalidArgumentException $e) {
			self::assertStringContainsString('yachts', $e->getMessage());
		}

		self::assertSame([], $this->config);
	}//end testAnUnknownCategoryIsRefused()

	/**
	 * A claim without a category, an unknown category and an unreadable
	 * stored value are not refused by the setting.
	 *
	 * @return void
	 */
	public function testOnlyACategorySwitchedOffIsRefused(): void {
		$this->config['expense_categories_disabled'] = '["meals"]';
		$policy = $this->policy();
		self::assertTrue($policy->isAllowed(''));
		self::assertTrue($policy->isAllowed('yachts'));
		self::assertFalse($policy->isAllowed('meals'));

		$this->config['expense_categories_disabled'] = 'not json';
		self::assertTrue($this->policy()->isAllowed('meals'));

		$this->config['expense_categories_disabled'] = '{"a":"meals"}';
		self::assertFalse($this->policy()->isAllowed('meals'));
	}//end testOnlyACategorySwitchedOffIsRefused()

}//end class
