<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Inputs of the asset dialect golden: Dutch, current, unknown and missing
 * category and status values, renamed fields with and without a clash, and
 * value types the mapper normalises.
 */

declare(strict_types=1);

/**
 * The golden rows, keyed by name.
 *
 * @return array<string, array<string, mixed>>
 */
function assetDialectRows(): array {
	return [
		'all dutch'         => ['id' => 'a1', 'category' => 'voertuig', 'status' => 'uitgegeven', 'kenteken' => 'AB-12-CD', 'aanschafdatum' => '2024-01-02', 'aanschafwaarde' => '1250.50'],
		'already current'   => ['id' => 'a2', 'category' => 'laptop', 'status' => 'available', 'serialNumber' => 'SN1', 'purchaseValue' => 900],
		'status only'       => ['id' => 'a3', 'category' => 'phone', 'status' => 'afgeschreven'],
		'category only'     => ['id' => 'a4', 'category' => 'kleding', 'status' => 'issued'],
		'unknown category'  => ['id' => 'a5', 'category' => 'meubel', 'status' => 'ingenomen'],
		'unknown status'    => ['id' => 'a6', 'category' => 'gereedschap', 'status' => 'kwijt'],
		'both unknown'      => ['id' => 'a7', 'category' => 'meubel', 'status' => 'kwijt', 'serienummer' => 'X'],
		'rename clash'      => ['id' => 'a8', 'category' => 'tool', 'serienummer' => 'OLD', 'serialNumber' => 'NEW'],
		'clash after unknown' => ['id' => 'a13', 'category' => 'meubel', 'status' => 'kwijt', 'serienummer' => 'OLD', 'serialNumber' => 'NEW'],
		'rename same value' => ['id' => 'a9', 'serienummer' => 'SAME', 'serialNumber' => 'SAME'],
		'missing values'    => ['id' => 'a10'],
		'null values'       => ['id' => 'a11', 'category' => null, 'status' => null, 'aanschafwaarde' => null],
		'padded values'     => ['id' => 'a12', 'category' => ' Toegangspas ', 'status' => 'BESCHIKBAAR', 'purchaseValue' => '12,50'],
	];
}//end assetDialectRows()
