<?php

/**
 * Humaniq CloudEventEnvelope
 *
 * The CloudEvents 1.0 envelope every humaniq outbound event uses, shared by
 * the time-entry event and the HR lifecycle events (platform-hr-lifecycle-events
 * task 1.1). Pure: it builds the array OpenRegister's WebhookService sends.
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
 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Builds a CloudEvents 1.0 envelope.
 *
 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
 */
class CloudEventEnvelope {

	/**
	 * One CloudEvent.
	 *
	 * @param string $type The event type, `nl.conduction.hrmq.*`.
	 * @param string $source The source path.
	 * @param string $eventId The event id.
	 * @param string $time The ISO 8601 UTC time.
	 * @param array<string, mixed> $data The event data.
	 * @param string|null $subject The subject, left out when null.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
	 */
	public static function build(string $type, string $source, string $eventId, string $time, array $data, ?string $subject = null): array {
		$envelope = [
			'specversion' => '1.0',
			'type' => $type,
			'source' => $source,
			'id' => $eventId,
		];
		if ($subject !== null) {
			$envelope['subject'] = $subject;
		}

		$envelope['time'] = $time;
		$envelope['datacontenttype'] = 'application/json';
		$envelope['data'] = $data;

		return $envelope;
	}//end build()

}//end class
