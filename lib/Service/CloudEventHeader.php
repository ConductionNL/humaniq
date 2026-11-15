<?php

/**
 * Humaniq CloudEventHeader
 *
 * The context attributes of one CloudEvent: its type, source, id, time and
 * optional subject.
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
 * What a CloudEvent says about itself, beside its data.
 *
 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
 */
final class CloudEventHeader {

	/**
	 * The event type, `nl.conduction.hrmq.*`.
	 *
	 * @var string
	 */
	public string $type = '';

	/**
	 * The source path.
	 *
	 * @var string
	 */
	public string $source = '';

	/**
	 * The event id.
	 *
	 * @var string
	 */
	public string $eventId = '';

	/**
	 * The ISO 8601 UTC time.
	 *
	 * @var string
	 */
	public string $time = '';

	/**
	 * The subject, left out when null.
	 *
	 * @var string|null
	 */
	public ?string $subject = null;
}//end class
