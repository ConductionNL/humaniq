<?php

/**
 * OpenRegister leaf-catalogue stub, deliberately PRE-#3956
 *
 * Mirrors the `LeafDescriptor` / `RegisterLeafProvidersEvent` pair, with the
 * grouped `LeafIdentity` / `LeafAvailability` / `LeafRendering` constructor, but
 * WITHOUT what ConductionNL/openregister#3956 added: the `loadStrategy`
 * parameter and the `LOADS_*` constants.
 *
 * That version is the one worth stubbing. humaniq cannot choose which
 * OpenRegister an admin runs it beside, and a leaf listener that reads a
 * constant the installed class does not declare raises an `Error` its own
 * catch then swallows: the leaf is absent and the only trace is a warning.
 * hermiq measured exactly that on a live instance. Stubbing the OLD shape
 * makes the standalone suite the instance that would have broken, so the
 * guard in the listeners is exercised rather than described.
 *
 * Every class is individually guarded, so a full server checkout with
 * OpenRegister installed keeps the real classes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Service\Integration
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

namespace OCA\OpenRegister\Service\Integration;

if (class_exists('OCA\OpenRegister\Service\Integration\LeafDescriptor') === false) {
	/**
	 * One leaf a consuming app contributes to the catalogue.
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-both-halves-of-the-leaf-agree
	 */
	class LeafDescriptor {

		/**
		 * The leaf renders a surface of its own.
		 *
		 * @var string
		 */
		public const KIND_RENDER_SURFACE = 'render-surface';

		/**
		 * The leaf mounts and unmounts its own DOM subtree.
		 *
		 * @var string
		 */
		public const RENDER_MODE_MOUNT = 'mount';

		public string $id;

		public string $label;

		public string $icon;

		public string $requiredApp;

		public ?string $group;

		public array $surfaces;

		public ?string $referenceType;

		public ?string $requiresPermission;

		public ?string $renderMode;

		/**
		 * Constructor, grouped as OpenRegister declares it, without the #3956 load strategy.
		 *
		 * @param LeafIdentity $identity The id, label, icon and group.
		 * @param array<int, string> $kinds What the leaf contributes.
		 * @param LeafAvailability|null $availability The app that must be installed.
		 * @param LeafRendering|null $rendering Surfaces, reference type and render mode.
		 */
		public function __construct(
			LeafIdentity $identity,
			private array $kinds,
			?LeafAvailability $availability = null,
			?LeafRendering $rendering = null,
		) {
			$availability = $availability ?? new LeafAvailability();
			$rendering = $rendering ?? new LeafRendering();
			$this->id = $identity->id;
			$this->label = $identity->label;
			$this->icon = $identity->icon;
			$this->requiredApp = $availability->requiredApp;
			$this->group = $identity->group;
			$this->surfaces = $rendering->surfaces;
			$this->referenceType = $rendering->referenceType;
			$this->requiresPermission = $availability->requiresPermission;
			$this->renderMode = $rendering->renderMode;
		}//end __construct()
	}//end class

	final class LeafIdentity {
		public function __construct(
			public readonly string $id,
			public readonly string $label,
			public readonly string $icon,
			public readonly ?string $group = null,
		) {
		}
	}

	final class LeafAvailability {
		public function __construct(
			public readonly ?string $requiredApp = null,
			public readonly ?string $requiresPermission = null,
		) {
		}
	}

	final class LeafRendering {
		/**
		 * @param array<int, string> $surfaces Surfaces.
		 */
		public function __construct(
			public readonly array $surfaces = [],
			public readonly ?string $referenceType = null,
			public readonly string $renderMode = 'component',
		) {
		}
	}
}//end if

namespace OCA\OpenRegister\Event;


if (class_exists('OCA\OpenRegister\Event\RegisterLeafProvidersEvent') === false) {
	/**
	 * The event OpenRegister dispatches to collect leaves.
	 *
	 * @spec openspec/specs/hours-leaf/spec.md#requirement-both-halves-of-the-leaf-agree
	 */
	class RegisterLeafProvidersEvent extends \OCP\EventDispatcher\Event {

		/**
		 * The leaves contributed so far.
		 *
		 * @var array<int, array{descriptor: mixed, provider: mixed}>
		 */
		private array $leaves = [];

		/**
		 * Contribute one leaf.
		 *
		 * @param mixed $descriptor The leaf descriptor.
		 * @param mixed $provider The integration provider, or null for a render-only leaf.
		 *
		 * @return void
		 */
		public function registerLeaf(mixed $descriptor, mixed $provider = null): void {
			$this->leaves[] = [
				'descriptor' => $descriptor,
				'provider' => $provider,
			];
		}//end registerLeaf()

		/**
		 * The leaves contributed so far.
		 *
		 * @return array<int, array{descriptor: mixed, provider: mixed}> The leaves.
		 */
		public function getLeaves(): array {
			return $this->leaves;
		}//end getLeaves()
	}//end class
}//end if
