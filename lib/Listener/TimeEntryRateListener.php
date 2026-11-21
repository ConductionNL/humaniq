<?php

/**
 * Humaniq TimeEntryRateListener
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pre-save listener on OpenRegister's `ObjectCreatingEvent` /
 * `ObjectUpdatingEvent` for the `TimeEntry` schema (REQ-TEC-009): a billable
 * booking carries the rate it is billed at, `hourlyRate` in integer cents,
 * taken from its project's rate when the entry is written. A rate already on
 * the entry is kept, so the project manager's edit stands; a booking that is
 * not billable carries no rate. It runs after TimeEntryStampListener and
 * merges into, never replaces, what that listener stamped.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/time-entry-capture/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\ProjectRateReader;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Stamps a billable booking's rate from its project.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/time-entry-capture/spec.md#requirement-a-billable-booking-shall-carry-the-rate-it-is-billed-at-req-tec-009
 */
class TimeEntryRateListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway The schema slug of an entity.
	 * @param ProjectRateReader    $rates   The project's rate.
	 * @param InternalWriteMarker  $marker  humaniq's own internal writes, which own their values.
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
		private readonly ProjectRateReader $rates,
		private readonly InternalWriteMarker $marker,
	) {

	}//end __construct()

	/**
	 * Stamp or clear `hourlyRate` on a TimeEntry create or update.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/time-entry-capture/spec.md#requirement-a-billable-booking-shall-carry-the-rate-it-is-billed-at-req-tec-009
	 */
	public function handle(Event $event): void {
		if ($this->marker->isInternal() === true) {
			return;
		}

		$creating = ($event instanceof \OCA\OpenRegister\Event\ObjectCreatingEvent);
		if ($creating === false && ($event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent) === false) {
			return;
		}

		$entity = ($creating === true) ? $event->getObject() : $event->getNewObject();
		if (strtolower($this->gateway->resolveSchemaSlug((string)$entity->getSchema())) !== TimeEntryStampListener::TIMEENTRY_SLUG) {
			return;
		}

		$stamped = $event->getModifiedData();
		$stamp = $this->rateStamp(entry: array_merge(($entity->getObject() ?? []), $stamped));
		if ($stamp !== null) {
			$event->setModifiedData(array_merge($stamped, $stamp));
		}
	}//end handle()

	/**
	 * The `hourlyRate` change for the entry as it will be saved, or null for none.
	 *
	 * @param array<string, mixed> $entry The entry with every stamp applied.
	 *
	 * @return array{hourlyRate: int|null}|null
	 *
	 * @spec openspec/specs/time-entry-capture/spec.md#requirement-a-billable-booking-shall-carry-the-rate-it-is-billed-at-req-tec-009
	 */
	public function rateStamp(array $entry): ?array {
		$rate = ($entry['hourlyRate'] ?? null);
		if (($entry['billable'] ?? false) !== true) {
			return ($rate === null) ? null : ['hourlyRate' => null];
		}

		if ($rate !== null && $rate !== '') {
			return null;
		}

		$cents = $this->rates->rateCents(projectId: (string)($entry['projectId'] ?? ''));

		return ($cents === null) ? null : ['hourlyRate' => $cents];
	}//end rateStamp()
}//end class
