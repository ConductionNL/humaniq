<?php
/**
 * Humaniq admin settings section.
 *
 * The "Humaniq" entry in the Nextcloud administration settings navigation,
 * under which HumaniqAdminSettings renders the admin page (board
 * HuInstellingen).
 *
 * @category Sections
 * @package  OCA\Humaniq\Sections
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

namespace OCA\Humaniq\Sections;

use OCA\Humaniq\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * The Humaniq section of the administration settings.
 *
 * @psalm-suppress UnusedClass Registered in appinfo/info.xml as admin-section.
 *
 * @spec exclude Nextcloud settings-section contract; the page it hosts is the admin page, no behavioural spec of its own.
 */
class HumaniqAdminSection implements IIconSection {
	/**
	 * Constructor.
	 *
	 * @param IL10N         $l10n         Translates the section name.
	 * @param IURLGenerator $urlGenerator Resolves the section icon.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
	) {
	}//end __construct()

	/**
	 * The section icon.
	 *
	 * @return string The icon URL.
	 *
	 * @spec exclude IIconSection contract method; no behavioural spec.
	 */
	public function getIcon(): string {
		return $this->urlGenerator->imagePath(appName: Application::APP_ID, file: 'app-dark.svg');
	}//end getIcon()

	/**
	 * The section id, which the admin settings class names.
	 *
	 * @return string The section id.
	 *
	 * @spec exclude IIconSection contract method; no behavioural spec.
	 */
	public function getID(): string {
		return Application::APP_ID;
	}//end getID()

	/**
	 * The section name in the navigation.
	 *
	 * @return string The section name.
	 *
	 * @spec exclude IIconSection contract method; no behavioural spec.
	 */
	public function getName(): string {
		return $this->l10n->t('Humaniq');
	}//end getName()

	/**
	 * Ordering priority among the administration sections.
	 *
	 * @return integer The priority.
	 *
	 * @spec exclude IIconSection contract method; no behavioural spec.
	 */
	public function getPriority(): int {
		return 55;
	}//end getPriority()
}//end class
