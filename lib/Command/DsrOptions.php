<?php

/**
 * Humaniq DsrOptions
 *
 * The options the AVG data-subject-rights commands read alike: the employee
 * and the optional DsrRequest id.
 *
 * @category Command
 * @package  OCA\Humaniq\Command
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
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-003
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reads the shared options of the AVG data-subject-rights commands.
 *
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-004
 */
final class DsrOptions {

	/**
	 * The trimmed `--employee`; null, with the error written, when it is empty.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-004
	 */
	public static function requiredEmployeeId(InputInterface $input, OutputInterface $output): ?string {
		$employeeId = trim((string)$input->getOption('employee'));
		if ($employeeId === '') {
			$output->writeln('<error>--employee is verplicht.</error>');
			return null;
		}

		return $employeeId;
	}//end requiredEmployeeId()

	/**
	 * The trimmed `--dsr-request-id`, or null when it is absent or blank.
	 *
	 * @param InputInterface $input Console input.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-004
	 */
	public static function dsrRequestId(InputInterface $input): ?string {
		$option = $input->getOption('dsr-request-id');

		return (is_string($option) === true && trim($option) !== '') ? trim($option) : null;
	}//end dsrRequestId()
}//end class
