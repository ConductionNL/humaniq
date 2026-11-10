<?php

/**
 * BuildsSaveOptions.
 *
 * Builds the ObjectSaveOptions OpenRegister's ObjectService::saveObject()
 * takes, for a save that names only a register, a schema and optionally the
 * uuid it updates. A class that saves through this keeps OpenRegister's
 * value objects out of its own dependency list.
 *
 * @category Support
 * @package  OCA\Humaniq\Support
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
 * @spec exclude object service plumbing shared by services, jobs and repair steps, no behavioural spec surface.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Support;

use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * The save options for one register, schema and uuid.
 */
trait BuildsSaveOptions {
	/**
	 * The options of a save into one register and schema.
	 *
	 * @param string      $register The register slug.
	 * @param string      $schema   The schema slug.
	 * @param string|null $uuid     The object to update, or null to create one.
	 *
	 * @return ObjectSaveOptions The options.
	 */
	private function saveOptions(string $register, string $schema, ?string $uuid = null): ObjectSaveOptions {
		return new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema), uuid: $uuid);
	}//end saveOptions()
}//end trait
