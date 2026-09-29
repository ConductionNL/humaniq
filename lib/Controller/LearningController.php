<?php

/**
 * Humaniq LearningController
 *
 * `GET /api/learning/people`: the people feed a learning platform reads
 * (talent-training-and-lms D3). Every row passes the caller's own
 * OpenRegister RBAC on the Employee, so an integration account sees only the
 * people its Nextcloud account may read.
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
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Controller;

use DateTimeImmutable;
use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\LearningPeopleFeed;
use OCA\Humaniq\Service\RbacObjectReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;

/**
 * Serves the people feed for learning platforms.
 *
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-002
 */
class LearningController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request The request.
	 * @param LearningPeopleFeed $feed    Composes the feed.
	 * @param RbacObjectReader   $rbac    Reads under the caller's own RBAC.
	 * @param ITimeFactory       $time    Today.
	 */
	public function __construct(
		IRequest $request,
		private readonly LearningPeopleFeed $feed,
		private readonly RbacObjectReader $rbac,
		private readonly ITimeFactory $time,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * `GET /api/learning/people?modifiedSince=<ISO 8601>`.
	 *
	 * @param string|null $modifiedSince Only people whose record, placement or unit changed after this moment.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-002
	 */
	#[NoAdminRequired]
	public function people(?string $modifiedSince=null): JSONResponse {
		// A `+` offset that was not URL-encoded arrives as a space.
		$since = (string)preg_replace('/ (\d{2}:\d{2})$/', '+$1', trim((string)$modifiedSince));
		if ($since !== '' && $this->readable($since) === false) {
			return new JSONResponse(['error' => 'modifiedSince moet een datum en tijd zijn, bijvoorbeeld 2026-09-28T00:00:00+00:00.'], Http::STATUS_BAD_REQUEST);
		}

		$people = [];
		$rows = $this->feed->people(today: $this->time->getDateTime()->format('Y-m-d'), modifiedSince: $since === '' ? null : $since);
		foreach ($rows as $row) {
			if ($this->rbac->findOrNull(id: (string)$row['id'], schema: 'Employee') !== null) {
				$people[] = $row;
			}
		}

		return new JSONResponse(['people' => $people]);
	}//end people()

	/**
	 * Whether a moment can be read as a date and time.
	 *
	 * @param string $moment The moment.
	 *
	 * @return bool
	 */
	private function readable(string $moment): bool {
		try {
			new DateTimeImmutable($moment);
		} catch (\Exception $e) {
			return false;
		}

		return true;
	}//end readable()

}//end class
