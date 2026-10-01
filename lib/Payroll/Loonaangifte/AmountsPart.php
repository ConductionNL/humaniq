<?php

/**
 * AmountsPart
 *
 * The Werknemersgegevens group of an income relationship (Gegevensspecificaties
 * 2026 p102-138): what was withheld and paid from the payslip, the premium
 * components and their bases from the engine's recalculation, the hours
 * and the contract wage (filings-wage-tax-message D5).
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
 * The amounts of an income relationship.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) PeriodPart::fourWeekly is a pure check on the same line.
 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
 */
final class AmountsPart {

	/**
	 * The amounts of Werknemersgegevens in the XSD's order, all in euros with cents.
	 *
	 * @var list<string>
	 */
	public const AMOUNTS = [
		'LnLbPh', 'LnSV', 'PrlnAofAnwLg', 'PrlnAofAnwHg', 'PrlnAofAnwUit', 'PrlnWhkAnw', 'PrlnAwfAnwLg', 'PrlnAwfAnwHg',
		'PrlnAwfAnwHz', 'PrlnAwfAnwUit', 'PrLnUfo', 'LnTabBB', 'VakBsl', 'OpgRchtVakBsl', 'OpnAvwb', 'OpbAvwb', 'LnInGld',
		'WrdLn', 'LnOwrk', 'VerstrAanv', 'IngLbPh', 'PrAofLg', 'PrAofHg', 'PrAofUit', 'OpslWko', 'PrGediffWhk', 'PrAwfLg',
		'PrAwfHg', 'PrAwfHz', 'PrAwfUit', 'PrUFO', 'BijdrZvw', 'WghZvw', 'WrdPrGebrAut', 'WrknBijdrAut', 'Reisk', 'VerrArbKrt',
	];

	/**
	 * The amounts in cents (design D5), recording what cannot be reported.
	 *
	 * @param LineFacts $facts The line.
	 * @param string    $code  The income code.
	 *
	 * @return array<string, int>
	 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public static function cents(LineFacts $facts, string $code): array {
		self::checkReproduced($facts);
		self::checkUnsupported($facts);
		$zvw = $facts->money('zvw');

		return array_merge(self::bases($facts, ($code !== '17' && $facts->insured() === true)), self::premiums($facts), [
			'OpgRchtVakBsl' => $facts->money('vakantiegeldReserved'),
			'LnInGld' => $facts->money('grossPay'),
			'LnOwrk' => $facts->money('overtimePay'),
			'IngLbPh' => $facts->money('loonheffing'),
			'BijdrZvw' => ($facts->withheldZvw() === true ? $zvw : 0),
			'WghZvw' => ($facts->withheldZvw() === true ? 0 : $zvw),
			'VerrArbKrt' => $facts->money('arbeidskorting'),
		]);
	}//end cents()

	/**
	 * The Werknemersgegevens group from the amounts.
	 *
	 * @param LineFacts          $facts The line.
	 * @param string             $code  The income code.
	 * @param array<string, int> $cents The amounts.
	 *
	 * @return array<string, string|null>
	 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public static function group(LineFacts $facts, string $code, array $cents): array {
		$data = [];
		foreach (self::AMOUNTS as $element) {
			$data[$element] = self::euros($cents[$element] ?? 0);
		}

		$contracted = in_array($code, ['11', '13', '15', '17'], true);
		$data['AantVerlU'] = (string)self::paidHours($facts);
		$data['Ctrctln'] = $contracted === true ? self::euros(self::contractWageCents($facts)) : null;
		$data['AantCtrcturenPWk'] = $contracted === true ? self::hours($facts->contractHours()) : null;
		$data['BedrRntKstvPersl'] = '0.00';

		return $data;
	}//end group()

	/**
	 * The wages and premium bases: Loon LB/PH, Loon SV and the Aof, Whk and
	 * AWf bases in the low or the high column (GS p103-118).
	 *
	 * @param LineFacts $facts   The line.
	 * @param bool      $insured Whether the employee insurances apply.
	 *
	 * @return array<string, int>
	 */
	private static function bases(LineFacts $facts, bool $insured): array {
		$taxable = ($facts->result->taxableWageCents ?? 0);
		$base = ($insured === true ? ($facts->result->premiumWageCents ?? 0) : 0);
		$aofLow = self::aofLow($facts);
		$awfLow = self::awfLow($facts);

		return [
			'LnLbPh' => $taxable,
			'LnSV' => ($insured === true ? $taxable : 0),
			'PrlnAofAnwLg' => ($aofLow === true ? $base : 0),
			'PrlnAofAnwHg' => ($aofLow === true ? 0 : $base),
			'PrlnWhkAnw' => $base,
			'PrlnAwfAnwLg' => ($awfLow === true ? $base : 0),
			'PrlnAwfAnwHg' => ($awfLow === true ? 0 : $base),
		];
	}//end bases()

	/**
	 * The premiums from the recalculation, in their column (GS p124-131).
	 *
	 * @param LineFacts $facts The line.
	 *
	 * @return array<string, int>
	 */
	private static function premiums(LineFacts $facts): array {
		$aof = ($facts->result->aofCents ?? 0);
		$awf = ($facts->result->awfCents ?? 0);
		$aofLow = self::aofLow($facts);
		$awfLow = self::awfLow($facts);

		return [
			'PrAofLg' => ($aofLow === true ? $aof : 0),
			'PrAofHg' => ($aofLow === true ? 0 : $aof),
			'OpslWko' => ($facts->result->wkoCents ?? 0),
			'PrGediffWhk' => ($facts->result->whkCents ?? 0),
			'PrAwfLg' => ($awfLow === true ? $awf : 0),
			'PrAwfHg' => ($awfLow === true ? 0 : $awf),
		];
	}//end premiums()

	/**
	 * Whether the Aof premium is the low one (the employer's class in the engine input).
	 *
	 * @param LineFacts $facts The line.
	 *
	 * @return bool
	 */
	private static function aofLow(LineFacts $facts): bool {
		return (($facts->snapshot()['aofTariff'] ?? 'laag') !== 'hoog');
	}//end aofLow()

	/**
	 * Whether the AWf premium is the low one (the payslip's rate, else the engine input's).
	 *
	 * @param LineFacts $facts The line.
	 *
	 * @return bool
	 */
	private static function awfLow(LineFacts $facts): bool {
		return ((string)($facts->payslip['awfTariff'] ?? $facts->snapshot()['awfTariff'] ?? 'low') !== 'high');
	}//end awfLow()

	/**
	 * The message must report what was paid: a payslip the engine no longer
	 * reproduces is refused (design D5).
	 *
	 * @param LineFacts $facts The line.
	 *
	 * @return void
	 */
	private static function checkReproduced(LineFacts $facts): void {
		if ($facts->result === null) {
			$facts->find('payslip-not-reproducible', 'IngLbPh', 'De loonstrook van ' . $facts->name() . ' kon niet opnieuw worden berekend uit de vastgelegde invoer; bereken de loonrun opnieuw.');
			return;
		}

		if ($facts->result->loonheffingCents !== $facts->money('loonheffing')) {
			$facts->find('payslip-not-reproducible', 'IngLbPh', 'De ingehouden loonheffing op de loonstrook van ' . $facts->name() . ' wijkt af van de herberekening; bereken de loonrun opnieuw.');
		}
	}//end checkReproduced()

	/**
	 * What the message cannot report without a guess yet.
	 *
	 * @param LineFacts $facts The line.
	 *
	 * @return void
	 */
	private static function checkUnsupported(LineFacts $facts): void {
		if ($facts->money('bijtelling') > 0) {
			$facts->find('company-car-not-supported', 'WrdPrGebrAut', 'Voor ' . $facts->name() . ' is een bijtelling auto verloond; de waarde vóór eigen bijdrage is niet vastgelegd, dus het bericht kan dit nog niet aangeven.');
		}

		if ($facts->money('retroAdjustment') !== 0) {
			$facts->find('retro-adjustment-elsewhere', 'IngLbPh', 'De loonstrook van ' . $facts->name() . ' verrekent een correctie over een eerder tijdvak; die hoort in een correctiebericht.', 'warning');
		}

		if ($facts->filled('publicSectorRegime') !== null) {
			$facts->find('public-sector-ufo', 'PrUFO', $facts->name() . ' is overheidspersoneel; de Ufo-premie wordt door de loonrun niet berekend, controleer de AWf- en Ufo-premie.', 'warning');
		}
	}//end checkUnsupported()

	/**
	 * The paid hours, half an hour or more rounding up (GS p135-136).
	 *
	 * @param LineFacts $facts The line.
	 *
	 * @return int
	 */
	private static function paidHours(LineFacts $facts): int {
		foreach (['hoursPaid', 'hoursWorked'] as $field) {
			if (is_numeric($facts->payslip[$field] ?? null) === true && (float)$facts->payslip[$field] > 0.0) {
				return (int)round((float)$facts->payslip[$field], 0, PHP_ROUND_HALF_UP);
			}
		}

		$weeks = (PeriodPart::fourWeekly($facts) === true) ? 4.0 : (52 / 12);
		return (int)round($facts->contractHours() * $weeks, 0, PHP_ROUND_HALF_UP);
	}//end paidHours()

	/**
	 * The contract wage (GS p136): the agreed monthly salary, else the hourly
	 * wage times the contracted hours of a month.
	 *
	 * @param LineFacts $facts The line.
	 *
	 * @return int
	 */
	private static function contractWageCents(LineFacts $facts): int {
		$salary = ($facts->employee['grossMonthlySalary'] ?? null);
		if (is_numeric($salary) === true && (float)$salary > 0.0) {
			return (int)round((float)$salary * 100);
		}

		$hourly = is_numeric($facts->contract['hourlyWage'] ?? null) === true ? (float)$facts->contract['hourlyWage'] : 0.0;
		return (int)round($hourly * $facts->contractHours() * 52 / 12 * 100);
	}//end contractWageCents()

	/**
	 * Cents as euros with two decimals.
	 *
	 * @param int $cents The amount.
	 *
	 * @return string
	 */
	private static function euros(int $cents): string {
		return number_format($cents / 100, 2, '.', '');
	}//end euros()

	/**
	 * Hours per week in the Aant format (GS p137): up to two decimals.
	 *
	 * @param float $hours The hours.
	 *
	 * @return string
	 */
	private static function hours(float $hours): string {
		$text = rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
		return $text === '' ? '0' : $text;
	}//end hours()

}//end class
