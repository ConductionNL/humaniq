<?php
/**
 * Humaniq admin settings page.
 *
 * Renders /settings/admin/humaniq (board HuInstellingen). Deliberately a plain
 * ISettings and NOT the delegated HumaniqAdmin marker: registering that class
 * would let Nextcloud's admin delegation hand a group the setup endpoints it
 * guards. This page only reads, so it needs no delegation.
 *
 * @category Settings
 * @package  OCA\Humaniq\Settings
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

namespace OCA\Humaniq\Settings;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\HumaniqRoles;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\Settings\ISettings;

/**
 * The Humaniq admin page.
 *
 * @psalm-suppress UnusedClass Registered in appinfo/info.xml as admin settings.
 *
 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
 */
class HumaniqAdminSettings implements ISettings {
	/**
	 * Constructor.
	 *
	 * @param IAppManager   $appManager   Reads the running app version.
	 * @param IInitialState $initialState Hands the page its initial state.
	 * @param IGroupManager $groupManager Counts the members of the role groups.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IInitialState $initialState,
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * The admin page: the settings bundle plus the version and the role groups.
	 *
	 * @return TemplateResponse The admin template.
	 *
	 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
	 */
	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('version', $this->appManager->getAppVersion(appId: Application::APP_ID));
		$this->initialState->provideInitialState(
			'roleGroups',
			[
				'hr'      => $this->describeGroup(gid: HumaniqRoles::HR_GROUP),
				'payroll' => $this->describeGroup(gid: HumaniqRoles::PAYROLL_GROUP),
			]
		);

		return new TemplateResponse(Application::APP_ID, 'settings/admin', []);
	}//end getForm()

	/**
	 * One role group as the page shows it: its id, whether it exists, and how
	 * many members it has.
	 *
	 * @param string $gid The Nextcloud group id.
	 *
	 * @return array{id: string, exists: bool, members: int|null} The group.
	 *
	 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
	 */
	private function describeGroup(string $gid): array {
		$group = $this->groupManager->get($gid);
		if ($group === null) {
			return ['id' => $gid, 'exists' => false, 'members' => null];
		}

		return ['id' => $gid, 'exists' => true, 'members' => $group->count()];
	}//end describeGroup()

	/**
	 * The section this page belongs to.
	 *
	 * @return string The section id.
	 *
	 * @spec exclude ISettings contract method; no behavioural spec.
	 */
	public function getSection(): string {
		return Application::APP_ID;
	}//end getSection()

	/**
	 * Ordering priority within the section.
	 *
	 * @return integer The priority.
	 *
	 * @spec exclude ISettings contract method; no behavioural spec.
	 */
	public function getPriority(): int {
		return 10;
	}//end getPriority()
}//end class
