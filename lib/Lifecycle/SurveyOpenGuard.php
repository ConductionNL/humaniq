<?php

/**
 * SurveyOpenGuard
 *
 * A survey is opened only by Open survey, which invites the employees in
 * its scope in the same step (talent-engagement-surveys D2). A direct write
 * of `status: open`, or the `openen` transition pressed anywhere else, would
 * open a survey nobody is invited to, so it is refused.
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
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Lifecycle;

use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;

/**
 * Only humaniq's own Open survey step may open a survey.
 *
 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
 */
class SurveyOpenGuard implements LifecycleGuardInterface {

	/**
	 * The guard.
	 *
	 * @param InternalWriteMarker $marker Set while SurveyService opens the survey.
	 */
	public function __construct(
		private readonly InternalWriteMarker $marker,
	) {
	}//end __construct()

	/**
	 * Decide the transition.
	 *
	 * @param array<string, mixed> $object The Survey at its current state.
	 * @param string               $action The transition.
	 * @param string               $userId The acting account.
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)          GuardResult exposes only the static allow()/deny() factories.
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 *
	 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		if ($this->marker->isInternal() === true) {
			return GuardResult::allow();
		}

		return GuardResult::deny('Open the survey with Open survey, which also invites the employees in its scope.');
	}//end check()

}//end class
