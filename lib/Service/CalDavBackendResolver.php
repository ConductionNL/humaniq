<?php

/**
 * CalDAV Backend Resolver
 *
 * Duck-typed resolution of `OCA\DAV\CalDAV\CalDavBackend` from the container
 * for the calendar-sync services (design.md D1), guarded so a resolution
 * failure, a non-object or a missing method on any of the methods the sync
 * calls degrades to null rather than a fatal.
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
 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-006
 * @spec openspec/specs/interview-scheduling/spec.md#REQ-INTV-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the CalDAV backend a calendar sync can use.
 *
 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-006
 */
final class CalDavBackendResolver {

	/**
	 * The CalDAV backend's container id.
	 *
	 * @var string
	 */
	private const CALDAV_BACKEND_CLASS = 'OCA\DAV\CalDAV\CalDavBackend';

	/**
	 * The backend methods the calendar syncs call.
	 *
	 * @var string[]
	 */
	private const REQUIRED_METHODS = [
		'getCalendarByUri',
		'getCalendarObjectByUID',
		'createCalendarObject',
		'updateCalendarObject',
		'deleteCalendarObject',
		'getCalendarObjects',
	];

	/**
	 * @param ContainerInterface $container The server container.
	 * @param LoggerInterface    $logger    PSR-3 logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The backend, or null when it cannot be resolved or lacks a method the sync calls.
	 *
	 * @param string $owner The calling service's name, for the log line.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/leave-calendar-nc/spec.md#REQ-LC-006
	 */
	public function resolve(string $owner): mixed {
		try {
			$backend = $this->container->get(self::CALDAV_BACKEND_CLASS);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($backend) === false) {
			return null;
		}

		foreach (self::REQUIRED_METHODS as $method) {
			if (method_exists($backend, $method) === false) {
				// REQ-LC-006 / REQ-INTV-006: a resolution miss degrades to
				// skipped-no-calendar and SHALL log nothing above INFO -- this
				// is an expected, healthy degradation path, not an error.
				$this->logger->info($owner . ': CalDavBackend mist methode ' . $method . '; kalendersync overgeslagen.');
				return null;
			}
		}

		return $backend;

	}//end resolve()
}//end class
