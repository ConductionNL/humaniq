<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Inputs of the CAO component fold golden: the example agreement's shift
 * allowance and night premium over whole and part months, paid and unpaid
 * timesheets, weekday and public-holiday nights, the contract's hourly wage
 * when the hours fold has no rate, refused and accepted overrides, an
 * unconfirmed agreement and an agreement that does not resolve.
 */

declare(strict_types=1);

/**
 * The golden cases, keyed by name.
 *
 * @return array<string, array{contract: array<string, mixed>, regularWageCents: int, hoursPay: array<string, mixed>|null, entries: list<array<string, mixed>>, nonWorkingDates: list<string>|null, period: string}>
 */
function caoFoldCases(): array {
	$entries = [
		['timesheetId' => 'ts-5', 'startedAt' => '2026-05-12T22:00:00+02:00', 'endedAt' => '2026-05-13T06:00:00+02:00', 'breakMinutes' => 0, 'hours' => 8],
		['timesheetId' => 'ts-5', 'startedAt' => '2026-05-14T01:00:00+02:00', 'endedAt' => '2026-05-14T04:30:00+02:00', 'breakMinutes' => 30, 'hours' => 3],
		['timesheetId' => 'ts-6', 'startedAt' => '2026-05-16T00:00:00+02:00', 'endedAt' => '2026-05-16T06:00:00+02:00', 'hours' => 6],
		['timesheetId' => 'ts-other', 'startedAt' => '2026-05-14T00:00:00+02:00', 'endedAt' => '2026-05-14T06:00:00+02:00', 'hours' => 6],
		['startedAt' => '2026-05-15T00:00:00+02:00', 'endedAt' => '2026-05-15T06:00:00+02:00', 'hours' => 6],
	];
	$both = ['cao' => 'cao-voorbeeld', 'caoComponents' => ['ploegentoeslag', 'nachttoeslag'], 'startDate' => '2025-01-01'];

	$cases = [];
	foreach ([300000, 0, 123457] as $wage) {
		foreach (['2026-05', '2026-02', 'mei'] as $period) {
			$cases['both, wage ' . $wage . ', ' . $period] = ['contract' => $both, 'regularWageCents' => $wage, 'hoursPay' => ['timesheetIds' => ['ts-5', 'ts-6'], 'hourlyRate' => 20.0], 'entries' => $entries, 'nonWorkingDates' => null, 'period' => $period];
		}
	}

	$cases['one timesheet paid'] = ['contract' => $both, 'regularWageCents' => 300000, 'hoursPay' => ['timesheetIds' => ['ts-6'], 'hourlyRate' => 20.0], 'entries' => $entries, 'nonWorkingDates' => null, 'period' => '2026-05'];
	$cases['numeric timesheet ids'] = ['contract' => $both, 'regularWageCents' => 300000, 'hoursPay' => ['timesheetIds' => [5], 'hourlyRate' => 20.0], 'entries' => array_merge($entries, [['timesheetId' => '5', 'startedAt' => '2026-05-19T00:00:00+02:00', 'endedAt' => '2026-05-19T02:00:00+02:00', 'hours' => 2]]), 'nonWorkingDates' => null, 'period' => '2026-05'];
	$cases['no hours fold, contract hourly wage'] = ['contract' => $both + ['hourlyWage' => '18.35'], 'regularWageCents' => 300000, 'hoursPay' => null, 'entries' => $entries, 'nonWorkingDates' => null, 'period' => '2026-05'];
	$cases['hours fold without rate, contract hourly wage'] = ['contract' => $both + ['hourlyWage' => 18.35], 'regularWageCents' => 300000, 'hoursPay' => ['timesheetIds' => ['ts-5']], 'entries' => $entries, 'nonWorkingDates' => null, 'period' => '2026-05'];
	$cases['non-numeric rate'] = ['contract' => $both, 'regularWageCents' => 300000, 'hoursPay' => ['timesheetIds' => ['ts-5'], 'hourlyRate' => 'twintig'], 'entries' => $entries, 'nonWorkingDates' => null, 'period' => '2026-05'];
	$cases['holiday night'] = ['contract' => $both, 'regularWageCents' => 300000, 'hoursPay' => ['timesheetIds' => ['ts-5', 'ts-6'], 'hourlyRate' => 22.5], 'entries' => $entries, 'nonWorkingDates' => ['2026-05-14', '2026-05-13'], 'period' => '2026-05'];
	$cases['empty holiday list'] = ['contract' => $both, 'regularWageCents' => 300000, 'hoursPay' => ['timesheetIds' => ['ts-5'], 'hourlyRate' => 22.5], 'entries' => $entries, 'nonWorkingDates' => [], 'period' => '2026-05'];
	$cases['starts mid month'] = ['contract' => ['startDate' => '2026-05-17'] + $both, 'regularWageCents' => 300000, 'hoursPay' => ['timesheetIds' => ['ts-5'], 'hourlyRate' => 20.0], 'entries' => $entries, 'nonWorkingDates' => null, 'period' => '2026-05'];
	$cases['ends mid month'] = ['contract' => $both + ['endDate' => '2026-02-10'], 'regularWageCents' => 300000, 'hoursPay' => null, 'entries' => [], 'nonWorkingDates' => null, 'period' => '2026-02'];
	$cases['nothing named'] = ['contract' => ['cao' => 'cao-voorbeeld'], 'regularWageCents' => 300000, 'hoursPay' => null, 'entries' => $entries, 'nonWorkingDates' => null, 'period' => '2026-05'];
	$cases['blank names only'] = ['contract' => ['cao' => 'cao-voorbeeld', 'caoComponents' => ['', null]], 'regularWageCents' => 300000, 'hoursPay' => null, 'entries' => [], 'nonWorkingDates' => null, 'period' => '2026-05'];
	$cases['refused override'] = ['contract' => $both + ['caoComponentOverrides' => ['ploegentoeslag' => ['pct' => 12]]], 'regularWageCents' => 300000, 'hoursPay' => null, 'entries' => [], 'nonWorkingDates' => null, 'period' => '2026-05'];
	$override = $both + ['caoComponentOverrides' => ['ploegentoeslag' => ['pct' => 12, 'minAmountCents' => 50000]], 'caoComponentOverrideReason' => 'Afspraak 2026'];
	$cases['override with a reason'] = ['contract' => $override, 'regularWageCents' => 300000, 'hoursPay' => null, 'entries' => [], 'nonWorkingDates' => null, 'period' => '2026-05'];
	foreach (['2026-05' => ['startDate' => '2026-05-17'], '2026-02' => ['endDate' => '2026-02-10'], 'mei' => ['startDate' => '2026-05-17'], '2026-04' => ['startDate' => '2026-04-30']] as $period => $span) {
		$cases['minimum pro rata ' . $period] = ['contract' => array_merge($override, $span), 'regularWageCents' => 100000, 'hoursPay' => null, 'entries' => [], 'nonWorkingDates' => null, 'period' => $period];
	}

	$cases['unknown component'] = ['contract' => ['cao' => 'cao-voorbeeld', 'caoComponents' => ['ploegentoeslag', 'bestaat-niet']], 'regularWageCents' => 300000, 'hoursPay' => null, 'entries' => [], 'nonWorkingDates' => null, 'period' => '2026-05'];
	$cases['unconfirmed agreement'] = ['contract' => ['cao' => 'cao-zorg-vvt', 'caoComponents' => ['ort']], 'regularWageCents' => 300000, 'hoursPay' => ['timesheetIds' => ['ts-5'], 'hourlyRate' => 20.0], 'entries' => $entries, 'nonWorkingDates' => null, 'period' => '2026-05'];
	$cases['agreement does not resolve'] = ['contract' => ['id' => 'c-9', 'cao' => 'bestaat-niet', 'caoComponents' => ['ploegentoeslag', 7]], 'regularWageCents' => 300000, 'hoursPay' => null, 'entries' => [], 'nonWorkingDates' => null, 'period' => '2026-05'];

	return $cases;
}//end caoFoldCases()
