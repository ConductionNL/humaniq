<?php

/**
 * ChecksShillinqAvailability.
 *
 * Whether shillinq and its target schema answer, for a hand-off that offers the schema through SHILLINQ_REGISTER and SHILLINQ_SCHEMA.
 *
 * @category Support
 * @package  OCA\Humaniq\Support
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
 * @spec exclude helper shared by services and checks, no behavioural spec surface.
 */

declare(strict_types=1);

namespace OCA\Humaniq\Support;

/**
 * Whether shillinq and its target schema answer.
 */
trait ChecksShillinqAvailability {
	/**
	 * Duck-typed shillinq availability probe (design.md D7): shillinq must be
	 * installed AND its JournalEntry register/schema must resolve.
	 *
	 * @return bool
	 */
	private function shillinqAvailable(): bool {
		if ($this->appManager->isInstalled(self::SHILLINQ_APP_ID) === false) {
			return false;
		}

		try {
			$this->objectService()->setRegister(self::SHILLINQ_REGISTER)->setSchema(self::SHILLINQ_SCHEMA)->findAll(['limit' => 1]);
		} catch (\Throwable $e) {
			return false;
		}

		return true;
	}//end shillinqAvailable()
}//end class
