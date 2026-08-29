<?php
/**
 * Humaniq delegated-admin marker for attribute-scoped endpoints.
 *
 * 🔴 THIS EXISTS TO BE NAMED, NOT TO BE RENDERED. Humaniq ships no admin
 * settings panel — `appinfo/info.xml` declares no `<settings>` block — and this
 * class deliberately does not add one. It is not registered anywhere.
 *
 * It exists because `#[AuthorizedAdminSetting(...)]` requires a
 * `class-string<OCP\Settings\IDelegatedSettings>`, and that attribute is the
 * only way to DECLARE "admin only" on a controller method. Without it, gate-5
 * flags the routed method — correctly, since an undeclared posture is one
 * nothing can check, and a missing attribute silently makes an endpoint
 * unreachable.
 *
 * `getForm()` returns the app's own template because the interface requires a
 * TemplateResponse and this app has no settings template of its own. It is
 * unreachable in practice: nothing registers this class, so Nextcloud never
 * asks it for a form. Registering it would be the bug, not calling it.
 *
 * @category Settings
 * @package  OCA\Humaniq\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Humaniq\Settings;

use OCA\Humaniq\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\IDelegatedSettings;

/**
 * Delegated-settings marker used by the setup wizard's admin-only endpoints.
 *
 * @spec exclude Auth marker for ADR-042 setup endpoints; no behavioural spec.
 */
class HumaniqAdmin implements IDelegatedSettings {
	/**
	 * The settings form.
	 *
	 * Unreachable in practice — see the class docblock.
	 *
	 * @return TemplateResponse The app template.
	 *
	 * @spec exclude IDelegatedSettings contract method; never rendered.
	 */
	public function getForm(): TemplateResponse {
		return new TemplateResponse(Application::APP_ID, 'index');
	}//end getForm()

	/**
	 * Settings section this belongs to.
	 *
	 * @return string The section id.
	 *
	 * @spec exclude IDelegatedSettings contract method; no behavioural spec.
	 */
	public function getSection(): string {
		return Application::APP_ID;
	}//end getSection()

	/**
	 * Ordering priority within the section.
	 *
	 * @return integer The priority.
	 *
	 * @spec exclude IDelegatedSettings contract method; no behavioural spec.
	 */
	public function getPriority(): int {
		return 50;
	}//end getPriority()

	/**
	 * Human-readable name of the delegated settings section.
	 *
	 * @return string|null Null, so the section's own default name is used.
	 *
	 * @spec exclude IDelegatedSettings contract method; no behavioural spec.
	 */
	public function getName(): ?string {
		return null;
	}//end getName()

	/**
	 * App config keys an authorized (delegated) admin may manage.
	 *
	 * EMPTY ON PURPOSE, and that is the auth-critical part: an empty map grants
	 * no group-restricted sub-admin anything, so every endpoint scoped to this
	 * class stays full-admin-only — the same posture it had while carrying no
	 * attribute at all. The declaration changes what is CHECKABLE, not who may
	 * call it.
	 *
	 * @return array<string,string[]> Map of appId to allowed config keys.
	 *
	 * @spec exclude IDelegatedSettings contract method; no behavioural spec.
	 */
	public function getAuthorizedAppConfig(): array {
		return [];
	}//end getAuthorizedAppConfig()
}//end class
