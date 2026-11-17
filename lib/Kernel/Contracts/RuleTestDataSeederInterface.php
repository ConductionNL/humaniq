<?php

/**
 * Contract of RuleTestDataSeeder
 *
 * The public surface of RuleTestDataSeeder that other components call, so they depend
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
 * Contract of RuleTestDataSeeder.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RuleTestDataSeederInterface {
	/**
	 * Seed/backfill the local test data to satisfy the enforced HR/labour rules.
	 *
	 * @return array<string, int> Counts: providerObjectsCreated, providerFieldsAdded, alreadyCompliant.
	 *
	 * The exclusion that used to sit here said "no spec target exists for this
	 * seeder", and it was true: the change was archived without its spec ever
	 * being promoted to openspec/specs/, so fifteen anchors across this app
	 * pointed at nothing. That is now fixed at the TARGET — the spec exists —
	 * so this is a real anchor rather than a reasoned skip.
	 *
	 * A skip whose reason has quietly stopped being true is worse than no skip:
	 * it reads as a considered decision and is an unnoticed hole.
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md#REQ-RULE-006
	 */
	public function seed(): array;
}//end interface
