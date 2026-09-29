<?php

/**
 * BRP Person Mapper
 *
 * Maps a Haal Centraal BRP Personen bevragen answer onto the Employee fields
 * (people-register-prefill D2): name, date of birth and the residential
 * address. The last name carries the prefix (`van Dijk`); the house number
 * carries the letter and the addition (`12a-2`); the postcode is written as
 * `1234 AB`.
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
 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * BRP person to Employee fields.
 *
 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-002
 */
class BrpPersonMapper {

	/**
	 * The Employee fields the BRP answers for one person.
	 *
	 * @param array<string, mixed> $person One entry of the answer's `personen`.
	 *
	 * @return array<string, string>
	 *
	 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-002
	 */
	public function map(array $person): array {
		$name = (is_array($person['naam'] ?? null) === true ? $person['naam'] : []);
		$birth = ($person['geboorte']['datum'] ?? []);
		$address = ($person['verblijfplaats']['verblijfadres'] ?? []);
		$address = (is_array($address) === true ? $address : []);

		$fields = [
			'firstName' => trim((string)($name['voornamen'] ?? '')),
			'lastName' => trim(trim((string)($name['voorvoegsel'] ?? '')) . ' ' . trim((string)($name['geslachtsnaam'] ?? ''))),
			'dateOfBirth' => (is_array($birth) === true && ($birth['type'] ?? '') === 'Datum' ? (string)($birth['datum'] ?? '') : ''),
			'straat' => trim((string)($address['officieleStraatnaam'] ?? ($address['korteStraatnaam'] ?? ''))),
			'huisnummer' => $this->houseNumber($address),
			'postcode' => $this->postcode((string)($address['postcode'] ?? '')),
			'woonplaats' => trim((string)($address['woonplaats'] ?? '')),
		];

		return array_filter($fields, static fn (string $value): bool => $value !== '');
	}//end map()

	/**
	 * The house number with its letter and addition.
	 *
	 * @param array<string, mixed> $address The residential address.
	 *
	 * @return string
	 */
	private function houseNumber(array $address): string {
		$number = trim((string)($address['huisnummer'] ?? ''));
		if ($number === '') {
			return '';
		}

		$addition = trim((string)($address['huisnummertoevoeging'] ?? ''));

		return $number . trim((string)($address['huisletter'] ?? '')) . ($addition === '' ? '' : '-' . $addition);
	}//end houseNumber()

	/**
	 * A Dutch postcode as `1234 AB`; anything else as given.
	 *
	 * @param string $postcode The postcode.
	 *
	 * @return string
	 */
	private function postcode(string $postcode): string {
		$compact = strtoupper(str_replace(' ', '', trim($postcode)));
		if (preg_match('/^\d{4}[A-Z]{2}$/', $compact) !== 1) {
			return trim($postcode);
		}

		return substr($compact, 0, 4) . ' ' . substr($compact, 4);
	}//end postcode()

}//end class
