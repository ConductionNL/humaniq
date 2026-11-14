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

				<!-- Board section "Audittrail" (REQ-RAH-003): the ten newest
				     entries of OpenRegister's readable audit list, narrowed to the
				     humaniq register, each as one sentence. humaniq writes none. -->
				<CnSettingsSection
					:name="t('humaniq', 'Audit trail')"
					:description="t('humaniq', 'The newest views and changes of Humaniq records. Entries cannot be changed or removed.')"
					wide
					data-testid="humaniq-admin-audit-trail">
					<ol v-if="auditEntries.length > 0" class="humaniq-admin__audit">
						<li v-for="(entry, index) in auditEntries" :key="entry.uuid || index">
							{{ auditLine(entry) }}
						</li>
					</ol>
					<p v-else-if="auditLoaded && !auditError" class="humaniq-admin__note">
						{{ t('humaniq', 'No audit entries yet.') }}
					</p>
					<p v-if="auditError" class="humaniq-admin__note" role="alert">
						{{ auditError }}
					</p>
					<p v-if="auditLinks" class="humaniq-admin__links">
						<a :href="auditLinks.full">{{ t('humaniq', 'Full audit trail') }}</a>
						<a :href="auditLinks.download">{{ t('humaniq', 'Download') }}</a>
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
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { defineComponent } from 'vue'
import { auditLinks, auditSentence, auditUrls, loadAuditEntries } from './auditTrail.js'
import { allowedAfterToggle, loadCategories, saveCategories } from './expenseCategories.js'

const CATEGORIES_URL = generateUrl('/apps/humaniq/api/expense-categories')

export default defineComponent({
	name: 'AdminSettings',

	components: {
		CnAdminSettingsShell,
		CnSettingsSection,
		NcCheckboxRadioSwitch,
	},

	data() {
		return {
			roleGroups: loadState('humaniq', 'roleGroups', {}),
			expenseCategories: [],
			savingCategories: false,
			categoryError: '',
			auditEntries: [],
			auditLinks: null,
			auditLoaded: false,
			auditError: '',
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
	},

	/**
	 * Read the expense category switches.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/changes/allowed-expense-categories/specs/humaniq-expenses/spec.md#Requirement:-The-employer-SHALL-choose-which-expense-categories-employees-may-claim
	 */
	async mounted() {
		this.loadAuditTrail()
		try {
			this.expenseCategories = await loadCategories(axios, CATEGORIES_URL)
		} catch {
			this.categoryError = t('humaniq', 'The expense categories could not be loaded.')
		}
	},

	methods: {
		t,

		/**
		 * Read the Audittrail section's entries and links.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/record-history-on-every-detail-page/specs/record-audit-history/spec.md#requirement-the-admin-settings-shall-show-an-audittrail-section-across-the-humaniq-register-req-rah-003
		 */
		async loadAuditTrail() {
			const urls = auditUrls(generateUrl, loadState('humaniq', 'registerSlug', 'humaniq'))
			try {
				const { registerId, entries } = await loadAuditEntries(axios, urls)
				this.auditEntries = entries
				this.auditLinks = auditLinks(urls, registerId)
			} catch {
				this.auditError = t('humaniq', 'The audit trail could not be loaded.')
			} finally {
				this.auditLoaded = true
			}
		},

		/**
		 * One audit entry as a sentence.
		 *
		 * @param {object} entry The entry.
		 * @return {string}
		 * @spec openspec/changes/record-history-on-every-detail-page/specs/record-audit-history/spec.md#scenario-the-newest-entries-read-as-sentences
		 */
		auditLine(entry) {
			return auditSentence(entry, (text, vars) => t('humaniq', text, vars), new Date())
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

.humaniq-admin__audit {
	margin: 0;
	padding-inline-start: 20px;
	line-height: 1.6;
}

.humaniq-admin__links {
	display: flex;
	gap: 16px;
	margin-top: 12px;
}

.humaniq-admin__links a {
	color: var(--color-primary-element);
	text-decoration: underline;
}

.humaniq-admin__note {
	margin-top: 12px;
	color: var(--color-text-maxcontrast);
}
</style>
