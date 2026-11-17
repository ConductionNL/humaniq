<?php

/**
 * Policy App Missing Exception
 *
 * A save of the password policy while Nextcloud's `password_policy` app is not
 * enabled: there is no policy to write to.
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
 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use RuntimeException;

/**
 * The password policy app is not enabled.
 *
 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
 */
class PolicyAppMissingException extends RuntimeException {
}//end class
