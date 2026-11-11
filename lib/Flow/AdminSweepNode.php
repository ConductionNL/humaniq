<?php

/**
 * Humaniq AdminSweepNode
 *
 * What the scheduled, administrator-only humaniq flow steps share: they run
 * over every due record, take no configuration to validate, and are offered
 * in the admin scope only.
 *
 * @category Flow
 * @package  OCA\Humaniq\Flow
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
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Flow;

use OCP\WorkflowEngine\IManager;

/**
 * A scheduled step over every due record, for administrators only. The
 * steps themselves implement OpenRegister's flow node interface, which only
 * exists once OpenRegister is installed.
 *
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-002
 */
abstract class AdminSweepNode {

	/**
	 * Available to administrators only: it acts on every case.
	 *
	 * @param int $scope The scope.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-002
	 */
	public function isAvailableForScope(int $scope): bool {
		return $scope === IManager::SCOPE_ADMIN;
	}//end isAvailableForScope()

	/**
	 * Nothing to validate.
	 *
	 * @param array<string, mixed> $config The step config.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-002
	 */
	public function validateConfig(array $config): void {
		unset($config);
	}//end validateConfig()
}//end class
