<?php

/**
 * ResolvesObjectServiceByClass.
 *
 * Looks up OpenRegister's ObjectService for a class that holds a container as `$container` and no SettingsService: it asks class_exists() whether OpenRegister is there.
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
 * Looks up OpenRegister's ObjectService for a class that holds a container as `$container` and no SettingsService: it asks class_exists() whether OpenRegister is there.
 */
trait ResolvesObjectServiceByClass {
	/**
	 * @return mixed The OpenRegister ObjectService.
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching. class_exists() rather
		// than SettingsService::isOpenRegisterAvailable(), because this guard
		// does not inject SettingsService and adding a constructor dependency
		// to a lifecycle guard purely to ask a yes/no question is the wrong
		// trade. It answers the same question the container would otherwise
		// have answered fatally.
		if (class_exists('OCA\OpenRegister\Service\ObjectService') === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()
}//end class
