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
	 * The points pool of each competence part.
	 *
	 * @var array<string, float>
	 */
	private const POOLS = [
		'required' => self::REQUIRED_POINTS,
		'preferred' => self::PREFERRED_POINTS,
	];

	/**
	 * Constructor.
	 *
	 * @param RecordStoreInterface $gateway Reads the register.
	 *
	 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-002
	 */
	public function __construct(
		private readonly RecordStoreInterface $gateway,
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
	 * @param array{education: ?int, experience: ?float, required: list<string>, preferred: list<string>} $requirements The vacancy's requirements.
	 * @param MatchCandidate                                                                                 $candidate    The candidate: education level and years (null when unknown) and the competence codes held.
	 *
	 * @return array{total: int, points: array{education: float, experience: float, required: float, preferred: float}, missing: list<string>}
	 *
	 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-002
	 */
	public static function score(array $requirements, MatchCandidate $candidate): array {
		$scoring = new CandidateScoring();
		$scoring->requirements = $requirements;
		$scoring->candidate = $candidate;
		$scoring->held = self::codes($candidate->competences);
		$points = self::points(scoring: $scoring);

		return [
			'total' => (int)round($points['education'] + $points['experience'] + $points['required'] + $points['preferred']),
			'points' => $points,
			'missing' => $scoring->missing,
		];
	}//end score()

	/**
	 * The points per part, collecting what is missing in this order:
	 * education, experience, required and preferred competences.
	 *
	 * @param CandidateScoring $scoring The candidate being scored.
	 *
	 * @return array{education: float, experience: float, required: float, preferred: float}
	 */
	private static function points(CandidateScoring $scoring): array {
		return [
			'education' => ($scoring->requirements['education'] === null ? self::EDUCATION_POINTS : self::educationPoints(scoring: $scoring)),
			'experience' => (($scoring->requirements['experience'] !== null && $scoring->requirements['experience'] > 0.0) ? self::experiencePoints(scoring: $scoring) : self::EXPERIENCE_POINTS),
			'required' => self::share(scoring: $scoring, part: 'required'),
			'preferred' => self::share(scoring: $scoring, part: 'preferred'),
		];
	}//end points()

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

			$candidate = self::applicantCandidate(application: $application);
			if ($candidate !== null) {
				$rows[] = self::row(candidate: $candidate, result: self::score(requirements: $requirements, candidate: $candidate));
			}
		}

		return $rows;
	}//end applicants()

	/**
	 * An applicant as a candidate, or null when the application has no
	 * profile at all to score.
	 *
	 * @param array<string, mixed> $application The application.
	 *
	 * @return MatchCandidate|null
	 */
	private static function applicantCandidate(array $application): ?MatchCandidate {
		$candidate = new MatchCandidate();
		$candidate->educationLevel = self::intOrNull($application['educationLevel'] ?? null);
		$candidate->experienceYears = self::floatOrNull($application['experienceYears'] ?? null);
		$candidate->competences = self::codes($application['competenceCodes'] ?? []);
		if ($candidate->educationLevel === null && $candidate->experienceYears === null && $candidate->competences === []) {
			return null;
		}

		$candidate->kind = 'applicant';
		$candidate->id = (string)($application['id'] ?? '');
		$candidate->name = (string)($application['candidateName'] ?? '');
		return $candidate;
	}//end applicantCandidate()

	/**
	 * Colleagues holding at least one competence that is current today.
	 *
	 * @param array{education: ?int, experience: ?float, required: list<string>, preferred: list<string>} $requirements The requirements.
	 * @param string                                                                                         $today        The day.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function employees(array $requirements, string $today): array {
		$rows = [];
		foreach ($this->heldCompetences(today: $today) as $employeeId => $codes) {
			$candidate = $this->employeeCandidate(employeeId: (string)$employeeId, codes: $codes);
			if ($candidate !== null) {
				$rows[] = self::row(candidate: $candidate, result: self::score(requirements: $requirements, candidate: $candidate));
			}
		}

		return $rows;
	}//end employees()

	/**
	 * A colleague as a candidate, with no education or experience known, or
	 * null when the Employee is not found.
	 *
	 * @param string       $employeeId The employee.
	 * @param list<string> $codes      The competence codes current today.
	 *
	 * @return MatchCandidate|null
	 */
	private function employeeCandidate(string $employeeId, array $codes): ?MatchCandidate {
		$employee = $this->gateway->findObjectData($employeeId, 'Employee');
		if ($employee === null) {
			return null;
		}

		$candidate = new MatchCandidate();
		$candidate->kind = 'employee';
		$candidate->id = $employeeId;
		$candidate->name = trim((string)($employee['firstName'] ?? '') . ' ' . (string)($employee['lastName'] ?? ''));
		$candidate->competences = $codes;
		return $candidate;
	}//end employeeCandidate()

	/**
	 * The competence codes each colleague holds on the day, by employee, in first-seen order.
	 *
	 * @param string $today The day (Y-m-d).
	 *
	 * @return array<string, list<string>>
	 */
	private function heldCompetences(string $today): array {
		$held = [];
		foreach ($this->gateway->loadAll('EmployeeCompetence') as $competence) {
			$employeeId = (string)($competence['employeeId'] ?? '');
			if ($employeeId !== '' && self::isCurrentOn(competence: $competence, today: $today) === true) {
				$held[$employeeId][] = (string)($competence['competenceCode'] ?? '');
			}
		}

		return $held;
	}//end heldCompetences()

	/**
	 * Whether a competence was issued by the day and is still valid on it; a missing date does not limit it.
	 *
	 * @param array<string, mixed> $competence The EmployeeCompetence.
	 * @param string $today The day (Y-m-d).
	 *
	 * @return bool
	 */
	private static function isCurrentOn(array $competence, string $today): bool {
		$issued = (string)($competence['issuedOn'] ?? '');
		$until = (string)($competence['validUntil'] ?? '');

		return ($issued === '' || $issued <= $today) && ($until === '' || $until >= $today);
	}//end isCurrentOn()

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
	 * @param MatchCandidate                                                                                                       $candidate The candidate: kind, id and name.
	 * @param array{total: int, points: array{education: float, experience: float, required: float, preferred: float}, missing: list<string>} $result    The score.
	 *
	 * @return array<string, mixed>
	 */
	private static function row(MatchCandidate $candidate, array $result): array {
		return [
			'kind' => $candidate->kind,
			'id' => $candidate->id,
			'name' => $candidate->name,
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
	 * @param CandidateScoring $scoring The candidate being scored, against a vacancy with a minimum level; collects what is missing.
	 *
	 * @return float
	 */
	private static function educationPoints(CandidateScoring $scoring): float {
		$minimum = (int)$scoring->requirements['education'];
		$level = $scoring->candidate->educationLevel;
		if ($level === null) {
			$scoring->missing[] = 'education unknown';
			return 0.0;
		}

		if ($level >= $minimum) {
			return self::EDUCATION_POINTS;
		}

		$scoring->missing[] = 'education level ' . $minimum;
		return 0.0;
	}//end educationPoints()

	/**
	 * Experience points: pro rata up to the minimum.
	 *
	 * @param CandidateScoring $scoring The candidate being scored, against a vacancy with a minimum above zero; collects what is missing.
	 *
	 * @return float
	 */
	private static function experiencePoints(CandidateScoring $scoring): float {
		$minimum = (float)$scoring->requirements['experience'];
		$years = $scoring->candidate->experienceYears;
		if ($years === null) {
			$scoring->missing[] = 'experience unknown';
			return 0.0;
		}

		if ($years < $minimum) {
			$scoring->missing[] = $minimum . ' years of experience';
		}

		return round(self::EXPERIENCE_POINTS * min(1.0, $years / $minimum), 1);
	}//end experiencePoints()

	/**
	 * A pool of points shared equally over the codes a part asks for, earned
	 * per code held; in full when the part asks for none.
	 *
	 * @param CandidateScoring $scoring The candidate being scored; collects what is missing.
	 * @param string           $part    `required` or `preferred`.
	 *
	 * @return float
	 */
	private static function share(CandidateScoring $scoring, string $part): float {
		if ($scoring->requirements[$part] === []) {
			return self::POOLS[$part];
		}

		return round(self::earned(scoring: $scoring, part: $part), 1);
	}//end share()

	/**
	 * The points earned of a part's pool, one equal share per code held; a
	 * code not held is missing.
	 *
	 * @param CandidateScoring $scoring The candidate being scored; collects what is missing.
	 * @param string           $part    `required` or `preferred`, asking for at least one code.
	 *
	 * @return float
	 */
	private static function earned(CandidateScoring $scoring, string $part): float {
		$pool = self::POOLS[$part];
		$codes = $scoring->requirements[$part];
		$earned = 0.0;
		foreach ($codes as $code) {
			if (in_array($code, $scoring->held, true) === true) {
				$earned += ($pool / count($codes));
				continue;
			}

			$scoring->missing[] = $code;
		}

		return $earned;
	}//end earned()

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
