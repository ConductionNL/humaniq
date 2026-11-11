<?php

/**
 * Payroll Desk Access
 *
 * The two checks the payroll-desk endpoints (`PayrollHandoffController`,
 * `ThirdPartyReportController`) run before they act: the signed-in caller
 * holds HR or payroll (or is an administrator), and the administration they
 * name resolves under that caller's RBAC.
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
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
 * @spec openspec/specs/third-party-payments/spec.md#REQ-UBD-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Service;

/**
 * Who may use a payroll-desk endpoint, and which administrations they can read.
 *
 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
 */
final class PayrollDeskAccess {

	/**
	 * @param CallerContext $caller The signed-in caller, their roles, the container and the logger.
	 * @param SettingsService $settings OpenRegister availability and the register slug.
	 */
	public function __construct(
		private readonly CallerContext $caller,
		private readonly SettingsService $settings,
	) {

	}//end __construct()

	/**
	 * The caller's uid when they hold HR or payroll (or are an
	 * administrator), else null.
	 *
	 * @return string|null
	 *
	 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
	 */
	public function hrOrPayrollUid(): ?string {
		$uid = $this->caller->userSession->getUser()?->getUID();
		if ($this->caller->roles->isHr($uid) === false && $this->caller->roles->isPayroll($uid) === false) {
			return null;
		}

		return (string)$uid;
	}//end hrOrPayrollUid()

	/**
	 * Whether the administration resolves under the caller's RBAC. A failed
	 * lookup is logged under the asking controller's name and answered as
	 * not readable.
	 *
	 * @param string $administrationId The administrationId.
	 * @param string $controller The asking controller's short class name, for the log line.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/payroll-external-bureau-handoff/spec.md#REQ-PXB-002
	 */
	public function administrationReadable(string $administrationId, string $controller): bool {
		if ($this->settings->isOpenRegisterAvailable() === false) {
			return false;
		}

		try {
			$rows = $this->caller->container->get('OCA\OpenRegister\Service\ObjectService')
				->setRegister($this->settings->getRegisterSlug())
				->setSchema('hrAdministration')
				->findAll(['filters' => ['administrationId' => $administrationId], 'limit' => 1]);
		} catch (\Throwable $e) {
			$this->caller->logger->warning($controller . ': an administration lookup failed and is answered as not readable.', ['exceptionClass' => $e::class]);
			return false;
		}

		return (is_array($rows) === true && $rows !== []);
	}//end administrationReadable()
}//end class
