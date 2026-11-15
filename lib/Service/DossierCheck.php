<?php

/**
 * Humaniq DossierCheck
 *
 * The state of judging one employee's dossier inside
 * DossierCompletenessService: the employee's rows, the day judged on, the
 * employment start, and the requirement being judged with its evidence.
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
 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use DateTimeImmutable;

/**
 * One employee's dossier being judged.
 *
 * @spec openspec/specs/dossier-completeness/spec.md#REQ-DCP-002
 */
final class DossierCheck {

	/**
	 * The employee's rows, one list per source schema.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $rows = [];

	/**
	 * The day judged on, at midnight.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $day;

	/**
	 * The employment start, or null when unknown.
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $start = null;

	/**
	 * The requirement being judged.
	 *
	 * @var array<string, mixed>
	 */
	public array $requirement = [];

	/**
	 * The newest evidence for that requirement, normalised, or null.
	 *
	 * @var array{schema: string, id: string, issuedOn: string|null, validUntil: string|null}|null
	 */
	public ?array $evidence = null;
}//end class
