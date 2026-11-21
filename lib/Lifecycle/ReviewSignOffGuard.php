<?php

/**
 * Humaniq ReviewSignOffGuard
 *
 * Lifecycle guard on the PerformanceReview `ondertekenen` transition
 * (REQ-PRV-007): only the employee the review is about may sign it as the
 * employee. The review's `userId` is that employee's Nextcloud account (the
 * denormalized copy of Employee.nextcloudUserId, REQ-MHS-002), so the acting
 * uid must equal it. Fails closed: an unknown caller, or a review without an
 * employee account, cannot be signed.
 *
 * @category Lifecycle
 * @package  OCA\Humaniq\Lifecycle
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
 * @spec openspec/specs/performance-reviews/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Lets only the reviewed employee sign their own review.
 *
 * @spec openspec/specs/performance-reviews/spec.md
 */
class ReviewSignOffGuard implements LifecycleGuardInterface {

	/**
	 * Allow the sign-off only for the review's own employee.
	 *
	 * @param array<string, mixed> $object The PerformanceReview at its current state.
	 * @param string $action The transition action (`ondertekenen`).
	 * @param string $userId The uid of the user signing.
	 *
	 * @return GuardResult Allow for the review's employee; deny otherwise.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult exposes only static factories.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $action is part of the interface.
	 *
	 * @spec openspec/specs/performance-reviews/spec.md
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		$userId = trim($userId);
		if ($userId === '') {
			return GuardResult::deny('U moet ingelogd zijn om een beoordeling te ondertekenen.');
		}

		$employeeUserId = trim((string)($object['userId'] ?? ''));
		if ($employeeUserId === '') {
			return GuardResult::deny('Deze beoordeling hoort bij een medewerker zonder account; niemand kan haar als medewerker ondertekenen.');
		}

		if ($employeeUserId !== $userId) {
			return GuardResult::deny('Alleen de medewerker zelf kan deze beoordeling ondertekenen.');
		}

		return GuardResult::allow();
	}//end check()
}//end class
