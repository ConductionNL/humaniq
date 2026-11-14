<?php

/**
 * Tests for the humaniq section of the administration settings.
 *
 * The section and the admin page meet on one id: a section id the page does
 * not name leaves the page in no section at all. The icon is the app's dark
 * mark, resolved through the URL generator, and the name is translated.
 *
 * @category Tests
 * @package  OCA\Humaniq\Tests\Unit\Sections
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Sections;

use OCA\Humaniq\AppInfo\Application;
use OCA\Humaniq\Sections\HumaniqAdminSection;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for HumaniqAdminSection.
 *
 * @covers \OCA\Humaniq\Sections\HumaniqAdminSection
 */
class HumaniqAdminSectionTest extends TestCase {

	/**
	 * The section's id is the app id, the section the admin page names, and the section is ordered at 55.
	 *
	 * @return void
	 */
	public function testTheSectionCarriesTheIdTheAdminPageNames(): void {
		$section = new HumaniqAdminSection($this->createMock(IL10N::class), $this->createMock(IURLGenerator::class));

		$this->assertSame('humaniq', $section->getID());
		$this->assertSame(Application::APP_ID, $section->getID());
		$xml = simplexml_load_file(dirname(__DIR__, 3).'/appinfo/info.xml');
		$this->assertSame(HumaniqAdminSection::class, (string) $xml->settings->{'admin-section'});
		$this->assertSame(55, $section->getPriority());
	}//end testTheSectionCarriesTheIdTheAdminPageNames()

	/**
	 * The icon is the app's dark mark, asked of the URL generator for this app.
	 *
	 * @return void
	 */
	public function testTheIconIsTheAppsDarkMark(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->expects($this->once())->method('imagePath')
			->with('humaniq', 'app-dark.svg')
			->willReturn('/apps/humaniq/img/app-dark.svg');

		$section = new HumaniqAdminSection($this->createMock(IL10N::class), $urls);

		$this->assertSame('/apps/humaniq/img/app-dark.svg', $section->getIcon());
	}//end testTheIconIsTheAppsDarkMark()

	/**
	 * The name in the navigation goes through the translator.
	 *
	 * @return void
	 */
	public function testTheNameIsTranslated(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->expects($this->once())->method('t')->with('Humaniq')->willReturn('Humaniq (vertaald)');

		$section = new HumaniqAdminSection($l10n, $this->createMock(IURLGenerator::class));

		$this->assertSame('Humaniq (vertaald)', $section->getName());
	}//end testTheNameIsTranslated()
}//end class
