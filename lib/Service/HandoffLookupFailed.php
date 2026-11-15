<?php

/**
 * HandoffLookupFailed.
 *
 * A hand-off could not read what it must check before it writes, so it
 * writes nothing rather than treat the failure as "not there".
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
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use RuntimeException;

/**
 * Thrown by a hand-off lookup that failed.
 *
 * @spec openspec/specs/payroll-glpost-shillinq/spec.md#REQ-PGP-007
 * @spec openspec/specs/payroll-sepa-netpay-shillinq/spec.md#REQ-PNP-006
 */
class HandoffLookupFailed extends RuntimeException {
}//end class
