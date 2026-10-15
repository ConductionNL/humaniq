<?php

/**
 * Tests for the humaniq admin page (board HuInstellingen).
 *
 * Two things are worth pinning. First the auth posture: the page is a plain
 * ISettings and `appinfo/info.xml` registers it, while the delegated
 * HumaniqAdmin marker stays unregistered. Registering the marker would let
 * Nextcloud's admin delegation hand a group the setup endpoints it guards.
 * Second the data: the role table reads its member counts from the initial
 * state, and a missing group must say so rather than read as zero members.
 *
 * @category Tests
 * @package  OCA\Humaniq\Tests\Unit\Settings
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Settings;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Settings\HumaniqAdminSettings;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\Settings\IDelegatedSettings;
use PHPUnit\Framework\TestCase;

/**
 * Tests for HumaniqAdminSettings.
 *
 * @category Tests
 * @package  OCA\Humaniq\Tests\Unit\Settings
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.conduction.nl
 */
class HumaniqAdminSettingsTest extends TestCase {
	/**
	 * The page must not be delegatable.
	 *
	 * @return void
	 */
	public function testThePageIsNotADelegatedSetting(): void {
		$this->assertFalse(is_subclass_of(HumaniqAdminSettings::class, IDelegatedSettings::class));
	}//end testThePageIsNotADelegatedSetting()

	/**
	 * info.xml registers the page and its section, and never the marker.
	 *
	 * @return void
	 */
	public function testInfoXmlRegistersThePageAndNotTheMarker(): void {
		$xml = simplexml_load_file(__DIR__ . '/../../../appinfo/info.xml');
		$this->assertNotFalse($xml);

		$admins = array_map('strval', iterator_to_array($xml->settings->admin, false));
		$this->assertSame([HumaniqAdminSettings::class], $admins);
		$this->assertSame('OCA\Humaniq\Sections\HumaniqAdminSection', (string) $xml->settings->{'admin-section'});
	}//end testInfoXmlRegistersThePageAndNotTheMarker()

	/**
	 * The form hands the page the version and both role groups; a missing
	 * group is reported as missing, not as an empty group.
	 *
	 * @return void
	 */
	public function testTheFormProvidesTheRoleGroups(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.2.3');

		$hr = $this->createMock(IGroup::class);
		$hr->method('count')->willReturn(6);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnMap(
			[
				[HumaniqRoles::HR_GROUP, $hr],
				[HumaniqRoles::PAYROLL_GROUP, null],
			]
		);

		$provided = [];
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(
			function (string $key, mixed $data) use (&$provided): void {
				$provided[$key] = $data;
			}
		);

		$settings = new HumaniqAdminSettings($appManager, $initialState, $groupManager);
		$response = $settings->getForm();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('settings/admin', $response->getTemplateName());
		$this->assertSame('1.2.3', $provided['version']);
		$this->assertSame(['id' => 'humaniq-hr', 'exists' => true, 'members' => 6], $provided['roleGroups']['hr']);
		$this->assertSame(['id' => 'humaniq-payroll', 'exists' => false, 'members' => null], $provided['roleGroups']['payroll']);
		$this->assertSame(Application::APP_ID, $settings->getSection());
	}//end testTheFormProvidesTheRoleGroups()
}//end class
