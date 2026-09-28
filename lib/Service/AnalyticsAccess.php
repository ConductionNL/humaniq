<?php

/**
 * Analytics Access
 *
 * Who may read which steering figures. HR and accountants of the caller's
 * active administration read the administration's figures and those of any
 * unit in it (REQ-DSI-005). department-figures adds one more reader: the
 * manager of a unit reads that unit and the units under it, and nothing
 * else, held to the small-unit threshold (REQ-DPF-002). Nothing here reads a
 * request parameter for the administration: the only tenant a caller may
 * query is the one their own access rows grant.
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
 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-005
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Resolves the caller's administration and the figures they may read.
 *
 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
 */
class AnalyticsAccess {

	/**
	 * `AdministrationAccess.role` values that read every figure of the
	 * administration (REQ-DSI-005); `employee` is not one of them.
	 *
	 * @var array<int, string>
	 */
	private const FULL_READER_ROLES = ['hr', 'accountant'];

	/**
	 * @param AdministrationService    $administrationService The caller's active administration and role.
	 * @param DepartmentFiguresService $departmentFigures     Who manages which unit, and the small-unit threshold.
	 */
	public function __construct(
		private readonly AdministrationService $administrationService,
		private readonly DepartmentFiguresService $departmentFigures,
	) {
	}//end __construct()

	/**
	 * The caller's active administration.
	 *
	 * @param string $userId The Nextcloud user id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
	 */
	public function activeAdministration(string $userId): ?string {
		return $this->administrationService->getActiveAdministrationId($userId);
	}//end activeAdministration()

	/**
	 * The caller's active administration when their role there is `hr` or
	 * `accountant`, else null.
	 *
	 * @param string $userId The Nextcloud user id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-005
	 */
	public function fullReaderAdministration(string $userId): ?string {
		$administrationId = $this->administrationService->getActiveAdministrationId($userId);
		if ($administrationId === null) {
			return null;
		}

		$role = $this->administrationService->getActiveAdministrationRole($userId);
		if (in_array($role, self::FULL_READER_ROLES, true) === false) {
			return null;
		}

		return $administrationId;
	}//end fullReaderAdministration()

	/**
	 * Who may read the administration's figures (`$orgUnitId` '') or one
	 * unit's, and under which small-unit threshold. HR and accountants read
	 * both without the threshold; anyone else only a unit they manage, held
	 * to it. Null means refused.
	 *
	 * @param string $userId    The Nextcloud user id.
	 * @param string $orgUnitId The unit asked for, or '' for the administration.
	 *
	 * @return array{administrationId: string, minimumMembers: int}|null
	 *
	 * @spec openspec/specs/department-figures/spec.md#REQ-DPF-002
	 */
	public function unitReader(string $userId, string $orgUnitId): ?array {
		$administrationId = $this->administrationService->getActiveAdministrationId($userId);
		if ($administrationId === null) {
			return null;
		}

		$role = $this->administrationService->getActiveAdministrationRole($userId);
		if (in_array($role, self::FULL_READER_ROLES, true) === true) {
			return ['administrationId' => $administrationId, 'minimumMembers' => 0];
		}

		if ($orgUnitId === '' || $this->departmentFigures->managesUnit($userId, $administrationId, $orgUnitId) === false) {
			return null;
		}

		return ['administrationId' => $administrationId, 'minimumMembers' => $this->departmentFigures->minimumMembers()];
	}//end unitReader()

}//end class
