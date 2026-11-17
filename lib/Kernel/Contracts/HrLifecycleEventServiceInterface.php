<?php

/**
 * Contract of HrLifecycleEventService
 *
 * The public surface of HrLifecycleEventService that other components call, so they depend
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
 * Contract of HrLifecycleEventService.
 *
 * @category Kernel
 * @package  OCA\Humaniq\Kernel\Contracts
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface HrLifecycleEventServiceInterface {
	/**
	 * The schemas whose writes can mark a moment, by slug.
	 */
	public const SLUGS = ['employmentcontract', 'onboarding', 'offboarding', 'orgassignment', 'leaverequest', 'sickleavecase'];

	/**
	 * Detect the moments a write marks and send each.
	 *
	 * @param string $slug The schema slug.
	 * @param array<string, mixed>|null $old The stored record, null on create.
	 * @param array<string, mixed> $new The saved record, with its id.
	 *
	 * @return int How many CloudEvents were handed to the webhook service.
	 *
	 * @spec openspec/specs/hr-lifecycle-events/spec.md#REQ-HLE-001
	 */
	public function emit(string $slug, ?array $old, array $new): int;
}//end interface
