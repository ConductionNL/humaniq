<?php

/**
 * NL Wage-Tax Filing Check Provider
 *
 * Executable checks for the Dutch loonaangifte filing + retention rules of the
 * payroll corpus (lib/Standards/rules/payroll.json), mapped onto the
 * LoonaangifteFiling object type: the filing must be electronic with a valid
 * tijdvak (nl-loonaangifte-tijdvak), submitted on or before the statutory
 * deadline (nl-loonaangifte-termijn), the payroll records retained for at
 * least 7 years (nl-loonadministratie-bewaarplicht), the tijdvakcode consistent
 * with the period + tijdvak (nl-loonaangifte-tijdvakcode), the deadline
 * correctly derived as period end + one calendar month with no weekend/holiday
 * extension (nl-loonaangifte-deadline-derivation), and an unsent filing not
 * approaching or past its deadline (nl-loonaangifte-deadline-alert). Each
 * predicate is side-effect free; the seedObjects() sample satisfies them all.
 *
 * @category Standards
 * @package  OCA\Humaniq\Standards\Checks
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
 * @spec openspec/specs/loonaangifte-filing-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Standards\Checks;

use DateTimeImmutable;
use OCA\Humaniq\Payroll\Loonaangifte\MessageDrift;
use OCA\Humaniq\Standards\RuleCatalogue;
use OCA\Humaniq\Support\ChecksFilingDeadlines;

/**
 * Dutch loonaangifte filing + payroll-record-retention executable checks.
 */
final class NlWageTaxFilingChecks implements CheckProvider, SeedsObjects {

	use ChecksFilingDeadlines;

	/**
	 * Number of days before (and including) the deadline that an unsent filing
	 * starts alerting (nl-loonaangifte-deadline-alert).
	 *
	 * @var int
	 */
	private const ALERT_WINDOW_DAYS = 14;

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, callable>>
	 */
	public static function checks(): array {
		return [
			'LoonaangifteFiling' => [
				// Wet LB 1964 art. 28 / AWR art. 19 — the loonaangifte is filed
				// electronically for each wage period (a recognised NL tijdvak).
				'nl-loonaangifte-tijdvak' => self::forLoonaangifte(self::filedElectronicallyForNlTijdvak(...)),
				// AWR art. 19 — the loonaangifte is filed and paid no later than the
				// deadline (last working day of the month following the wage period).
				'nl-loonaangifte-termijn' => self::forLoonaangifte(self::submittedByDeadline(...)),
				// AWR art. 52 lid 4 — payroll administration is retained at least 7 years
				// after the end of the relevant financial year of the period.
				'nl-loonadministratie-bewaarplicht' => self::forLoonaangifte(self::retainedSevenYears(...)),
				// Belastingdienst LH 210 (2026) — the tijdvakcode matches the period + tijdvak.
				'nl-loonaangifte-tijdvakcode' => self::forNlLoonaangifte(self::tijdvakcodeConsistent(...)),
				// AWR art. 19 / LH 210 — the deadline is period end + 1 calendar month, no
				// weekend/holiday extension.
				'nl-loonaangifte-deadline-derivation' => self::forNlLoonaangifte(self::deadlineDerivationCorrect(...)),
				// AWR art. 19 — an unsent filing does not sit within 14 days of, or past, its deadline.
				'nl-loonaangifte-deadline-alert' => self::forNlLoonaangifte(self::deadlineNotAlerting(...)),
				// Gegevensspecificaties 2026 p39-55 (0001, 0002, 2315): the message reports
				// the run's figures, so a run recalculated after the message was made, or
				// whose wage tax changed, leaves a message that no longer matches it
				// (filings-wage-tax-message D4).
				'nl-loonaangifte-message-drift' => self::forNlLoonaangifte(MessageDrift::matchesRun(...)),
			],
		];

	}//end checks()

	/**
	 * A predicate that holds for every filing that is not a loonaangifte and applies the rule to one.
	 *
	 * @param callable $rule The rule for a loonaangifte filing.
	 *
	 * @return \Closure(array<string, mixed>): bool
	 */
	private static function forLoonaangifte(callable $rule): \Closure {
		return static fn (array $o): bool => (($o['filingType'] ?? '') !== 'loonaangifte') || $rule($o);

	}//end forLoonaangifte()

	/**
	 * A predicate that holds for every filing that is not a Dutch loonaangifte and applies the rule to one.
	 *
	 * @param callable $rule The rule for a Dutch loonaangifte, given the filing and the context.
	 *
	 * @return \Closure(array<string, mixed>, array<string, mixed>=): bool
	 */
	private static function forNlLoonaangifte(callable $rule): \Closure {
		return static fn (array $o, array $context = []): bool => self::isNlLoonaangifte($o) === false || $rule($o, $context);

	}//end forNlLoonaangifte()

	/**
	 * Whether the filing was transmitted electronically for a recognised NL tijdvak.
	 *
	 * @param array<string, mixed> $o The filing.
	 *
	 * @return bool
	 */
	private static function filedElectronicallyForNlTijdvak(array $o): bool {
		return (($o['electronicallyFiled'] ?? false) === true)
			&& in_array((string)($o['tijdvak'] ?? ''), ['maand', 'vierweken', 'kwartaal', 'jaar'], true);

	}//end filedElectronicallyForNlTijdvak()

	/**
	 * Whether the filing was submitted on or before its deadline.
	 *
	 * @param array<string, mixed> $o The filing.
	 *
	 * @return bool
	 */
	private static function submittedByDeadline(array $o): bool {
		return FilingDates::onOrBeforeDeadline($o, 'submittedDate');

	}//end submittedByDeadline()

	/**
	 * Whether the payroll administration is retained at least 7 years after the period.
	 *
	 * @param array<string, mixed> $o The filing.
	 *
	 * @return bool
	 */
	private static function retainedSevenYears(array $o): bool {
		return FilingDates::retainedYearsAfterPeriod($o, 7);

	}//end retainedSevenYears()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function seedSpec(): array {
		return [];
	}//end seedSpec()

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public static function seedObjects(): array {
		return [
			'LoonaangifteFiling' => [
				[
					'period' => '2026-01',
					'jurisdiction' => 'NL',
					'filingType' => 'loonaangifte',
					'tijdvak' => 'maand',
					// 2026-02-28 (a Saturday) is the correct calendar-exact deadline for
					// the 2026-01 period — nl-loonaangifte-deadline-derivation asserts no
					// weekend/holiday extension, so this is NOT the same as "last working day".
					'deadline' => '2026-02-28',
					'submittedDate' => '2026-02-20',
					'paidDate' => '2026-02-20',
					'electronicallyFiled' => true,
					'retainedUntil' => '2033-12-31',
					'status' => 'verzonden',
					'tijdvakcode' => '6010',
				],
			],
		];

	}//end seedObjects()

	/**
	 * True when the object is an NL loonaangifte filing (the scope of the
	 * lifecycle-related tijdvakcode/deadline checks — DE/FR/US filings and
	 * non-loonaangifte filingTypes are unaffected).
	 *
	 * @param array<string, mixed> $o The filing.
	 *
	 * @return bool
	 */
	private static function isNlLoonaangifte(array $o): bool {
		return (string)($o['filingType'] ?? '') === 'loonaangifte' && (string)($o['jurisdiction'] ?? '') === 'NL';
	}//end isNlLoonaangifte()

	/**
	 * True when the filing's tijdvakcode is consistent with its period + tijdvak
	 * per the nl-loonaangifte-tijdvakcode rule's `parameters` code tables (read
	 * from the corpus, never hard-coded here). A non-concept filing with no
	 * tijdvakcode at all is a violation; a concept filing may still be missing
	 * one. When the period/tijdvak shape yields no deterministic expectation
	 * (e.g. an unrecognised vierweken period format), the check does not flag —
	 * only structured, decidable mismatches are reported.
	 *
	 * @param array<string, mixed> $o The filing.
	 *
	 * @return bool
	 */
	private static function tijdvakcodeConsistent(array $o): bool {
		$tijdvakcode = trim((string)($o['tijdvakcode'] ?? ''));
		if ($tijdvakcode === '') {
			return (string)($o['status'] ?? 'concept') === 'concept';
		}

		$parameters = self::tijdvakcodeParameters();
		if ($parameters === null) {
			return true;
		}

		$expected = self::expectedTijdvakcode($o, $parameters);

		return $expected === null || $tijdvakcode === $expected;
	}//end tijdvakcodeConsistent()

	/**
	 * The `parameters` object of the nl-loonaangifte-tijdvakcode corpus rule, or
	 * null when the rule is missing from the catalogue.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function tijdvakcodeParameters(): ?array {
		foreach (RuleCatalogue::all() as $rule) {
			if ((string)($rule['id'] ?? '') !== 'nl-loonaangifte-tijdvakcode') {
				continue;
			}

			$parameters = ($rule['parameters'] ?? null);
			return is_array($parameters) === true ? $parameters : null;
		}

		return null;
	}//end tijdvakcodeParameters()

	/**
	 * The expected tijdvakcode for a filing's period + tijdvak, per the rule's
	 * code tables. Returns null when the tijdvak/period shape has no
	 * deterministic mapping (e.g. `jaar` needs only the year, `maand` needs
	 * YYYY-MM, and `vierweken` needs a YYYY-P## period-index convention not
	 * otherwise modelled on this schema).
	 *
	 * @param array<string, mixed> $o          The filing.
	 * @param array<string, mixed> $parameters The rule's parameters.
	 *
	 * @return string|null
	 */
	private static function expectedTijdvakcode(array $o, array $parameters): ?string {
		$period = (string)($o['period'] ?? '');

		return match ((string)($o['tijdvak'] ?? '')) {
			'jaar' => self::jaarCode($parameters),
			'maand' => self::maandCode($period, $parameters),
			'vierweken' => self::vierwekenCode($period, $parameters),
			default => null,
		};
	}//end expectedTijdvakcode()

	/**
	 * The rule's code for a `jaar` filing.
	 *
	 * @param array<string, mixed> $parameters The rule's parameters.
	 *
	 * @return string|null
	 */
	private static function jaarCode(array $parameters): ?string {
		$jaarCode = ($parameters['jaarCode'] ?? null);
		return $jaarCode !== null ? (string)$jaarCode : null;

	}//end jaarCode()

	/**
	 * The rule's code for a `maand` filing's YYYY-MM period.
	 *
	 * @param string               $period     The filing's period.
	 * @param array<string, mixed> $parameters The rule's parameters.
	 *
	 * @return string|null
	 */
	private static function maandCode(string $period, array $parameters): ?string {
		if (preg_match('/^\d{4}-(\d{2})$/', $period, $m) !== 1) {
			return null;
		}

		$maandCodes = (is_array($parameters['maandCodes'] ?? null) === true) ? $parameters['maandCodes'] : [];
		return isset($maandCodes[$m[1]]) === true ? (string)$maandCodes[$m[1]] : null;

	}//end maandCode()

	/**
	 * The rule's code for a `vierweken` filing's YYYY-P## period.
	 *
	 * @param string               $period     The filing's period.
	 * @param array<string, mixed> $parameters The rule's parameters.
	 *
	 * @return string|null
	 */
	private static function vierwekenCode(string $period, array $parameters): ?string {
		if (preg_match('/^\d{4}-P(\d{2})$/', $period, $m) !== 1) {
			return null;
		}

		$codes = (is_array($parameters['vierwekenCodes'] ?? null) === true) ? $parameters['vierwekenCodes'] : [];
		$index = ((int)$m[1] - 1);
		return isset($codes[$index]) === true ? (string)$codes[$index] : null;

	}//end vierwekenCode()

	/**
	 * True when the filing's deadline equals period end + 1 calendar month, with
	 * no weekend/holiday extension. Only `maand` (YYYY-MM) and `jaar` (YYYY..)
	 * periods carry a calendar-derivable period end on this schema; `vierweken`
	 * deadlines follow a different statutory table and are out of scope here.
	 *
	 * @param array<string, mixed> $o The filing.
	 *
	 * @return bool
	 */
	private static function deadlineDerivationCorrect(array $o): bool {
		$tijdvak = (string)($o['tijdvak'] ?? '');
		$period = (string)($o['period'] ?? '');

		if ($tijdvak === 'maand' && preg_match('/^(\d{4})-(\d{2})$/', $period, $m) === 1) {
			return (string)($o['deadline'] ?? '') === self::expectedDeadline((int)$m[1], (int)$m[2]);
		}

		if ($tijdvak === 'jaar' && preg_match('/^(\d{4})/', $period, $m) === 1) {
			return (string)($o['deadline'] ?? '') === self::expectedDeadline((int)$m[1], 12);
		}

		// Any other tijdvak/period shape has no derivable deadline to check.
		return true;
	}//end deadlineDerivationCorrect()

	/**
	 * The last calendar day of the month following {$year}-{$month}, formatted
	 * YYYY-MM-DD. No weekend/public-holiday adjustment is applied.
	 *
	 * @param int $year Period-end year.
	 * @param int $month Period-end month (1-12).
	 *
	 * @return string
	 */
	private static function expectedDeadline(int $year, int $month): string {
		$followingMonth = ($month + 1);
		$followingYear = $year;
		if ($followingMonth > 12) {
			$followingMonth = 1;
			$followingYear++;
		}

		$lastDay = (int)date('t', mktime(0, 0, 0, $followingMonth, 1, $followingYear));
		return sprintf('%04d-%02d-%02d', $followingYear, $followingMonth, $lastDay);
	}//end expectedDeadline()
}//end class
