<?php

/**
 * LoonaangifteMessageBuilder
 *
 * Assembles the wage tax return from its income relationships: the message
 * header (GS p30-35), the declaration period (GS p35-37) and the collective
 * part (GS p38-55), each collective amount the sum of the unrounded
 * employee amounts cut to whole euros in the employer's favour (GS p38,
 * 0318), TotTeBet per condition 2315 and TotGen equal to it (0011). Pure:
 * the service supplies the data and stores the result.
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
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll\Loonaangifte;

/**
 * The wage tax return's header, period and collective part.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) PersonPart::elfproef is a pure check.
 *
 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
 */
final class LoonaangifteMessageBuilder {

	/**
	 * Each collective amount and the employee amount it sums, in the XSD's
	 * order (CollectieveAangifteType). The optional final levies and
	 * reductions are not sent: humaniq calculates none of them.
	 *
	 * @var array<string, string>
	 */
	public const COLLECTIVE = [
		'TotLnLbPh' => 'LnLbPh',
		'TotLnSV' => 'LnSV',
		'TotPrlnAofAnwLg' => 'PrlnAofAnwLg',
		'TotPrlnAofAnwHg' => 'PrlnAofAnwHg',
		'TotPrlnAofAnwUit' => 'PrlnAofAnwUit',
		'TotPrlnWhkAnw' => 'PrlnWhkAnw',
		'TotPrlnAwfAnwLg' => 'PrlnAwfAnwLg',
		'TotPrlnAwfAnwHg' => 'PrlnAwfAnwHg',
		'TotPrlnAwfAnwHz' => 'PrlnAwfAnwHz',
		'TotPrlnAwfAnwUit' => 'PrlnAwfAnwUit',
		'PrLnUFO' => 'PrLnUfo',
		'IngLbPh' => 'IngLbPh',
		'TotPrAofLg' => 'PrAofLg',
		'TotPrAofHg' => 'PrAofHg',
		'TotPrAofUit' => 'PrAofUit',
		'TotOpslWko' => 'OpslWko',
		'TotPrGediffWhk' => 'PrGediffWhk',
		'TotPrAwfLg' => 'PrAwfLg',
		'TotPrAwfHg' => 'PrAwfHg',
		'TotPrAwfHz' => 'PrAwfHz',
		'TotPrAwfUit' => 'PrAwfUit',
		'PrUFO' => 'PrUFO',
		'IngBijdrZvw' => 'BijdrZvw',
		'TotWghZvw' => 'WghZvw',
	];

	/**
	 * The amounts condition 2315 adds up to TotTeBet (GS p55).
	 *
	 * @var list<string>
	 */
	public const PAYABLE = [
		'IngLbPh', 'TotWghZvw', 'IngBijdrZvw', 'TotPrAofLg', 'TotPrAofHg', 'TotPrAofUit', 'TotOpslWko', 'TotPrGediffWhk',
		'TotPrAwfLg', 'TotPrAwfHg', 'TotPrAwfHz', 'TotPrAwfUit', 'PrUFO',
	];

	/**
	 * Build the message tree.
	 *
	 * @param array<string, mixed>        $administration The hrAdministration.
	 * @param array<string, string>       $header         idBer, createdAt, relNr, software.
	 * @param array{0: string, 1: string} $period         The declaration period's first and last day.
	 * @param list<array<string, mixed>> $lines The income relationships (IncomeRelationshipLine::make): tree, cents, findings.
	 *
	 * @return array{tree: array<string, mixed>, collective: array<string, int>, findings: list<array<string, string>>}
	 *
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
	 */
	public static function build(array $administration, array $header, array $period, array $lines): array {
		$collective = self::collective($lines);
		$findings = self::headerFindings($administration, $header);
		foreach ($lines as $line) {
			$findings = array_merge($findings, array_values((array)($line['findings'] ?? [])));
		}

		$collectivePart = array_map(static fn (int $euros): string => (string)$euros, $collective);

		$tree = [
			'Bericht' => [
				'IdBer' => $header['idBer'],
				'DatTdAanm' => $header['createdAt'],
				'ContPers' => mb_substr(trim((string)($administration['aangifteContactName'] ?? '')), 0, 35),
				'TelNr' => mb_substr(trim((string)($administration['aangifteContactPhone'] ?? '')), 0, 25),
				'RelNr' => $header['relNr'],
				'GebrSwPakket' => mb_substr($header['software'], 0, 27),
			],
			'AdministratieveEenheid' => [
				'LhNr' => (string)($administration['loonheffingennummer'] ?? ''),
				'NmIP' => mb_substr(trim((string)($administration['name'] ?? '')), 0, 200),
				'TijdvakAangifte' => [
					'DatAanvTv' => $period[0],
					'DatEindTv' => $period[1],
					'VolledigeAangifte' => [
						'CollectieveAangifte' => $collectivePart,
						'InkomstenverhoudingInitieel' => array_map(static fn (array $line): array => (array)($line['tree'] ?? []), $lines),
					],
				],
			],
		];

		return ['tree' => $tree, 'collective' => $collective, 'findings' => $findings];
	}//end build()

	/**
	 * The collective amounts in whole euros, cut towards zero (GS p38).
	 *
	 * @param list<array<string, mixed>> $lines The income relationships, each with its `cents`.
	 *
	 * @return array<string, int>
	 *
	 * @spec openspec/changes/filings-wage-tax-message/specs/loonaangifte-message/spec.md#REQ-LAM-001
	 */
	public static function collective(array $lines): array {
		$totals = [];
		foreach (self::COLLECTIVE as $total => $element) {
			$cents = 0;
			foreach ($lines as $line) {
				$cents += (int)(((array)($line['cents'] ?? []))[$element] ?? 0);
			}

			$totals[$total] = intdiv($cents, 100);
		}

		$totals['TotTeBet'] = array_sum(array_map(static fn (string $part): int => $totals[$part], self::PAYABLE));
		$totals['TotGen'] = $totals['TotTeBet'];

		return $totals;
	}//end collective()

	/**
	 * What the header needs and the administration lacks (GS p32-35).
	 *
	 * @param array<string, mixed>  $administration The hrAdministration.
	 * @param array<string, string> $header         The header values.
	 *
	 * @return list<array<string, string>>
	 */
	private static function headerFindings(array $administration, array $header): array {
		$findings = [];
		$taxNumber = (string)($administration['loonheffingennummer'] ?? '');
		if (preg_match('/^(\d{9})L(\d{2})$/', $taxNumber, $match) !== 1 || $match[2] === '00' || PersonPart::elfproef($match[1]) === false) {
			$findings[] = self::finding('administration-tax-number-invalid', 'LhNr', 'Het loonheffingennummer van de administratie moet bestaan uit negen cijfers die aan de elfproef voldoen, de letter L en een subnummer van 01 tot en met 99.');
		}

		$required = [
			'NmIP' => ['name', 'De administratie heeft geen naam.'],
			'ContPers' => ['aangifteContactName', 'De administratie heeft geen contactpersoon voor de aangifte.'],
			'TelNr' => ['aangifteContactPhone', 'De administratie heeft geen telefoonnummer van de contactpersoon voor de aangifte.'],
		];
		foreach ($required as $element => [$field, $problem]) {
			if (trim((string)($administration[$field] ?? '')) === '') {
				$findings[] = self::finding('administration-without-' . strtolower($element), $element, $problem);
			}
		}

		if (mb_strlen($header['relNr']) !== 8) {
			$findings[] = self::finding('software-relation-number-missing', 'RelNr', 'Het relatienummer van de softwareleverancier bij de Belastingdienst (acht tekens) is niet ingesteld.');
		}

		return $findings;
	}//end headerFindings()

	/**
	 * A blocking finding about the administration or the software.
	 *
	 * @param string $kind    The kind.
	 * @param string $element The element.
	 * @param string $problem What is wrong.
	 *
	 * @return array<string, string>
	 */
	private static function finding(string $kind, string $element, string $problem): array {
		return ['kind' => $kind, 'severity' => 'blocking', 'employeeId' => '', 'element' => $element, 'problem' => $problem];
	}//end finding()

}//end class
