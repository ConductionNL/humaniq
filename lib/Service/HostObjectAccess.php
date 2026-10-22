<?php
/**
 * Humaniq HostObjectAccess
 *
 * Whether the caller may read the object a time entry is booked on.
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
 * @spec openspec/changes/hours-week-host-access/specs/hours-leaf/spec.md#requirement-the-week-is-answered-only-to-a-reader-of-the-object
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use Psr\Container\ContainerInterface;

/**
 * Asks OpenRegister, with the caller's access rules and tenancy, whether the
 * host object of a booking can be read.
 *
 * A time entry names its host as `domainObjectType` (`<register>:<schema>`)
 * and `domainObjectRef` (the uuid). What may be said about the hours on an
 * object follows from whether the object itself may be read: a reader of a
 * case may see who booked on it, a user who cannot open the case may not.
 * Anything that cannot be decided (a malformed type, OpenRegister absent or
 * failing) answers no.
 *
 * @spec openspec/changes/hours-week-host-access/specs/hours-leaf/spec.md#requirement-the-week-is-answered-only-to-a-reader-of-the-object
 */
class HostObjectAccess {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Reaches OpenRegister's object service when it is installed.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Whether the caller may read the host object.
	 *
	 * @param string $domainObjectType The `<register>:<schema>` literal, for example `dossiq:case`.
	 * @param string $domainObjectRef  The object's uuid.
	 *
	 * @return bool True only when OpenRegister hands the object to the caller.
	 *
	 * @spec openspec/changes/hours-week-host-access/specs/hours-leaf/spec.md#requirement-the-week-is-answered-only-to-a-reader-of-the-object
	 */
	public function canRead(string $domainObjectType, string $domainObjectRef): bool {
		$parts = explode(':', $domainObjectType);
		if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '' || trim($domainObjectRef) === '') {
			return false;
		}

		try {
			$found = $this->container->get('OCA\OpenRegister\Service\ObjectService')->find(
				id: trim($domainObjectRef),
				register: trim($parts[0]),
				schema: trim($parts[1]),
				_rbac: true,
				_multitenancy: true
			);
		} catch (\Throwable $e) {
			return false;
		}

		return $found !== null;
	}//end canRead()
}//end class
