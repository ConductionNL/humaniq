<?php

/**
 * Administration Service
 *
 * The multi-administratie (accountant multi-client) access + active-selection
 * service (design.md D4/D7):
 *
 * - Resolves a caller's accessible administraties from their
 *   `AdministrationAccess` rows (the closed set the switcher offers and the
 *   setter validates against — an accountant carries many rows, their whole
 *   client book; a company HR/employee user carries one).
 * - Reads/writes the caller's PER-USER active-administration pointer as an
 *   `IConfig` user value keyed on the app id (NOT an `IAppConfig` instance
 *   value, whose granularity is wrong for per-user session-ish state, and NOT
 *   an OpenRegister object, which would itself need scoping).
 * - `setActive()` MUST be called only after the caller's access has been
 *   verified by `hasAccess()` (the `AdministrationController::setActive`
 *   no-admin-idor guard, the `DocumentController`/`PayrollController`
 *   resolve-first-then-404 precedent) — this service itself does not refuse
 *   an unauthorized id; the controller is the enforcement point, exactly the
 *   split every other guarded endpoint in this app uses (authorize* in the
 *   controller, the write in the service).
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
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-002
 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

use OCA\Humaniq\AppInfo\Application;
use OCP\IConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Accountant multi-client access resolution + per-user active-administration
 * pointer.
 */
class AdministrationService {

	/**
	 * The `IConfig` user-value key the active administratie id is stored
	 * under.
	 *
	 * @var string
	 */
	private const ACTIVE_ADMINISTRATION_KEY = 'active_administration_id';

	/**
	 * Max AdministrationAccess/Administration rows loaded per call — the
	 * fleet's `findAll(['limit' => N])`-then-filter-in-PHP convention
	 * (RuleAuditService::loadAll() precedent); a client book realistically
	 * never approaches this.
	 *
	 * @var int
	 */
	private const LIMIT = 10000;

	/**
	 * The default `Administration.mode` (single-person-modes REQ-SPM-001):
	 * every administratie created before this change, and every one with no
	 * `mode` value set, resolves to `standard` -- the identical no-regression
	 * default REQ-MULTI-004 established for `activeAdministrationId`.
	 *
	 * @var string
	 */
	public const DEFAULT_MODE = 'standard';

	/**
	 * The valid `Administration.mode` values (single-person-modes REQ-SPM-001).
	 * Anything outside this set degrades to `DEFAULT_MODE` -- an unknown/legacy
	 * value never hides a menu by accident.
	 *
	 * @var string[]
	 */
	private const VALID_MODES = ['standard', 'dga_single_person', 'eenmanszaak_no_payroll'];

	/**
	 * @param ContainerInterface $container DI container for the ObjectService resolve.
	 * @param IConfig $config Per-user config store for the active-administration pointer.
	 * @param SettingsService $settingsService The register-slug source.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IConfig $config,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The caller's currently active administratie id, or null when unset.
	 *
	 * @param string $userId The Nextcloud user id.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
	 */
	public function getActiveAdministrationId(string $userId): ?string {
		$value = trim($this->config->getUserValue($userId, Application::APP_ID, self::ACTIVE_ADMINISTRATION_KEY, ''));
		return $value === '' ? null : $value;
	}//end getActiveAdministrationId()

	/**
	 * The caller's active administratie's resolved `mode`
	 * (single-person-modes REQ-SPM-002/D2): the `Administration.mode` of the
	 * caller's currently active administratie, or `DEFAULT_MODE` (`standard`)
	 * when no active administratie is resolved, the active id has no catalog
	 * entry, or its `mode` is unset/invalid -- the identical no-regression
	 * default REQ-MULTI-004 established for `activeAdministrationId`. Stamped
	 * into `manifest.runtime.user.administrationMode` by
	 * `PageController::index()` so nc-vue's `visibleIf` primitive can act on
	 * it.
	 *
	 * @param string $userId The Nextcloud user id.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/single-person-modes/specs/single-person-modes/spec.md#REQ-SPM-002
	 */
	public function getActiveAdministrationMode(string $userId): string {
		$activeId = $this->getActiveAdministrationId($userId);
		if ($activeId === null) {
			return self::DEFAULT_MODE;
		}

		$catalog = $this->administrationCatalogById();
		return (string)($catalog[$activeId]['mode'] ?? self::DEFAULT_MODE);
	}//end getActiveAdministrationMode()

	/**
	 * The caller's active administratie's `AdministrationAccess.role` for
	 * the caller, or null when no active administratie is resolved or no
	 * matching access row exists (humaniq-dashboard-steering-indicators
	 * REQ-DSI-005/design.md D3): the first reader of this field past row
	 * presence. Reuses `accessibleAdministrations()` rather than
	 * re-querying `AdministrationAccess` directly, so the two callers never
	 * disagree on which row is "the" access row for an administratie.
	 *
	 * @param string $userId The Nextcloud user id.
	 *
	 * @return string|null One of `accountant`/`hr`/`employee`, or null.
	 *
	 * @spec openspec/changes/archive/2026-08-20-hrmq-dashboard-steering-indicators/specs/hrmq-dashboard-steering-indicators/spec.md#REQ-DSI-005
	 */
	public function getActiveAdministrationRole(string $userId): ?string {
		$activeId = $this->getActiveAdministrationId($userId);
		if ($activeId === null) {
			return null;
		}

		foreach ($this->accessibleAdministrations($userId) as $administration) {
			if ($administration['administrationId'] === $activeId) {
				$role = trim($administration['role']);
				return $role === '' ? null : $role;
			}
		}

		return null;
	}//end getActiveAdministrationRole()

	/**
	 * Persist the caller's active administratie id. The CALLER (the
	 * controller) MUST have already verified `hasAccess($userId,
	 * $administrationId)` — this method performs no authorization check of
	 * its own, mirroring the split every other guarded write in this app
	 * uses (authorize in the controller, write in the service).
	 *
	 * @param string $userId The Nextcloud user id.
	 * @param string $administrationId The administratie id to activate.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
	 */
	public function setActiveAdministrationId(string $userId, string $administrationId): void {
		$this->config->setUserValue($userId, Application::APP_ID, self::ACTIVE_ADMINISTRATION_KEY, $administrationId);

	}//end setActiveAdministrationId()

	/**
	 * Whether the caller has an `AdministrationAccess` row for the given
	 * administratie id — the guard `AdministrationController::setActive`
	 * MUST call before `setActiveAdministrationId()` (D4/D7). Row PRESENCE
	 * is the only thing checked in the MVP; `role` is descriptive, not yet
	 * authorization-differentiated (the AdministrationAccess.role
	 * description).
	 *
	 * @param string $userId The Nextcloud user id.
	 * @param string $administrationId The administratie id to check.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-003
	 */
	public function hasAccess(string $userId, string $administrationId): bool {
		if ($administrationId === '') {
			return false;
		}

		foreach ($this->accessRowsForUser($userId) as $row) {
			if ((string)($row['administrationId'] ?? '') === $administrationId) {
				return true;
			}
		}

		return false;
	}//end hasAccess()

	/**
	 * The caller's accessible administraties: every `AdministrationAccess`
	 * row for `$userId`, each enriched with the `Administration` catalog's
	 * `name` (falling back to the bare administrationId when the catalog
	 * entry is missing/not yet imported — a degrade-to-honest default, never
	 * a fatal error).
	 *
	 * @param string $userId The Nextcloud user id.
	 *
	 * @return array<int, array{administrationId: string, name: string, role: string, mode: string}>
	 *
	 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-002
	 * @spec openspec/changes/single-person-modes/specs/single-person-modes/spec.md#REQ-SPM-002
	 */
	public function accessibleAdministrations(string $userId): array {
		$catalog = $this->administrationCatalogById();

		$result = [];
		foreach ($this->accessRowsForUser($userId) as $row) {
			$administrationId = (string)($row['administrationId'] ?? '');
			if ($administrationId === '') {
				continue;
			}

			$entry = ($catalog[$administrationId] ?? null);
			$result[] = [
				'administrationId' => $administrationId,
				'name' => (string)($entry['name'] ?? $administrationId),
				'role' => (string)($row['role'] ?? ''),
				// single-person-modes (REQ-SPM-002): the administratie's
				// resolved mode, so the switcher UI can display it and a
				// client-side switch can update `runtime.user.administrationMode`
				// without a reload. Defaults to `standard` for a legacy/absent
				// value (the no-regression default).
				'mode' => (string)($entry['mode'] ?? self::DEFAULT_MODE),
			];
		}

		return $result;
	}//end accessibleAdministrations()

	/**
	 * The full context payload for the switcher/topbar indicator: the
	 * caller's active administratie id (or null) plus their accessible
	 * administraties (REQ-MULTI-004's `GET /api/administration/context`).
	 *
	 * @param string $userId The Nextcloud user id.
	 *
	 * @return array{activeAdministrationId: string|null, administrations: array<int, array{administrationId: string, name: string, role: string, mode: string}>}
	 *
	 * @spec openspec/changes/multi-administratie/specs/multi-administratie/spec.md#REQ-MULTI-004
	 * @spec openspec/changes/single-person-modes/specs/single-person-modes/spec.md#REQ-SPM-002
	 */
	public function context(string $userId): array {
		return [
			'activeAdministrationId' => $this->getActiveAdministrationId($userId),
			'administrations' => $this->accessibleAdministrations($userId),
		];

	}//end context()

	/**
	 * Every `AdministrationAccess` row belonging to `$userId`, as plain
	 * arrays. Degrades to an empty list when the schema does not exist yet
	 * in the register (fresh install before the fragment is imported).
	 *
	 * @param string $userId The Nextcloud user id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function accessRowsForUser(string $userId): array {
		$rows = [];
		foreach ($this->loadAll('AdministrationAccess') as $row) {
			if ((string)($row['userId'] ?? '') === $userId) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end accessRowsForUser()

	/**
	 * The `Administration` catalog's `administrationId => {name, mode}` map
	 * (single-person-modes REQ-SPM-002: the `name` this switcher already
	 * needed, plus the resolved `mode` the mode-switch now needs, loaded in
	 * one pass). Degrades to an empty map when the schema does not exist yet
	 * in the register.
	 *
	 * @return array<string, array{name: string, mode: string}>
	 */
	private function administrationCatalogById(): array {
		$catalog = [];
		foreach ($this->loadAll('Administration') as $row) {
			$administrationId = (string)($row['administrationId'] ?? '');
			if ($administrationId !== '') {
				$catalog[$administrationId] = [
					'name' => (string)($row['name'] ?? $administrationId),
					'mode' => self::normaliseMode($row['mode'] ?? null),
				];
			}
		}

		return $catalog;
	}//end administrationCatalogById()

	/**
	 * Normalise a raw `Administration.mode` value to one of the valid enum
	 * values, degrading anything unset/unknown to `DEFAULT_MODE` (`standard`)
	 * -- a legacy or malformed value never hides a menu by accident
	 * (single-person-modes REQ-SPM-001/-002).
	 *
	 * @param mixed $mode The raw mode value from the register.
	 *
	 * @return string
	 */
	private static function normaliseMode(mixed $mode): string {
		$mode = trim((string)($mode ?? ''));
		return in_array($mode, self::VALID_MODES, true) === true ? $mode : self::DEFAULT_MODE;
	}//end normaliseMode()

	/**
	 * Load all objects of a schema (capped), as plain arrays — the
	 * RuleAuditService::loadAll() precedent.
	 *
	 * @param string $schema The schema name.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function loadAll(string $schema): array {
		try {
			$rows = $this->objectService()
				->setRegister($this->settingsService->getRegisterSlug())
				->setSchema($schema)
				->findAll(['limit' => self::LIMIT]);
		} catch (\Throwable $e) {
			$this->logger->warning('AdministrationService: could not load ' . $schema . ': ' . $e->getMessage());
			return [];
		}

		return $this->normaliseRows($rows);
	}//end loadAll()

	/**
	 * Normalise a list of ObjectService rows (entities or arrays) to arrays.
	 *
	 * @param mixed $rows Raw rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function normaliseRows(mixed $rows): array {
		$out = [];
		foreach ((is_array($rows) === true ? $rows : []) as $row) {
			if (is_array($row) === true) {
				$out[] = $row;
				continue;
			}

			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$out[] = (array)$row->jsonSerialize();
			}
		}

		return $out;
	}//end normaliseRows()

	/**
	 * The OpenRegister ObjectService, once availability has been established.
	 *
	 * ADR-083: the reach is guarded rather than blind. humaniq owns no database
	 * tables — every object it manages lives in OpenRegister — so this is not
	 * an optional capability in the sense of a feature that degrades. What the
	 * guard buys is the ADR's rule 3 promise: an instance without OpenRegister
	 * must still reach a start screen that EXPLAINS ITSELF. Unguarded, the
	 * container raises `NotFoundExceptionInterface` naming a class the admin
	 * has never heard of; guarded, they are told which app to install.
	 *
	 * @return mixed The OpenRegister ObjectService.
	 *
	 * @throws \RuntimeException When OpenRegister is not installed.
	 */
	private function objectService(): mixed {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			throw new RuntimeException(
				'humaniq requires the OpenRegister app, which is not installed on this instance. '
				. 'Install and enable it, then reload.'
			);
		}

		return $this->container->get('OCA\OpenRegister\Service\ObjectService');
	}//end objectService()

}//end class
