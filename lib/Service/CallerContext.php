<?php

/**
 * Caller Context
 *
 * What an HR controller needs about the request it serves besides its own
 * services: the signed-in caller, the caller's HR roles, the container that
 * resolves OpenRegister, and the logger that records each decision.
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
 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The caller, their roles, the container and the logger of a controller.
 *
 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
 */
final class CallerContext {

	/**
	 * @param IUserSession $userSession The signed-in caller.
	 * @param HumaniqRoles $roles The caller's HR and payroll roles.
	 * @param \Psr\Container\ContainerInterface $container Resolves the OpenRegister ObjectService.
	 * @param LoggerInterface $logger Records each decision.
	 */
	public function __construct(
		public readonly IUserSession $userSession,
		public readonly HumaniqRoles $roles,
		public readonly \Psr\Container\ContainerInterface $container,
		public readonly LoggerInterface $logger,
	) {

	}//end __construct()
}//end class
