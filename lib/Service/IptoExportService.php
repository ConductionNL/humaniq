<?php

/**
 * Humaniq IPTO Export Service
 *
 * The staff count rows for the IPTO (Integrale Personeelstelling Onderwijs)
 * of one administration in one reference week (education-staff-deployment-
 * and-count D4): one row per teacher, subject and education level with the
 * weekly teaching hours, taken from the teaching (`lesgevend`) lines of the
 * confirmed (`vastgesteld`) plans whose school year holds the reference week.
 * Plans that are still `concept` are left out and listed as not confirmed.
 *
 * The file layout OCW publishes for the IPTO is not in this repository and is
 * not invented here (design D4): until its specification is obtained and its
 * version recorded (task 3.1), `specificationVersion` is null and the export
 * returns the rows the file is built from.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
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
 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#requirement-the-ipto-staff-count-file-shall-be-produced-from-the-confirmed-plans-req-edu-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * Builds the IPTO staff count rows from the deployment plans.
 *
 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#requirement-the-ipto-staff-count-file-shall-be-produced-from-the-confirmed-plans-req-edu-003
 */
class IptoExportService {

	/**
	 * The version of OCW's IPTO file specification the export follows; null until it is recorded (task 3.1).
	 */
	public const SPECIFICATION_VERSION = null;

	/**
	 * The plan line category that counts as teaching.
	 */
	public const TEACHING_CATEGORY = 'lesgevend';

	/**
	 * The plan status that is exported.
	 */
	public const CONFIRMED_STATUS = 'vastgesteld';

	/**
	 * The Monday of an ISO week (`YYYY-Www`), or null for a malformed one.
	 *
	 * @param string $referenceWeek The week, for example `2026-W46`.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#scenario-the-november-export
	 */
	public static function weekStart(string $referenceWeek): ?DateTimeImmutable {
		if (preg_match('/^(\d{4})-W(\d{2})$/', trim($referenceWeek), $match) !== 1) {
			return null;
		}

		$week = (int)$match[2];
		$lastWeek = (int)(new DateTimeImmutable($match[1].'-12-28'))->format('W');
		if ($week < 1 || $week > $lastWeek) {
			return null;
		}

		return (new DateTimeImmutable())->setISODate((int)$match[1], $week, 1)->setTime(0, 0);
	}//end weekStart()

	/**
	 * The export for one administration and reference week.
	 *
	 * @param array<string, mixed>                $administration The hrAdministration (`id`, `name`, `brinNummer`).
	 * @param array<int, array<string, mixed>>    $plans          The administration's DeploymentPlan rows.
	 * @param array<string, array<string, mixed>> $employees      The teachers, by employee id.
	 * @param string                              $referenceWeek  The reference week, `YYYY-Www`.
	 *
	 * @return array<string, mixed>|null The export, or null for a malformed reference week.
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#scenario-the-november-export
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#scenario-a-draft-plan-is-left-out
	 */
	public function export(array $administration, array $plans, array $employees, string $referenceWeek): ?array {
		$monday = self::weekStart(referenceWeek: $referenceWeek);
		if ($monday === null) {
			return null;
		}

		$schoolYear = DeploymentPlanService::schoolYearOf(day: $monday);
		$ofYear = array_filter($plans, static fn (array $plan): bool => (string)($plan['schoolYear'] ?? '') === $schoolYear);
		$confirmed = array_filter($ofYear, static fn (array $plan): bool => ($plan['status'] ?? null) === self::CONFIRMED_STATUS);
		$notConfirmed = array_map(
			static fn (array $plan): array => self::notConfirmedRow(plan: $plan, employees: $employees),
			array_values(array_diff_key($ofYear, $confirmed))
		);

		return [
			'administrationId'     => ($administration['id'] ?? null),
			'brinNummer'           => ($administration['brinNummer'] ?? null),
			'referenceWeek'        => trim($referenceWeek),
			'schoolYear'           => $schoolYear,
			'specificationVersion' => self::SPECIFICATION_VERSION,
			'rows'                 => self::teachingRows(plans: $confirmed, employees: $employees),
			'notConfirmed'         => $notConfirmed,
		];
	}//end export()

	/**
	 * The row of a plan left out of the export because it is not confirmed.
	 *
	 * @param array<string, mixed>                $plan      The DeploymentPlan row.
	 * @param array<string, array<string, mixed>> $employees The teachers, by employee id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#scenario-a-draft-plan-is-left-out
	 */
	private static function notConfirmedRow(array $plan, array $employees): array {
		$employeeId = (string)($plan['employeeId'] ?? '');

		return [
			'employeeId' => $employeeId,
			'name'       => self::name(employee: ($employees[$employeeId] ?? [])),
			'planId'     => ($plan['id'] ?? null),
			'status'     => ($plan['status'] ?? null),
		];
	}//end notConfirmedRow()

	/**
	 * The teaching hours per week of the confirmed plans, one row per teacher, subject and education level.
	 *
	 * @param array<int, array<string, mixed>>    $plans     The confirmed DeploymentPlan rows of the school year.
	 * @param array<string, array<string, mixed>> $employees The teachers, by employee id.
	 *
	 * @return array<int, array<string, mixed>> The rows, by name, subject and education level.
	 *
	 * @spec openspec/changes/education-staff-deployment-and-count/specs/education-staff-deployment/spec.md#scenario-the-november-export
	 */
	private static function teachingRows(array $plans, array $employees): array {
		$rows = [];
		foreach ($plans as $plan) {
			$employeeId = (string)($plan['employeeId'] ?? '');
			$employee = ($employees[$employeeId] ?? []);
			foreach (self::teachingLines(plan: $plan) as $line) {
				$key = $employeeId."\0".$line['subject']."\0".$line['educationLevel'];
				$rows[$key] ??= [
					'employeeId'     => $employeeId,
					'employeeNumber' => ($employee['employeeNumber'] ?? null),
					'name'           => self::name(employee: $employee),
					'subject'        => $line['subject'],
					'educationLevel' => $line['educationLevel'],
					'hoursPerWeek'   => 0.0,
				];
				$rows[$key]['hoursPerWeek'] = round($rows[$key]['hoursPerWeek'] + $line['hoursPerWeek'], 2);
			}
		}

		$rows = array_values($rows);
		usort(
			$rows,
			static fn (array $left, array $right): int => [$left['name'], $left['subject'], $left['educationLevel']] <=> [$right['name'], $right['subject'], $right['educationLevel']]
		);

		return $rows;
	}//end teachingRows()

	/**
	 * A plan's teaching lines with a numeric weekly figure.
	 *
	 * @param array<string, mixed> $plan The plan.
	 *
	 * @return array<int, array{subject: string, educationLevel: string, hoursPerWeek: float}>
	 */
	private static function teachingLines(array $plan): array {
		$lines = [];
		foreach ((is_array($plan['lines'] ?? null) === true ? $plan['lines'] : []) as $line) {
			if (is_array($line) === false || ($line['category'] ?? null) !== self::TEACHING_CATEGORY || is_numeric($line['hoursPerWeek'] ?? null) === false) {
				continue;
			}

			$lines[] = [
				'subject'        => (string)($line['subject'] ?? ''),
				'educationLevel' => (string)($line['educationLevel'] ?? ''),
				'hoursPerWeek'   => (float)$line['hoursPerWeek'],
			];
		}

		return $lines;
	}//end teachingLines()

	/**
	 * A teacher's display name.
	 *
	 * @param array<string, mixed> $employee The Employee row.
	 *
	 * @return string
	 */
	private static function name(array $employee): string {
		return trim((string)($employee['firstName'] ?? '').' '.(string)($employee['lastName'] ?? ''));
	}//end name()
}//end class
