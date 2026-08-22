<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 Pro-forma payslip simulator (proforma-payslip design.md D6). The manifest v2
 `type: "custom"` host-app SFC for the "Simuleer loonstrook" page: a
 hypothetical-input form that POSTs to `/api/payroll/proforma` and renders the
 full gross-to-net breakdown. Nothing is ever saved through the object store —
 there is no register/schema on this page and no create/save call anywhere in
 this file; the whole point of the pro-forma simulator is that it persists
 nothing (design.md D1).
-->
<template>
	<div class="proforma-payslip">
		<h2>{{ t('humaniq', 'Simulate payslip') }}</h2>
		<p class="proforma-payslip__intro">
			{{ t('humaniq', 'Enter a hypothetical gross salary to see the full net breakdown. Nothing is saved — no employee, contract, payroll run or payslip is created.') }}
		</p>

		<form class="proforma-payslip__form" @submit.prevent="simulate">
			<NcTextField v-model="form.gross"
				:label="t('humaniq', 'Gross monthly salary (EUR)')"
				type="number"
				step="0.01"
				min="0"
				required />

			<div class="proforma-payslip__radio-group">
				<span class="proforma-payslip__radio-label">{{ t('humaniq', 'Tax table') }}</span>
				<NcCheckboxRadioSwitch v-model="form.table"
					value="wit"
					name="table"
					type="radio">
					{{ t('humaniq', 'White table (wit)') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="form.table"
					value="groen"
					name="table"
					type="radio">
					{{ t('humaniq', 'Green table (groen)') }}
				</NcCheckboxRadioSwitch>
			</div>

			<NcCheckboxRadioSwitch v-model="form.loonheffingskorting" type="switch">
				{{ t('humaniq', 'Loonheffingskorting applied') }}
			</NcCheckboxRadioSwitch>

			<NcTextField v-model="form.dateOfBirth"
				:label="t('humaniq', 'Date of birth (optional — unknown is treated as below AOW age)')"
				type="date" />

			<NcTextField v-model="form.parttime"
				:label="t('humaniq', 'Part-time factor')"
				type="number"
				step="0.01"
				min="0.01" />

			<NcTextField v-model="form.bijzonder"
				:label="t('humaniq', 'One-off special payment (EUR, optional)')"
				type="number"
				step="0.01"
				min="0" />

			<NcTextField v-model="form.period"
				:label="t('humaniq', 'Wage period (YYYY-MM, defaults to the current month)')"
				type="text"
				placeholder="2026-02" />

			<!-- @nextcloud/vue 9 renamed NcButton's style prop `type` → `variant`
			     and repurposed `type` as the NATIVE button type (default
			     "button"), dropping `native-type` entirely. Left as
			     `type="primary" native-type="submit"` this button would set an
			     invalid native type and stop submitting the form, with no
			     warning from Vue or from any lint rule. -->
			<NcButton variant="primary" type="submit" :disabled="loading">
				<template #icon>
					<NcLoadingIcon v-if="loading" />
					<Calculator v-else :size="20" />
				</template>
				{{ t('humaniq', 'Calculate') }}
			</NcButton>
		</form>

		<NcNoteCard v-if="errorMessage" type="error" class="proforma-payslip__note">
			{{ errorMessage }}
		</NcNoteCard>

		<div v-if="breakdown" class="proforma-payslip__result">
			<h3>{{ t('humaniq', 'Breakdown') }}</h3>

			<dl class="proforma-payslip__breakdown">
				<dt>{{ t('humaniq', 'Gross pay') }}</dt>
				<dd>{{ euro(breakdown.grossPay) }}</dd>
				<dt>{{ t('humaniq', 'Loonheffing') }}</dt>
				<dd>{{ euro(breakdown.loonheffing) }}</dd>
				<dt>{{ t('humaniq', 'Arbeidskorting') }}</dt>
				<dd>{{ euro(breakdown.arbeidskorting) }}</dd>
				<dt>{{ t('humaniq', 'Volksverzekeringen') }}</dt>
				<dd>{{ euro(breakdown.volksverzekeringen) }}</dd>
				<dt>{{ t('humaniq', 'Zvw') }}</dt>
				<dd>{{ euro(breakdown.zvw) }}</dd>
				<dt>{{ t('humaniq', 'Werknemersverzekeringen') }}</dt>
				<dd>{{ euro(breakdown.werknemersverzekeringen) }}</dd>
				<dt>{{ t('humaniq', 'Employer charges') }}</dt>
				<dd>{{ euro(breakdown.employerCharges) }}</dd>
				<dt>{{ t('humaniq', 'Vakantiegeld reserved') }}</dt>
				<dd>{{ euro(breakdown.vakantiegeldReserved) }}</dd>
				<dt class="proforma-payslip__net-label">
					{{ t('humaniq', 'Net pay') }}
				</dt>
				<dd class="proforma-payslip__net-value">
					{{ euro(breakdown.nettoPay) }}
				</dd>
			</dl>

			<NcNoteCard type="info" class="proforma-payslip__note">
				{{ t('humaniq', 'This is a pro-forma simulation. Nothing was saved: no employee, contract, payroll run or payslip was created.') }}
			</NcNoteCard>

			<NcNoteCard v-if="form.bijzonder && Number(form.bijzonder) > 0" type="warning" class="proforma-payslip__note">
				{{ breakdown.bijzondereBeloningNote }}
			</NcNoteCard>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'
import Calculator from 'vue-material-design-icons/Calculator.vue'

export default {
	name: 'ProformaPayslip',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		Calculator,
	},

	data() {
		return {
			form: {
				gross: '',
				table: 'wit',
				loonheffingskorting: true,
				dateOfBirth: '',
				parttime: '1.0',
				bijzonder: '0',
				period: '',
			},

			loading: false,
			breakdown: null,
			errorMessage: '',
		}
	},

	methods: {
		/**
		 * POST the hypothetical inputs to /api/payroll/proforma and render
		 * the returned breakdown. Nothing here writes to the object store —
		 * this is the only network call in the component, and it targets the
		 * persist-nothing proforma endpoint exclusively.
		 *
		 * @spec openspec/specs/proforma-payslip/spec.md#REQ-PRO-005
		 * @return {Promise<void>}
		 */
		async simulate() {
			this.loading = true
			this.errorMessage = ''
			this.breakdown = null

			try {
				const response = await axios.post(generateUrl('/apps/humaniq/api/payroll/proforma'), {
					gross: this.form.gross,
					table: this.form.table,
					loonheffingskorting: this.form.loonheffingskorting,
					dateOfBirth: this.form.dateOfBirth || null,
					parttime: this.form.parttime,
					bijzonder: this.form.bijzonder,
					period: this.form.period || null,
				})
				this.breakdown = response.data
			} catch (error) {
				this.errorMessage = error?.response?.data?.error
					|| this.t('humaniq', 'The simulation failed. Check the entered values and try again.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Format a euro-decimal number as a Dutch-locale currency string.
		 *
		 * @param {number} value The euro amount.
		 * @return {string}
		 */
		euro(value) {
			return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(value ?? 0)
		},
	},
}
</script>

<style scoped lang="scss">
.proforma-payslip {
	max-width: 640px;
	padding: 20px;

	&__intro {
		color: var(--color-text-maxcontrast);
		margin-bottom: 16px;
	}

	&__form {
		display: flex;
		flex-direction: column;
		gap: 12px;
		margin-bottom: 24px;
	}

	&__radio-group {
		display: flex;
		align-items: center;
		gap: 12px;
	}

	&__radio-label {
		color: var(--color-text-maxcontrast);
		margin-right: 4px;
	}

	&__note {
		margin-top: 12px;
	}

	&__result {
		border-top: 1px solid var(--color-border);
		padding-top: 16px;
	}

	&__breakdown {
		display: grid;
		grid-template-columns: 1fr auto;
		row-gap: 6px;
		column-gap: 16px;

		dt {
			color: var(--color-text-maxcontrast);
		}

		dd {
			margin: 0;
			text-align: right;
			font-variant-numeric: tabular-nums;
		}
	}

	&__net-label,
	&__net-value {
		font-weight: bold;
		font-size: 1.1em;
		border-top: 1px solid var(--color-border);
		padding-top: 6px;
	}
}
</style>
