<?php

/**
 * Humaniq FieldReadAccess
 *
 * Asks OpenRegister which of a schema's authorization-governed properties the
 * current user cannot read on one object (compliance-roles-and-field-access
 * D5). The answer comes from OpenRegister's own PropertyRbacHandler, the same
 * check that strips those properties from every read, so humaniq never keeps
 * a second copy of the rules.
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
 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-002
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Which governed properties the caller cannot read.
 *
 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-002
 */
class FieldReadAccess {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's schema mapper and property RBAC handler.
	 * @param LoggerInterface    $logger    Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The governed properties of the schema the current user cannot read on
	 * this object, or null when OpenRegister could not be asked.
	 *
	 * @param string               $schemaId The schema id of the object.
	 * @param array<string, mixed> $object   The stored object, which the match rules are evaluated against.
	 *
	 * @return list<string>|null
	 *
	 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-002
	 */
	public function unreadable(string $schemaId, array $object): ?array {
		try {
			$schema = $this->container->get('OCA\OpenRegister\Db\SchemaMapper')->find($schemaId);
			$handler = $this->container->get('OCA\OpenRegister\Service\PropertyRbacHandler');
			$unreadable = [];
			foreach (array_keys($schema->getPropertiesWithAuthorization()) as $property) {
				if ($handler->canReadProperty($schema, (string)$property, $object) === false) {
					$unreadable[] = (string)$property;
				}
			}

			return $unreadable;
		} catch (\Throwable $e) {
			$this->logger->warning('humaniq: could not ask OpenRegister which fields the caller may read', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end unreadable()

	/**
	 * The stored values of fields an update would null although the writer
	 * cannot read them: the values a save must carry forward rather than
	 * wipe. OpenRegister strips a field the reader may not see, and a full
	 * save fills every absent property with null, so without this a manager
	 * who opens an employee and saves it erases the BSN, bank account and
	 * salary they were never shown. When OpenRegister cannot be asked, every
	 * nulled value is carried: a lost BSN cannot be restored, a refused clear
	 * can be repeated.
	 *
	 * @param string               $schemaId The schema id.
	 * @param array<string, mixed> $old      The stored object.
	 * @param array<string, mixed> $new      The object as it will be saved.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-002
	 */
	public function carriedForward(string $schemaId, array $old, array $new): array {
		$nulled = [];
		foreach ($old as $field => $value) {
			if ($this->isEmpty($value) === false && $this->isEmpty($new[$field] ?? null) === true) {
				$nulled[(string)$field] = $value;
			}
		}

		if ($nulled === []) {
			return [];
		}

		$unreadable = $this->unreadable($schemaId, $old);
		if ($unreadable === null) {
			return $nulled;
		}

		return array_intersect_key($nulled, array_flip($unreadable));
	}//end carriedForward()

	/**
	 * Whether a value is absent: null, an empty string or an empty list.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	private function isEmpty(mixed $value): bool {
		return $value === null || $value === '' || $value === [];
	}//end isEmpty()

}//end class
