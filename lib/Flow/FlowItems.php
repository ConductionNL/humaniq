<?php

/**
 * Humaniq FlowItems
 *
 * Place a step's outcome on every item that passes through a flow step.
 *
 * @category Flow
 * @package  OCA\Humaniq\Flow
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
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Flow;

/**
 * The item loop shared by the steps that run once per run and pass one
 * outcome on to every item.
 *
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-002
 */
final class FlowItems {

	/**
	 * Merge the fields into the record (`json`) of every item; a run without
	 * items gets one empty item, so the outcome still reaches the next step.
	 *
	 * @param array<int, mixed>    $items  The input items.
	 * @param array<string, mixed> $fields The outcome, keyed by the step's outcome key.
	 *
	 * @return array<int, mixed> The items out, each carrying the outcome.
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-002
	 */
	public static function stampEach(array $items, array $fields): array {
		$out = [];
		foreach (($items === [] ? [['json' => []]] : $items) as $item) {
			$item = (array)$item;
			$json = (array)($item['json'] ?? []);
			foreach ($fields as $key => $value) {
				$json[$key] = $value;
			}

			$item['json'] = $json;
			$out[] = $item;
		}

		return $out;
	}//end stampEach()

	/**
	 * The outcome of a step that acted on a list of records.
	 *
	 * @param array<int, string> $ids The ids acted on.
	 *
	 * @return array{ids: array<int, string>, count: int}
	 *
	 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-002
	 */
	public static function idsAndCount(array $ids): array {
		return ['ids' => $ids, 'count' => count($ids)];
	}//end idsAndCount()
}//end class
