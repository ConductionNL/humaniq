<?php

/**
 * PayrollRunApprovalGuardTest
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Listener
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
 * @spec openspec/specs/payroll-run-pay-date-and-schedule/spec.md#REQ-PRD-003
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Listener;

use OCA\Humaniq\Listener\PayrollRunApprovalGuard;
use OCA\Humaniq\Service\HoursRegisterGateway;
use OCA\Humaniq\Service\InternalWriteMarker;
use OCA\Humaniq\Service\ShippedFlowAdoption;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Once the Loonrun flow is adopted, its review step is the only approver.
 *
 * @covers \OCA\Humaniq\Listener\PayrollRunApprovalGuard
 * @uses   \OCA\Humaniq\Listener\PreSaveEvents
 * @uses   \OCA\Humaniq\Service\InternalWriteMarker
 */
class PayrollRunApprovalGuardTest extends TestCase {

	/**
	 * GIVEN the flow is adopted WHEN a user edits a draft run to approved THEN
	 * the edit is refused and names the review step.
	 *
	 * @return void
	 */
	public function testADirectApprovalIsRefusedOnceTheFlowIsAdopted(): void {
		$event = $this->update(old: 'draft', new: 'approved');

		$this->guard(adopted: true)->handle($event);

		self::assertTrue($event->isPropagationStopped());
		self::assertStringContainsString('beoordelingsstap (review) van de Loonrun-flow', $event->getErrors()['message']);
	}//end testADirectApprovalIsRefusedOnceTheFlowIsAdopted()

	/**
	 * Skipping approval by writing posted or paid out of draft is refused too,
	 * as is creating a run that is already approved.
	 *
	 * @return void
	 */
	public function testSkippingTheApprovalIsRefused(): void {
		foreach (['posted', 'paid'] as $status) {
			$event = $this->update(old: 'draft', new: $status);
			$this->guard(adopted: true)->handle($event);
			self::assertTrue($event->isPropagationStopped(), $status);
		}

		$entity = $this->payrollRun('approved');
		$create = new ObjectCreatingEvent($entity);
		$this->guard(adopted: true)->handle($create);
		self::assertTrue($create->isPropagationStopped());
	}//end testSkippingTheApprovalIsRefused()

	/**
	 * The flow's own approve step writes under the marker and passes; so does
	 * a shipped import.
	 *
	 * @return void
	 */
	public function testTheFlowsOwnStepAndAShippedImportPass(): void {
		$marker = new InternalWriteMarker();
		$guard = $this->guard(adopted: true, marker: $marker);

		$event = $this->update(old: 'draft', new: 'approved');
		$marker->runInternal(static fn () => $guard->handle($event));
		self::assertFalse($event->isPropagationStopped());

		$import = $this->update(old: 'draft', new: 'approved');
		$marker->runShippedImport(static fn () => $guard->handle($import));
		self::assertFalse($import->isPropagationStopped());
	}//end testTheFlowsOwnStepAndAShippedImportPass()

	/**
	 * Before adoption the raw edit is the production path and passes.
	 *
	 * @return void
	 */
	public function testBeforeAdoptionTheRawEditPasses(): void {
		$event = $this->update(old: 'draft', new: 'approved');

		$this->guard(adopted: false)->handle($event);

		self::assertFalse($event->isPropagationStopped());
		self::assertSame([], $event->getErrors());
	}//end testBeforeAdoptionTheRawEditPasses()

	/**
	 * An adoption lookup that fails is read as not adopted, not as a refusal.
	 *
	 * @return void
	 */
	public function testAFailingAdoptionLookupLetsTheEditThrough(): void {
		$event = $this->update(old: 'draft', new: 'approved');

		$this->guard(adopted: true, throws: true)->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAFailingAdoptionLookupLetsTheEditThrough()

	/**
	 * Edits that are not an approval are left alone without asking about
	 * adoption: a draft staying draft, and posting an approved run.
	 *
	 * @return void
	 */
	public function testEditsThatAreNotAnApprovalPass(): void {
		foreach ([['draft', 'draft'], ['approved', 'posted'], ['posted', 'paid']] as [$old, $new]) {
			$event = $this->update(old: $old, new: $new);
			$this->guard(adopted: true, expectLookup: false)->handle($event);
			self::assertFalse($event->isPropagationStopped(), $old . ' -> ' . $new);
		}
	}//end testEditsThatAreNotAnApprovalPass()

	/**
	 * Another schema's write is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemaIsIgnored(): void {
		$event = $this->update(old: 'draft', new: 'approved');

		$this->guard(adopted: true, slug: 'Timesheet', expectLookup: false)->handle($event);

		self::assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemaIsIgnored()

	/**
	 * A PayrollRun entity in the given status.
	 *
	 * @param string $status The run's status.
	 *
	 * @return ObjectEntity
	 */
	private function payrollRun(string $status): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema('PayrollRun');
		$entity->setUuid('run-1');
		$entity->setObject(['period' => '2026-05', 'administrationId' => 'ADM-001', 'status' => $status]);

		return $entity;
	}//end run()

	/**
	 * An update of the run from one status to another.
	 *
	 * @param string $old The stored status.
	 * @param string $new The written status.
	 *
	 * @return ObjectUpdatingEvent
	 */
	private function update(string $old, string $new): ObjectUpdatingEvent {
		return new ObjectUpdatingEvent($this->payrollRun($new), $this->payrollRun($old));
	}//end update()

	/**
	 * The guard over doubles.
	 *
	 * @param bool                     $adopted      Whether the Loonrun flow is adopted.
	 * @param InternalWriteMarker|null $marker       The marker, a fresh one by default.
	 * @param bool                     $throws       Whether the adoption lookup fails.
	 * @param string                   $slug         The schema slug the entity resolves to.
	 * @param bool                     $expectLookup Whether the adoption may be asked at all.
	 *
	 * @return PayrollRunApprovalGuard
	 */
	private function guard(bool $adopted, ?InternalWriteMarker $marker=null, bool $throws=false, string $slug='PayrollRun', bool $expectLookup=true): PayrollRunApprovalGuard {
		$gateway = $this->getMockBuilder(HoursRegisterGateway::class)->disableOriginalConstructor()->onlyMethods(['resolveSchemaSlug'])->getMock();
		$gateway->method('resolveSchemaSlug')->willReturn($slug);

		$adoption = $this->createMock(ShippedFlowAdoption::class);
		if ($expectLookup === false) {
			$adoption->expects(self::never())->method('isAdopted');
		} else if ($throws === true) {
			$adoption->method('isAdopted')->willThrowException(new RuntimeException('flow store unavailable'));
		} else {
			$adoption->method('isAdopted')->with('humaniq', 'Loonrun', 'PayrollRun')->willReturn($adopted);
		}

		return new PayrollRunApprovalGuard(gateway: $gateway, marker: ($marker ?? new InternalWriteMarker()), adoption: $adoption, logger: new NullLogger());
	}//end guard()
}//end class
