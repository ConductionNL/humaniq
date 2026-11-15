<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Inputs of the on-call averages golden: contracts that start before, inside
 * and after the window, end inside it, have a recorded offer or a malformed
 * start, plus approved, unapproved and out-of-window hours.
 */

declare(strict_types=1);

/**
 * The golden cases, keyed by name.
 *
 * @return array<string, array{contracts: array<int, array<string, mixed>>, entries: array<int, array<string, mixed>>, approved: array<int, string>, window: array{0: string, 1: string}}>
 */
function oncallCases(): array {
	$entries = [
		['employeeId' => 'emp-a', 'timesheetId' => 'ts-ok', 'date' => '2026-01-06', 'hours' => 20],
		['employeeId' => 'emp-a', 'timesheetId' => 'ts-ok', 'date' => '2025-11-30', 'hours' => 99],
		['employeeId' => 'emp-a', 'timesheetId' => 'ts-open', 'date' => '2026-01-07', 'hours' => 7],
		['employeeId' => 'emp-b', 'timesheetId' => 'ts-ok', 'date' => '2026-02-10T09:00:00Z', 'hours' => '3.5'],
		['employeeId' => 'emp-c', 'timesheetId' => 'ts-ok', 'date' => '2026-03-31', 'hours' => 8],
		['employeeId' => 'emp-c', 'timesheetId' => 'ts-ok', 'date' => '2026-04-01', 'hours' => 8],
		['employeeId' => 'emp-d', 'timesheetId' => 'ts-ok', 'date' => '2026-05-01'],
	];
	$contracts = [
		['id' => 'c-a', 'employeeId' => 'emp-a', 'type' => 'oproep', 'startDate' => '2025-01-05'],
		['id' => 'c-b', 'employeeId' => 'emp-b', 'type' => 'oproep', 'startDate' => '2026-02-01T00:00:00Z', 'vasteUrenAanbodOp' => '2026-03-01', 'vasteUrenAanbodUren' => 12],
		['id' => 'c-c', 'employeeId' => 'emp-c', 'type' => 'oproep', 'startDate' => '2024-06-01', 'endDate' => '2026-03-31'],
		['id' => 'c-d', 'employeeId' => 'emp-d', 'type' => 'oproep', 'startDate' => 'soon'],
		['id' => 'c-e', 'employeeId' => 'emp-e', 'type' => 'oproep', 'startDate' => '2027-01-01'],
		['id' => 'c-f', 'employeeId' => 'emp-f', 'type' => 'permanent', 'startDate' => '2020-01-01'],
		['employeeId' => 'emp-g', 'type' => 'oproep'],
		['id' => 'c-h', 'employeeId' => 'emp-c', 'type' => 'oproep', 'startDate' => '2025-06-01', 'endDate' => '2026-03-31'],
		['id' => 'c-i', 'employeeId' => 'emp-a', 'type' => 'oproep', 'startDate' => '2025-01-01', 'endDate' => '2026-01-01'],
	];

	return [
		'full year'    => ['contracts' => $contracts, 'entries' => $entries, 'approved' => ['ts-ok'], 'window' => ['2025-12-01', '2026-11-30']],
		'short window' => ['contracts' => $contracts, 'entries' => $entries, 'approved' => ['ts-ok', 'ts-open'], 'window' => ['2026-01-01', '2026-01-31']],
		'nothing approved' => ['contracts' => $contracts, 'entries' => $entries, 'approved' => [], 'window' => ['2026-01-01', '2026-06-30']],
	];
}//end oncallCases()
