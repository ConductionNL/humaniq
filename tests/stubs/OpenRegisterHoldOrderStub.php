<?php

/**
 * OpenRegister legal-hold order — test-only mirror.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * RetentionService::placeHold() and releaseHold() take the reason and matter as
 * one `OCA\OpenRegister\Service\HoldOrder`. humaniq builds that order by name
 * only when OpenRegister offers it, so the standalone suite needs the class to
 * reach that path. It mirrors openregister@main lib/Service/HoldOrder.php,
 * parameter names included, because humaniq constructs it by name.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

/**
 * Why a legal hold is placed or released, and for which matter.
 */
final class HoldOrder {

	/**
	 * Constructor.
	 *
	 * @param string      $reason   Why.
	 * @param string|null $ownerKey The matter; null for a manual hold, or to release every hold.
	 */
	public function __construct(
		public readonly string $reason,
		public readonly ?string $ownerKey = null,
	) {
	}//end __construct()
}//end class
