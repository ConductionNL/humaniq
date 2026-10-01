<?php

/**
 * PeriodPart
 *
 * The Inkomstenperiode group of an income relationship (Gegevensspecificaties
 * 2026 p77-99): the income code, the relationship kind, the contract
 * indicators, the tax table and the insurance codes.
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
 * The income period of an income relationship.
 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
 */
final class PeriodPart {

	/**
	 * The income codes for which the contract indicators, end reason and contract wage are reported (GS p65, p84-86).
	 *
	 * @var list<string>
	 */
	public const EMPLOYMENT_CODES = ['11', '13', '15'];

	/**
	 * The relationship kinds that carry the three contract indicators (GS p84-86, 2213-2215).
	 *
	 * @var list<string>
	 */
	private const INDICATOR_KINDS = ['1', '11', '21', '22', '23', '24', '82', '83'];

	/**
	 * The Inkomstenperiode group.
	 *
	 * @param LineFacts $facts The line.
	 * @param string    $code  The income code.
	 * @param string    $start The start of the income relationship.
	 *
	 * @return array<string, string|null>
	 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public static function make(LineFacts $facts, string $code, string $start): array {
		$kind = self::relationshipKind($facts, $code);
		$insured = ($code !== '17' && $facts->insured() === true) ? 'J' : 'N';
		$korting = (($facts->snapshot()['loonheffingskortingToegepast'] ?? false) === true && $facts->anonymous() === false);

		return array_merge(
			['DatAanv' => max($start, $facts->period[0]), 'SrtIV' => $code, 'CdAard' => $kind],
			self::indicators($facts, $code, $kind),
			[
				'IndLhKort' => ($korting === true ? 'J' : 'N'),
				'LbTab' => self::table($facts),
				'IndWAO' => $insured,
				'IndWW' => $insured,
				'IndZW' => $insured,
				'CdZvw' => ($facts->withheldZvw() === true ? 'M' : 'K'),
			]
		);
	}//end make()

	/**
	 * The income code (GS p78): 17 for a DGA not insured for the employee
	 * insurances, 11 for a civil servant, else 15.
	 *
	 * @param LineFacts $facts The line.
	 *
	 * @return string
	 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public static function incomeCode(LineFacts $facts): string {
		if (($facts->employee['isDga'] ?? false) === true && $facts->insured() === false) {
			return '17';
		}

		return $facts->filled('publicSectorRegime') !== null ? '11' : '15';
	}//end incomeCode()

	/**
	 * Whether the wage period is four weeks (`YYYY-Pnn`).
	 *
	 * @param LineFacts $facts The line.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-002
	 */
	public static function fourWeekly(LineFacts $facts): bool {
		return preg_match('/-P\d{2}$/', (string)($facts->payslip['period'] ?? '')) === 1;
	}//end fourWeekly()

	/**
	 * The relationship kind (GS p79-80); not sent with income code 17 (2218).
	 *
	 * @param LineFacts $facts The line.
	 * @param string    $code  The income code.
	 *
	 * @return string|null
	 */
	private static function relationshipKind(LineFacts $facts, string $code): ?string {
		if ($code === '17') {
			return null;
		}

		if (($facts->employee['publicSectorRegime'] ?? null) === 'ambtenarenwet') {
			return '18';
		}

		$kinds = ['bbl' => '83', 'agency' => '11'];
		return ($kinds[(string)($facts->contract['type'] ?? '')] ?? '1');
	}//end relationshipKind()

	/**
	 * The three contract indicators, sent only for 11/13/15 with an indicator kind (GS p84-86).
	 *
	 * @param LineFacts   $facts The line.
	 * @param string      $code  The income code.
	 * @param string|null $kind  The relationship kind.
	 *
	 * @return array<string, string|null>
	 */
	private static function indicators(LineFacts $facts, string $code, ?string $kind): array {
		if (in_array($code, self::EMPLOYMENT_CODES, true) === false || in_array((string)$kind, self::INDICATOR_KINDS, true) === false) {
			return ['IndArbovOnbepTd' => null, 'IndSchriftArbov' => null, 'IndOprov' => null];
		}

		$onCall = ((string)($facts->contract['type'] ?? '') === 'oproep' || $facts->contractHours() === 0.0);
		return [
			'IndArbovOnbepTd' => (trim((string)($facts->contract['endDate'] ?? '')) === '' ? 'J' : 'N'),
			'IndSchriftArbov' => (($facts->contract['writtenContract'] ?? false) === true ? 'J' : 'N'),
			'IndOprov' => ($onCall === true ? 'J' : 'N'),
		];
	}//end indicators()

	/**
	 * The tax table code (GS p93-94): 940 for the anonymous rate, else
	 * 0 + colour + wage period.
	 *
	 * @param LineFacts $facts The line.
	 *
	 * @return string
	 */
	private static function table(LineFacts $facts): string {
		if ($facts->anonymous() === true) {
			return '940';
		}

		$colour = (($facts->snapshot()['taxTableColor'] ?? 'wit') === 'groen') ? '2' : '1';
		return '0' . $colour . (self::fourWeekly($facts) === true ? '4' : '2');
	}//end table()

}//end class
