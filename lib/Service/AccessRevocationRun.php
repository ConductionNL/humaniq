<?php

/**
 * Humaniq AccessRevocationRun
 *
 * The state of revoking access on one offboarding case: the case, who
 * revokes and when.
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
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * One case whose leaver's account is being disabled.
 *
 * @spec openspec/specs/offboarding-completion/spec.md#REQ-OFC-003
 */
final class AccessRevocationRun {

	/**
	 * The case id.
	 *
	 * @var string
	 */
	public string $offboardingId = '';

	/**
	 * The stored case.
	 *
	 * @var array<string, mixed>
	 */
	public array $case = [];

	/**
	 * Who revokes.
	 *
	 * @var string
	 */
	public string $actorUid = '';

	/**
	 * The moment, ISO 8601.
	 *
	 * @var string
	 */
	public string $now = '';
}//end class
