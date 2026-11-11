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
	 * @param CloudEventHeader     $header The type, source, id, time and subject.
	 * @param array<string, mixed> $data   The event data.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
	 */
	public function build(CloudEventHeader $header, array $data): array {
		$envelope = [
			'specversion' => '1.0',
			'type' => $header->type,
			'source' => $header->source,
			'id' => $header->eventId,
		];
		if ($header->subject !== null) {
			$envelope['subject'] = $header->subject;
		}

		$envelope['time'] = $header->time;
		$envelope['datacontenttype'] = 'application/json';
		$envelope['data'] = $data;

		return $envelope;
	}//end build()

}//end class
