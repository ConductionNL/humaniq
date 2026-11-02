<?php

/**
 * Forged caller identity test for the HR and payroll controllers.
 *
 * The acting user is always the session user, never a value in the request.
 * The session user "clerk" holds no role; every request claims userId, uid
 * and the HR and payroll roles for "boss"; only "boss" holds those roles in
 * the group manager, and "hr-only" and "pay-only" hold one each. Every case
 * pins one way a forged request value could have been trusted.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Controller
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
 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Controller;

use OCA\Humaniq\Controller\ExpenseController;
use OCA\Humaniq\Controller\InterviewController;
use OCA\Humaniq\Controller\LoonbeslagController;
use OCA\Humaniq\Controller\OfferController;
use OCA\Humaniq\Controller\PayrollCheckController;
use OCA\Humaniq\Controller\PayrollHandoffController;
use OCA\Humaniq\Service\CallerContext;
use OCA\Humaniq\Service\HumaniqRoles;
use OCA\Humaniq\Service\InterviewCalendarService;
use OCA\Humaniq\Service\OfferEsignService;
use OCA\Humaniq\Service\PayrollRunCheckService;
use OCA\Humaniq\Service\ReceiptExtractionService;
use OCA\Humaniq\Service\SalaryBureauExchangeService;
use OCA\Humaniq\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The acting user is the session user, never a request value.
 */
class ForgedCallerIdentityTest extends TestCase {

	/**
	 * A request that claims another user's identity and roles.
	 *
	 * @param string $f The user the request claims to be.
	 *
	 * @return IRequest
	 */
	private function request(string $f = 'boss'): IRequest {
		$forged = ['userId' => $f, 'uid' => $f, 'user' => $f, 'requestedBy' => $f, 'roles' => ['humaniq-hr', 'humaniq-payroll'], 'isHr' => true, 'isPayroll' => true];
		$r = $this->createMock(IRequest::class);
		$r->method('getParam')->willReturnCallback(fn ($k, $d = null) => $forged[$k] ?? $d);
		$r->method('getParams')->willReturn($forged);
		return $r;
	}

	/**
	 * The caller context for one session user, with uid-specific role answers.
	 *
	 * @param string $sessionUid The session user.
	 * @param ContainerInterface $container The container.
	 *
	 * @return CallerContext
	 */
	private function context(string $sessionUid, ContainerInterface $container): CallerContext {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($sessionUid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$gm = $this->createMock(IGroupManager::class);
		$gm->method('isAdmin')->willReturnCallback(fn ($u) => $u === 'boss');
		$gm->method('isInGroup')->willReturnCallback(fn ($u, $g) => $u === 'boss' || ($u === 'hr-only' && $g === 'humaniq-hr') || ($u === 'pay-only' && $g === 'humaniq-payroll'));
		return new CallerContext($session, new HumaniqRoles($gm), $container, new NullLogger());
	}

	/**
	 * Settings with OpenRegister available.
	 *
	 * @return SettingsService
	 */
	private function settings(): SettingsService {
		$s = $this->createMock(SettingsService::class);
		$s->method('isOpenRegisterAvailable')->willReturn(true);
		$s->method('getRegisterSlug')->willReturn('humaniq');
		return $s;
	}

	/**
	 * A container that must not be touched: a read before the guard fails the test.
	 *
	 * @return ContainerInterface
	 */
	private function untouchable(): ContainerInterface {
		$c = $this->createMock(ContainerInterface::class);
		$c->expects($this->never())->method('get');
		return $c;
	}

	/**
	 * Every guarded action, with its arguments.
	 *
	 * @return array<string, array{0: string, 1: string, 2: array<int, string>}>
	 */
	public static function guardedActions(): array {
		return [
			'loonbeslag.activate' => ['loonbeslag', 'activate', ['lb-1']],
			'loonbeslag.settle' => ['loonbeslag', 'settle', ['lb-1']],
			'loonbeslag.withdraw' => ['loonbeslag', 'withdraw', ['lb-1', 'reden']],
			'interview.sync' => ['interview', 'sync', ['intv-1']],
			'offer.requestSignature' => ['offer', 'requestSignature', ['app-1']],
			'payrollCheck.check' => ['payrollCheck', 'check', ['run-1']],
			'payrollHandoff.compile' => ['payrollHandoff', 'compile', ['ADM-006', '2026-05']],
			'payrollHandoff.checkIntake' => ['payrollHandoff', 'checkIntake', ['ho-1']],
		];
	}

	/**
	 * One controller under a forged request.
	 *
	 * @param string $kind The controller.
	 * @param CallerContext $ctx The caller context.
	 * @param bool $servicesNever Whether its own service must not be called.
	 *
	 * @return object
	 */
	private function build(string $kind, CallerContext $ctx, bool $servicesNever): object {
		$req = $this->request();
		$svc = function (string $cls) use ($servicesNever) {
			$m = $this->createMock($cls);
			if ($servicesNever === true) {
				foreach (get_class_methods($cls) as $meth) {
					if ($meth !== '__construct') {
						$m->expects($this->never())->method($meth);
					}
				}
			}
			return $m;
		};
		return match ($kind) {
			'loonbeslag' => new LoonbeslagController($req, $this->settings(), $ctx),
			'interview' => new InterviewController($req, $svc(InterviewCalendarService::class), $this->settings(), $ctx),
			'offer' => new OfferController($req, $svc(OfferEsignService::class), $this->settings(), $ctx),
			'payrollCheck' => new PayrollCheckController($req, $this->settings(), $svc(PayrollRunCheckService::class), $ctx),
			'payrollHandoff' => new PayrollHandoffController($req, $this->settings(), $svc(SalaryBureauExchangeService::class), $ctx),
		};
	}

	/**
	 * A forged identity is refused before anything is read.
	 *
	 * @param string $kind The controller.
	 * @param string $action The action.
	 * @param array<int, string> $args Its arguments.
	 *
	 * @return void
	 *
	 * @dataProvider guardedActions
	 */
	public function testForgedRequestIdentityIsRefusedBeforeAnyRead(string $kind, string $action, array $args): void {
		$controller = $this->build($kind, $this->context('clerk', $this->untouchable()), true);
		$response = $controller->$action(...$args);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	/**
	 * A session user holding the roles passes the guard.
	 *
	 * @param string $kind The controller.
	 * @param string $action The action.
	 * @param array<int, string> $args Its arguments.
	 *
	 * @return void
	 *
	 * @dataProvider guardedActions
	 */
	public function testSessionRoleHolderPassesTheGuard(string $kind, string $action, array $args): void {
		$c = $this->createMock(ContainerInterface::class);
		$c->method('get')->willThrowException(new \RuntimeException('reached the read'));
		$controller = $this->build($kind, $this->context('boss', $c), false);
		try {
			$response = $controller->$action(...$args);
			$this->assertNotSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		} catch (\RuntimeException $e) {
			$this->assertSame('reached the read', $e->getMessage());
		}
	}

	/**
	 * A container whose ObjectService finds one expense of the given owner.
	 *
	 * @param string $owner The expense owner.
	 *
	 * @return ContainerInterface
	 */
	private function expenseContainer(string $owner): ContainerInterface {
		$os = new class($owner) {
			public function __construct(private string $owner) {
			}
			public function find(...$a): array {
				return ['id' => 'exp-1', 'userId' => $this->owner];
			}
		};
		$c = $this->createMock(ContainerInterface::class);
		$c->method('get')->willReturn($os);
		return $c;
	}

	/**
	 * An expense owned by the forged user is refused to the session user.
	 *
	 * @return void
	 */
	public function testExpenseForgedOwnerIsRefused(): void {
		$extract = $this->createMock(ReceiptExtractionService::class);
		$extract->expects($this->never())->method('extractForExpense');
		$ctx = $this->context('clerk', $this->expenseContainer('boss'));
		$controller = new ExpenseController($this->request(), $extract, $this->settings(), $ctx);
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->extractReceipt('exp-1')->getStatus());
	}

	/**
	 * The session owner extracts a receipt as itself.
	 *
	 * @return void
	 */
	public function testExpenseSessionOwnerPassesAsItself(): void {
		$extract = $this->createMock(ReceiptExtractionService::class);
		$extract->expects($this->once())->method('extractForExpense')->with('exp-1', 'clerk')->willReturn(['status' => 'ok']);
		$ctx = $this->context('clerk', $this->expenseContainer('clerk'));
		$controller = new ExpenseController($this->request(), $extract, $this->settings(), $ctx);
		$this->assertSame(Http::STATUS_OK, $controller->extractReceipt('exp-1')->getStatus());
	}

	/**
	 * Offer signing and handoff compile act as the session user, not the forged one.
	 *
	 * @return void
	 */
	public function testOfferAndHandoffActAsTheSessionUser(): void {
		$esign = $this->createMock(OfferEsignService::class);
		$esign->expects($this->once())->method('requestSignature')->with('app-1', 'boss')->willReturn(['status' => 'ok']);
		$os = new class {
			public function find(...$a): array {
				return ['id' => 'app-1', 'status' => 'aanbod'];
			}
		};
		$c = $this->createMock(ContainerInterface::class);
		$c->method('get')->willReturn($os);
		$controller = new OfferController($this->request('victim'), $esign, $this->settings(), $this->context('boss', $c));
		$controller->requestSignature('app-1');

		$h = $this->createMock(SalaryBureauExchangeService::class);
		$h->expects($this->once())->method('compile')->with('ADM-006', '2026-05', 'boss')->willReturn(['status' => 'compiled']);
		$rows = new class {
			public function setRegister(...$a): static { return $this; }
			public function setSchema(...$a): static { return $this; }
			public function findAll(...$a): array { return [['administrationId' => 'ADM-006']]; }
		};
		$c2 = $this->createMock(ContainerInterface::class);
		$c2->method('get')->willReturn($rows);
		$ho = new PayrollHandoffController($this->request('victim'), $this->settings(), $h, $this->context('boss', $c2));
		$this->assertSame(Http::STATUS_OK, $ho->compile('ADM-006', '2026-05')->getStatus());
	}

	/**
	 * Single-role holders and whether each action admits them.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string, 3: array<int, string>, 4: bool}>
	 */
	public static function singleRole(): array {
		return [
			'hr-only check' => ['hr-only', 'payrollCheck', 'check', ['run-1'], true],
			'pay-only check' => ['pay-only', 'payrollCheck', 'check', ['run-1'], true],
			'hr-only compile' => ['hr-only', 'payrollHandoff', 'compile', ['ADM-006', '2026-05'], true],
			'pay-only compile' => ['pay-only', 'payrollHandoff', 'compile', ['ADM-006', '2026-05'], true],
			'pay-only loonbeslag' => ['pay-only', 'loonbeslag', 'activate', ['lb-1'], false],
			'pay-only offer' => ['pay-only', 'offer', 'requestSignature', ['app-1'], false],
		];
	}

	/**
	 * A single role admits exactly the actions that role may take.
	 *
	 * @param string $uid The session user.
	 * @param string $kind The controller.
	 * @param string $action The action.
	 * @param array<int, string> $args Its arguments.
	 * @param bool $allowed Whether the role is admitted.
	 *
	 * @return void
	 *
	 * @dataProvider singleRole
	 */
	public function testSingleRoleHolder(string $uid, string $kind, string $action, array $args, bool $allowed): void {
		$c = $this->createMock(ContainerInterface::class);
		$c->method('get')->willThrowException(new \RuntimeException('reached the read'));
		$controller = $this->build($kind, $this->context($uid, $c), false);
		try {
			$status = $controller->$action(...$args)->getStatus();
		} catch (\RuntimeException $e) {
			$status = 'read';
		}
		if ($allowed === true) {
			$this->assertNotSame(Http::STATUS_FORBIDDEN, $status);
		} else {
			$this->assertSame(Http::STATUS_FORBIDDEN, $status);
		}
	}

	/**
	 * HR extracts a receipt of someone else as itself.
	 *
	 * @return void
	 */
	public function testExpenseHrNonOwnerPasses(): void {
		$extract = $this->createMock(ReceiptExtractionService::class);
		$extract->expects($this->once())->method('extractForExpense')->with('exp-1', 'hr-only')->willReturn(['status' => 'ok']);
		$ctx = $this->context('hr-only', $this->expenseContainer('someone'));
		$controller = new ExpenseController($this->request('someone'), $extract, $this->settings(), $ctx);
		$this->assertSame(Http::STATUS_OK, $controller->extractReceipt('exp-1')->getStatus());
	}
}
