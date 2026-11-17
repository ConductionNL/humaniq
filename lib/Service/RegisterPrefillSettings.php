<?php

/**
 * Humaniq Register Prefill Settings
 *
 * Which integriq source each base register (RDW, BRP) is called through for
 * the people-register prefill.
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
 * Reads the register prefill sources from humaniq's app config.
 *
 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
 */
final class RegisterPrefillSettings {

	/**
	 * @param IAppConfig $appConfig humaniq's app config.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * The integriq source uuid a base register is called through
	 * (people-register-prefill D1): app config `prefill_rdw_source` or
	 * `prefill_brp_source`. Empty when not configured.
	 *
	 * @param string $register The register key: `rdw` or `brp`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
	 */
	public function getRegisterPrefillSource(string $register): string {
		return trim($this->appConfig->getAppValueString('prefill_' . $register . '_source', ''));
	}//end getRegisterPrefillSource()

}//end class
