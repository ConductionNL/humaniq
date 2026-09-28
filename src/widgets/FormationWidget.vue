<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 FormationWidget (people-formation-positions REQ-FRM-002, REQ-FRM-003).

 Shows the formation of one unit, or of one formation place: per place the
 budgeted, filled, vacant and net FTE, plus the unit total. The figures are
 computed on read by GET /api/formation/occupancy and never stored, which is
 why this is a host widget and not a declarative stat: filled, vacant and net
 FTE cross three schemas and are weighted by absence per day.

 Placed on OrgUnitDetail (objectType OrgUnit: the unit's places) and on
 FormatieplaatsDetail (objectType Formatieplaats: that one place).
-->
<template>
	<CnWidgetWrapper :title="title || t('humaniq', 'Formation')" :widgetId="widgetId">
		<div class="formation-widget">
			<NcLoadingIcon v-if="loading" />
			<NcNoteCard v-else-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcEmptyContent
				v-else-if="places.length === 0"
				:name="t('humaniq', 'No formation places yet')"
				:description="t('humaniq', 'Add a formation place to this unit to see its budget and occupancy.')" />
			<table v-else class="formation-widget__table">
				<caption class="hidden-visually">
					{{ t('humaniq', 'Formation in FTE, averaged over the working days from {from} to {to}', { from: period.from, to: period.to }) }}
				</caption>
				<thead>
					<tr>
						<th scope="col">
							{{ t('humaniq', 'Formation place') }}
						</th>
						<th scope="col">
							{{ t('humaniq', 'Budgeted') }}
						</th>
						<th scope="col">
							{{ t('humaniq', 'Filled') }}
						</th>
						<th scope="col">
							{{ t('humaniq', 'Vacant') }}
						</th>
						<th scope="col">
							{{ t('humaniq', 'Net') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="place in places" :key="place.id">
						<th scope="row">
							{{ place.title }}
							<span v-if="place.overfilled" class="formation-widget__overfilled">
								{{ t('humaniq', 'Overfilled') }}
							</span>
						</th>
						<td>{{ format(place.budgetedFte) }}</td>
						<td>{{ format(place.filledFte) }}</td>
						<td>{{ format(place.vacantFte) }}</td>
						<td>{{ format(place.netFte) }}</td>
					</tr>
				</tbody>
				<tfoot v-if="places.length > 1">
					<tr>
						<th scope="row">
							{{ t('humaniq', 'Total') }}
						</th>
						<td>{{ format(total.budgetedFte) }}</td>
						<td>{{ format(total.filledFte) }}</td>
						<td>{{ format(total.vacantFte) }}</td>
						<td>{{ format(total.netFte) }}</td>
					</tr>
				</tfoot>
			</table>
			<p v-if="!loading && !error && places.length > 0" class="formation-widget__note">
				{{ t('humaniq', 'Net leaves out parental leave, pregnancy and childbirth leave, and sickness longer than {weeks} weeks.', { weeks: longTermSickWeeks }) }}
			</p>
		</div>
	</CnWidgetWrapper>
</template>

<script>
import { CnWidgetWrapper } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'FormationWidget',

	components: { CnWidgetWrapper, NcEmptyContent, NcLoadingIcon, NcNoteCard },

	props: {
		/** Widget title from the manifest. */
		title: { type: String, default: '' },
		/** Widget id, merged in by CnWidgetGrid. */
		widgetId: { type: String, default: '' },
		/** The current record's id, merged in by CnWidgetGrid's detail context. */
		objectId: { type: [String, Number], default: '' },
		/** The current record's schema slug, merged in by CnWidgetGrid's detail context. */
		objectType: { type: String, default: '' },
	},

	data() {
		return {
			loading: true,
			error: '',
			places: [],
			total: {},
			period: { from: '', to: '' },
			longTermSickWeeks: 6,
		}
	},

	watch: {
		objectId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the occupancy of this unit or this place for today.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-002
		 */
		async load() {
			if (!this.objectId) {
				return
			}
			this.loading = true
			this.error = ''
			const key = String(this.objectType).toLowerCase() === 'formatieplaats' ? 'formatieplaatsId' : 'orgUnitId'
			try {
				const response = await axios.get(generateUrl('/apps/humaniq/api/formation/occupancy'), {
					params: { [key]: this.objectId },
				})
				this.places = response.data.places || []
				this.total = response.data.total || {}
				this.period = { from: response.data.from, to: response.data.to }
				this.longTermSickWeeks = response.data.longTermSickWeeks ?? 6
			} catch {
				this.error = this.t('humaniq', 'Could not load the formation. Try again.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * One FTE figure with two decimals in the reader's locale.
		 *
		 * @param {number} value The figure.
		 * @return {string}
		 *
		 * @spec openspec/specs/formation-positions/spec.md#REQ-FRM-002
		 */
		format(value) {
			return Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 2 })
		},
	},
}
</script>

<style scoped>
.formation-widget__table {
	width: 100%;
	border-collapse: collapse;
}

.formation-widget__table th,
.formation-widget__table td {
	padding: calc(var(--default-grid-baseline) * 2);
	text-align: end;
	border-bottom: 1px solid var(--color-border);
}

.formation-widget__table th[scope='row'],
.formation-widget__table thead th:first-child {
	text-align: start;
}

.formation-widget__overfilled {
	margin-inline-start: calc(var(--default-grid-baseline) * 2);
	color: var(--color-warning-text);
	font-weight: normal;
}

.formation-widget__note {
	margin-top: calc(var(--default-grid-baseline) * 2);
	color: var(--color-text-maxcontrast);
}
</style>
