<?php

/**
 * Golden test for the hours ceiling an enforced time estimate puts on a booking.
 *
 * Runs TimeEstimateService::refusalForEntry() for incoming entries (new and
 * corrected, with and without a role, from a timer, booked against nothing)
 * against enforced and advisory estimates per object and role, and stored
 * entries on the same object, another object, another role, the entry
 * itself and with unreadable hours. Every refusal is compared with
 * tests/fixtures/golden/time-estimate-refusal.json. Set RECORD_GOLDEN=1 to
 * rewrite that file.
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
 * @spec openspec/specs/hours-leaf/spec.md#REQ-HL-EST-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\TimeEstimateService;
use PHPUnit\Framework\TestCase;

/**
 * Golden cases for the estimate ceiling.
 *
 * @covers \OCA\Humaniq\Service\TimeEstimateService
 */
class TimeEstimateRefusalGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/time-estimate-refusal.json';

	/**
	 * Every refusal, compared with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$on = ['domainObjectType' => 'dossiq:Zaak', 'domainObjectRef' => 'z-1'];
		$entries = [
			'new 3h' => ($on + ['hours' => 3]),
			'new 3h, analist' => ($on + ['hours' => '3', 'role' => 'analist']),
			'correct e1 to 6h' => ($on + ['id' => 'e1', 'hours' => 6]),
			'correct e5 (analist) to 2.5h' => ($on + ['id' => 'e5', 'hours' => 2.5, 'role' => ' analist ']),
			'timer 50h' => ($on + ['hours' => 50, 'origin' => 'timer']),
			'against nothing' => ['hours' => 99],
			'no ref' => ['domainObjectType' => 'dossiq:Zaak', 'hours' => 99],
			'negative hours' => ($on + ['hours' => -4]),
			'other object' => ['domainObjectType' => 'dossiq:Zaak', 'domainObjectRef' => 'z-2', 'hours' => 9],
		];
		$estimateSets = [
			'enforced 8h' => [($on + ['estimatedHours' => 8, 'enforced' => true])],
			'advisory 1h' => [($on + ['estimatedHours' => 1, 'enforced' => false])],
			'enforced per role' => [($on + ['estimatedHours' => 7, 'enforced' => true]), ($on + ['estimatedHours' => 4, 'enforced' => true, 'role' => 'analist'])],
			'enforced on another object' => [['domainObjectType' => 'dossiq:Zaak', 'domainObjectRef' => 'z-2', 'estimatedHours' => 1, 'enforced' => true]],
			'none' => [],
		];
		$stored = [
			($on + ['id' => 'e1', 'hours' => 4]),
			($on + ['id' => 'e2', 'hours' => '2.25']),
			($on + ['id' => 'e3', 'hours' => 'veel']),
			($on + ['id' => 'e4', 'hours' => 1, 'role' => 'analist']),
			($on + ['id' => 'e5', 'hours' => 1.5, 'role' => 'analist']),
			['domainObjectType' => 'dossiq:Zaak', 'domainObjectRef' => 'z-2', 'id' => 'e6', 'hours' => 30],
			($on + ['hours' => 0.5]),
		];

		$service = new TimeEstimateService();
		$actual = [];
		foreach ($entries as $entryName => $entry) {
			foreach ($estimateSets as $setName => $estimates) {
				$actual[$entryName . ' | ' . $setName] = $service->refusalForEntry($entry, $estimates, $stored);
			}
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped('recorded');
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), $actual);

	}//end testGolden()

}//end class
