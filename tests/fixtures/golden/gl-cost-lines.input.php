<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Inputs of the GL cost lines golden: allocation lines of this and another
 * run, with and without cost centre and project, padded codes, a missing or
 * non-numeric amount, and totals above and below the coded sum.
 */

declare(strict_types=1);

/**
 * The golden cases, keyed by name.
 *
 * @return array<string, array{allocations: list<array<string, mixed>>, cost: array{runId: string, field: string, totalCents: int, account: string, description: string}}>
 */
function glCostLineCases(): array {
	$allocations = [
		['payrollRunId' => 'run-1', 'costCenter' => 'KP100', 'projectId' => 'P-7', 'gross' => 1000.10, 'employerCharges' => '250.555'],
		['payrollRunId' => 'run-1', 'costCenter' => ' KP100 ', 'projectId' => 'P-7 ', 'gross' => '99.90', 'employerCharges' => 10],
		['payrollRunId' => 'run-1', 'costCenter' => 'KP200', 'gross' => 500],
		['payrollRunId' => 'run-1', 'costCenter' => '', 'projectId' => 'P-9', 'gross' => 75.25, 'employerCharges' => 'n/a'],
		['payrollRunId' => 'run-1', 'costCenter' => 'KP300', 'projectId' => 'P-1', 'gross' => null, 'employerCharges' => 5],
		['payrollRunId' => 'run-2', 'costCenter' => 'KP100', 'projectId' => 'P-7', 'gross' => 9999],
	];

	return [
		'gross, total above coded' => ['allocations' => $allocations, 'cost' => ['runId' => 'run-1', 'field' => 'gross', 'totalCents' => 200000, 'account' => '4000', 'description' => 'Loonkosten bruto']],
		'charges, total below coded' => ['allocations' => $allocations, 'cost' => ['runId' => 'run-1', 'field' => 'employerCharges', 'totalCents' => 100, 'account' => '4100', 'description' => 'Werkgeverslasten sociale premies']],
		'no allocations' => ['allocations' => [], 'cost' => ['runId' => 'run-1', 'field' => 'gross', 'totalCents' => 123456, 'account' => '4000', 'description' => 'Loonkosten bruto']],
		'other run only' => ['allocations' => $allocations, 'cost' => ['runId' => 'run-3', 'field' => 'gross', 'totalCents' => 5, 'account' => '4000', 'description' => 'x']],
	];
}//end glCostLineCases()
