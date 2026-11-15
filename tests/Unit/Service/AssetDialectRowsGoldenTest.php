<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The asset dialect mapping matches the recorded golden for Dutch, current,
 * unknown and missing values, renamed fields with and without a clash and
 * values the mapper normalises.
 *
 * @spec openspec/specs/asset-management/spec.md#REQ-AST-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\AssetDialectMapper;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../fixtures/golden/asset-dialect-rows.input.php';

/**
 * Golden test for {@see AssetDialectMapper::mapAssetRow()}.
 *
 * @covers \OCA\Humaniq\Service\AssetDialectMapper
 */
class AssetDialectRowsGoldenTest extends TestCase {

	/**
	 * Every row maps to the recorded result.
	 *
	 * @return void
	 */
	public function testRowsMatchTheGolden(): void {
		$golden = json_decode((string)file_get_contents(__DIR__ . '/../../fixtures/golden/asset-dialect-rows.json'), true);
		foreach (assetDialectRows() as $name => $row) {
			self::assertSame(
				json_encode($golden[$name], JSON_PRESERVE_ZERO_FRACTION),
				json_encode((new AssetDialectMapper())->mapAssetRow($row), JSON_PRESERVE_ZERO_FRACTION),
				$name
			);
		}
	}//end testRowsMatchTheGolden()
}//end class
