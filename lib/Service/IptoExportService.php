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
		$rows = [];
		$notConfirmed = [];
		foreach ($plans as $plan) {
			if ((string)($plan['schoolYear'] ?? '') !== $schoolYear) {
				continue;
			}

			$employeeId = (string)($plan['employeeId'] ?? '');
			$employee = ($employees[$employeeId] ?? []);
			if (($plan['status'] ?? null) !== self::CONFIRMED_STATUS) {
				$notConfirmed[] = [
					'employeeId' => $employeeId,
					'name'       => self::name(employee: $employee),
					'planId'     => ($plan['id'] ?? null),
					'status'     => ($plan['status'] ?? null),
				];
				continue;
			}

			foreach (self::teachingLines(plan: $plan) as $line) {
				$key = $employeeId."\0".$line['subject']."\0".$line['educationLevel'];
				if (isset($rows[$key]) === false) {
					$rows[$key] = [
						'employeeId'     => $employeeId,
						'employeeNumber' => ($employee['employeeNumber'] ?? null),
						'name'           => self::name(employee: $employee),
						'subject'        => $line['subject'],
						'educationLevel' => $line['educationLevel'],
						'hoursPerWeek'   => 0.0,
					];
				}

				$rows[$key]['hoursPerWeek'] = round($rows[$key]['hoursPerWeek'] + $line['hoursPerWeek'], 2);
			}
		}//end foreach

		$rows = array_values($rows);
		usort(
			$rows,
			static fn (array $left, array $right): int => [$left['name'], $left['subject'], $left['educationLevel']] <=> [$right['name'], $right['subject'], $right['educationLevel']]
		);

		return [
			'administrationId'     => ($administration['id'] ?? null),
			'brinNummer'           => ($administration['brinNummer'] ?? null),
			'referenceWeek'        => trim($referenceWeek),
			'schoolYear'           => $schoolYear,
			'specificationVersion' => self::SPECIFICATION_VERSION,
			'rows'                 => $rows,
			'notConfirmed'         => $notConfirmed,
		];
	}//end export()

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
