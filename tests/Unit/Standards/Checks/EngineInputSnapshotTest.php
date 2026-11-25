<?php

/**
 * Unit tests for EngineInputSnapshot.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Standards\Checks
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/rules-onto-or-decision-tables/specs/hrm-rule-engine/spec.md#REQ-RULE-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Standards\Checks;

use OCA\Humaniq\Standards\Checks\EngineInputSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Humaniq\Standards\Checks\EngineInputSnapshot
 */
class EngineInputSnapshotTest extends TestCase {

	/**
	 * Arrays pass through, JSON objects decode, everything else is null.
	 *
	 * @return void
	 */
	public function testTheSnapshotDecodesFromArrayOrJson(): void {
		$snapshot = new EngineInputSnapshot();

		self::assertSame(['jurisdiction' => 'NL'], $snapshot->decode(['jurisdiction' => 'NL']));
		self::assertSame(['jurisdiction' => 'NL'], $snapshot->decode(' {"jurisdiction": "NL"} '));
		self::assertNull($snapshot->decode('{nope'));
		self::assertNull($snapshot->decode('"NL"'));
		self::assertNull($snapshot->decode([]));
		self::assertNull($snapshot->decode(''));
		self::assertNull($snapshot->decode(null));
	}//end testTheSnapshotDecodesFromArrayOrJson()
}//end class
