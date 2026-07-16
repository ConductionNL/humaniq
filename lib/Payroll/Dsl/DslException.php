<?php

/**
 * DSL Exception
 *
 * The single error type for every jurisdiction-pack failure — validation
 * rejections and interpreter faults alike (jurisdiction-packs design.md D11).
 * Every message names the offending op, reference, handler or bound so a
 * rejected pack is diagnosable by its author rather than opaquely refused
 * (REQ-JP-008).
 *
 * @category Payroll
 * @package  OCA\Hrmq\Payroll\Dsl
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-008
 */

declare(strict_types=1);

namespace OCA\Hrmq\Payroll\Dsl;

use RuntimeException;

/**
 * Raised whenever a pack is malformed, unresolvable, out of bounds, or fails
 * its own golden vectors.
 */
final class DslException extends RuntimeException
{


}//end class
