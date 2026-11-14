<?php

/**
 * Humaniq SignInProviderNames
 *
 * The names of the identity providers configured in Nextcloud's sign-in apps,
 * read only, for the Inloggen section. `user_saml` keeps each provider in its
 * table `user_saml_configurations` (a JSON configuration with
 * `general-idp0_display_name`); `user_oidc` keeps each in `user_oidc_providers`
 * (its `identifier`). humaniq writes neither: a provider is configured in that
 * app's own settings.
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
 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-004
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads provider names from the sign-in apps' own tables.
 *
 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-004
 */
class SignInProviderNames {

	/**
	 * Per sign-in app: its table, the column to read and, for a JSON column, the key of the name.
	 *
	 * @var array<string, array{table: string, column: string, jsonKey: string|null}>
	 */
	public const SOURCES = [
		'user_saml' => ['table' => 'user_saml_configurations', 'column' => 'configuration', 'jsonKey' => 'general-idp0_display_name'],
		'user_oidc' => ['table' => 'user_oidc_providers', 'column' => 'identifier', 'jsonKey' => null],
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     The database.
	 * @param LoggerInterface $logger Notes a table that could not be read.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The provider names of one sign-in app, in table order, without blanks or doubles.
	 *
	 * A table that cannot be read (an older app version, no provider yet) gives no names.
	 *
	 * @param string $app The sign-in app id.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-004
	 */
	public function namesFor(string $app): array {
		$source = (self::SOURCES[$app] ?? null);
		if ($source === null) {
			return [];
		}

		try {
			// Table and column come from SOURCES only, never from input.
			$result = $this->db->executeQuery('SELECT '.$source['column'].' FROM *PREFIX*'.$source['table']);
			$rows   = $result->fetchAll();
			$result->closeCursor();
		} catch (Throwable $e) {
			$this->logger->info('humaniq: the providers of {app} could not be read', ['app' => $app, 'exception' => $e]);
			return [];
		}

		$names = [];
		foreach ($rows as $row) {
			$name = $this->nameOf(value: ($row[$source['column']] ?? null), jsonKey: $source['jsonKey']);
			if ($name !== '' && in_array($name, $names, true) === false) {
				$names[] = $name;
			}
		}

		return $names;
	}//end namesFor()

	/**
	 * The name in one row: the column itself, or the key inside its JSON.
	 *
	 * @param mixed       $value   The column value.
	 * @param string|null $jsonKey The key of the name in a JSON column, or null.
	 *
	 * @return string The name, or '' when the row has none.
	 *
	 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-004
	 */
	private function nameOf(mixed $value, ?string $jsonKey): string {
		if (is_string($value) === false) {
			return '';
		}

		if ($jsonKey === null) {
			return trim($value);
		}

		$decoded = json_decode($value, true);
		if (is_array($decoded) === false || is_string($decoded[$jsonKey] ?? null) === false) {
			return '';
		}

		return trim($decoded[$jsonKey]);
	}//end nameOf()

}//end class
