<?php

/**
 * BuildsFindOptions.
 *
 * Builds the ObjectFindOptions OpenRegister's ObjectService::find() takes,
 * for a read that names only a register and a schema. A class that reads
 * through this keeps OpenRegister's value object out of its own dependency
 * list.
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
 * @spec exclude object service plumbing shared by services, no behavioural spec surface.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Support;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * The find options for one register and schema.
 */
trait BuildsFindOptions {
	/**
	 * The options of a read from one register and schema.
	 *
	 * @param string $register The register slug.
	 * @param string $schema   The schema slug.
	 *
	 * @return ObjectFindOptions The options.
	 */
	private function findOptions(string $register, string $schema): ObjectFindOptions {
		return new ObjectFindOptions(register: $register, schema: $schema);
	}//end findOptions()
}//end trait
