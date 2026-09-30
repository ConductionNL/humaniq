<?php

/**
 * Tax table source seam
 *
 * The second home of a tables corpus: an uploaded set an administrator loaded
 * for a year the release has not reached yet (payroll-pack-and-cao-updates
 * design.md D1). `TaxTables::load()` consults it only for an id no bundled
 * file owns, so an upload can never shadow a bundled year. Zero Nextcloud
 * dependencies, the `PackSourceInterface` precedent.
 *
 * @category Payroll
 * @package  OCA\Humaniq\Payroll
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
 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Payroll;

/**
 * Supplies uploaded, active tables documents to the loader.
 */
interface TaxTableSourceInterface {

	/**
	 * The decoded tables document that is ACTIVE for this id, or null when
	 * there is none.
	 *
	 * @param string $id The tables id, e.g. nl-2027.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/specs/payroll-pack-and-table-updates/spec.md#REQ-PKU-001
	 */
	public function activeTables(string $id): ?array;

}//end interface
