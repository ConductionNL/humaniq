<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Inputs of the transition payment golden: a salaried and an hourly
 * employee, chained and broken contract spans, variable pay and a payslip
 * holiday rate, the cap and a departure the employer did not initiate.
 */

declare(strict_types=1);

/**
 * The golden cases, keyed by name.
 *
 * @return array<string, array{employee: array<string, mixed>, contracts: array<int, array<string, mixed>>, payslips: array<int, array<string, mixed>>, parameters: array<string, mixed>, endDate: string, reason: string}>
 */
function transitionPaymentCases(): array {
	$parameters = ['capEur' => 102000, 'dismissalInitiatedReasons' => ['opzegging-werkgever', 'ontbinding-kantonrechter']];
	$salaried = ['grossMonthlySalary' => 4000.0, 'startDate' => '2018-03-15'];
	$slips = [
		['period' => '2025-10', 'grossPay' => 4300, 'vakantiegeldRate' => 8.33],
		['period' => '2025-12', 'grossPay' => 4600, 'vakantiegeldRate' => 0.0833],
		['period' => '2025-11', 'grossPay' => 3900],
	];

	return [
		'salaried, no contracts'  => ['employee' => $salaried, 'contracts' => [], 'payslips' => [], 'parameters' => $parameters, 'endDate' => '2025-12-31', 'reason' => 'opzegging-werkgever'],
		'chained contracts'       => [
			'employee' => $salaried,
			'contracts' => [
				['startDate' => '2016-01-01', 'endDate' => '2016-12-31', 'hoursPerWeek' => 32, 'hourlyWage' => 20],
				['startDate' => '2017-04-01', 'endDate' => null, 'hoursPerWeek' => 36, 'hourlyWage' => 25.5],
			],
			'payslips' => $slips,
			'parameters' => $parameters,
			'endDate' => '2025-06-30',
			'reason' => 'ontbinding-kantonrechter',
		],
		'broken chain'            => [
			'employee' => $salaried,
			'contracts' => [
				['startDate' => '2010-01-01', 'endDate' => '2011-01-01', 'hoursPerWeek' => 40, 'hourlyWage' => 15],
				['startDate' => '2012-01-01', 'endDate' => null, 'hoursPerWeek' => 40, 'hourlyWage' => 18],
			],
			'payslips' => [],
			'parameters' => $parameters,
			'endDate' => '2024-02-29',
			'reason' => 'opzegging-werkgever',
		],
		'capped'                  => ['employee' => ['grossMonthlySalary' => 15000.0, 'startDate' => '1985-01-01'], 'contracts' => [], 'payslips' => [], 'parameters' => ['capEur' => 1000.5, 'dismissalInitiatedReasons' => ['opzegging-werkgever']], 'endDate' => '2025-12-31', 'reason' => 'opzegging-werkgever'],
		'fractional cap binds'    => ['employee' => ['grossMonthlySalary' => 4000.0, 'startDate' => '1960-01-01'], 'contracts' => [], 'payslips' => [], 'parameters' => ['capEur' => 60000.5, 'dismissalInitiatedReasons' => ['opzegging-werkgever']], 'endDate' => '2025-12-31', 'reason' => 'opzegging-werkgever'],
		'not initiated'           => ['employee' => $salaried, 'contracts' => [], 'payslips' => $slips, 'parameters' => $parameters, 'endDate' => '2025-12-31', 'reason' => 'opzegging-werknemer'],
	];
}//end transitionPaymentCases()
