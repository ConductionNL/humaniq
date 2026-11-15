<?php

/**
 * Reference Audit Run
 *
 * One run of `occ humaniq:references:audit`: the ObjectService it reads and
 * writes through, the console it reports to, whether it may write, and the
 * owner records indexed by identity key.
 *
 * @category Command
 * @package  OCA\Humaniq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/multi-administratie/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * The collaborators and the owner index of one reference audit.
 *
 * @spec openspec/specs/multi-administratie/spec.md
 */
final class ReferenceAuditRun {

	/**
	 * Every known owner id.
	 *
	 * @var array<string, bool>
	 */
	private array $ownerIds = [];

	/**
	 * @param mixed $objectService The OpenRegister object service.
	 * @param OutputInterface $output Console output.
	 * @param bool $write Whether this run may write.
	 * @param array<string, array<int, string>> $owners Identity key to owner ids, empty when the owner register could not be read.
	 */
	public function __construct(
		public readonly mixed $objectService,
		public readonly OutputInterface $output,
		public readonly bool $write,
		public readonly array $owners,
	) {
		foreach ($owners as $ids) {
			foreach ($ids as $id) {
				$this->ownerIds[$id] = true;
			}
		}

	}//end __construct()

	/**
	 * Whether an owner record carries this id.
	 *
	 * @param string $id The owner id.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/multi-administratie/spec.md
	 */
	public function knowsOwner(string $id): bool {
		return isset($this->ownerIds[$id]);

	}//end knowsOwner()
}//end class
