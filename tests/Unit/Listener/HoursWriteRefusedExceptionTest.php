<?php

/**
 * HoursWriteRefusedException unit tests
 *
 * The dedicated refusal type: a RuntimeException carrying the user-facing
 * message — and distinguishable from a bare RuntimeException, which is the
 * class's entire reason to exist.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Listener
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
 * @spec openspec/changes/humaniq-hours-process-redesign/specs/time-entry-capture/spec.md#Requirement:-Entries-of-a-submitted-or-approved-timesheet-are-immutable-(REQ-TEC-005)
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\HoursWriteRefusedException;
use PHPUnit\Framework\TestCase;

/**
 * Type and message of the deliberate-refusal exception.
 */
class HoursWriteRefusedExceptionTest extends TestCase {

	/**
	 * It is a RuntimeException subtype carrying the message, and a bare
	 * RuntimeException is NOT an HoursWriteRefusedException (the listener's
	 * catch order depends on that asymmetry).
	 *
	 * @return void
	 */
	public function testIsADistinguishableRuntimeException(): void {
		$exception = new HoursWriteRefusedException('De eindtijd moet na de starttijd liggen.');
		$this->assertInstanceOf(\RuntimeException::class, $exception);
		$this->assertSame('De eindtijd moet na de starttijd liggen.', $exception->getMessage());
		$this->assertNotInstanceOf(HoursWriteRefusedException::class, new \RuntimeException('infra'));
	}//end testIsADistinguishableRuntimeException()

}//end class
