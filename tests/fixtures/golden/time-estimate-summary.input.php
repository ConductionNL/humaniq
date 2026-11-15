<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Inputs of the time-estimate summary golden: estimates per role and without
 * a role, an overrun, an enforced estimate, hours booked on a role nobody
 * estimated, malformed hours and rows of another object.
 */

declare(strict_types=1);

/**
 * The golden cases, keyed by name.
 *
 * @return array<string, array{estimates: array<int, array<string, mixed>>, entries: array<int, array<string, mixed>>}>
 */
function timeEstimateSummaryCases(): array {
	$on = ['domainObjectType' => 'dossiq:Zaak', 'domainObjectRef' => 'z-1'];
	$other = ['domainObjectType' => 'dossiq:Zaak', 'domainObjectRef' => 'z-2'];
	$entries = [
		$on + ['role' => 'jurist', 'hours' => 6],
		$on + ['role' => ' jurist ', 'hours' => '2.25'],
		$on + ['role' => 'behandelaar', 'hours' => 1.5],
		$on + ['hours' => 3],
		$on + ['role' => 'archivaris', 'hours' => 'veel'],
		$on + ['role' => 'adviseur', 'hours' => -4],
		$other + ['role' => 'jurist', 'hours' => 40],
	];

	return [
		'estimated per role' => [
			'estimates' => [
				$on + ['role' => 'jurist', 'estimatedHours' => 5, 'enforced' => true],
				$on + ['role' => 'behandelaar', 'estimatedHours' => '10.333'],
				$on + ['estimatedHours' => 2, 'enforced' => 'yes'],
				$on + ['role' => 'adviseur', 'estimatedHours' => 'n/a'],
				$other + ['role' => 'jurist', 'estimatedHours' => 99],
			],
			'entries' => $entries,
		],
		'no estimate' => ['estimates' => [$other + ['role' => 'jurist', 'estimatedHours' => 1]], 'entries' => $entries],
		'nothing booked' => ['estimates' => [$on + ['role' => 'jurist', 'estimatedHours' => 1]], 'entries' => []],
	];
}//end timeEstimateSummaryCases()
