<?php

/**
 * Register Prefill Unavailable Exception
 *
 * A register lookup that could not fill anything, with a machine reason next
 * to the message: `skipped-no-integriq`, `no-source`, `error`, `not-found` or
 * `not-applicable` (people-register-prefill D1).
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
 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use RuntimeException;

/**
 * The register could not fill the record.
 *
 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
 */
class RegisterPrefillUnavailableException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $message The reason for the user.
	 * @param string $reason  The machine reason.
	 */
	public function __construct(string $message, private readonly string $reason='error') {
		parent::__construct($message);
	}//end __construct()

	/**
	 * The machine reason.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
	 */
	public function getReason(): string {
		return $this->reason;
	}//end getReason()

}//end class
