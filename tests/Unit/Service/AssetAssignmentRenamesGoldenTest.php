<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for the field-rename engine through
 * {@see AssetDialectMapper::mapAssignmentRow()}: a null old value, equivalent
 * old and new values, clashes reported in map order, a rename next to a clash
 * and plain renames.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\AssetDialectMapper;
use PHPUnit\Framework\TestCase;

/**
 * The rename engine's answer per recorded assignment row.
 *
 * @covers \OCA\Humaniq\Service\AssetDialectMapper
 */
class AssetAssignmentRenamesGoldenTest extends TestCase {

	/**
	 * Every recorded row maps to its recorded result.
	 *
	 * @return void
	 */
	public function testAssignmentRowsMatchTheGolden(): void {
		$golden = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/golden/asset-assignment-renames.json'), true);
		self::assertCount(5, $golden);
		foreach ($golden as $name => $case) {
			self::assertSame(
				json_encode($case['expected'], JSON_PRESERVE_ZERO_FRACTION),
				json_encode((new AssetDialectMapper())->mapAssignmentRow($case['input']), JSON_PRESERVE_ZERO_FRACTION),
				$name
			);
		}
	}//end testAssignmentRowsMatchTheGolden()
}//end class
