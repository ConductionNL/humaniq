<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The humaniq admin page, /settings/admin/humaniq (board HuInstellingen).

 An app component, not manifest config, because the admin page lives outside
 the app: Nextcloud renders it on its own settings page, where no CnAppRoot or
 manifest runs. The frame is the library's CnAdminSettingsShell in the board
 look; this file only fills its sections.

 @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
 @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
 @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-004
 @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
 @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-006
-->
<template>
	<div class="humaniq-admin">
		<CnAdminSettingsShell
			appId="humaniq"
			appName="Humaniq"
			look="board"
			:title="t('humaniq', 'Humaniq administration: settings')"
			docUrl="https://humaniq.conduction.nl"
			:breadcrumbs="breadcrumbs"
			:breadcrumbNote="t('humaniq', 'visible to Humaniq administrators')"
			:showVersionCard="false">
			<div class="humaniq-admin__sections">
				<!-- Board section "Rollen en rechten". The groups are fixed in code
				     (HumaniqRoles): OpenRegister's property authorization names them
				     literally, so the table reads and does not save. -->
				<CnSettingsSection
					:name="t('humaniq', 'Roles and rights')"
					:description="t('humaniq', 'Each role hangs on a Nextcloud group. Whoever is in the group gets the role.')"
					wide
					data-testid="humaniq-admin-roles">
					<table class="humaniq-admin__table">
						<thead>
							<tr>
								<th scope="col">
									{{ t('humaniq', 'Role') }}
								</th>
								<th scope="col">
									{{ t('humaniq', 'Nextcloud group') }}
								</th>
								<th scope="col">
									{{ t('humaniq', 'Allowed to') }}
								</th>
								<th scope="col" class="humaniq-admin__num">
									{{ t('humaniq', 'Members') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="role in roles" :key="role.id" :data-role="role.id">
								<td class="humaniq-admin__role">
									{{ role.label }}
								</td>
								<td>{{ role.group }}</td>
								<td>{{ role.may }}</td>
								<td class="humaniq-admin__num">
									{{ role.members }}
								</td>
							</tr>
						</tbody>
					</table>
					<p class="humaniq-admin__note">
						{{ t('humaniq', 'The groups are fixed: OpenRegister guards the sensitive fields by these group names. Add people to a group under Users and groups.') }}
					</p>
				</CnSettingsSection>

				<!-- Which expense categories employees may claim. The server refuses
				     a claim in a category that is off. -->
				<CnSettingsSection
					:name="t('humaniq', 'Expense categories')"
					:description="t('humaniq', 'Choose what employees may claim. A claim in a category that is off cannot be made or submitted.')"
					data-testid="humaniq-admin-expense-categories">
					<NcCheckboxRadioSwitch
						v-for="category in expenseCategories"
						:key="category.id"
						type="switch"
						:modelValue="category.allowed"
						:disabled="savingCategories"
						:data-category="category.id"
						@update:modelValue="toggleCategory(category.id, $event)">
						{{ categoryLabel(category.id) }}
					</NcCheckboxRadioSwitch>
					<p v-if="categoryError" class="humaniq-admin__note" role="alert">
						{{ categoryError }}
					</p>
				</CnSettingsSection>

				<!-- Board tab "Inloggen". Sign-in is Nextcloud's: the methods come
				     from user_saml / user_oidc (read only), the password rules and
				     the lock after failed attempts are password_policy's own
				     config, which the API reads and writes. humaniq stores none. -->
				<CnSettingsSection
					:name="t('humaniq', 'Sign-in')"
					:description="t('humaniq', 'How employees, applicants and former employees sign in. These are Nextcloud settings: what you save here is what the Nextcloud security settings show.')"
					data-testid="humaniq-admin-sign-in">
					<h3 class="humaniq-admin__subheading">
						{{ t('humaniq', 'Sign-in methods') }}
					</h3>
					<ul class="humaniq-admin__methods">
						<li v-for="method in signIn.signInMethods"
							:key="method.app"
							:data-method="method.app">
							<span class="humaniq-admin__role">{{ methodLabel(method.protocol) }}</span>
							<span>{{ methodState(method) }}</span>
							<a v-if="method.enabled" :href="adminSectionUrl(method.section)">
								{{ t('humaniq', 'Provider settings') }}
							</a>
						</li>
					</ul>

					<template v-if="signIn.passwordPolicy.available">
						<h3 class="humaniq-admin__subheading">
							{{ t('humaniq', 'Password rules for accounts outside single sign-on') }}
						</h3>
						<NcTextField :modelValue="String(policy.minLength ?? '')"
							:label="t('humaniq', 'Minimum password length')"
							type="number"
							min="1"
							max="255"
							data-policy="minLength"
							@update:modelValue="policy.minLength = $event" />
						<NcCheckboxRadioSwitch v-for="rule in policyRules"
							:key="rule.key"
							type="switch"
							:modelValue="policy[rule.key] === true"
							:data-policy="rule.key"
							@update:modelValue="policy[rule.key] = $event">
							{{ rule.label }}
						</NcCheckboxRadioSwitch>

						<h3 class="humaniq-admin__subheading">
							{{ t('humaniq', 'Failed sign-in attempts') }}
						</h3>
						<p class="humaniq-admin__note">
							{{ t('humaniq', 'Nextcloud slows down repeated failed sign-in attempts. It can also lock the account after a number of them.') }}
						</p>
						<NcTextField :modelValue="String(policy.maximumLoginAttempts ?? '')"
							:label="t('humaniq', 'Lock the account after this many failed attempts (0 is never)')"
							type="number"
							min="0"
							max="255"
							data-policy="maximumLoginAttempts"
							@update:modelValue="policy.maximumLoginAttempts = $event" />

						<NcButton variant="primary"
							class="humaniq-admin__save"
							:disabled="savingSignIn"
							data-testid="humaniq-admin-sign-in-save"
							@click="saveSignInSettings">
							{{ t('humaniq', 'Save') }}
						</NcButton>
					</template>
					<p v-else-if="signInLoaded" class="humaniq-admin__note" data-testid="humaniq-admin-sign-in-missing">
						{{ t('humaniq', 'The password policy needs the Nextcloud password policy app.') }}
						<a :href="appsUrl">{{ t('humaniq', 'Open the apps page') }}</a>
					</p>
					<p v-if="signInMessage" class="humaniq-admin__note" :role="signInError ? 'alert' : 'status'">
						{{ signInMessage }}
					</p>
				</CnSettingsSection>
			</div>
		</CnAdminSettingsShell>
	</div>
</template>

<script>
import { CnAdminSettingsShell, CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcTextField } from '@nextcloud/vue'
import { defineComponent } from 'vue'
import { allowedAfterToggle, loadCategories, saveCategories } from './expenseCategories.js'
import { emptySignIn, loadSignIn, saveRefusal, saveSignIn } from './signInSettings.js'

const CATEGORIES_URL = generateUrl('/apps/humaniq/api/expense-categories')
const SIGN_IN_URL = generateUrl('/apps/humaniq/api/settings/sign-in')

export default defineComponent({
	name: 'AdminSettings',

	components: {
		CnAdminSettingsShell,
		CnSettingsSection,
		NcButton,
		NcCheckboxRadioSwitch,
		NcTextField,
	},

	data() {
		return {
			roleGroups: loadState('humaniq', 'roleGroups', {}),
			expenseCategories: [],
			savingCategories: false,
			categoryError: '',
			signIn: emptySignIn(),
			policy: {},
			signInLoaded: false,
			savingSignIn: false,
			signInMessage: '',
			signInError: false,
		}
	},

	computed: {
		/**
		 * The path line under the title: the administration settings, the app,
		 * then this page.
		 *
		 * @return {Array<{label: string, href?: string}>}
		 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
		 */
		breadcrumbs() {
			return [
				{ label: t('humaniq', 'Admin settings'), href: generateUrl('/settings/admin') },
				{ label: 'Humaniq', href: generateUrl('/apps/humaniq/') },
				{ label: t('humaniq', 'Settings') },
			]
		},

		/**
		 * The four roles of REQ-RFA-001. HR and payroll are groups with a member
		 * count from the server; managers follow from the org chart and
		 * employees from their own account, so neither has a group to count.
		 *
		 * @return {Array<{id: string, label: string, group: string, may: string, members: string}>}
		 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
		 */
		roles() {
			return [...this.groupRoles(), ...this.derivedRoles()]
		},

		/**
		 * The password policy switches with their labels, in board order.
		 *
		 * @return {Array<{key: string, label: string}>}
		 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
		 */
		policyRules() {
			return [
				{ key: 'enforceUpperLowerCase', label: t('humaniq', 'Require upper and lower case letters') },
				{ key: 'enforceNumericCharacters', label: t('humaniq', 'Require a digit') },
				{ key: 'enforceSpecialCharacters', label: t('humaniq', 'Require a special character') },
				{ key: 'enforceHaveIBeenPwned', label: t('humaniq', 'Refuse passwords known from data breaches') },
			]
		},

		/**
		 * Nextcloud's apps page, where the password policy app is enabled.
		 *
		 * @return {string}
		 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
		 */
		appsUrl() {
			return generateUrl('/settings/apps')
		},
	},

	/**
	 * Read the expense category switches.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
	 */
	async mounted() {
		await Promise.all([this.loadExpenseCategories(), this.loadSignInSettings()])
	},

	methods: {
		t,

		/**
		 * Read the expense category switches.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
		 */
		async loadExpenseCategories() {
			try {
				this.expenseCategories = await loadCategories(axios, CATEGORIES_URL)
			} catch {
				this.categoryError = t('humaniq', 'The expense categories could not be loaded.')
			}
		},

		/**
		 * Read the sign-in methods and the password policy.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-004
		 */
		async loadSignInSettings() {
			try {
				this.showSignIn(await loadSignIn(axios, SIGN_IN_URL))
				this.signInLoaded = true
			} catch {
				this.signInError = true
				this.signInMessage = t('humaniq', 'The sign-in settings could not be loaded.')
			}
		},

		/**
		 * Show the settings as the server holds them.
		 *
		 * @param {{signInMethods: Array<object>, passwordPolicy: {available: boolean, values: object|null}}} settings The settings.
		 * @return {void}
		 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
		 */
		showSignIn(settings) {
			this.signIn = settings
			this.policy = { ...(settings.passwordPolicy.values ?? {}) }
		},

		/**
		 * Save the password policy in Nextcloud's password policy app.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
		 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-006
		 */
		async saveSignInSettings() {
			this.savingSignIn = true
			this.signInMessage = ''
			this.signInError = false
			try {
				this.showSignIn(await saveSignIn(axios, SIGN_IN_URL, this.policy))
				this.signInMessage = t('humaniq', 'The sign-in settings are saved.')
			} catch (error) {
				this.signInError = true
				this.signInMessage = this.signInRefusal(saveRefusal(error))
			} finally {
				this.savingSignIn = false
			}
		},

		/**
		 * The message for a refused save.
		 *
		 * @param {'missing-app'|'invalid'|'failed'} reason Why the save was refused.
		 * @return {string}
		 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-005
		 */
		signInRefusal(reason) {
			if (reason === 'missing-app') {
				return t('humaniq', 'The password policy needs the Nextcloud password policy app.')
			}
			if (reason === 'invalid') {
				return t('humaniq', 'Use whole numbers: a minimum length from 1 to 255 and a number of failed attempts from 0 to 255.')
			}
			return t('humaniq', 'The sign-in settings could not be saved.')
		},

		/**
		 * The name of a sign-in method.
		 *
		 * @param {string} protocol `saml` or `oidc`.
		 * @return {string}
		 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-004
		 */
		methodLabel(protocol) {
			const labels = {
				saml: t('humaniq', 'Single sign-on through SAML'),
				oidc: t('humaniq', 'Single sign-on through OpenID Connect'),
			}
			return labels[protocol] ?? protocol
		},

		/**
		 * What a sign-in method shows: its providers, or that it is off.
		 *
		 * @param {{enabled: boolean, providers: Array<string>}} method The method.
		 * @return {string}
		 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-004
		 */
		methodState(method) {
			if (method.enabled !== true) {
				return t('humaniq', 'not enabled')
			}
			if (!Array.isArray(method.providers) || method.providers.length === 0) {
				return t('humaniq', 'enabled, no provider configured yet')
			}
			return method.providers.join(', ')
		},

		/**
		 * A Nextcloud admin settings section.
		 *
		 * @param {string} section The section id.
		 * @return {string}
		 * @spec openspec/changes/sign-in-and-hosting-are-the-platforms/specs/irma-digid-auth/spec.md#REQ-AUTH-004
		 */
		adminSectionUrl(section) {
			return generateUrl('/settings/admin/' + encodeURIComponent(section))
		},

		/**
		 * The label of an expense category.
		 *
		 * @param {string} id The category id.
		 * @return {string}
		 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
		 */
		categoryLabel(id) {
			const labels = {
				travel: t('humaniq', 'Travel'),
				accommodation: t('humaniq', 'Accommodation'),
				meals: t('humaniq', 'Meals'),
				supplies: t('humaniq', 'Supplies'),
				software: t('humaniq', 'Software'),
				training: t('humaniq', 'Training'),
				other: t('humaniq', 'Other'),
			}
			return labels[id] ?? id
		},

		/**
		 * Save one switch; the page shows what the server stored.
		 *
		 * @param {string} id The category.
		 * @param {boolean} on Whether it is now on.
		 * @return {Promise<void>}
		 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
		 */
		async toggleCategory(id, on) {
			this.savingCategories = true
			this.categoryError = ''
			try {
				this.expenseCategories = await saveCategories(axios, CATEGORIES_URL, allowedAfterToggle(this.expenseCategories, id, on))
			} catch {
				this.categoryError = t('humaniq', 'The expense categories could not be saved.')
			} finally {
				this.savingCategories = false
			}
		},

		/**
		 * The two roles that are Nextcloud groups: HR and payroll, each with
		 * its group id and member count from the server.
		 *
		 * @return {Array<{id: string, label: string, group: string, may: string, members: string}>}
		 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
		 */
		groupRoles() {
			return [
				this.groupRole('hr', {
					label: t('humaniq', 'HR'),
					may: t('humaniq', 'employees, contracts, leave, absence, GDPR requests'),
				}),
				this.groupRole('payroll', {
					label: t('humaniq', 'Payroll administration'),
					may: t('humaniq', 'calculate and approve payroll runs, tax returns, payments'),
				}),
			]
		},

		/**
		 * The two roles without a group to count: managers follow from the org
		 * chart, employees from their own account.
		 *
		 * @return {Array<{id: string, label: string, group: string, may: string, members: string}>}
		 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
		 */
		derivedRoles() {
			return [
				this.derivedRole('manager', {
					label: t('humaniq', 'Manager'),
					group: t('humaniq', 'from the org chart'),
					may: t('humaniq', 'own team: approve leave, hours and reviews'),
				}),
				this.derivedRole('employee', {
					label: t('humaniq', 'Employee'),
					group: t('humaniq', 'everyone'),
					may: t('humaniq', 'own details, payslips, leave and expense claims'),
				}),
			]
		},

		/**
		 * A role row for a role group (`humaniq-<key>` unless the server
		 * reported another id).
		 *
		 * @param {string} key The role key in the initial state.
		 * @param {{label: string, may: string}} texts The role's name and what it may do.
		 * @return {{id: string, label: string, group: string, may: string, members: string}}
		 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
		 */
		groupRole(key, texts) {
			return {
				id: key,
				label: texts.label,
				group: this.groupId(key, 'humaniq-' + key),
				may: texts.may,
				members: this.memberCount(key),
			}
		},

		/**
		 * A role row for a role without a group: nothing to count.
		 *
		 * @param {string} key The role id.
		 * @param {{label: string, group: string, may: string}} texts The role's name, where it comes from and what it may do.
		 * @return {{id: string, label: string, group: string, may: string, members: string}}
		 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
		 */
		derivedRole(key, texts) {
			return {
				id: key,
				label: texts.label,
				group: texts.group,
				may: texts.may,
				members: '',
			}
		},

		/**
		 * The group id the server reported for a role, or the fixed id.
		 *
		 * @param {string} key The role key in the initial state.
		 * @param {string} fallback The fixed group id.
		 * @return {string}
		 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
		 */
		groupId(key, fallback) {
			return this.roleGroups?.[key]?.id || fallback
		},

		/**
		 * A role group's member count as shown, or a note when the group is
		 * missing (the repair step creates it on install and upgrade).
		 *
		 * @param {string} key The role key in the initial state.
		 * @return {string}
		 * @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
		 */
		memberCount(key) {
			const group = this.roleGroups?.[key]
			if (!group || group.exists !== true) {
				return t('humaniq', 'group missing')
			}
			return String(group.members ?? '')
		},
	},
})
</script>

<style scoped>
.humaniq-admin__table {
	width: 100%;
	border-collapse: collapse;
}

.humaniq-admin__table th,
.humaniq-admin__table td {
	padding: 10px 14px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.humaniq-admin__table thead th {
	background: var(--color-background-hover);
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.humaniq-admin__table thead th {
	font-size: 0.9em;
}

/* The role name is a bold cell, not a row `th`: the NL Design theme paints
   every `th` as a header with !important. */
.humaniq-admin__table .humaniq-admin__role {
	font-weight: 600;
}

.humaniq-admin__table .humaniq-admin__num {
	text-align: end;
}

.humaniq-admin__note {
	margin-top: 12px;
	color: var(--color-text-maxcontrast);
}

.humaniq-admin__subheading {
	margin: 16px 0 8px;
	font-size: 1em;
	font-weight: 600;
}

.humaniq-admin__methods {
	margin: 0;
	padding: 0;
	list-style: none;
}

.humaniq-admin__methods li {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 12px;
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}

.humaniq-admin__save {
	margin-top: 12px;
}
</style>
