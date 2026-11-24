<?php

/**
 * Receipt File Reference
 *
 * Reads an Expense's `receiptFile` into the one reference receipt extraction
 * passes on: a file id, or a Files path.
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
 * @spec openspec/changes/self-service-mobile/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The receipt reference of an Expense.
 *
 * `receiptFile` is a file property: OpenRegister stores the photo or PDF as a
 * file id and renders it as the file object (`id`, `path`, ...). A Files path
 * string saved before that change is kept as sent.
 *
 * @spec openspec/changes/self-service-mobile/tasks.md#task-2.2
 */
final class ReceiptFileReference {

	/**
	 * The reference held by a `receiptFile` value: the file id as a string of
	 * digits, a path, or '' when the value holds no receipt.
	 *
	 * @param mixed $value The `receiptFile` value: null, an int id, a string, or a rendered file object.
	 *
	 * @return string The trimmed reference, or '' for none.
	 *
	 * @spec openspec/changes/self-service-mobile/tasks.md#task-2.2
	 */
	public static function of(mixed $value): string {
		if (is_int($value) === true) {
			return $value > 0 ? (string)$value : '';
		}

		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_array($value) === false) {
			return '';
		}

		$id = $value['id'] ?? $value['fileId'] ?? null;
		if (is_int($id) === true || (is_string($id) === true && ctype_digit(trim($id)) === true)) {
			return self::of(is_int($id) === true ? $id : trim($id));
		}

		$path = $value['path'] ?? null;
		return is_string($path) === true ? trim($path) : '';
	}//end of()
}//end class
