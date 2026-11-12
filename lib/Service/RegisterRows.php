<?php

/**
 * Register Rows
 *
 * Normalises what OpenRegister's ObjectService returns into plain row arrays
 * (an array as it is, an entity through jsonSerialize() or getObject(),
 * anything else as an empty row) and finds a row by its `id` or `@self.id`.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Plain-array reading of register rows.
 *
 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-006
 */
final class RegisterRows {

	/**
	 * Every row of a findAll() answer as a plain array; no rows when the answer is not a list.
	 *
	 * @param mixed $rows The findAll() answer.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-006
	 */
	public static function fromRows(mixed $rows): array {
		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			$out[] = self::fromRow($row);
		}

		return $out;

	}//end fromRows()

	/**
	 * Normalise an ObjectService row (entity or array) to a plain array.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-006
	 */
	public static function fromRow(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			return (array)$row->jsonSerialize();
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			return (array)$row->getObject();
		}

		return [];

	}//end fromRow()

	/**
	 * The first row whose id is the given id, or null.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows.
	 * @param string                           $id   The object id (uuid).
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-006
	 */
	public static function byId(array $rows, string $id): ?array {
		foreach ($rows as $row) {
			if (self::idOf($row) === $id) {
				return $row;
			}
		}

		return null;

	}//end byId()

	/**
	 * A row's id, from `id` or `@self.id`, or '' when it has none.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/payroll-core-engine/spec.md#REQ-PCE-006
	 */
	public static function idOf(array $row): string {
		return (string)($row['id'] ?? $row['@self']['id'] ?? '');

	}//end idOf()
}//end class
