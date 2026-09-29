<?php

/**
 * Manager Or Deputy Recipient Resolver
 *
 * The recipients of a "request submitted" notification
 * (self-service-approvals-inbox D3): the request's manager, from its stamped
 * managerUserId or else the org chart, and every deputy standing in for that
 * manager today. The requester is never a recipient. Nothing is stamped on
 * the request, so a deputy period that ends stops the notifications with it.
 *
 * Declared as `{kind: expression, resolver: <this FQCN>}` on the submitted
 * rules of LeaveRequest, Timesheet, Expense and LeaveTransaction;
 * OpenRegister resolves it from the server container.
 *
 * @category Notification
 * @package  OCA\Humaniq\Notification
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
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Notification;

use OCA\Humaniq\Service\ManagerDeputies;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Notification\RecipientResolverInterface;

/**
 * The manager of a submitted request and their active deputies.
 *
 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
 */
class ManagerOrDeputyRecipientResolver implements RecipientResolverInterface {

	/**
	 * @param ManagerDeputies $deputies Managers and their deputies.
	 */
	public function __construct(
		private readonly ManagerDeputies $deputies,
	) {

	}//end __construct()

	/**
	 * The uids to notify about this request.
	 *
	 * @param ObjectEntity $object The submitted request.
	 * @param array<string, mixed> $context Trigger extras (unused: the request decides).
	 *
	 * @return array<int, string>
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $context is part of OpenRegister's interface.
	 *
	 * @spec openspec/specs/approvals-inbox/spec.md#REQ-API-002
	 */
	public function resolve(ObjectEntity $object, array $context): array {
		return $this->deputies->approversOf(request: ($object->getObject() ?? []), today: gmdate('Y-m-d'));
	}//end resolve()

}//end class
