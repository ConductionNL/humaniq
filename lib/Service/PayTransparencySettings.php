<?php

/**
 * Humaniq Pay Transparency Settings
 *
 * The smallest group of women and of men the pay transparency report shows
 * figures of.
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCP\AppFramework\Services\IAppConfig;

/**
 * Reads the pay transparency report settings from humaniq's app config.
 *
 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-002
 */
final class PayTransparencySettings {

	/**
	 * @param IAppConfig $appConfig humaniq's app config.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * reporting-pay-transparency: the fewest women and the fewest men a group
	 * needs before the pay transparency report shows its figures. App config
	 * key `pay_transparency_minimum_group`, default 5.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/pay-transparency/spec.md#REQ-PTR-002
	 */
	public function getPayTransparencyMinimumGroup(): int {
		return max(1, $this->appConfig->getAppValueInt('pay_transparency_minimum_group', 5));
	}//end getPayTransparencyMinimumGroup()

}//end class
