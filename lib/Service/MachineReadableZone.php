<?php

/**
 * Humaniq MachineReadableZone
 *
 * Reads the machine-readable zone of a passport (TD3, two lines of 44) or an
 * identity or residence card (TD1, three lines of 30) and verifies the ICAO
 * 9303 check digits of the document number, birth date and expiry date
 * (people-dossier-completeness D4). It answers the document type, the
 * nationality and the expiry date; the document number never leaves it.
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
 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Reads a machine-readable zone.
 *
 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-003
 */
class MachineReadableZone {

	/**
	 * The ICAO 9303 check digit of a zone field (weights 7, 3, 1).
	 *
	 * @param string $field The field as printed in the zone.
	 *
	 * @return string One digit.
	 *
	 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-003
	 */
	public function checkDigit(string $field): string {
		$weights = [7, 3, 1];
		$sum = 0;
		foreach (str_split(strtoupper($field)) as $index => $char) {
			$sum += ($this->charValue(char: $char) * $weights[$index % 3]);
		}

		return (string)($sum % 10);
	}//end checkDigit()

	/**
	 * Read a TD3 (passport, two lines of 44) or TD1 (card, three lines of 30) zone.
	 *
	 * @param array<int, string> $lines The zone lines.
	 *
	 * @return array{error: string|null, documentType: string, nationality: string, documentExpiry: string}
	 *
	 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-003
	 */
	public function read(array $lines): array {
		$out = ['error' => null, 'documentType' => '', 'nationality' => '', 'documentExpiry' => ''];
		$zone = $this->zoneFields(lines: $lines);
		if ($zone === null) {
			$out['error'] = 'The machine-readable zone could not be read: expected two lines of 44 or three lines of 30 characters.';
			return $out;
		}

		foreach ($zone['fields'] as $name => [$value, $digit]) {
			if ($this->checkDigit(field: $value) !== $digit) {
				$out['error'] = 'A check digit in the machine-readable zone is wrong (' . $name . ').';
				return $out;
			}
		}

		$out['documentType'] = $zone['type'];
		$out['nationality'] = rtrim($zone['nationality'], '<');
		$out['documentExpiry'] = $this->zoneDate(value: $zone['fields']['expiry date'][0]);

		return $out;
	}//end read()

	/**
	 * The checked fields, type and nationality of a TD3 or TD1 zone, or null for any other shape.
	 *
	 * @param array<int, string> $lines The zone lines.
	 *
	 * @return array{fields: array<string, array{0: string, 1: string}>, type: string, nationality: string}|null
	 */
	private function zoneFields(array $lines): ?array {
		if (count($lines) === 2 && strlen($lines[0]) === 44 && strlen($lines[1]) === 44) {
			$line = $lines[1];
			return [
				'fields' => [
					'document number' => [substr($line, 0, 9), $line[9]],
					'birth date' => [substr($line, 13, 6), $line[19]],
					'expiry date' => [substr($line, 21, 6), $line[27]],
				],
				'type' => 'paspoort',
				'nationality' => substr($line, 10, 3),
			];
		}

		if (count($lines) === 3 && strlen($lines[0]) === 30 && strlen($lines[1]) === 30) {
			return [
				'fields' => [
					'document number' => [substr($lines[0], 5, 9), $lines[0][14]],
					'birth date' => [substr($lines[1], 0, 6), $lines[1][6]],
					'expiry date' => [substr($lines[1], 8, 6), $lines[1][14]],
				],
				'type' => $this->cardType(code: substr($lines[0], 0, 2)),
				'nationality' => substr($lines[1], 15, 3),
			];
		}

		return null;
	}//end zoneFields()

	/**
	 * The document type a TD1 card code names: I-R (residence) or an ID card.
	 *
	 * @param string $code The first two characters of the card zone.
	 *
	 * @return string The documentType value.
	 */
	private function cardType(string $code): string {
		if ($code === 'IR' || $code === 'AR' || $code === 'CR') {
			return 'verblijfsdocument';
		}

		return 'identiteitskaart';
	}//end cardType()

	/**
	 * An expiry date in the zone (YYMMDD), always in this century.
	 *
	 * @param string $value Six digits.
	 *
	 * @return string Y-m-d, or '' when it is not a date.
	 */
	private function zoneDate(string $value): string {
		if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $value, $parts) !== 1 || checkdate((int)$parts[2], (int)$parts[3], (2000 + (int)$parts[1])) === false) {
			return '';
		}

		return '20' . $parts[1] . '-' . $parts[2] . '-' . $parts[3];
	}//end zoneDate()

	/**
	 * The numeric value of one zone character.
	 *
	 * @param string $char One character.
	 *
	 * @return int
	 */
	private function charValue(string $char): int {
		if (ctype_digit($char) === true) {
			return (int)$char;
		}

		if (ctype_upper($char) === true) {
			return (ord($char) - 55);
		}

		return 0;
	}//end charValue()

}//end class
