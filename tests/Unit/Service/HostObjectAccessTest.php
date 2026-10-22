<?php
/**
 * HostObjectAccess tests
 *
 * Whether the caller may read the object a time entry is booked on: asked of
 * OpenRegister with the caller's access rules, never answered yes when the
 * object is unknown, unreadable, malformed or OpenRegister cannot be reached.
 *
 * @category Test
 * @package  OCA\Humaniq\Tests\Unit\Service
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
 * @spec openspec/changes/hours-week-host-access/specs/hours-leaf/spec.md#requirement-the-week-is-answered-only-to-a-reader-of-the-object
 */

declare(strict_types=1);

namespace OCA\Humaniq\Tests\Unit\Service;

use OCA\Humaniq\Service\HostObjectAccess;
use OCA\Humaniq\Tests\Unit\Support\FakeContainer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Humaniq\Service\HostObjectAccess
 */
class HostObjectAccessTest extends TestCase {

	/**
	 * An object service that records how it was asked and answers from a list.
	 *
	 * @param array<string, bool> $readable "register/schema/id" => whether the caller may read it.
	 * @param bool                $throws   Whether every read fails.
	 *
	 * @return object The spy.
	 */
	private function objects(array $readable, bool $throws = false): object {
		return new class($readable, $throws) {
			/** @var array<int, array<string, mixed>> */
			public array $calls = [];

			/**
			 * @param array<string, bool> $readable The readable objects.
			 * @param bool                $throws   Whether reads fail.
			 */
			public function __construct(private array $readable, private bool $throws) {
			}

			/**
			 * Mirror of ObjectService::find().
			 *
			 * @param int|string $id            The id.
			 * @param array|null $_extend       Ignored.
			 * @param bool       $files         Ignored.
			 * @param mixed      $register      The register.
			 * @param mixed      $schema        The schema.
			 * @param bool       $_rbac         Whether the caller's rules apply.
			 * @param bool       $_multitenancy Whether tenancy applies.
			 *
			 * @return object|null A found object, or null.
			 */
			public function find(int|string $id, ?array $_extend = [], bool $files = false, mixed $register = null, mixed $schema = null, bool $_rbac = true, bool $_multitenancy = true): ?object {
				$this->calls[] = ['id' => $id, 'register' => $register, 'schema' => $schema, 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				if ($this->throws === true) {
					throw new RuntimeException('no access');
				}

				return ($this->readable["$register/$schema/$id"] ?? false) === true ? new \stdClass() : null;
			}
		};
	}//end objects()

	/**
	 * A readable dossiq case is readable, asked as the caller.
	 *
	 * @return void
	 */
	public function testAReadableObjectIsAskedAsTheCaller(): void {
		$objects = $this->objects(['dossiq/case/case-1' => true]);
		$access = new HostObjectAccess(container: new FakeContainer(['OCA\OpenRegister\Service\ObjectService' => $objects]));

		$this->assertTrue($access->canRead('dossiq:case', 'case-1'));
		$this->assertSame([['id' => 'case-1', 'register' => 'dossiq', 'schema' => 'case', 'rbac' => true, 'multitenancy' => true]], $objects->calls);
	}//end testAReadableObjectIsAskedAsTheCaller()

	/**
	 * Unknown, unreadable, malformed or failing: no.
	 *
	 * @return void
	 */
	public function testEverythingElseIsNo(): void {
		$access = new HostObjectAccess(container: new FakeContainer(['OCA\OpenRegister\Service\ObjectService' => $this->objects(['dossiq/case/case-1' => true])]));
		$this->assertFalse($access->canRead('dossiq:case', 'case-2'));
		$this->assertFalse($access->canRead('dossiq-case', 'case-1'));
		$this->assertFalse($access->canRead(':case', 'case-1'));
		$this->assertFalse($access->canRead('dossiq:', 'case-1'));
		$this->assertFalse($access->canRead('dossiq:case', ''));

		$failing = new HostObjectAccess(container: new FakeContainer(['OCA\OpenRegister\Service\ObjectService' => $this->objects([], true)]));
		$this->assertFalse($failing->canRead('dossiq:case', 'case-1'));

		$absent = new HostObjectAccess(container: new FakeContainer([]));
		$this->assertFalse($absent->canRead('dossiq:case', 'case-1'));
	}//end testEverythingElseIsNo()
}//end class
