<?php

/**
 * PersonPart
 *
 * The NatuurlijkPersoon group of an income relationship (Gegevensspecificaties
 * 2026 p65-73): BSN with the elfproef, initials, surname, birth date and,
 * when complete, the Dutch address.
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
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll\Loonaangifte;


/**
 * The person of an income relationship.
 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
 */
final class PersonPart {

	/**
	 * The NatuurlijkPersoon group, recording what is missing.
	 *
	 * @param LineFacts $facts The line.
	 *
	 * @return array<string, mixed>
	 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public static function make(LineFacts $facts): array {
		$bsn = $facts->filled('bsn');
		$bsn = ($bsn === null ? null : str_pad($bsn, 9, '0', STR_PAD_LEFT));
		self::check($facts, $bsn);

		return [
			'SofiNr' => $bsn,
			'Voorl' => self::initials((string)($facts->employee['firstName'] ?? '')),
			'SignNm' => $facts->filled('lastName'),
			'Gebdat' => $facts->filled('dateOfBirth'),
			'AdresBinnenland' => self::address($facts),
		];
	}//end make()

	/**
	 * Whether a nine-digit number passes the elfproef of GS p34 (0014.1) and p66 (0045).
	 *
	 * @param string $number The number.
	 *
	 * @return bool
	 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public static function elfproef(string $number): bool {
		if (preg_match('/^\d{9}$/', $number) !== 1 || substr($number, 0, 3) === '000') {
			return false;
		}

		$sum = 0;
		for ($i = 0; $i < 8; $i++) {
			$sum += ((9 - $i) * (int)$number[$i]);
		}

		return ($sum % 11) === (int)$number[8];
	}//end elfproef()

	/**
	 * Record what the person lacks (GS p66-69: 2287, 0045, 0046, 0047).
	 *
	 * @param LineFacts   $facts The line.
	 * @param string|null $bsn   The padded BSN.
	 *
	 * @return void
	 */
	private static function check(LineFacts $facts, ?string $bsn): void {
		if ($bsn === null) {
			if ($facts->anonymous() === false) {
				$facts->find('employee-without-bsn', 'SofiNr', $facts->name() . ' heeft geen burgerservicenummer; zonder BSN mag alleen het anoniementarief (tabel 940) worden aangegeven.');
			}

			return;
		}

		if (self::elfproef($bsn) === false) {
			$facts->find('employee-bsn-invalid', 'SofiNr', 'Het burgerservicenummer van ' . $facts->name() . ' voldoet niet aan de elfproef.');
		}

		if ($facts->filled('lastName') === null) {
			$facts->find('employee-without-last-name', 'SignNm', $facts->name() . ' heeft geen achternaam.');
		}

		if ($facts->filled('dateOfBirth') === null) {
			$facts->find('employee-without-date-of-birth', 'Gebdat', $facts->name() . ' heeft geen geboortedatum.');
		}
	}//end check()

	/**
	 * The Dutch address when every required part is there; otherwise omitted (it is optional).
	 *
	 * @param LineFacts $facts The line.
	 *
	 * @return array<string, string|null>|null
	 */
	private static function address(LineFacts $facts): ?array {
		$postcode = strtoupper(str_replace(' ', '', (string)($facts->employee['postcode'] ?? '')));
		$street = $facts->filled('straat');
		$place = $facts->filled('woonplaats');
		$country = strtoupper((string)($facts->employee['land'] ?? 'NL'));
		if ($street === null || $place === null || preg_match('/^[1-9]\d{3}[A-Z]{2}$/', $postcode) !== 1 || in_array($country, ['', 'NL', 'NEDERLAND'], true) === false) {
			return null;
		}

		preg_match('/^\s*(\d{1,5})\s*[-\s]?\s*(.{0,4})/', (string)($facts->employee['huisnummer'] ?? ''), $number);
		$houseNumber = ((int)($number[1] ?? 0) > 0) ? (string)(int)$number[1] : null;
		$addition = trim((string)($number[2] ?? ''));

		return [
			'Str' => mb_substr($street, 0, 24),
			'HuisNr' => $houseNumber,
			'HuisNrToev' => ($houseNumber !== null && $addition !== '') ? $addition : null,
			'Pc' => $postcode,
			'Woonpl' => mb_substr($place, 0, 24),
		];
	}//end address()

	/**
	 * The initials of the given names, at most six (GS p67).
	 *
	 * @param string $firstNames The given names.
	 *
	 * @return string|null
	 */
	private static function initials(string $firstNames): ?string {
		$initials = '';
		foreach (preg_split('/[\s.\-]+/u', $firstNames, -1, PREG_SPLIT_NO_EMPTY) as $name) {
			$initials .= mb_strtoupper(mb_substr($name, 0, 1));
		}

		return $initials === '' ? null : mb_substr($initials, 0, 6);
	}//end initials()

}//end class
