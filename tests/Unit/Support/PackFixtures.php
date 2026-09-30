<?php

/**
 * A 2027 pack and tables document for the pack-upload tests, built from the
 * bundled 2026 corpus so no second copy of the figures lives in the repo.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Support
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
 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Support;

/**
 * Pack and tables documents for NL 2027.
 */
final class PackFixtures {

	/**
	 * The bundled 2026 tables, re-labelled as 2027.
	 *
	 * @param string $id The tables id to declare.
	 *
	 * @return array<string, mixed>
	 */
	public static function tables(string $id = 'nl-2027'): array {
		$decoded = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/lib/Standards/tables/nl-2026.json'), true);
		$decoded['id'] = $id;
		$decoded['year'] = (int)substr($id, -4);
		$decoded['issued'] = '2026-12-01';

		return $decoded;
	}//end tables()

	/**
	 * A 2027 pack whose one step withholds the Zvw employee rate
	 * (@table.zvw.inhouding, 4.85 in the corpus), so a mistyped rate in the
	 * tables breaks its golden vector: 100000 gross nets 95150.
	 *
	 * @param int $expectedNet The net the golden vector expects, in cents.
	 * @param string $tables The tables id the pack declares.
	 *
	 * @return array<string, mixed>
	 */
	public static function pack(int $expectedNet = 95150, string $tables = 'nl-2027'): array {
		return [
			'id' => 'nl-2027',
			'jurisdiction' => 'NL',
			'taxYear' => 2027,
			'packVersion' => '1.0.0',
			'dslVersion' => '1.0',
			'tables' => $tables,
			'currency' => 'EUR',
			'grossRef' => '@input.gross',
			'inputs' => ['gross' => ['type' => 'cents', 'required' => true]],
			'bindings' => [],
			'steps' => [
				[
					'id' => 'zvw',
					'op' => 'expr',
					'incidence' => 'reduces-net',
					'expression' => '@input.gross * @table.zvw.inhouding / 100',
					'round' => ['mode' => 'nearest', 'unit' => 'cent'],
				],
			],
			'selfTest' => [
				'vectors' => [
					[
						'period' => '2027-01',
						'input' => ['gross' => 100000],
						'expected' => ['@net' => $expectedNet],
					],
				],
			],
		];
	}//end pack()

}//end class
