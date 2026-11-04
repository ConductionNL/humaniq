<?php

/**
 * ResolvesObjectServiceWithReload.
 *
 * Looks up OpenRegister's ObjectService for a class that holds SettingsService and a container, and tells the admin to install the app and reload when it is missing.
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
 * @spec exclude helper shared by services and checks, no behavioural spec surface.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Support;

use RuntimeException;

/**
 * Looks up OpenRegister's ObjectService for a class that holds SettingsService and a container.
 */
trait ResolvesObjectServiceWithReload {
	/**
	 * The OpenRegister ObjectService, once availability has been established.
	 *
	 * ADR-083: the reach is guarded rather than blind. humaniq owns no database
	 * tables — every object it manages lives in OpenRegister — so this is not
	 * an optional capability in the sense of a feature that degrades. What the
	 * guard buys is the ADR's rule 3 promise: an instance without OpenRegister
	 * must still reach a start screen that EXPLAINS ITSELF. Unguarded, the
	 * container raises `NotFoundExceptionInterface` naming a class the admin
	 * has never heard of; guarded, they are told which app to install.
	 *
	 * @return mixed The OpenRegister ObjectService.
	 *
	 * @throws \RuntimeException When OpenRegister is not installed.
	 */
	private function objectService(): mixed {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance. '
				. 'Install and enable it, then reload.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()
}//end class
