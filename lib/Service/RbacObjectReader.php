<?php

/**
 * Rbac Object Reader
 *
 * Reads one OpenRegister object under the CALLER's own ambient RBAC.
 *
 * The register gateways in this app read with `_rbac: false`, because most
 * views need the whole administration to compose an answer. A view that shows
 * one person what they personally may see needs the opposite, and asking for
 * it must be a deliberate act rather than a flag somebody forgets to set. That
 * is what this reader is: the one place that leaves RBAC on.
 *
 * Null covers both "does not exist" and "this caller may not see it", on
 * purpose: two answers would let a reader enumerate what exists.
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
 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S01
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Reads one object with the caller's ambient RBAC left on.
 *
 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S01
 */
class RbacObjectReader {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container for the RBAC-honouring ObjectService resolve.
	 * @param SettingsService $settingsService The register-slug source.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Read one object under the CALLER's ambient RBAC.
	 *
	 * @param string $id The object id.
	 * @param string $schema The schema slug.
	 *
	 * @return array<string, mixed>|null The payload, or null when it does not exist or the caller may not see it.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S01
	 */
	public function find(string $id, string $schema): ?array {
		try {
			$entity = $this->objectService()->find(
				id: $id,
				register: $this->settingsService->getRegisterSlug(),
				schema: $schema
			);
		} catch (\Throwable $e) {
			$this->logger->info(
				'RbacObjectReader: ' . $schema . ' ' . $id . ' kon niet worden gelezen: ' . $e->getMessage()
			);
			return null;
		}

		if ($entity === null) {
			return null;
		}

		$data = $entity->getObject();
		if (is_array($data) === false) {
			return null;
		}

		$uuid = (string)$entity->getUuid();
		if ($uuid !== '' && isset($data['id']) === false) {
			$data['id'] = $uuid;
		}

		return $data;
	}//end find()

	/**
	 * OpenRegister's ObjectService, with the caller's ambient RBAC left on.
	 *
	 * @return mixed The service.
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 *
	 * @spec openspec/specs/leave-management/spec.md#REQ-LVM-S01
	 */
	private function objectService(): mixed {
		// ADR-083: establish availability before reaching, so an instance
		// without OpenRegister is told which app to install rather than handed
		// a container exception naming a class nobody has heard of.
		if (class_exists('OCA\OpenRegister\Service\ObjectService') === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()
}//end class
