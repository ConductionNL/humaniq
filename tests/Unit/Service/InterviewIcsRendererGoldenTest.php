<?php

/**
 * Golden test for the interview calendar rendering and its no-op diff.
 *
 * Renders VEVENTs with and without a location and interviewers, with text
 * that needs RFC 5545 escaping (commas, semicolons, backslashes, newlines),
 * and compares stored calendar objects against desired values: identical,
 * a moved start or end, a changed or escaped summary, a location added,
 * removed or changed, and objects missing a property. The DTSTAMP, the
 * render time, is masked. Every answer is compared with
 * tests/fixtures/golden/interview-ics.json. Set RECORD_GOLDEN=1 to rewrite
 * that file.
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
 * @spec openspec/changes/interview-scheduling/specs/interview-scheduling/spec.md#REQ-INTV-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\InterviewIcsRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Golden cases for the interview ICS renderer.
 *
 * @covers \OCA\Humaniq\Service\InterviewIcsRenderer
 * @uses   \OCA\Humaniq\Support\ReadsIcsValues
 * @uses \OCA\Humaniq\Service\IcsText
 */
class InterviewIcsRendererGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/../../fixtures/golden/interview-ics.json';

	/**
	 * Every answer, compared with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$renderer = new InterviewIcsRenderer();
		$texts = ['' => '', 'plain' => 'Kamer 2', 'escaped' => "Zaal A, vleugel; \\oost\nachter"];
		$actual = ['render' => [], 'unchanged' => []];
		foreach ($texts as $locationName => $location) {
			foreach ($texts as $interviewersName => $interviewers) {
				$ics = $renderer->render(...$this->renderArgs('uid-1', '20260801T080000Z', '20260801T090000Z', 'Gesprek; vacature, 1', $location, $interviewers));
				$actual['render']['location ' . $locationName . ' | interviewers ' . $interviewersName] = preg_replace('/DTSTAMP:\d{8}T\d{6}Z/', 'DTSTAMP:(masked)', $ics);
			}
		}

		$stored = $renderer->render(...$this->renderArgs('uid-1', '20260801T080000Z', '20260801T090000Z', 'Gesprek; vacature, 1', 'Kamer 2', 'Jansen'));
		$desired = [
			'identical' => ['20260801T080000Z', '20260801T090000Z', 'Gesprek; vacature, 1', 'Kamer 2'],
			'moved start' => ['20260801T083000Z', '20260801T090000Z', 'Gesprek; vacature, 1', 'Kamer 2'],
			'moved end' => ['20260801T080000Z', '20260801T093000Z', 'Gesprek; vacature, 1', 'Kamer 2'],
			'other summary' => ['20260801T080000Z', '20260801T090000Z', 'Gesprek', 'Kamer 2'],
			'escaped summary given' => ['20260801T080000Z', '20260801T090000Z', 'Gesprek\; vacature\\, 1', 'Kamer 2'],
			'location removed' => ['20260801T080000Z', '20260801T090000Z', 'Gesprek; vacature, 1', ''],
			'location changed' => ['20260801T080000Z', '20260801T090000Z', 'Gesprek; vacature, 1', 'Kamer 3'],
		];
		$storedObjects = [
			'rendered' => $stored,
			'without location' => $renderer->render(...$this->renderArgs('uid-1', '20260801T080000Z', '20260801T090000Z', 'Gesprek; vacature, 1', '', '')),
			'without summary' => str_replace("SUMMARY:Gesprek\; vacature\\, 1\r\n", '', $stored),
			'without start' => str_replace("DTSTART:20260801T080000Z\r\n", '', $stored),
			'without end' => str_replace("DTEND:20260801T090000Z\r\n", '', $stored),
			'empty' => '',
		];
		foreach ($storedObjects as $storedName => $ics) {
			foreach ($desired as $desiredName => $values) {
				$actual['unchanged'][$storedName . ' | ' . $desiredName] = $renderer->unchanged(...$this->unchangedArgs($ics, $values));
			}
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('RECORD_GOLDEN') === '1') {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped('recorded');
		}

		$this->assertSame(json_decode((string)file_get_contents(self::GOLDEN), true), $actual);

	}//end testGolden()

	/**
	 * The arguments of one render.
	 *
	 * @param string $uid The UID.
	 * @param string $dtstart The start.
	 * @param string $dtend The end.
	 * @param string $summary The summary.
	 * @param string $location The location.
	 * @param string $interviewers The interviewers.
	 *
	 * @return array<int|string, mixed>
	 */
	private function renderArgs(string $uid, string $dtstart, string $dtend, string $summary, string $location, string $interviewers): array {
		return [$uid, ['dtstart' => $dtstart, 'dtend' => $dtend, 'summary' => $summary, 'location' => $location, 'interviewers' => $interviewers]];

	}//end renderArgs()

	/**
	 * The arguments of one comparison.
	 *
	 * @param string $ics The stored object.
	 * @param array{0: string, 1: string, 2: string, 3: string} $values Desired start, end, summary and location.
	 *
	 * @return array<int|string, mixed>
	 */
	private function unchangedArgs(string $ics, array $values): array {
		return [$ics, ['dtstart' => $values[0], 'dtend' => $values[1], 'summary' => $values[2], 'location' => $values[3]]];

	}//end unchangedArgs()

}//end class
