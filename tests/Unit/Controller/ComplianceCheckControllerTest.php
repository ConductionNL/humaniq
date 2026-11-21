<?php

/**
 * Unit tests for ComplianceCheckController.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\ComplianceCheckController;
use OCA\Humaniq\Service\ComplianceCheckService;
use OCA\Humaniq\Service\RecordStoreInterface;
use OCA\Humaniq\Service\RuleAuditService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Signed-in callers only; an unreadable register is an error, not an empty page.
 *
 * @spec openspec/specs/hrm-rule-engine/spec.md#requirement-hr-shall-see-the-audits-violations-and-coverage-on-a-page-req-rule-010
 */
#[CoversClass(ComplianceCheckController::class)]
#[UsesClass(ComplianceCheckService::class)]
final class ComplianceCheckControllerTest extends TestCase {

	/**
	 * A controller whose audit answers or throws.
	 *
	 * @param bool $signedIn Whether a user is signed in.
	 * @param bool $failing  Whether the audit throws.
	 *
	 * @return ComplianceCheckController
	 */
	private function controller(bool $signedIn, bool $failing = false): ComplianceCheckController {
		$audit = $this->createMock(RuleAuditService::class);
		if ($failing === true) {
			$audit->method('findings')->willThrowException(new RuntimeException('no register'));
		} else {
			$audit->method('findings')->willReturn(['report' => ['machineCheckable' => 10, 'enforceableRules' => 4], 'violations' => []]);
		}

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return new ComplianceCheckController($this->createMock(IRequest::class), new ComplianceCheckService($audit, $this->createMock(RecordStoreInterface::class)), $session, $this->createMock(LoggerInterface::class));
	}//end controller()

	/**
	 * The three outcomes.
	 *
	 * @return void
	 */
	public function testTheCheckAnswersSignedInCallersOnly(): void {
		self::assertSame(401, $this->controller(false)->check()->getStatus());

		$response = $this->controller(true)->check();
		self::assertSame(200, $response->getStatus());
		self::assertSame(6, $response->getData()['coverage']['notEnforced']);
		self::assertSame([], $response->getData()['violations']);

		self::assertSame(500, $this->controller(true, true)->check()->getStatus());
	}//end testTheCheckAnswersSignedInCallersOnly()
}//end class
