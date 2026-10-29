<?php

/**
 * Example data for the Uren registreren card on dossiq case 2026-0110: the hours booked on it this week.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Settings
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
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Zuiddrecht set books 11,5 hours on the Lindelaan Woo case, as time entries that name the case.
 */
class ZuiddrechtCaseHoursTest extends TestCase {

	/**
	 * The time entries on the case, by account: hours per person, dates relative to today, the case found by lookup.
	 *
	 * @return void
	 */
	public function testTheLindelaanCaseHasItsHoursThisWeek(): void {
		$file     = __DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json';
		$fragment = json_decode((string)file_get_contents($file), true);
		$this->assertIsArray(actual: $fragment, message: 'humaniq ships a zuiddrecht fragment');
		$this->assertSame(expected: 'zuiddrecht', actual: $fragment['set']);
		$this->assertContains(needle: 'portaliq', haystack: $fragment['after'], message: 'portaliq writes the case first');

		$hours = [];
		foreach ($fragment['objects'] as $object) {
			$this->assertSame(expected: 'humaniq', actual: $object['register'], message: $object['key']);
			$this->assertSame(expected: 'TimeEntry', actual: $object['schema'], message: $object['key']);
			$this->assertSame(
				expected: ['title' => 'Verlichting fietspad Lindelaan', 'externalReference' => 'MZ-ZDR-2026-0412'],
				actual: $object['lookups']['case']['where'],
				message: $object['key']
			);

			$data = $object['data'];
			$this->assertSame(expected: 'dossiq:case', actual: $data['domainObjectType'], message: $object['key']);
			$this->assertSame(expected: '{{lookup:case}}', actual: $data['domainObjectRef'], message: $object['key']);
			$this->assertMatchesRegularExpression(pattern: '/^\{\{date:-?[01]\}\}$/', string: $data['date'], message: $object['key']);
			$hours[$data['userId']] = (($hours[$data['userId']] ?? 0) + $data['hours']);
		}

		$this->assertSame(
			expected: ['{{cast:pieter.uid}}' => 6.5, '{{cast:lars.uid}}' => 3, '{{cast:marloes.uid}}' => 2],
			actual: $hours
		);
	}//end testTheLindelaanCaseHasItsHoursThisWeek()
}//end class
