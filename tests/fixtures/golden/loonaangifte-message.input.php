<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Inputs of the wage tax return message golden: a complete and an
 * incomplete administration, two income relationships with every collective
 * element in cents, line findings and two corrections of earlier periods.
 */

declare(strict_types=1);

use OCA\Humaniq\Payroll\Loonaangifte\LoonaangifteMessageBuilder;

/**
 * The golden cases, keyed by name.
 *
 * @return array<string, array{administration: array<string, mixed>, header: array<string, string>, period: array{0: string, 1: string}, lines: list<array<string, mixed>>, corrections: list<array<string, mixed>>}>
 */
function loonaangifteMessageCases(): array {
	$elements = array_values(LoonaangifteMessageBuilder::COLLECTIVE);
	$cents = static fn (int $base): array => array_combine($elements, array_map(static fn (int $i): int => ($base + ($i * 1337)), array_keys($elements)));
	$lines = [
		['tree' => ['NumIV' => '1', 'SofiNr' => '123456782'], 'cents' => $cents(250099), 'findings' => []],
		['tree' => ['NumIV' => '2', 'SofiNr' => '111222333'], 'cents' => $cents(99), 'findings' => [['kind' => 'line-x', 'element' => 'SofiNr', 'problem' => 'p']]],
	];
	$corrections = [
		['tree' => ['DatAanvTv' => '2026-01-01', 'DatEindTv' => '2026-01-31', 'X' => 'a'], 'saldo' => 1250],
		['tree' => ['DatAanvTv' => '2026-02-01', 'DatEindTv' => '2026-02-28', 'X' => 'b'], 'saldo' => -310],
	];
	$header = ['idBer' => 'LA202603', 'createdAt' => '2026-04-02T10:00:00', 'relNr' => '12345678', 'software' => 'humaniq loonaangifte module version 1.2.3 long name'];

	return [
		'complete with corrections' => [
			'administration' => ['loonheffingennummer' => '123456782L01', 'name' => ' Gemeente Voorbeeld ', 'aangifteContactName' => 'Sanne de Vries', 'aangifteContactPhone' => '0201234567'],
			'header' => $header,
			'period' => ['2026-03-01', '2026-03-31'],
			'lines' => $lines,
			'corrections' => $corrections,
		],
		'incomplete, no corrections' => [
			'administration' => ['loonheffingennummer' => '123456789L00'],
			'header' => ['relNr' => '123'] + $header,
			'period' => ['2026-03-01', '2026-03-31'],
			'lines' => [],
			'corrections' => [],
		],
	];
}//end loonaangifteMessageCases()
