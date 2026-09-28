<?php

/**
 * OpenRegister notification recipient-resolver interface test stub
 *
 * TEST-ONLY shape of `OCA\OpenRegister\Service\Notification\RecipientResolverInterface`,
 * a method-for-method mirror of openregister development ac7296b4: the seam an
 * `x-openregister-notifications` recipient of `kind: expression` resolves
 * through. humaniq's CaseManagerResolver implements it. Loaded ONLY from
 * tests/bootstrap.php behind an interface_exists() check, so the real
 * interface always wins on a live instance.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests
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

namespace OCA\OpenRegister\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Test-stub mirror of OpenRegister's RecipientResolverInterface.
 */
interface RecipientResolverInterface {

	/**
	 * Resolve the recipients for an event on an object.
	 *
	 * @param ObjectEntity $object The object the event happened on.
	 * @param array<string, mixed> $context Trigger-specific extras (action, from, to, aggregation, ...).
	 *
	 * @return array<int, string> List of Nextcloud uids.
	 */
	public function resolve(ObjectEntity $object, array $context): array;

}//end interface
