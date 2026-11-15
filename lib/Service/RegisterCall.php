<?php

/**
 * Humaniq RegisterCall
 *
 * One call to a base register's integriq source: the register, the path,
 * the verb, the request config and whether integriq keeps a CallLog.
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

/**
 * One call to a base register.
 *
 * @spec openspec/specs/register-prefill/spec.md#REQ-RPF-001
 */
final class RegisterCall {

	/**
	 * The register key: `rdw` or `brp`.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The path on the source.
	 *
	 * @var string
	 */
	public string $endpoint = '';

	/**
	 * The verb.
	 *
	 * @var string
	 */
	public string $method = 'GET';

	/**
	 * The request config (query, body, headers).
	 *
	 * @var array<string, mixed>
	 */
	public array $config = [];

	/**
	 * Whether integriq keeps a CallLog of the call.
	 *
	 * @var boolean
	 */
	public bool $persistLog = true;
}//end class
