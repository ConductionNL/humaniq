<?php

/**
 * Golden test for ResourceBookingService::refusal().
 *
 * Runs incoming bookings against a resource and the bookings already stored:
 * missing or unparsable times, an end before the start, an unknown or retired
 * resource, capacities of one, three, zero and junk, clashes that overlap,
 * touch, enclose or lie on another resource, the booking under edit itself,
 * stored bookings without an id or with unparsable times. Every refusal is
 * compared with tests/fixtures/golden/resource-booking.json. Set
 * RECORD_GOLDEN=1 to rewrite that file.
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
 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\ResourceBookingService;
use PHPUnit\Framework\TestCase;

/**
 * Golden cases for the booking refusal.
 *
 * @covers \OCA\Humaniq\Service\ResourceBookingService
 */
class ResourceBookingGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/resource-booking.json';

	/**
	 * Every refusal, compared with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		$service = new ResourceBookingService();
		foreach (self::bookings() as $bookingName => $booking) {
			foreach (self::resources() as $resourceName => $resource) {
				foreach (self::stores() as $storeName => $store) {
					$actual[$bookingName . ' | ' . $resourceName . ' | ' . $storeName] = $service->refusal($booking, $resource, $store);
				}
			}
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped('recorded');
		}

		$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(count($golden), count($actual));
		foreach ($golden as $case => $refusal) {
			$this->assertArrayHasKey($case, $actual);
			$this->assertSame($refusal, $actual[$case], $case);
		}

	}//end testGolden()

	/**
	 * Incoming bookings, on resource r1 unless said otherwise.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function bookings(): array {
		return [
			'new 10-12' => ['resourceId' => 'r1', 'start' => '2026-06-01T10:00:00', 'end' => '2026-06-01T12:00:00'],
			'edit b1 10-12' => ['id' => 'b1', 'resourceId' => ' r1 ', 'start' => '2026-06-01T10:00:00', 'end' => '2026-06-01T12:00:00'],
			'edit b2 9-13' => ['id' => 'b2', 'resourceId' => 'r1', 'start' => '2026-06-01T09:00:00', 'end' => '2026-06-01T13:00:00'],
			'new 12-13' => ['resourceId' => 'r1', 'start' => '2026-06-01T12:00:00', 'end' => '2026-06-01T13:00:00'],
			'new 11-11:30' => ['id' => '', 'resourceId' => 'r1', 'start' => '2026-06-01T11:00:00', 'end' => '2026-06-01T11:30:00'],
			'other resource' => ['resourceId' => 'r2', 'start' => '2026-06-01T10:00:00', 'end' => '2026-06-01T12:00:00'],
			'ends before start' => ['resourceId' => 'r1', 'start' => '2026-06-01T12:00:00', 'end' => '2026-06-01T10:00:00'],
			'zero length' => ['resourceId' => 'r1', 'start' => '2026-06-01T12:00:00', 'end' => '2026-06-01T12:00:00'],
			'no resource' => ['resourceId' => ' ', 'start' => '2026-06-01T10:00:00', 'end' => '2026-06-01T12:00:00'],
			'bad start' => ['resourceId' => 'r1', 'start' => 'tomorrow-ish', 'end' => '2026-06-01T12:00:00'],
			'no end' => ['resourceId' => 'r1', 'start' => '2026-06-01T10:00:00'],
		];

	}//end bookings()

	/**
	 * The resource the booking asks for.
	 *
	 * @return array<string, array<string, mixed>|null>
	 */
	private static function resources(): array {
		return [
			'unknown' => null,
			'retired' => ['name' => 'Zaal 1', 'active' => false],
			'retired unnamed' => ['active' => false],
			'single' => ['name' => 'Zaal 1'],
			'unnamed' => [],
			'three' => ['name' => 'Beamer', 'quantity' => 3],
			'two as text' => ['name' => 'Auto', 'quantity' => '2'],
			'zero' => ['name' => 'Kast', 'quantity' => 0],
			'junk quantity' => ['name' => 'Kast', 'quantity' => 'veel'],
		];

	}//end resources()

	/**
	 * The bookings already stored.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private static function stores(): array {
		$b1 = ['id' => 'b1', 'resourceId' => 'r1', 'employeeId' => 'e1', 'start' => '2026-06-01T10:00:00', 'end' => '2026-06-01T12:00:00'];
		$b2 = ['id' => 'b2', 'resourceId' => 'r1', 'start' => '2026-06-01T11:00:00', 'end' => '2026-06-01T11:15:00'];
		$b3 = ['id' => 'b3', 'resourceId' => 'r1', 'employeeId' => 'e3', 'start' => '2026-06-01T08:00:00', 'end' => '2026-06-01T16:00:00'];
		$touch = ['id' => 'b4', 'resourceId' => 'r1', 'start' => '2026-06-01T12:00:00', 'end' => '2026-06-01T14:00:00'];
		$elsewhere = ['id' => 'b5', 'resourceId' => 'r2', 'start' => '2026-06-01T10:00:00', 'end' => '2026-06-01T12:00:00'];
		$noId = ['resourceId' => ' r1', 'employeeId' => 'e9', 'start' => '2026-06-01T10:30:00', 'end' => '2026-06-01T10:45:00'];
		$badTimes = ['id' => 'b6', 'resourceId' => 'r1', 'start' => 'later', 'end' => '2026-06-01T12:00:00'];
		$noEnd = ['id' => 'b7', 'resourceId' => 'r1', 'start' => '2026-06-01T10:00:00'];

		return [
			'empty' => [],
			'one clash' => [$b1],
			'two clashes' => [$b1, $b2],
			'three clashes' => [$b1, $b2, $b3],
			'touching' => [$touch],
			'elsewhere' => [$elsewhere],
			'no id' => [$noId],
			'unreadable' => [$badTimes, $noEnd],
			'everything' => [$elsewhere, $touch, $badTimes, $b3, $noId, $b1, $b2, $noEnd],
		];

	}//end stores()

}//end class
