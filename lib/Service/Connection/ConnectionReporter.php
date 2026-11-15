<?php

/**
 * Humaniq connection reporter.
 *
 * Tells integriq's connection registry what humaniq observed about one of its
 * declared connections. Integriq owns the rows its Integrations overview lists;
 * humaniq only reports what it alone can see: whether shillinq answered its last
 * payroll handoff (platform-integrations-catalogue, design D2).
 *
 * @category Service
 * @package  OCA\Humaniq\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/platform-integrations-catalogue/design.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service\Connection;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends a connection report to integriq when integriq is installed.
 *
 * @spec openspec/changes/platform-integrations-catalogue/design.md
 */
class ConnectionReporter {

	/**
	 * The app id integriq keys the rows by.
	 *
	 * @var string
	 */
	public const APP_ID = 'humaniq';

	/**
	 * Integriq's report event. Named by string so humaniq stays installable
	 * without integriq: the class only exists when integriq does.
	 *
	 * @var string
	 */
	public const STATUS_EVENT = 'OCA\Integriq\Event\ConnectionStatusReportedEvent';

	/**
	 * The ledger connection: whether shillinq answered humaniq's last handoff.
	 *
	 * @var string
	 */
	public const LEDGER = 'shillinq-ledger';

	/**
	 * The declared keys humaniq reports on. A key outside this set is a
	 * caller's typo, not a new connection. A unit test keeps it in
	 * `lib/Settings/connections.json` as `reportedOnly`.
	 *
	 * @var array<int, string>
	 */
	public const KEYS = [self::LEDGER];

	/**
	 * The statuses integriq accepts in a report.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = ['configured', 'limited', 'unconfigured', 'simulated', 'unavailable', 'error'];

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Sends the integriq event.
	 * @param LoggerInterface  $logger          Records what could not be sent.
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Report a connection's status to integriq.
	 *
	 * Never throws: it runs beside a handoff whose own outcome is what the
	 * caller returns. Without integriq nothing is sent and nothing is logged,
	 * because a missing optional app is not a fault.
	 *
	 * @param string $key     One of {@see self::KEYS}.
	 * @param string $status  One of {@see self::STATUSES}.
	 * @param string $message What humaniq observed.
	 *
	 * @return bool True when the report was sent.
	 *
	 * @spec openspec/changes/platform-integrations-catalogue/design.md
	 */
	public function report(string $key, string $status, string $message = ''): bool {
		if ($this->isValid(key: $key, status: $status) === false) {
			return false;
		}

		$eventClass = $this->resolveEventClass(eventClass: self::STATUS_EVENT);
		if ($eventClass === null) {
			return false;
		}

		try {
			$event = new $eventClass(app: self::APP_ID, key: $key, status: $status, message: $message);
			if ($event instanceof Event === false) {
				return false;
			}

			$this->eventDispatcher->dispatchTyped($event);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'[ConnectionReporter] Could not send a connection report to integriq',
				['key' => $key, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end report()

	/**
	 * The event class to instantiate, or null when integriq does not ship it.
	 *
	 * @param string $eventClass The fully qualified class name, without a leading backslash.
	 *
	 * @return string|null
	 */
	protected function resolveEventClass(string $eventClass): ?string {
		$qualified = '\\' . $eventClass;
		if (class_exists($qualified) === false) {
			return null;
		}

		return $qualified;
	}//end resolveEventClass()

	/**
	 * Whether the key and status are ones integriq would accept, warning when not.
	 *
	 * @param string $key    The connection key.
	 * @param string $status The status.
	 *
	 * @return bool
	 */
	private function isValid(string $key, string $status): bool {
		if (in_array($key, self::KEYS, true) === false) {
			$this->logger->warning('[ConnectionReporter] Refusing to report an unknown connection key', ['key' => $key]);
			return false;
		}

		if (in_array($status, self::STATUSES, true) === false) {
			$this->logger->warning('[ConnectionReporter] Refusing to report an unknown connection status', ['key' => $key, 'status' => $status]);
			return false;
		}

		return true;
	}//end isValid()
}//end class
