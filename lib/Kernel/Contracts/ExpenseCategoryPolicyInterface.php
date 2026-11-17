<?php

/**
 * Contract of ExpenseCategoryPolicy
 *
 * The public surface of ExpenseCategoryPolicy that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
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
 * @spec exclude Structural contract, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Kernel\Contracts;

/**
 * Contract of ExpenseCategoryPolicy.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExpenseCategoryPolicyInterface {
	/**
	 * Every category and whether it is on.
	 *
	 * @return list<array{id: string, allowed: bool}>
	 *
	 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
	 */
	public function categories(): array;

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
	public function isAllowed(string $category): bool;

	/**
	 * Save which categories are on; every other category is switched off.
	 * At least one must stay on.
	 *
	 * @param list<string> $allowed The categories that are on.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When a category is unknown, or none is on.
	 *
	 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
	 */
	public function saveAllowed(array $allowed): void;
}//end interface
