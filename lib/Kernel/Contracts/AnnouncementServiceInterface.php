<?php

/**
 * Contract of AnnouncementService
 *
 * The public surface of AnnouncementService that other components call, so they depend
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

/**
 * Contract of AnnouncementService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AnnouncementServiceInterface {
	public const CONFIRMATION_SLUG = 'announcementconfirmation';

	/**
	 * The published announcements the caller is in the audience of today,
	 * newest first, each with the caller's own confirmation.
	 *
	 * @param string $uid   The caller.
	 * @param string $today The day, `YYYY-MM-DD`.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-001
	 */
	public function mine(string $uid, string $today): array;

	/**
	 * Confirm, as the caller, that they read an announcement.
	 *
	 * @param string $announcementId The announcement.
	 * @param string $uid            The caller.
	 * @param string $now            The moment, ISO 8601.
	 *
	 * @return array{status: int, message: ?string, confirmation: ?array<string, mixed>} 201, or 404/409 with the reason.
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-002
	 */
	public function confirm(string $announcementId, string $uid, string $now): array;

	/**
	 * Who in the audience confirmed an announcement and who did not, those
	 * who did not first.
	 *
	 * @param string $announcementId The announcement.
	 * @param string $today          The day the audience is taken on.
	 *
	 * @return array<string, mixed>|null The overview, or null when it does not exist.
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-002
	 */
	public function overview(string $announcementId, string $today): ?array;

	/**
	 * Place a confirmation created past the endpoint on the caller, or refuse
	 * it when the employee already confirmed that announcement.
	 *
	 * @param array<string, mixed> $confirmation The confirmation being created.
	 * @param string               $uid          The caller.
	 *
	 * @return array{stamps: array<string, mixed>, error: ?string}
	 *
	 * @spec openspec/specs/announcements-and-digest/spec.md#REQ-AND-002
	 */
	public function place(array $confirmation, string $uid): array;
}//end interface
