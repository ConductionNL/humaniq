<?php

/**
 * Unit tests for the CloudEvents 1.0 envelope.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\CloudEventEnvelope;
use OCA\Humaniq\Service\CloudEventHeader;
use PHPUnit\Framework\TestCase;

/**
 * The envelope's fields, in order, with and without a subject.
 */
class CloudEventEnvelopeTest extends TestCase {

	/**
	 * Build one envelope.
	 *
	 * @param string|null $subject The subject, or null.
	 *
	 * @return array<string, mixed>
	 */
	private function envelope(?string $subject): array {
		$header = new CloudEventHeader();
		$header->type = 'nl.conduction.hrmq.employee.joined';
		$header->source = '/humaniq/employees';
		$header->eventId = 'evt-1';
		$header->time = '2026-06-01T08:00:00Z';
		$header->subject = $subject;

		return (new CloudEventEnvelope())->build(header: $header, data: ['employeeId' => 'emp-1']);
	}//end envelope()

	/**
	 * The subject sits between the id and the time, and is left out when null.
	 *
	 * @return void
	 */
	public function testTheEnvelopeFields(): void {
		$head = ['specversion' => '1.0', 'type' => 'nl.conduction.hrmq.employee.joined', 'source' => '/humaniq/employees', 'id' => 'evt-1'];
		$tail = ['time' => '2026-06-01T08:00:00Z', 'datacontenttype' => 'application/json', 'data' => ['employeeId' => 'emp-1']];

		self::assertSame($head + ['subject' => 'emp-1'] + $tail, $this->envelope('emp-1'));
		self::assertSame($head + $tail, $this->envelope(null));
	}//end testTheEnvelopeFields()
}//end class
