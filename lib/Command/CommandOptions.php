<?php

/**
 * Command Options
 *
 * Reads console options the way the humaniq occ commands treat them: a
 * non-blank string option is trimmed, anything else is absent.
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
 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-003
 * @spec openspec/specs/receipt-ocr/spec.md#REQ-RCPT-006
 */

declare(strict_types=1);

namespace OCA\Humaniq\Command;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Typed reads of console options.
 *
 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-003
 * @spec openspec/specs/receipt-ocr/spec.md#REQ-RCPT-006
 */
final class CommandOptions {

	/**
	 * The trimmed option value, or null when it is not a non-blank string.
	 *
	 * @param InputInterface $input Console input.
	 * @param string         $name  The option name.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/payslip-pdf-docudesk/spec.md#REQ-PPD-003
	 */
	public static function trimmed(InputInterface $input, string $name): ?string {
		$value = $input->getOption($name);

		return (is_string($value) === true && trim($value) !== '') ? trim($value) : null;

	}//end trimmed()
}//end class
