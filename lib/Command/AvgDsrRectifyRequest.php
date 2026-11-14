<?php

/**
 * AVG rectification request
 *
 * The options of one `humaniq:avg:rectify` run: the employee, the DsrRequest
 * it is recorded against and the decoded field changes, plus the employee id
 * once it resolved under the privileged session. Knows which of its options
 * are missing or malformed.
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
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use Symfony\Component\Console\Input\InputInterface;

/**
 * One rectification run's options.
 *
 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-007
 */
final class AvgDsrRectifyRequest {

	/**
	 * The Employee id (trimmed).
	 *
	 * @var string
	 */
	public string $employeeId = '';

	/**
	 * The DsrRequest id (trimmed).
	 *
	 * @var string
	 */
	public string $dsrRequestId = '';

	/**
	 * The decoded --changes JSON, whatever it decoded to.
	 *
	 * @var mixed
	 */
	public mixed $changes = null;

	/**
	 * The employee id as resolved under the privileged session, null until
	 * (or unless) it resolves.
	 *
	 * @var string|null
	 */
	public ?string $resolvedEmployeeId = null;

	/**
	 * Read the request from the command's options.
	 *
	 * @param InputInterface $input Console input.
	 *
	 * @return self
	 *
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-007
	 */
	public static function fromInput(InputInterface $input): self {
		$request = new self();
		$request->employeeId = trim((string)$input->getOption('employee'));
		$request->dsrRequestId = trim((string)$input->getOption('dsr-request-id'));
		$request->changes = json_decode((string)$input->getOption('changes'), true);

		return $request;
	}//end fromInput()

	/**
	 * Why the options cannot be used, in the order they are checked, or null.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/avg-dsr/spec.md#REQ-DSR-007
	 */
	public function inputError(): ?string {
		$missing = $this->missingOption();
		if ($missing !== null) {
			return $missing;
		}

		if (is_array($this->changes) === false || $this->changes === []) {
			return '--changes moet een niet-lege JSON-object zijn.';
		}

		return null;
	}//end inputError()

	/**
	 * The message for the first required id option left blank, or null.
	 *
	 * @return string|null
	 */
	private function missingOption(): ?string {
		if ($this->employeeId === '') {
			return '--employee is verplicht.';
		}

		if ($this->dsrRequestId === '') {
			return '--dsr-request-id is verplicht.';
		}

		return null;
	}//end missingOption()
}//end class
