<?php

/**
 * Golden test for MachineReadableZone: what each shape of zone reads as.
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
 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\MachineReadableZone;
use PHPUnit\Framework\TestCase;

/**
 * What each shape of zone reads as.
 */
class MachineReadableZoneGoldenTest extends TestCase {

	private const PASSPORT_1 = 'P<NLDDE<BRUIJN<<WILLEKE<LISELOTTE<<<<<<<<<<<';

	private const PASSPORT_2 = 'SPECI20142NLD6503101F3103094<<<<<<<<<<<<<<06';

	private const PERMIT_1 = 'IRNLDXA12345679<<<<<<<<<<<<<<<';

	private const PERMIT_2 = '9001011M2905017SYR<<<<<<<<<<<2';

	private const PERMIT_3 = 'AL<HASSAN<<OMAR<<<<<<<<<<<<<<<';

	/**
	 * Every shape, read.
	 *
	 * @return void
	 */
	public function testEveryShapeOfZone(): void {
		$cases = [
			'passport' => [self::PASSPORT_1, self::PASSPORT_2],
			'permit' => [self::PERMIT_1, self::PERMIT_2, self::PERMIT_3],
			'identity-card' => ['ID' . substr(self::PERMIT_1, 2), self::PERMIT_2, self::PERMIT_3],
			'other-card' => ['AC' . substr(self::PERMIT_1, 2), self::PERMIT_2, self::PERMIT_3],
			'bad-number' => [self::PASSPORT_1, substr_replace(self::PASSPORT_2, '1', 9, 1)],
			'bad-birth' => [self::PERMIT_1, substr_replace(self::PERMIT_2, '0', 6, 1), self::PERMIT_3],
			'bad-expiry' => [self::PASSPORT_1, substr_replace(self::PASSPORT_2, '5', 27, 1)],
			'one-line' => [self::PASSPORT_2],
			'short-second' => [self::PASSPORT_1, substr(self::PASSPORT_2, 0, 43)],
			'short-first' => [substr(self::PASSPORT_1, 0, 43), self::PASSPORT_2],
			'three-of-44' => [self::PASSPORT_1, self::PASSPORT_2, self::PASSPORT_2],
			'permit-short-second' => [self::PERMIT_1, substr(self::PERMIT_2, 0, 29), self::PERMIT_3],
			'permit-short-third' => [self::PERMIT_1, self::PERMIT_2, 'AL<HASSAN'],
			'none' => [],
		];
		$zone = new MachineReadableZone();
		$got = [];
		foreach ($cases as $name => $lines) {
			$got[$name] = $zone->read(lines: $lines);
		}

		$this->assertSame((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/golden/machine-readable-zone.json'), json_encode($got, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . "\n");
	}//end testEveryShapeOfZone()
}//end class
