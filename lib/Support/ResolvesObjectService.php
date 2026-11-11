<?php

/**
 * ResolvesObjectService.
 *
 * Looks up OpenRegister's services for a class that holds the app's
 * SettingsService as `$settingsService` and a container as `$container`.
 * A class that needs nothing else from SettingsService holds
 * OpenRegisterAvailability instead and declares its own
 * openRegisterAvailable() asking it.
 *
 * @category Support
 * @package  OCA\Humaniq\Support
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
 * @spec exclude object service plumbing shared by services, jobs and controllers, no behavioural spec surface.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Support;

use RuntimeException;

/**
 * OpenRegister's services, after checking that OpenRegister is there.
 */
trait ResolvesObjectService {
	/**
	 * OpenRegister's ObjectService.
	 *
	 * @return mixed The OpenRegister ObjectService.
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function objectService(): mixed {
		return $this->openRegisterService('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

	/**
	 * A service of OpenRegister. ADR-083: availability is checked before the
	 * container is asked, so an instance without OpenRegister is told which
	 * app to install instead of getting a container error that names a class
	 * the admin has never heard of.
	 *
	 * @param string $service The OpenRegister service class.
	 *
	 * @return mixed The service.
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function openRegisterService(string $service): mixed {
		if ($this->openRegisterAvailable() === false) {
			throw new RuntimeException('humaniq requires the OpenRegister app, which is not installed on this instance.');
		}

		return $this->container->get($service);
	}//end openRegisterService()

	/**
	 * Whether OpenRegister is installed, asked of the class's SettingsService.
	 * A class holding OpenRegisterAvailability instead declares its own.
	 *
	 * @return bool
	 */
	protected function openRegisterAvailable(): bool {
		return $this->settingsService->isOpenRegisterAvailable();
	}//end openRegisterAvailable()
}//end class
