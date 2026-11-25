<?php
/**
 * Humaniq SetupStepServices.
 *
 * The services behind the setup wizard's later steps, bundled so the setup
 * controller takes them as one dependency.
 *
 * @category Service
 * @package  OCA\Humaniq\Service\Setup
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-the-setup-wizard-shall-offer-a-sjablonen-step-that-installs-chosen-sets-req-fts-007
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service\Setup;

use OCP\IL10N;

/**
 * The Sjablonen step and the Klaar step's health check.
 *
 * @spec openspec/specs/first-time-setup/spec.md#requirement-the-setup-wizard-shall-offer-a-sjablonen-step-that-installs-chosen-sets-req-fts-007
 */
final class SetupStepServices {
	/**
	 * Constructor.
	 *
	 * @param SetupHealthCheck $healthCheck The Klaar step's health check.
	 * @param SetupTemplates   $templates   The Sjablonen step.
	 * @param IL10N            $l10n        Translates what the steps report, such as the health check rows.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md#requirement-the-setup-wizard-shall-offer-a-sjablonen-step-that-installs-chosen-sets-req-fts-007
	 */
	public function __construct(
		public readonly SetupHealthCheck $healthCheck,
		public readonly SetupTemplates $templates,
		public readonly IL10N $l10n,
	) {
	}//end __construct()
}//end class
