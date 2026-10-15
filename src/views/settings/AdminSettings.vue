<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 The humaniq admin page, /settings/admin/humaniq (board HuInstellingen).

 An app component, not manifest config, because the admin page lives outside
 the app: Nextcloud renders it on its own settings page, where no CnAppRoot or
 manifest runs. The frame is the library's CnAdminSettingsShell in the board
 look; this file only fills its sections.

 @spec openspec/specs/humaniq-roles-and-field-access/spec.md#REQ-RFA-001
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
			</div>
		</CnAdminSettingsShell>
	</div>
</template>

<script>
import { CnAdminSettingsShell, CnSettingsSection } from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { defineComponent } from 'vue'

export default defineComponent({
	name: 'AdminSettings',

	components: {
		CnAdminSettingsShell,
		CnSettingsSection,
	},

	data() {
		return {
			roleGroups: loadState('humaniq', 'roleGroups', {}),
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
			return [
				{
					id: 'hr',
					label: t('humaniq', 'HR'),
					group: this.groupId('hr', 'humaniq-hr'),
					may: t('humaniq', 'employees, contracts, leave, absence, GDPR requests'),
					members: this.memberCount('hr'),
				},
				{
					id: 'payroll',
					label: t('humaniq', 'Payroll administration'),
					group: this.groupId('payroll', 'humaniq-payroll'),
					may: t('humaniq', 'calculate and approve payroll runs, tax returns, payments'),
					members: this.memberCount('payroll'),
				},
				{
					id: 'manager',
					label: t('humaniq', 'Manager'),
					group: t('humaniq', 'from the org chart'),
					may: t('humaniq', 'own team: approve leave, hours and reviews'),
					members: '',
				},
				{
					id: 'employee',
					label: t('humaniq', 'Employee'),
					group: t('humaniq', 'everyone'),
					may: t('humaniq', 'own details, payslips, leave and expense claims'),
					members: '',
				},
			]
		},
	},

	methods: {
		t,

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
</style>
