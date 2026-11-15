<?php

/**
 * Humaniq IssuedCredential
 *
 * One credential learniq issued, being taken over inside
 * LearniqCredentialListener: its id, the credential as learniq saved it, and
 * learniq's register slug.
 *
 * @category Listener
 * @package  OCA\Humaniq\Listener
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
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Listener;

/**
 * A learniq credential to record on the personnel file.
 *
 * @spec openspec/specs/training-and-lms-sync/spec.md#REQ-TRN-003
 */
final class IssuedCredential {

	/**
	 * The credential id.
	 *
	 * @var string
	 */
	public string $id = '';

	/**
	 * The credential as learniq saved it.
	 *
	 * @var array<string, mixed>
	 */
	public array $data = [];

	/**
	 * learniq's register slug.
	 *
	 * @var string
	 */
	public string $register = '';
}//end class
