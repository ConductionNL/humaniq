<?php

/**
 * The setup wizard's Administraties and Werkwijze steps, server side.
 *
 * The Administraties step posts the administrations humaniq starts with; each
 * becomes (or reuses, by loonheffingennummer) an `hrAdministration`, the posting
 * administrator gets an `AdministrationAccess` row of role `hr` on it, and the
 * first becomes their active administration. The ids are remembered so the
 * Werkwijze step can write its mode onto exactly these administrations.
 *
 * @category Service
 * @package  OCA\Humaniq\Service\Setup
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#requirement-the-administraties-step-shall-create-the-administrations-humaniq-starts-with-req-fts-008
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service\Setup;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\AdministrationService;
use OCA\Humaniq\Service\SettingsService;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Creates the wizard's administrations and writes the chosen working mode onto them.
 *
 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#requirement-the-administraties-step-shall-create-the-administrations-humaniq-starts-with-req-fts-008
 */
class SetupAdministrations {

	/**
	 * App-config key recording that the Administraties step was answered or skipped.
	 *
	 * @var string
	 */
	public const DECIDED_KEY = 'administrations_decided';

	/**
	 * App-config key holding the OpenRegister ids of the administrations the step created or reused.
	 *
	 * @var string
	 */
	public const IDS_KEY = 'setup_administration_object_ids';

	/**
	 * The working modes an administration may have (REQ-SPM-001).
	 *
	 * @var array<int,string>
	 */
	public const MODES = ['standard', 'eenmanszaak_no_payroll', 'dga_single_person'];

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface    $container       Serves OpenRegister's ObjectService.
	 * @param SettingsService       $settings        Names humaniq's register.
	 * @param AdministrationService $administrations Sets the administrator's active administration.
	 * @param IAppConfig            $appConfig       Remembers the step's answer and ids.
	 *
	 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#requirement-the-administraties-step-shall-create-the-administrations-humaniq-starts-with-req-fts-008
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settings,
		private readonly AdministrationService $administrations,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Why the posted administrations are refused, or null when every entry is sound.
	 *
	 * Checked for every entry before anything is created, so a refused list
	 * leaves nothing half made.
	 *
	 * @param mixed $entries The posted `administrations`.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#scenario-an-entry-without-a-loonheffingennummer-is-refused
	 */
	public static function refusal(mixed $entries): ?string {
		if (is_array($entries) === false || $entries === []) {
			return 'Enter at least one administration.';
		}

		foreach (array_values($entries) as $index => $entry) {
			$refused = self::entryRefusal(entry: $entry, row: ($index + 1));
			if ($refused !== null) {
				return $refused;
			}
		}

		return null;
	}//end refusal()

	/**
	 * Why one posted administration is refused, or null when it is sound.
	 *
	 * @param mixed $entry The entry.
	 * @param int   $row   Its 1-based position, for the message.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#scenario-an-entry-without-a-loonheffingennummer-is-refused
	 */
	private static function entryRefusal(mixed $entry, int $row): ?string {
		if (is_array($entry) === false) {
			return 'Administration ' . $row . ' is not a set of fields.';
		}

		if (self::text(value: ($entry['name'] ?? null)) === '') {
			return 'Administration ' . $row . ' has no name.';
		}

		if (self::text(value: ($entry['loonheffingennummer'] ?? null)) === '') {
			return 'Administration ' . $row . ' has no loonheffingennummer.';
		}

		return null;
	}//end entryRefusal()

	/**
	 * Create or reuse each administration, grant the administrator `hr` on it, and make the first active.
	 *
	 * @param array<int,array<string,mixed>> $entries The entries, already free of a refusal.
	 * @param string                         $userId  The posting administrator.
	 *
	 * @return array<int,string> The business keys (`administrationId`) of the administrations, in order.
	 *
	 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#requirement-the-administraties-step-shall-create-the-administrations-humaniq-starts-with-req-fts-008
	 */
	public function create(array $entries, string $userId): array {
		$existing  = $this->rows(schema: 'hrAdministration');
		$keys      = [];
		$objectIds = [];
		foreach (array_values($entries) as $entry) {
			$number = self::text(value: $entry['loonheffingennummer']);
			$found  = $this->byNumber(rows: $existing, number: $number);
			if ($found === null) {
				$found      = $this->createOne(entry: $entry, number: $number);
				$existing[] = $found;
			}

			$keys[]      = (string)($found['administrationId'] ?? '');
			$objectIds[] = (string)($found['@self']['id'] ?? ($found['id'] ?? ($found['uuid'] ?? '')));
		}

		$this->grantHr(keys: $keys, userId: $userId);
		$this->activateFirst(keys: $keys, userId: $userId);

		$this->appConfig->setValueString(Application::APP_ID, self::IDS_KEY, (string)json_encode(array_values(array_filter($objectIds))));
		$this->appConfig->setValueString(Application::APP_ID, self::DECIDED_KEY, 'created');

		return $keys;
	}//end create()

	/**
	 * Create one administration in the standard working mode.
	 *
	 * @param array<string,mixed> $entry  The entry.
	 * @param string              $number Its trimmed loonheffingennummer.
	 *
	 * @return array<string,mixed>|null The saved administration.
	 *
	 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#requirement-the-administraties-step-shall-create-the-administrations-humaniq-starts-with-req-fts-008
	 */
	private function createOne(array $entry, string $number): ?array {
		$kvkNumber = self::text(value: ($entry['kvkNumber'] ?? null));

		return $this->row(
			value: $this->objects()->saveObject(
				object: [
					'administrationId' => 'ADM-' . $number,
					'name' => self::text(value: $entry['name']),
					'loonheffingennummer' => $number,
					'kvkNumber' => ($kvkNumber !== '' ? $kvkNumber : null),
					'active' => true,
					'mode' => 'standard',
				],
				register: $this->settings->getRegisterSlug(),
				schema: 'hrAdministration'
			)
		);
	}//end createOne()

	/**
	 * Make the first administration the administrator's active one.
	 *
	 * @param array<int,string> $keys   The administrations' business keys, in order.
	 * @param string            $userId The administrator.
	 *
	 * @return void
	 */
	private function activateFirst(array $keys, string $userId): void {
		if ($keys !== [] && $keys[0] !== '') {
			$this->administrations->setActiveAdministrationId(userId: $userId, administrationId: $keys[0]);
		}
	}//end activateFirst()

	/**
	 * Record that the Administraties step was skipped; nothing is created.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#requirement-the-administraties-step-shall-create-the-administrations-humaniq-starts-with-req-fts-008
	 */
	public function skip(): void {
		$this->appConfig->setValueString(Application::APP_ID, self::DECIDED_KEY, 'skipped');
	}//end skip()

	/**
	 * Write a working mode onto every administration the Administraties step created or reused.
	 *
	 * @param string $mode One of {@see self::MODES}; the caller has refused anything else.
	 *
	 * @return int How many administrations were written.
	 *
	 * @spec openspec/changes/setup-wizard-six-steps/specs/first-time-setup/spec.md#requirement-the-werkwijze-step-shall-set-the-mode-of-the-wizards-administrations-req-fts-009
	 */
	public function applyMode(string $mode): int {
		if (in_array($mode, self::MODES, true) === false) {
			throw new RuntimeException('Unknown working mode: ' . $mode);
		}

		$written = 0;
		foreach ($this->createdIds() as $id) {
			$this->objects()->patchObject(
				objectId: $id,
				data: ['mode' => $mode],
				register: $this->settings->getRegisterSlug(),
				schema: 'hrAdministration'
			);
			$written++;
		}

		return $written;
	}//end applyMode()

	/**
	 * The object ids of the administrations the Administraties step created or reused.
	 *
	 * @return array<int,string>
	 */
	private function createdIds(): array {
		$ids = json_decode($this->appConfig->getValueString(Application::APP_ID, self::IDS_KEY, '[]'), true);
		if (is_array($ids) === false) {
			return [];
		}

		return array_values(array_filter($ids, fn (mixed $id): bool => is_string($id) === true && $id !== ''));
	}//end createdIds()

	/**
	 * Give the administrator `hr` on each administration they do not already hold it on.
	 *
	 * @param array<int,string> $keys   The administrations' business keys.
	 * @param string            $userId The administrator.
	 *
	 * @return void
	 */
	private function grantHr(array $keys, string $userId): void {
		$held = $this->heldHr(userId: $userId);
		foreach (array_unique($keys) as $key) {
			if ($key === '' || isset($held[$key]) === true) {
				continue;
			}

			$this->objects()->saveObject(
				object: ['userId' => $userId, 'administrationId' => $key, 'role' => 'hr'],
				register: $this->settings->getRegisterSlug(),
				schema: 'AdministrationAccess'
			);
		}
	}//end grantHr()

	/**
	 * The administrations the user already holds `hr` on, as keys.
	 *
	 * @param string $userId The user.
	 *
	 * @return array<string,true>
	 */
	private function heldHr(string $userId): array {
		$held = [];
		foreach ($this->rows(schema: 'AdministrationAccess') as $row) {
			if ((string)($row['userId'] ?? '') === $userId && (string)($row['role'] ?? '') === 'hr') {
				$held[(string)($row['administrationId'] ?? '')] = true;
			}
		}

		return $held;
	}//end heldHr()

	/**
	 * The administration with this loonheffingennummer, or null.
	 *
	 * @param array<int,array<string,mixed>> $rows   The administrations.
	 * @param string                         $number The loonheffingennummer.
	 *
	 * @return array<string,mixed>|null
	 */
	private function byNumber(array $rows, string $number): ?array {
		foreach ($rows as $row) {
			if (strcasecmp(self::text(value: ($row['loonheffingennummer'] ?? null)), $number) === 0) {
				return $row;
			}
		}

		return null;
	}//end byNumber()

	/**
	 * Every row of a schema, as arrays.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(string $schema): array {
		$found = $this->objects()
			->setRegister($this->settings->getRegisterSlug())
			->setSchema($schema)
			->findAll(['limit' => 10000]);

		return array_values(array_filter(array_map(fn (mixed $row): ?array => $this->row(value: $row), (array)$found)));
	}//end rows()

	/**
	 * An object as an array, or null.
	 *
	 * @param mixed $value An object entity or array.
	 *
	 * @return array<string,mixed>|null
	 */
	private function row(mixed $value): ?array {
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$value = $value->jsonSerialize();
		}

		if (is_array($value) === false) {
			return null;
		}

		return $value;
	}//end row()

	/**
	 * OpenRegister's ObjectService.
	 *
	 * @return mixed
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function objects(): mixed {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			throw new RuntimeException('humaniq requires the OpenRegister app, which is not installed on this instance.');
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objects()

	/**
	 * A trimmed string, or ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()
}//end class
