<?php

/**
 * Humaniq RecruitingController
 *
 * Two reads for HR: who fits a vacancy, with every point explained, and the
 * average score per criterion over an application's evaluations. Each
 * resolves the object as the caller first (404), then requires HR or an
 * administrator (403) (hiring-candidate-assessment D1, D2).
 *
 * @category Controller
 * @package  OCA\Humaniq\Controller
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
 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-001
 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Kernel\Contracts\VacancyMatchServiceInterface;
use OCA\Humaniq\Service\CvProfileReader;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Matches and evaluation averages.
 */
class RecruitingController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                     $request     The request.
	 * @param RbacObjectReader             $rbac        Reads as the caller.
	 * @param HumaniqRoles                 $roles       HR membership.
	 * @param VacancyMatchServiceInterface $matcher     The match score.
	 * @param RecordStoreInterface         $gateway     Reads the evaluations.
	 * @param IUserSession                 $userSession The caller.
	 * @param CvProfileReader|null         $cvReader    Reads a CV into the profile.
	 *
	 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-002
	 */
	public function __construct(
		IRequest $request,
		private readonly RbacObjectReader $rbac,
		private readonly HumaniqRoles $roles,
		private readonly VacancyMatchServiceInterface $matcher,
		private readonly RecordStoreInterface $gateway,
		private readonly IUserSession $userSession,
		private readonly ?CvProfileReader $cvReader = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The ranked matches for a vacancy.
	 *
	 * @param string $id The vacancy id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-002
	 */
	#[NoAdminRequired]
	public function matches(string $id): JSONResponse {
		if ($this->rbac->findOrNull(id: $id, schema: 'Vacancy') === null) {
			return new JSONResponse(['message' => 'Vacancy not found.'], Http::STATUS_NOT_FOUND);
		}

		if ($this->mayRead() === false) {
			return new JSONResponse(['message' => 'Only HR or an administrator can see who fits a vacancy.'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['vacancyId' => $id, 'matches' => $this->matcher->matchesFor($id, gmdate('Y-m-d'))]);
	}//end matches()

	/**
	 * The average score per criterion over an application's evaluations.
	 *
	 * @param string $id The application id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-001
	 */
	#[NoAdminRequired]
	public function evaluationSummary(string $id): JSONResponse {
		if ($this->rbac->findOrNull(id: $id, schema: 'job-application') === null) {
			return new JSONResponse(['message' => 'Application not found.'], Http::STATUS_NOT_FOUND);
		}

		if ($this->mayRead() === false) {
			return new JSONResponse(['message' => 'Only HR or an administrator can see the average scores.'], Http::STATUS_FORBIDDEN);
		}

		$evaluations = $this->gateway->findFiltered('CandidateEvaluation', ['applicationId' => $id]);
		return new JSONResponse(['applicationId' => $id, 'evaluations' => count($evaluations), 'criteria' => self::averages(evaluations: $evaluations)]);
	}//end evaluationSummary()

	/**
	 * Read CV: fill an empty profile from the application's CV through filinq.
	 * The application is resolved as the caller (404), then HR or an
	 * administrator is required (403).
	 *
	 * @param string $id The application id.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/hiring-cv-profile-extraction/specs/candidate-assessment/spec.md#REQ-CAS-004
	 */
	#[NoAdminRequired]
	public function readCv(string $id): JSONResponse {
		$application = $this->rbac->findOrNull(id: $id, schema: 'job-application');
		if ($application === null || $this->cvReader === null) {
			return new JSONResponse(['message' => 'Application not found.'], Http::STATUS_NOT_FOUND);
		}

		if ($this->mayRead() === false) {
			return new JSONResponse(['message' => 'Only HR or an administrator can read a CV into the profile.'], Http::STATUS_FORBIDDEN);
		}

		$answer = $this->cvReader->read(application: ($application + ['id' => $id]), userId: (string)($this->userSession->getUser()?->getUID() ?? ''));
		// Only a filled profile reads as done; every other answer carries the
		// reason the page shows, and leaves the application unchanged.
		$code = Http::STATUS_UNPROCESSABLE_ENTITY;
		if ($answer['status'] === 'filled') {
			$code = Http::STATUS_OK;
		}

		return new JSONResponse(['applicationId' => $id] + $answer, $code);
	}//end readCv()

	/**
	 * Average score per criterion, in the order the criteria first appear.
	 *
	 * @param list<array<string, mixed>> $evaluations The evaluations.
	 *
	 * @return list<array{criterion: string, average: float, count: int}>
	 *
	 * @spec openspec/specs/candidate-assessment/spec.md#REQ-CAS-001
	 */
	public static function averages(array $evaluations): array {
		$sums = [];
		foreach ($evaluations as $evaluation) {
			foreach ((array)($evaluation['scores'] ?? []) as $score) {
				$criterion = trim((string)($score['criterion'] ?? ''));
				if ($criterion === '' || is_numeric($score['score'] ?? null) === false) {
					continue;
				}

				$sums[$criterion] = ($sums[$criterion] ?? ['total' => 0, 'count' => 0]);
				$sums[$criterion]['total'] += (int)$score['score'];
				$sums[$criterion]['count']++;
			}
		}

		$out = [];
		foreach ($sums as $criterion => $sum) {
			$out[] = ['criterion' => (string)$criterion, 'average' => round($sum['total'] / $sum['count'], 1), 'count' => $sum['count']];
		}

		return $out;
	}//end averages()

	/**
	 * Whether the caller is HR or an administrator.
	 *
	 * @return boolean
	 */
	private function mayRead(): bool {
		return $this->roles->isHr((string)($this->userSession->getUser()?->getUID() ?? ''));
	}//end mayRead()

}//end class
