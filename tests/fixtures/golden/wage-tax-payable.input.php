<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Inputs of the wage tax payable golden: periods at month and year ends and
 * in a leap February, an unreadable period, a return with and without its
 * number, a padded payment reference, a missing deadline and
 * administration, and amounts with cents.
 */

declare(strict_types=1);

/**
 * The golden cases, keyed by name.
 *
 * @return array<string, array{run: array<string, mixed>, filing: array<string, mixed>, amount: float, payeeId: string, account: string}>
 */
function wageTaxPayableCases(): array {
	$filing = ['period' => '2026-05', 'aangiftenummer' => 'LH-2026-05-001', 'betalingskenmerk' => ' 1234 5678 9012 3456 ', 'deadline' => '2026-06-30'];
	$cases = [];
	foreach (['2026-05', '2026-02', '2028-02', '2026-12', '2026-04', 'mei'] as $period) {
		$cases['period ' . $period] = ['run' => ['administrationId' => 'ADM-001'], 'filing' => array_merge($filing, ['period' => $period]), 'amount' => 12345.0, 'payeeId' => 'payee-bd', 'account' => '1701'];
	}

	$cases['no return number'] = ['run' => ['administrationId' => 'ADM-001'], 'filing' => array_merge($filing, ['aangiftenummer' => '']), 'amount' => 999.99, 'payeeId' => 'payee-bd', 'account' => '1701'];
	$cases['return number absent'] = ['run' => ['administrationId' => 'ADM-001'], 'filing' => ['period' => '2026-05', 'betalingskenmerk' => '9'], 'amount' => 0.01, 'payeeId' => 'p', 'account' => 'a'];
	$cases['no administration'] = ['run' => [], 'filing' => $filing, 'amount' => 1.0, 'payeeId' => 'payee-bd', 'account' => '1701'];
	$cases['numeric administration'] = ['run' => ['administrationId' => 42], 'filing' => $filing + ['extra' => true], 'amount' => 1000000.0, 'payeeId' => ' payee ', 'account' => ' 1701 '];

	return $cases;
}//end wageTaxPayableCases()
