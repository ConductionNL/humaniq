<?php

/**
 * Characterisation tests for TaxTables::resolveLeaf().
 *
 * Pins, on the shipped nl-2026 tables: a provenance leaf resolves to its value
 * and provenance (and to cents on request), a plain branch resolves without
 * provenance, the empty path is the whole table, and the exact refusals for a
 * missing parameter (also below a leaf) and for a non-amount asked in cents.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Payroll
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
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Payroll;

use OCA\Humaniq\Payroll\TaxTables;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * TaxTables::resolveLeaf() resolves and refuses exactly as pinned.
 *
 * @covers \OCA\Humaniq\Payroll\TaxTables
 */
class TaxTablesResolveLeafTest extends TestCase {

	/**
	 * @return void
	 */
	public function testResolution(): void {
		$tables     = TaxTables::load('nl-2026');
		$provenance = ['path' => 'werknemersverzekeringen.whk', 'source' => 'RPP2026 (gemiddeld)', 'verified' => true, 'placeholder' => true];

		$this->assertSame(['value' => 1.52, 'provenance' => $provenance], $tables->resolveLeaf(['werknemersverzekeringen', 'whk']));
		$this->assertSame(['value' => 152, 'provenance' => $provenance], $tables->resolveLeaf(['werknemersverzekeringen', 'whk'], true));

		$branch = $tables->resolveLeaf(['werknemersverzekeringen']);
		$this->assertSame(['value', 'provenance'], array_keys($branch));
		$this->assertSame(['maximumpremieloon', 'awf', 'aof'], array_slice(array_keys($branch['value']), 0, 3));
		$this->assertNull($branch['provenance']);

		$this->assertSame(['loonheffing', 'heffingskortingen', 'volksverzekeringen'], array_slice(array_keys($tables->resolveLeaf([])['value']), 0, 3));

	}//end testResolution()

	/**
	 * @return array<string, array{0: list<string>, 1: bool, 2: string}>
	 */
	public static function refusals(): array {
		return [
			'missing at the top' => [['nope'], false, 'TaxTables (nl-2026): ontbrekende parameter "nope".'],
			'missing below a branch' => [['werknemersverzekeringen', 'whkDefault'], true, 'TaxTables (nl-2026): ontbrekende parameter "werknemersverzekeringen.whkDefault".'],
			'missing below a leaf' => [['werknemersverzekeringen', 'whk', 'x'], false, 'TaxTables (nl-2026): ontbrekende parameter "werknemersverzekeringen.whk.x".'],
			'a branch in cents' => [['werknemersverzekeringen'], true, 'TaxTables (nl-2026): parameter "werknemersverzekeringen" is geen bedrag en kan niet naar centen worden omgezet.'],
		];

	}//end refusals()

	/**
	 * @param list<string> $segments The path.
	 * @param bool         $toCents  Whether cents are asked.
	 * @param string       $message  The refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refusals
	 */
	public function testRefusal(array $segments, bool $toCents, string $message): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage($message);

		TaxTables::load('nl-2026')->resolveLeaf($segments, $toCents);

	}//end testRefusal()
}//end class
