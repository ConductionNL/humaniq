<?php

/**
 * Humaniq ExpenseCategoryPolicy
 *
 * Which expense categories an employer lets employees claim. Every category
 * is on until an admin switches it off. The app config key
 * `expense_categories_disabled` holds the categories that are off, as a
 * JSON list, so a category added to the schema later is on by default.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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

namespace OCA\Humaniq\Service;

use InvalidArgumentException;
use OCA\Humaniq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Reads and saves the expense category switches.
 *
 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
 */
class ExpenseCategoryPolicy {

	/**
	 * The expense categories, in the order the schema lists them.
	 *
	 * @var list<string>
	 */
	public const CATEGORIES = ['travel', 'accommodation', 'meals', 'supplies', 'software', 'training', 'other'];

	/**
	 * App config key holding the categories that are off.
	 *
	 * @var string
	 */
	public const DISABLED_KEY = 'expense_categories_disabled';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The app config.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * Every category and whether it is on.
	 *
	 * @return list<array{id: string, allowed: bool}>
	 *
	 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
	 */
	public function categories(): array {
		$disabled = $this->disabled();

		return array_map(
			static fn (string $id): array => ['id' => $id, 'allowed' => in_array($id, $disabled, true) === false],
			self::CATEGORIES
		);
	}//end categories()

	/**
	 * Whether a claim in this category may be made: one of the seven names,
	 * compared without case or surrounding spaces, and switched on.
	 *
	 * @param string $category The claim's category.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
	 */
	public function isAllowed(string $category): bool {
		$name = self::normalise(name: $category);

		return in_array($name, self::CATEGORIES, true) === true && in_array($name, $this->disabled(), true) === false;
	}//end isAllowed()

	/**
	 * Save which categories are on; every other category is switched off.
	 * At least one must stay on.
	 *
	 * @param list<string> $allowed The categories that are on.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a category is unknown, or none is on.
	 *
	 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
	 */
	public function saveAllowed(array $allowed): void {
		$names = array_map(static fn (string $name): string => self::normalise(name: $name), $allowed);
		$unknown = array_diff($names, self::CATEGORIES);
		if ($unknown !== []) {
			throw new InvalidArgumentException('Unknown expense category: ' . implode(', ', $unknown) . '.');
		}

		if ($names === []) {
			throw new InvalidArgumentException('Keep at least one expense category on.');
		}

		$disabled = array_values(array_diff(self::CATEGORIES, $names));
		$this->appConfig->setValueString(Application::APP_ID, self::DISABLED_KEY, (string)json_encode($disabled));
	}//end saveAllowed()

	/**
	 * The categories that are off; none when the stored value is unreadable.
	 *
	 * @return list<string>
	 */
	private function disabled(): array {
		$decoded = json_decode($this->appConfig->getValueString(Application::APP_ID, self::DISABLED_KEY, '[]'), true);
		if (is_array($decoded) === false) {
			return [];
		}

		return array_map(static fn (string $name): string => self::normalise(name: $name), array_values(array_filter($decoded, 'is_string')));
	}//end disabled()

	/**
	 * A category name without case or surrounding spaces.
	 *
	 * @param string $name The name.
	 *
	 * @return string
	 */
	private static function normalise(string $name): string {
		return strtolower(trim($name));
	}//end normalise()

}//end class
