<?php

/**
 * Scores applicants and colleagues against a vacancy's requirements.
 *
 * The score is plain arithmetic with its breakdown, so a recruiter can explain
 * every point to the person it is about (hiring-candidate-assessment D2):
 * education level at or above the minimum 25, years of experience 25 (pro rata
 * below the minimum), required competences 40 and preferred competences 10,
 * each shared equally. A requirement the vacancy does not state is met by
 * everyone; one it states and the candidate's record does not show scores
 * zero, and the breakdown says it is unknown. Nothing is stored and no
 * application moves: the list ranks, it never decides.
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
 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * The match score and the ranked list for one vacancy.
 */
class VacancyMatchService {

	private const EDUCATION_POINTS = 25.0;
	private const EXPERIENCE_POINTS = 25.0;
	private const REQUIRED_POINTS = 40.0;
	private const PREFERRED_POINTS = 10.0;

	/**
	 * Constructor.
	 *
	 * @param HoursRegisterGateway $gateway Reads the register.
	 *
	 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-002
	 */
	public function __construct(
		private readonly HoursRegisterGateway $gateway,
	) {
	}//end __construct()

	/**
	 * The requirements a vacancy states.
	 *
	 * @param array<string, mixed> $vacancy The vacancy.
	 *
	 * @return array{education: ?int, experience: ?float, required: list<string>, preferred: list<string>}
	 *
	 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-002
	 */
	public static function requirementsOf(array $vacancy): array {
		return [
			'education' => self::intOrNull($vacancy['minEducationLevel'] ?? null),
			'experience' => self::floatOrNull($vacancy['minExperienceYears'] ?? null),
			'required' => self::codes($vacancy['requiredCompetences'] ?? []),
			'preferred' => self::codes($vacancy['preferredCompetences'] ?? []),
		];
	}//end requirementsOf()

	/**
	 * Score one candidate.
	 *
	 * @param array{education: ?int, experience: ?float, required: list<string>, preferred: list<string>} $requirements    The vacancy's requirements.
	 * @param int|null                                                                                       $educationLevel  The candidate's level, null when unknown.
	 * @param float|null                                                                                     $experienceYears The candidate's years, null when unknown.
	 * @param list<string>                                                                                   $competences     The competence codes the candidate holds.
	 *
	 * @return array{total: int, points: array{education: float, experience: float, required: float, preferred: float}, missing: list<string>}
	 *
	 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-002
	 */
	public static function score(array $requirements, ?int $educationLevel, ?float $experienceYears, array $competences): array {
		$missing = [];
		$education = self::EDUCATION_POINTS;
		if ($requirements['education'] !== null) {
			$education = self::educationPoints(minimum: $requirements['education'], level: $educationLevel, missing: $missing);
		}

		$experience = self::EXPERIENCE_POINTS;
		if ($requirements['experience'] !== null && $requirements['experience'] > 0.0) {
			$experience = self::experiencePoints(minimum: $requirements['experience'], years: $experienceYears, missing: $missing);
		}

		$held = self::codes($competences);
		$required = self::share(pool: self::REQUIRED_POINTS, codes: $requirements['required'], held: $held, missing: $missing);
		$preferred = self::share(pool: self::PREFERRED_POINTS, codes: $requirements['preferred'], held: $held, missing: $missing);

		return [
			'total' => (int)round($education + $experience + $required + $preferred),
			'points' => [
				'education' => $education,
				'experience' => $experience,
				'required' => $required,
				'preferred' => $preferred,
			],
			'missing' => $missing,
		];
	}//end score()

	/**
	 * The ranked matches for one vacancy: applicants still in play or in the
	 * talent pool, and colleagues with a current competence.
	 *
	 * @param string $vacancyId The vacancy.
	 * @param string $today     The day competences and retention are read on (Y-m-d).
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-002
	 */
	public function matchesFor(string $vacancyId, string $today): array {
		$vacancy = $this->gateway->findObjectData($vacancyId, 'Vacancy');
		if ($vacancy === null) {
			return [];
		}

		$requirements = self::requirementsOf($vacancy);
		$rows = array_merge($this->applicants(requirements: $requirements, today: $today), $this->employees(requirements: $requirements, today: $today));
		usort($rows, static fn (array $a, array $b): int => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

		return $rows;
	}//end matchesFor()

	/**
	 * Applicants with a profile who may be proposed.
	 *
	 * @param array{education: ?int, experience: ?float, required: list<string>, preferred: list<string>} $requirements The requirements.
	 * @param string                                                                                         $today        The day.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function applicants(array $requirements, string $today): array {
		$rows = [];
		foreach ($this->gateway->loadAll('job-application') as $application) {
			if (self::mayBeProposed(application: $application, today: $today) === false) {
				continue;
			}

			$education = self::intOrNull($application['educationLevel'] ?? null);
			$years = self::floatOrNull($application['experienceYears'] ?? null);
			$codes = self::codes($application['competenceCodes'] ?? []);
			if ($education === null && $years === null && $codes === []) {
				continue;
			}

			$rows[] = self::row(kind: 'applicant', id: (string)($application['id'] ?? ''), name: (string)($application['candidateName'] ?? ''), result: self::score($requirements, $education, $years, $codes));
		}

		return $rows;
	}//end applicants()

	/**
	 * Colleagues holding at least one competence that is current today.
	 *
	 * @param array{education: ?int, experience: ?float, required: list<string>, preferred: list<string>} $requirements The requirements.
	 * @param string                                                                                         $today        The day.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function employees(array $requirements, string $today): array {
		$held = [];
		foreach ($this->gateway->loadAll('EmployeeCompetence') as $competence) {
			$employeeId = (string)($competence['employeeId'] ?? '');
			$issued = (string)($competence['issuedOn'] ?? '');
			$until = (string)($competence['validUntil'] ?? '');
			if ($employeeId === '' || ($issued !== '' && $issued > $today) || ($until !== '' && $until < $today)) {
				continue;
			}

			$held[$employeeId][] = (string)($competence['competenceCode'] ?? '');
		}

		$rows = [];
		foreach ($held as $employeeId => $codes) {
			$employee = $this->gateway->findObjectData((string)$employeeId, 'Employee');
			if ($employee === null) {
				continue;
			}

			$name = trim((string)($employee['firstName'] ?? '') . ' ' . (string)($employee['lastName'] ?? ''));
			$rows[] = self::row(kind: 'employee', id: (string)$employeeId, name: $name, result: self::score($requirements, null, null, $codes));
		}

		return $rows;
	}//end employees()

	/**
	 * Whether an application may appear: in play, or rejected with talent-pool
	 * consent and retention time left. Hired applicants are colleagues now.
	 *
	 * @param array<string, mixed> $application The application.
	 * @param string               $today       The day.
	 *
	 * @return boolean
	 */
	private static function mayBeProposed(array $application, string $today): bool {
		$status = (string)($application['status'] ?? '');
		if ($status === 'aangenomen') {
			return false;
		}

		if ($status !== 'afgewezen') {
			return true;
		}

		$retention = (string)($application['retentionExpiryDate'] ?? '');
		return ($application['talentPoolOptIn'] ?? false) === true && $retention !== '' && $retention >= $today;
	}//end mayBeProposed()

	/**
	 * One row of the list, flat for a table.
	 *
	 * @param string                                                                                                               $kind   Applicant or employee.
	 * @param string                                                                                                               $id     The record id.
	 * @param string                                                                                                               $name   The person's name.
	 * @param array{total: int, points: array{education: float, experience: float, required: float, preferred: float}, missing: list<string>} $result The score.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(string $kind, string $id, string $name, array $result): array {
		return [
			'kind' => $kind,
			'id' => $id,
			'name' => $name,
			'total' => $result['total'],
			'educationPoints' => $result['points']['education'],
			'experiencePoints' => $result['points']['experience'],
			'requiredPoints' => $result['points']['required'],
			'preferredPoints' => $result['points']['preferred'],
			'missing' => implode(', ', $result['missing']),
		];
	}//end row()

	/**
	 * Education points: all or nothing against the minimum.
	 *
	 * @param integer      $minimum The minimum level.
	 * @param integer|null $level   The candidate's level.
	 * @param list<string> $missing Collects what is missing.
	 *
	 * @return float
	 */
	private static function educationPoints(int $minimum, ?int $level, array &$missing): float {
		if ($level === null) {
			$missing[] = 'education unknown';
			return 0.0;
		}

		if ($level >= $minimum) {
			return self::EDUCATION_POINTS;
		}

		$missing[] = 'education level ' . $minimum;
		return 0.0;
	}//end educationPoints()

	/**
	 * Experience points: pro rata up to the minimum.
	 *
	 * @param float        $minimum The minimum years.
	 * @param float|null   $years   The candidate's years.
	 * @param list<string> $missing Collects what is missing.
	 *
	 * @return float
	 */
	private static function experiencePoints(float $minimum, ?float $years, array &$missing): float {
		if ($years === null) {
			$missing[] = 'experience unknown';
			return 0.0;
		}

		if ($years < $minimum) {
			$missing[] = $minimum . ' years of experience';
		}

		return round(self::EXPERIENCE_POINTS * min(1.0, $years / $minimum), 1);
	}//end experiencePoints()

	/**
	 * A pool of points shared equally over the codes, earned per code held.
	 *
	 * @param float        $pool    The points in the pool.
	 * @param list<string> $codes   The codes asked for.
	 * @param list<string> $held    The codes held.
	 * @param list<string> $missing Collects what is missing.
	 *
	 * @return float
	 */
	private static function share(float $pool, array $codes, array $held, array &$missing): float {
		if ($codes === []) {
			return $pool;
		}

		$earned = 0.0;
		foreach ($codes as $code) {
			if (in_array($code, $held, true) === true) {
				$earned += ($pool / count($codes));
				continue;
			}

			$missing[] = $code;
		}

		return round($earned, 1);
	}//end share()

	/**
	 * Clean competence codes.
	 *
	 * @param mixed $codes The raw list.
	 *
	 * @return list<string>
	 */
	private static function codes(mixed $codes): array {
		if (is_array($codes) === false) {
			return [];
		}

		return array_values(array_unique(array_filter(array_map(static fn ($code): string => trim((string)$code), $codes), static fn (string $code): bool => $code !== '')));
	}//end codes()

	/**
	 * An integer or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return integer|null
	 */
	private static function intOrNull(mixed $value): ?int {
		if (is_numeric($value) === false) {
			return null;
		}

		return (int)$value;
	}//end intOrNull()

	/**
	 * A float or null.
	 *
	 * @param mixed $value The raw value.
	 *
	 * @return float|null
	 */
	private static function floatOrNull(mixed $value): ?float {
		if (is_numeric($value) === false) {
			return null;
		}

		return (float)$value;
	}//end floatOrNull()

}//end class
