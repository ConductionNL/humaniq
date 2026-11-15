<?php

/**
 * Unit test for the shared collaborators of the payroll flow nodes.
 *
 * Pins that each node reads its translations, icon URL, container and
 * settings from the services it was built with.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Flow
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
 * @spec openspec/changes/payroll-run-as-a-flow/specs/payroll-run-as-a-flow/spec.md#REQ-PRF-001
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Flow;

use OCA\Humaniq\Flow\PayrollApproveNode;
use OCA\Humaniq\Flow\PayrollFlowNodeServices;
use OCA\Humaniq\Service\SettingsService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;
use stdClass;

/**
 * The node reads every shared collaborator from its services.
 *
 * @covers \OCA\Humaniq\Flow\PayrollFlowNodeServices
 * @covers \OCA\Humaniq\Flow\PayrollFlowNodeBase
 * @covers \OCA\Humaniq\Flow\PayrollApproveNode
 * @uses   \OCA\Humaniq\Support\ConvertsRowsToArrays
 */
class PayrollFlowNodeServicesTest extends TestCase {

	/**
	 * A node over the given availability, with a recognisable slug, icon and translation.
	 *
	 * @param bool $available Whether OpenRegister is installed.
	 * @param object $objectService What the container resolves.
	 *
	 * @return PayrollApproveNode
	 */
	private function node(bool $available, object $objectService): PayrollApproveNode {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => 'nl:' . $text);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->with('humaniq', 'app-dark.svg')->willReturn('/apps/humaniq/img/app-dark.svg');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with('OCA\OpenRegister\Service\ObjectService')->willReturn($objectService);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($available);
		$settings->method('getRegisterSlug')->willReturn('humaniq-slug');

		return new PayrollApproveNode(new PayrollFlowNodeServices($l10n, $urls, $container, $settings), $this->createMock(LoggerInterface::class));
	}//end node()

	/**
	 * The icon and the palette strings come from the services.
	 *
	 * @return void
	 */
	public function testTheIconAndNameComeFromTheServices(): void {
		$node = $this->node(true, new stdClass());

		$this->assertSame('/apps/humaniq/img/app-dark.svg', $node->getIcon());
		$this->assertStringStartsWith('nl:', $node->getDisplayName());
	}//end testTheIconAndNameComeFromTheServices()

	/**
	 * The register slug and the ObjectService come from the services.
	 *
	 * @return void
	 */
	public function testTheRegisterAndObjectServiceComeFromTheServices(): void {
		$objectService = new stdClass();
		$node = $this->node(true, $objectService);

		$this->assertSame('humaniq-slug', (new ReflectionMethod($node, 'register'))->invoke($node));
		$this->assertSame($objectService, (new ReflectionMethod($node, 'objectService'))->invoke($node));
	}//end testTheRegisterAndObjectServiceComeFromTheServices()

	/**
	 * Without OpenRegister the node refuses and names the app to install.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheNodeRefuses(): void {
		$node = $this->node(false, new stdClass());

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister');
		(new ReflectionMethod($node, 'objectService'))->invoke($node);
	}//end testWithoutOpenRegisterTheNodeRefuses()
}//end class
