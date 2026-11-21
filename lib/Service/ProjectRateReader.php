<?php

/**
 * Project Rate Reader
 *
 * The hourly rate a project bills at, in cents (REQ-TEC-009). humaniq models
 * no project: planninq does (register `planninq`, schema `project`), whose
 * `hourlyRate` is the project's default rate in euros, and a humaniq
 * `TimeEntry.projectId` names such a project. The read is a system read
 * because the rate is a fact of the project, not of the employee booking the
 * hours. Without planninq, OpenRegister or the project there is no rate.
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
 * @spec openspec/specs/time-entry-capture/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\Kernel\Contracts\OpenRegisterAvailabilityInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A planninq project's hourly rate in cents.
 *
 * @spec openspec/specs/time-entry-capture/spec.md#requirement-a-billable-booking-shall-carry-the-rate-it-is-billed-at-req-tec-009
 */
class ProjectRateReader {

	/**
	 * Constructor.
	 *
	 * @param IAppManager                       $appManager   Whether planninq is installed.
	 * @param ContainerInterface                $container    Resolves OpenRegister's ObjectService.
	 * @param OpenRegisterAvailabilityInterface $openRegister OpenRegister availability.
	 * @param LoggerInterface                   $logger       Logger.
	 *
	 * @spec openspec/specs/time-entry-capture/spec.md#requirement-a-billable-booking-shall-carry-the-rate-it-is-billed-at-req-tec-009
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly OpenRegisterAvailabilityInterface $openRegister,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The project's hourly rate in cents, or null when there is none.
	 *
	 * @param string $projectId The planninq project id.
	 *
	 * @return int|null
	 *
	 * @spec openspec/specs/time-entry-capture/spec.md#requirement-a-billable-booking-shall-carry-the-rate-it-is-billed-at-req-tec-009
	 */
	public function rateCents(string $projectId): ?int {
		$projectId = trim($projectId);
		if ($projectId === '' || $this->appManager->isInstalled(CostRateAccess::PLANNINQ_APP_ID) === false || $this->openRegister->isAvailable() === false) {
			return null;
		}

		try {
			$entity = $this->container->get('OCA\OpenRegister\Service\ObjectService')->find(
				id: $projectId,
				options: new ObjectFindOptions(register: CostRateAccess::PLANNINQ_REGISTER, schema: CostRateAccess::PLANNINQ_SCHEMA),
				_rbac: false,
				_multitenancy: false
			);
		} catch (\Throwable $e) {
			$this->logger->info('humaniq: project rate not read (' . $e::class . ')');
			return null;
		}

		$project = (is_object($entity) === true && method_exists($entity, 'getObject') === true) ? $entity->getObject() : null;
		$rate = (is_array($project) === true) ? ($project['hourlyRate'] ?? null) : null;
		if (is_numeric($rate) === false || (float)$rate <= 0.0) {
			return null;
		}

		return (int)round(((float)$rate) * 100);
	}//end rateCents()
}//end class
