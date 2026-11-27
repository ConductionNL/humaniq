<?php

/**
 * Shipped Flow Adoption
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Answers whether an administrator has adopted a flow humaniq ships. A shipped
 * flow arrives disabled (OpenRegister's adoption contract); enabling it is the
 * adoption. The flow is found by its import identity (app, name and the schema
 * whose save imports it), the key `SchemaFlowImportListener` upserts on, never
 * by uuid, which differs per instance.
 *
 * Read by write guards that only apply once a flow carries a decision, so the
 * pre-adoption path keeps working on an instance that has not adopted it.
 *
 * @category Service
 * @package  OCA\Humaniq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Kernel\Contracts\OpenRegisterAvailabilityInterface;
use Psr\Container\ContainerInterface;

/**
 * Whether a shipped flow is enabled on this instance.
 *
 * @spec openspec/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-003
 */
class ShippedFlowAdoption {

	/**
	 * OpenRegister's flow store, resolved lazily.
	 *
	 * @var string
	 */
	private const FLOW_MAPPER = 'OCA\OpenRegister\Db\FlowMapper';

	/**
	 * Constructor.
	 *
	 * @param OpenRegisterAvailabilityInterface $openRegister Whether OpenRegister is installed.
	 * @param ContainerInterface                $container    Resolves OpenRegister's flow store lazily.
	 *
	 * @spec openspec/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-003
	 */
	public function __construct(
		private readonly OpenRegisterAvailabilityInterface $openRegister,
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Whether the flow with this import identity is stored and enabled.
	 *
	 * Without OpenRegister, or without the flow imported, nothing is adopted.
	 * A failing lookup is thrown to the caller, which decides its own
	 * direction.
	 *
	 * @param string $app           The app that ships the flow.
	 * @param string $name          The flow's name.
	 * @param string $triggerSchema The schema whose save imports the flow.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-003
	 */
	public function isAdopted(string $app, string $name, string $triggerSchema): bool {
		if ($this->openRegister->isAvailable() === false) {
			return false;
		}

		$mapper = $this->container->get(self::FLOW_MAPPER);
		foreach ($mapper->findAllFlows(app: $app, limit: 500) as $flow) {
			if ((string)$flow->getName() === $name && (string)$flow->getTriggerSchema() === $triggerSchema) {
				return $flow->getEnabled() === true;
			}
		}

		return false;
	}//end isAdopted()
}//end class
