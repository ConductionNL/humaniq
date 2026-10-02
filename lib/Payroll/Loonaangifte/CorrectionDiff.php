<?php

/**
 * CorrectionDiff
 *
 * Compares the income relationships the Belastingdienst last received for a
 * period with the ones humaniq would report now. A relationship is keyed by
 * its BSN (or, without one, its personnel number) and its income
 * relationship number (Gegevensspecificaties 2026 p58, 0036/0037). Address
 * and name details are not compared: they need no correction back in time
 * (GS 2.4.5). A changed or new relationship is reported in full (GS 2.4.5,
 * "alle gegevens van een werknemer"); one no longer reported is withdrawn
 * (GS 2.3.2, InkomstenverhoudingIntrekking). Pure.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll\Loonaangifte
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
 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll\Loonaangifte;

use DOMDocument;
use DOMElement;

/**
 * The difference between the last received stand and the current one.
 *
 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-002
 */
final class CorrectionDiff {

	/**
	 * The relationship-level elements that are compared.
	 *
	 * @var list<string>
	 */
	private const TOP = ['DatAanv', 'DatEind', 'CdRdnEindArbov'];

	/**
	 * The relationships of a message, keyed.
	 *
	 * @param string $xml The message.
	 *
	 * @return array<string, array{numIv: string, bsn: string, persNr: string, values: array<string, string>}>
	 *
	 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-002
	 */
	public static function fromXml(string $xml): array {
		$doc = new DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$loaded = ($xml !== '' && $doc->loadXML($xml) === true);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		if ($loaded === false) {
			return [];
		}

		$relationships = [];
		foreach ($doc->getElementsByTagNameNS('*', 'InkomstenverhoudingInitieel') as $element) {
			$relationship = self::fromElement($element);
			$relationships[self::key($relationship)] = $relationship;
		}

		return $relationships;
	}//end fromXml()

	/**
	 * Apply a sent correction's TijdvakCorrectie to a stand.
	 *
	 * @param array<string, array{numIv: string, bsn: string, persNr: string, values: array<string, string>}> $relationships The stand.
	 * @param array<string, mixed>                                                                           $tree          The TijdvakCorrectie.
	 *
	 * @return array<string, array{numIv: string, bsn: string, persNr: string, values: array<string, string>}>
	 *
	 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-002
	 */
	public static function apply(array $relationships, array $tree): array {
		foreach ((array)($tree['InkomstenverhoudingInitieel'] ?? []) as $initial) {
			$relationship = self::fromTree((array)$initial);
			$relationships[self::key($relationship)] = $relationship;
		}

		foreach ((array)($tree['InkomstenverhoudingIntrekking'] ?? []) as $withdrawal) {
			$withdrawal = (array)$withdrawal;
			unset($relationships[self::key(['numIv' => (string)($withdrawal['NumIV'] ?? ''), 'bsn' => (string)($withdrawal['SofiNr'] ?? ''), 'persNr' => (string)($withdrawal['PersNr'] ?? '')])]);
		}

		return $relationships;
	}//end apply()

	/**
	 * Compare the received stand with the current lines.
	 *
	 * @param array<string, array{numIv: string, bsn: string, persNr: string, values: array<string, string>}> $received The last received stand.
	 * @param list<array<string, mixed>>                                                                      $current  The current lines, each with its tree and employeeId.
	 *
	 * @return array{lines: list<array<string, mixed>>, initial: list<array<string, mixed>>, withdrawn: list<array<string, string>>}
	 *
	 * @spec openspec/changes/filings-correction-message/specs/loonaangifte-correction/spec.md#REQ-LHC-002
	 */
	public static function compare(array $received, array $current): array {
		$out = ['lines' => [], 'initial' => [], 'withdrawn' => []];
		foreach ($current as $line) {
			$now = self::fromTree((array)($line['tree'] ?? []));
			$key = self::key($now);
			$before = ($received[$key] ?? null);
			unset($received[$key]);
			$changes = self::changes(($before['values'] ?? []), $now['values']);
			if ($before !== null && $changes === []) {
				continue;
			}

			$out['lines'][] = ['employeeId' => (string)($line['employeeId'] ?? ''), 'kind' => ($before === null ? 'added' : 'changed'), 'changes' => $changes];
			$out['initial'][] = (array)($line['tree'] ?? []);
		}

		foreach ($received as $gone) {
			$withdrawal = ['NumIV' => $gone['numIv']];
			$withdrawal += ($gone['bsn'] !== '' ? ['SofiNr' => $gone['bsn']] : ['PersNr' => $gone['persNr']]);
			$out['withdrawn'][] = $withdrawal;
			$out['lines'][] = ['employeeId' => '', 'bsn' => $gone['bsn'], 'kind' => 'withdrawn', 'changes' => []];
		}

		return $out;
	}//end compare()

	/**
	 * What changed, element by element.
	 *
	 * @param array<string, string> $before The received values.
	 * @param array<string, string> $now    The current values.
	 *
	 * @return array<string, array{old: string|null, new: string|null}>
	 */
	private static function changes(array $before, array $now): array {
		$changes = [];
		foreach (array_unique(array_merge(array_keys($before), array_keys($now))) as $element) {
			if (($before[$element] ?? null) !== ($now[$element] ?? null)) {
				$changes[$element] = ['old' => ($before[$element] ?? null), 'new' => ($now[$element] ?? null)];
			}
		}

		return $changes;
	}//end changes()

	/**
	 * A relationship from its element tree (IncomeRelationshipLine).
	 *
	 * @param array<string, mixed> $tree The tree.
	 *
	 * @return array{numIv: string, bsn: string, persNr: string, values: array<string, string>}
	 */
	private static function fromTree(array $tree): array {
		$values = [];
		foreach (self::TOP as $element) {
			if (($tree[$element] ?? null) !== null) {
				$values[$element] = (string)$tree[$element];
			}
		}

		$period = (array)(($tree['Inkomstenperiode'] ?? [])[0] ?? []);
		foreach ($period as $element => $value) {
			if ($value !== null) {
				$values['Inkomstenperiode/' . $element] = (string)$value;
			}
		}

		foreach ((array)($tree['Werknemersgegevens'] ?? []) as $element => $value) {
			if ($value !== null) {
				$values[(string)$element] = (string)$value;
			}
		}

		return ['numIv' => (string)($tree['NumIV'] ?? ''), 'bsn' => (string)($tree['NatuurlijkPersoon']['SofiNr'] ?? ''), 'persNr' => (string)($tree['PersNr'] ?? ''), 'values' => $values];
	}//end fromTree()

	/**
	 * A relationship from its XML element.
	 *
	 * @param DOMElement $element The InkomstenverhoudingInitieel element.
	 *
	 * @return array{numIv: string, bsn: string, persNr: string, values: array<string, string>}
	 */
	private static function fromElement(DOMElement $element): array {
		$tree = ['Inkomstenperiode' => [[]], 'Werknemersgegevens' => [], 'NatuurlijkPersoon' => []];
		foreach ($element->childNodes as $child) {
			if ($child instanceof DOMElement === false) {
				continue;
			}

			$name = $child->localName;
			if ($name === 'Inkomstenperiode' && $tree['Inkomstenperiode'][0] === []) {
				$tree['Inkomstenperiode'][0] = self::children($child);
			} else if ($name === 'Werknemersgegevens' || $name === 'NatuurlijkPersoon') {
				$tree[$name] = self::children($child);
			} else if ($child->childElementCount === 0) {
				$tree[$name] = $child->textContent;
			}
		}

		return self::fromTree($tree);
	}//end fromElement()

	/**
	 * The text children of an element.
	 *
	 * @param DOMElement $element The element.
	 *
	 * @return array<string, string>
	 */
	private static function children(DOMElement $element): array {
		$values = [];
		foreach ($element->childNodes as $child) {
			if ($child instanceof DOMElement === true && $child->childElementCount === 0) {
				$values[$child->localName] = $child->textContent;
			}
		}

		return $values;
	}//end children()

	/**
	 * The key of a relationship.
	 *
	 * @param array{numIv: string, bsn: string, persNr: string, values?: array<string, string>} $relationship The relationship.
	 *
	 * @return string
	 */
	private static function key(array $relationship): string {
		$who = ($relationship['bsn'] !== '' ? 'B' . $relationship['bsn'] : 'P' . $relationship['persNr']);
		return $who . '#' . $relationship['numIv'];
	}//end key()

}//end class
