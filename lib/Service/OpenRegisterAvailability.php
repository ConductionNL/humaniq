<?php

/**
 * Humaniq OpenRegister Availability
 *
 * Tells whether the OpenRegister app, where every humaniq record lives, is
 * installed on this instance.
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
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCP\App\IAppManager;

/**
 * Whether OpenRegister is installed.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */
final class OpenRegisterAvailability {

	/**
	 * @param IAppManager $appManager The app manager.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
	) {

	}//end __construct()

	/**
	 * Whether OpenRegister is installed and available.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/hrm-rule-engine/spec.md
	 */
	public function isAvailable(): bool {
		return $this->appManager->isInstalled('openregister');
	}//end isAvailable()

}//end class
