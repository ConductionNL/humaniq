<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 OrgChartWidget (people-org-chart-view D4, REQ-OCV-001).

 The organisation chart of the caller's administration on a date, from
 GET /api/org/chart, in two views of the same payload: an expandable list
 (CnTreeView, keyboard and screen reader friendly) and a drawn chart
 (CnRelationshipGraph, manual layout from the x and y the server computes).
 Choosing a unit shows its manager and headcount, and its people when asked;
 its name links to OrgUnitDetail. No logic beyond passing the payload on.
-->
<template>
	<div class="org-chart">
		<div class="org-chart__controls">
			<NcSelect v-model="root"
				:options="rootOptions"
				label="label"
				:inputLabel="t('humaniq', 'Start from unit')"
				:placeholder="t('humaniq', 'The whole organisation')"
				@update:modelValue="load" />
			<NcTextField v-model="date"
				:label="t('humaniq', 'On date')"
				type="date"
				@change="load" />
			<NcCheckboxRadioSwitch v-model="withPeople" type="switch" @update:modelValue="load">
				{{ t('humaniq', 'Show people') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="drawn" type="switch">
				{{ t('humaniq', 'Drawn chart') }}
			</NcCheckboxRadioSwitch>
		</div>

		<NcNoteCard v-if="errorMessage" type="error">
			{{ errorMessage }}
		</NcNoteCard>
		<NcLoadingIcon v-else-if="loading" />

		<template v-else>
			<CnTreeView v-if="!drawn"
				v-model:expandedIds="expanded"
				:nodes="tree"
				:selectedId="selected ? selected.id : null"
				:emptyLabel="t('humaniq', 'No units on this date')"
				:expandLabel="t('humaniq', 'Expand')"
				:collapseLabel="t('humaniq', 'Collapse')"
				@select="select" />
			<CnRelationshipGraph v-else
				:nodes="graphNodes"
				:edges="edges"
				layout="manual"
				:size="graphSize"
				:ariaLabel="t('humaniq', 'Organisation chart')"
				@nodeClick="selectById" />

			<section v-if="selected" class="org-chart__unit" :aria-label="selected.label">
				<h3>
					<router-link :to="{ name: 'OrgUnitDetail', params: { id: selected.id } }">
						{{ selected.label }}
					</router-link>
				</h3>
				<p>{{ t('humaniq', 'Manager: {name}', { name: selected.managerName || t('humaniq', 'none') }) }}</p>
				<p>{{ t('humaniq', 'People placed: {count}', { count: selected.headcount }) }}</p>
				<ul v-if="selected.people && selected.people.length > 0">
					<li v-for="person in selected.people" :key="person.id">
						{{ person.name }}<span v-if="person.role">, {{ person.role }}</span>
					</li>
				</ul>
			</section>
		</template>
	</div>
</template>

<script>
import { CnRelationshipGraph, CnTreeView } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcCheckboxRadioSwitch, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'

export default {
	name: 'OrgChartWidget',

	components: {
		CnRelationshipGraph,
		CnTreeView,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	data() {
		return {
			root: null,
			rootOptions: [],
			date: new Date().toISOString().slice(0, 10),
			withPeople: false,
			drawn: false,
			tree: [],
			nodes: [],
			edges: [],
			width: 1,
			depth: 1,
			expanded: [],
			selected: null,
			loading: false,
			errorMessage: '',
		}
	},

	computed: {
		/**
		 * The drawn chart's square size, growing with the widest level.
		 *
		 * @return {number}
		 * @spec openspec/specs/org-chart-view/spec.md#REQ-OCV-001
		 */
		graphSize() {
			return Math.max(400, Math.max(this.width, this.depth) * 120)
		},

		/**
		 * The nodes placed in the chart's square: x from the leaf position,
		 * y from the depth, each centred in its cell.
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/org-chart-view/spec.md#REQ-OCV-001
		 */
		graphNodes() {
			const size = this.graphSize
			return this.nodes.map((node) => ({
				id: node.id,
				label: node.label,
				isRoot: node.y === 0,
				x: ((node.x + 0.5) / Math.max(1, this.width)) * size,
				y: ((node.y + 0.5) / Math.max(1, this.depth)) * size,
			}))
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the chart for the chosen root, date and people switch.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/org-chart-view/spec.md#REQ-OCV-001
		 */
		async load() {
			this.loading = true
			this.errorMessage = ''
			try {
				const params = { date: this.date, withPeople: this.withPeople ? 'true' : 'false' }
				if (this.root) {
					params.rootId = this.root.id
				}
				const { data } = await axios.get(generateUrl('/apps/humaniq/api/org/chart'), { params })
				this.tree = data.tree || []
				this.nodes = data.nodes || []
				this.edges = data.edges || []
				this.width = data.width || 1
				this.depth = data.depth || 1
				this.expanded = this.nodes.filter((node) => node.y < 2).map((node) => node.id)
				if (!this.root) {
					this.rootOptions = this.nodes.map((node) => ({ id: node.id, label: node.label }))
						.sort((a, b) => a.label.localeCompare(b.label))
				}
				this.selected = this.selected ? this.find(this.selected.id) : null
			} catch {
				this.errorMessage = this.t('humaniq', 'The organisation chart could not be read.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Show a unit chosen in the list.
		 *
		 * @param {object} node The tree node.
		 * @spec openspec/specs/org-chart-view/spec.md#REQ-OCV-001
		 */
		select(node) {
			this.selected = node
		},

		/**
		 * Show a unit clicked in the drawn chart.
		 *
		 * @param {object} node The graph node.
		 * @spec openspec/specs/org-chart-view/spec.md#REQ-OCV-001
		 */
		selectById(node) {
			this.selected = this.find(node.id)
		},

		/**
		 * Find a unit in the tree by id.
		 *
		 * @param {string} id The unit id.
		 * @return {object|null}
		 * @spec openspec/specs/org-chart-view/spec.md#REQ-OCV-001
		 */
		find(id) {
			const walk = (list) => {
				for (const node of list) {
					if (node.id === id) {
						return node
					}
					const hit = walk(node.children || [])
					if (hit) {
						return hit
					}
				}
				return null
			}
			return walk(this.tree)
		},
	},
}
</script>

<style scoped lang="scss">
.org-chart {
	padding: 8px 0;

	&__controls {
		display: flex;
		flex-wrap: wrap;
		align-items: flex-end;
		gap: 12px;
		margin-bottom: 16px;
	}

	&__unit {
		margin-top: 16px;
		padding-top: 12px;
		border-top: 1px solid var(--color-border);
	}
}
</style>
