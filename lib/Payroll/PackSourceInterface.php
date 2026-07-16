<?php

/**
 * Pack Source Interface
 *
 * The seam between the PURE pack resolver and uploaded packs stored as
 * OpenRegister objects (jurisdiction-packs design.md D7).
 *
 * `lib/Payroll/` carries zero Nextcloud dependencies by design — the whole
 * engine must stay portable outside Nextcloud and directly unit-testable. So
 * the resolver depends on this interface, and the OpenRegister-backed
 * implementation lives in `lib/Service/`, where cross-app dependencies belong.
 *
 * An implementation MUST only ever return a pack that is genuinely ACTIVE. In
 * particular, a pack claiming a `(jurisdiction, taxYear)` that a bundled pack
 * already owns is active only when an admin explicitly recorded it as an
 * override — that decision is recorded on the stored object, never read from
 * the pack document, because the pack document is author-supplied and an
 * author must not be able to promote their own upload over the bundled NL
 * regression contract.
 *
 * @category Payroll
 * @package  OCA\Hrmq\Payroll
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
 * @spec openspec/specs/jurisdiction-packs/spec.md#REQ-JP-006
 */

declare(strict_types=1);

namespace OCA\Hrmq\Payroll;

/**
 * Supplies uploaded, activated packs to the resolver.
 */
interface PackSourceInterface
{


    /**
     * The uploaded pack that is ACTIVE for this key, or null when there is
     * none.
     *
     * @param string $jurisdiction The ISO 3166-1 alpha-2 jurisdiction.
     * @param int    $taxYear      The tax year.
     *
     * @return JurisdictionPack|null
     */
    public function activePack(string $jurisdiction, int $taxYear): ?JurisdictionPack;


}//end interface
