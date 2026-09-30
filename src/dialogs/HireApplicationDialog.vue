<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  - SPDX-License-Identifier: EUPL-1.2
-->

<template>
	<NcDialog
		:name="t('humaniq', 'Create employee')"
		:open="true"
		size="normal"
		@update:open="onOpen">
		<div class="hq-hire" data-testid="hq-hire">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcLoadingIcon v-if="loading" :name="t('humaniq', 'Loading')" />

			<template v-else-if="employeeId">
				<p role="status" data-testid="hq-hire-done">
					{{ doneText }}
				</p>
			</template>

			<template v-else>
				<p>{{ t('humaniq', 'Check the name and add the start date. The e-mail and phone number come from the application.') }}</p>
				<NcTextField
					v-model="form.startDate"
					type="date"
					:label="t('humaniq', 'Start date')"
					:required="true"
					data-testid="hq-hire-start" />
				<NcTextField
					v-model="form.firstName"
					:label="t('humaniq', 'First name')"
					data-testid="hq-hire-first" />
				<NcTextField
					v-model="form.lastName"
					:label="t('humaniq', 'Last name')"
					:required="true"
					data-testid="hq-hire-last" />
				<NcTextField
					v-model="form.dateOfBirth"
					type="date"
					:label="t('humaniq', 'Date of birth (optional)')"
					data-testid="hq-hire-born" />
				<NcTextField
					v-model="form.bsn"
					:label="t('humaniq', 'BSN (optional)')"
					data-testid="hq-hire-bsn" />

				<section v-if="matches.length > 0" class="hq-hire__matches" data-testid="hq-hire-matches">
					<h3>{{ t('humaniq', 'This person may already have a record') }}</h3>
					<p>{{ t('humaniq', 'Use an earlier record so their history stays in one place. Or create a new record if this is someone else.') }}</p>
					<ul>
						<li v-for="match in matches" :key="match.employeeId" class="hq-hire__match">
							<span>
								<strong>{{ match.name }}</strong>
								<span>{{ matchText(match) }}</span>
							</span>
							<NcButton
								variant="secondary"
								:disabled="saving"
								:data-testid="`hq-hire-attach-${match.employeeId}`"
								@click="hire({ attachToEmployeeId: match.employeeId })">
								{{ t('humaniq', 'Use this record') }}
							</NcButton>
						</li>
					</ul>
				</section>
			</template>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="close">
				{{ employeeId ? t('humaniq', 'Close') : t('humaniq', 'Cancel') }}
			</NcButton>
			<NcButton
				v-if="employeeId"
				variant="primary"
				data-testid="hq-hire-open"
				@click="openEmployee">
				{{ t('humaniq', 'Open employee') }}
			</NcButton>
			<NcButton
				v-else
				variant="primary"
				:disabled="!canHire"
				data-testid="hq-hire-confirm"
				@click="hire(matches.length > 0 ? { createNew: true } : {})">
				{{ matches.length > 0 ? t('humaniq', 'Create a new record') : t('humaniq', 'Create employee') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'

/**
 * HireApplicationDialog: turn a hired application into an employee.
 *
 * Opened from the Create employee action on a hired application. It proposes
 * the name from the application, asks for the start date, and shows earlier
 * records the person may have (same BSN, same last name and date of birth,
 * same private e-mail). HR attaches the hire to one of them or creates a new
 * record on purpose. The server answers 409 with the matches until HR chooses.
 *
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
 */
export default {
	name: 'HireApplicationDialog',

	components: { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcTextField },

	props: {
		/** The application; empty on the application page (read from the route). */
		applicationId: { type: String, default: '' },
	},

	emits: ['close'],

	data() {
		return {
			loading: true,
			saving: false,
			error: '',
			employeeId: '',
			outcome: '',
			matches: [],
			form: { startDate: '', firstName: '', lastName: '', dateOfBirth: '', bsn: '' },
		}
	},

	computed: {
		/**
		 * @return {string} The application this dialog hires.
		 *
		 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
		 */
		targetId() {
			return this.applicationId !== '' ? this.applicationId : String(this.$route?.params?.id ?? '')
		},

		/**
		 * @return {boolean} Whether the form can be sent.
		 *
		 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
		 */
		canHire() {
			return !this.saving && this.form.startDate !== '' && this.form.lastName.trim() !== ''
		},

		/**
		 * @return {string} What happened.
		 *
		 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
		 */
		doneText() {
			if (this.outcome === 'attached') {
				return t('humaniq', 'The hire is on the earlier record, with a new onboarding case.')
			}
			if (this.outcome === 'created') {
				return t('humaniq', 'The employee and the onboarding case are created.')
			}
			return t('humaniq', 'This application already has an employee.')
		},
	},

	/**
	 * Load the proposal, the linked employee and the first matches.
	 *
	 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
	 */
	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * @return {string} The application's hire endpoint base.
		 *
		 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
		 */
		base() {
			return `/apps/humaniq/api/applications/${encodeURIComponent(this.targetId)}`
		},

		/**
		 * Read the proposed name, a linked employee and matches on the e-mail.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
		 */
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl(`${this.base()}/hire-matches`))
				this.employeeId = data?.employeeId ?? ''
				this.form.firstName = data?.proposal?.firstName ?? ''
				this.form.lastName = data?.proposal?.lastName ?? ''
				this.matches = Array.isArray(data?.matches) ? data.matches : []
			} catch (e) {
				this.error = e?.response?.data?.message || t('humaniq', 'Could not load this application. Try again.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Send the hire. A 409 with matches shows them; anything else is an error.
		 *
		 * @param {object} choice attachToEmployeeId or createNew.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
		 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
		 */
		async hire(choice) {
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.post(generateUrl(`${this.base()}/hire`), { ...this.form, ...choice })
				this.outcome = data?.outcome ?? ''
				this.employeeId = data?.employeeId ?? ''
			} catch (e) {
				const data = e?.response?.data ?? {}
				if (data.outcome === 'matches') {
					this.matches = data.matches ?? []
				} else {
					this.error = data.message || t('humaniq', 'This did not work. Nothing was changed.')
				}
			} finally {
				this.saving = false
			}
		},

		/**
		 * @param {object} match One earlier record.
		 * @return {string} Why it matched and whether the person left.
		 *
		 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-002
		 */
		matchText(match) {
			const on = {
				bsn: t('humaniq', 'Same BSN'),
				'name-and-birth-date': t('humaniq', 'Same last name and date of birth'),
				'private-email': t('humaniq', 'Same private e-mail'),
			}[match.matchedOn] ?? ''
			const state = match.hasLeft
				? t('humaniq', 'Left on {date}', { date: match.endDate })
				: t('humaniq', 'Still employed')
			return `${on}. ${state}.`
		},

		/**
		 * Go to the employee.
		 *
		 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
		 */
		openEmployee() {
			const id = this.employeeId
			this.close()
			this.$router?.push({ name: 'EmployeeDetail', params: { id } })
		},

		/**
		 * @param {boolean} open The dialog's open state.
		 *
		 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
		 */
		onOpen(open) {
			if (!open) {
				this.close()
			}
		},

		/**
		 * Tell the host to close the dialog.
		 *
		 * @spec openspec/specs/hire-to-employee/spec.md#REQ-HTE-001
		 */
		close() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.hq-hire {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.hq-hire__matches ul {
	margin: 0;
	padding: 0;
	list-style: none;
}

.hq-hire__match {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.hq-hire__match span {
	display: flex;
	flex-direction: column;
}
</style>
