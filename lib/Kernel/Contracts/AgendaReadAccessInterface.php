<?php

/**
 * Contract of AgendaReadAccess
 *
 * The public surface of AgendaReadAccess that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
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
 * @spec exclude Structural contract, no functional requirement.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Kernel\Contracts;

use DateTimeImmutable;

/**
 * Contract of AgendaReadAccess.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AgendaReadAccessInterface {
	/**
	 * What the session user may see of one subject's agenda: null when they
	 * may not open it, `['all' => true]` when they see every entry in full,
	 * otherwise the employees whose entries they see in full.
	 *
	 * @param string $subjectType `employee`, `orgUnit` or `resource`.
	 * @param string $subjectId The subject's id.
	 *
	 * @return array{all: bool, full: array<int, string>}|null
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function grant(string $subjectType, string $subjectId): ?array;

	/**
	 * The employees an availability or capacity answer may cover for the
	 * session user, and those it covers in full. With a unit: its members,
	 * when the user may read the unit's agenda. Without one: everybody for HR,
	 * otherwise the employees whose agenda the user may read. Null when that
	 * leaves nobody.
	 *
	 * @param string $orgUnitId The unit asked for, or '' for none.
	 * @param DateTimeImmutable $onDate The date a unit placement must be active on.
	 *
	 * @return array{ids: array<int, string>, all: bool, full: array<int, string>}|null The scope, or null when refused.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
	 */
	public function employeeScope(string $orgUnitId, DateTimeImmutable $onDate): ?array;

	/**
	 * The entries a grant lets the viewer see: a colleague's interviews and
	 * kinds not listed for colleagues are dropped, and their secondment hours
	 * left out.
	 *
	 * @param array<int, array<string, mixed>> $entries The composed entries.
	 * @param array{all: bool, full: array<int, string>} $grant What {@see grant()} answered.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-007
	 */
	public function visibleEntries(array $entries, array $grant): array;

	/**
	 * The source rows an hours answer may count for this scope: for an
	 * employee not covered in full, no secondment and no interview.
	 *
	 * @param array<string, mixed> $sources The rows the answer reads.
	 * @param array{ids: array<int, string>, all: bool, full: array<int, string>} $scope What {@see employeeScope()} answered.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-008
	 */
	public function visibleSources(array $sources, array $scope): array;

	/**
	 * The employees placed in one org unit on one date. Anyone but HR gets
	 * only those placed there today as well, so an early window does not
	 * bring back former members.
	 *
	 * @param string $orgUnitId The org unit.
	 * @param DateTimeImmutable $onDate The date the placement must be active on.
	 *
	 * @return array<int, string> The employee ids.
	 *
	 * @spec openspec/specs/agenda-and-resource-booking/spec.md#REQ-AGD-001
	 */
	public function members(string $orgUnitId, DateTimeImmutable $onDate): array;
}//end interface
