<?php

/**
 * Payroll Flow Node Services
 *
 * What every payroll flow node needs besides its own payroll service: the
 * translations of its palette strings, the URL of its icon, and the container
 * and settings that reach OpenRegister.
 *
 * @category Flow
 * @package  OCA\Humaniq\Flow
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
 * @spec openspec/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Flow;

use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\SettingsService;
use OCP\IURLGenerator;
use OCP\IL10N;

/**
 * The shared collaborators of {@see PayrollFlowNodeBase}.
 *
 * @spec openspec/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-001
 */
final class PayrollFlowNodeServices {

	/**
	 * @param IL10N $l10n Localisation for palette strings.
	 * @param IURLGenerator $urls URL generator for the node icon.
	 * @param \Psr\Container\ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param SettingsService $settingsService Register slug + OpenRegister availability.
	 * @param InternalWriteMarker $marker The shared marker a node's own writes run under, so the
	 *                                    pre-save guards tell a flow step from a raw edit.
	 *
	 * @spec openspec/changes/payroll-run-pay-date-and-schedule/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-003
	 */
	public function __construct(
		public readonly IL10N $l10n,
		public readonly IURLGenerator $urls,
		public readonly \Psr\Container\ContainerInterface $container,
		public readonly SettingsService $settingsService,
		public readonly InternalWriteMarker $marker = new InternalWriteMarker(),
	) {

	}//end __construct()
}//end class
