<?php

/**
 * Humaniq StampsProcessFields
 *
 * The parts every pre-save process-stamp listener shares: dispatching an
 * ObjectCreatingEvent or ObjectUpdatingEvent to the listener's own
 * stampCreating() / stampUpdating(), refusing the write when stamping
 * throws, the UTC timestamp the stamps carry, and the trimmed-or-null value.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
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
 * @spec openspec/changes/leave-approval-steps-and-history/specs/leave-approval-steps/spec.md#requirement-every-leave-decision-shall-be-stamped-on-the-request-by-the-server-req-las-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

use DateTimeImmutable;
use DateTimeZone;
use OCP\EventDispatcher\Event;

/**
 * Shared dispatch, refusal, clock and trimming of the process-stamp listeners.
 *
 * The using class has a `$logger` (LoggerInterface), its own
 * stampCreating() and stampUpdating(), and the constants STAMP_FAILURE (the
 * warning logged when stamping throws) and STAMP_REFUSAL (the user-facing
 * message the write is refused with).
 *
 * @spec openspec/changes/leave-approval-steps-and-history/specs/leave-approval-steps/spec.md#requirement-every-leave-decision-shall-be-stamped-on-the-request-by-the-server-req-las-001
 */
trait StampsProcessFields {

	/**
	 * Stamp a create or an update; when stamping throws, log it and refuse the write.
	 *
	 * @param Event $event The pre-save event.
	 *
	 * @return void
	 */
	private function stampOrRefuse(Event $event): void {
		try {
			if ($event instanceof \OCA\OpenRegister\Event\ObjectCreatingEvent) {
				$this->stampCreating(event: $event);
				return;
			}

			if ($event instanceof \OCA\OpenRegister\Event\ObjectUpdatingEvent) {
				$this->stampUpdating(event: $event);
			}
		} catch (\Throwable $e) {
			$this->logger->warning(self::STAMP_FAILURE, ['exception' => $e->getMessage()]);
			$this->refuse(event: $event, message: self::STAMP_REFUSAL);
		}
	}//end stampOrRefuse()

	/**
	 * Now, as an ISO 8601 UTC timestamp.
	 *
	 * @return string
	 */
	private function now(): string {
		return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
	}//end now()

	/**
	 * Refuse the write with a user-facing message.
	 *
	 * @param object $event   The pre-save event.
	 * @param string $message The message.
	 *
	 * @return void
	 */
	private function refuse(object $event, string $message): void {
		if (method_exists($event, 'setErrors') === true) {
			$event->setErrors(['message' => $message]);
		}

		if (method_exists($event, 'stopPropagation') === true) {
			$event->stopPropagation();
		}
	}//end refuse()

	/**
	 * A trimmed string, or null when empty.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null
	 */
	private static function nullableTrim(mixed $value): ?string {
		$trimmed = trim((string)($value ?? ''));

		return $trimmed === '' ? null : $trimmed;
	}//end nullableTrim()
}//end trait
