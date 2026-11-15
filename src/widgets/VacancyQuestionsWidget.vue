<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 VacancyQuestionsWidget (hiring-portal-audiences D3, REQ-PAU-002).

 HR adds their own questions to a vacancy's application form with the
 library's CnFormBuilder. The list is stored as Vacancy.questions and portaliq
 shows it on the public apply form; the answers land on
 job-application.answers. Reads and patches the vacancy through OpenRegister.
-->
<template>
	<ObjectQuestionsEditor :objectId="objectId"
		objectSchema="Vacancy"
		:types="types"
		:description="t('humaniq', 'Candidates answer these on the application form of this vacancy.')"
		:readError="t('humaniq', 'The questions of this vacancy could not be read.')" />
</template>

<script>
import ObjectQuestionsEditor from './ObjectQuestionsEditor.vue'

export default {
	name: 'VacancyQuestionsWidget',

	components: { ObjectQuestionsEditor },

	props: {
		/** The vacancy's id, handed in by CnDetailWidgetHost's detail context. */
		objectId: { type: [String, Number], default: '' },
	},

	computed: {
		/**
		 * The answer types a candidate can be asked for.
		 *
		 * @return {Array<{type: string, label: string}>}
		 * @spec openspec/specs/portal-audiences/spec.md#REQ-PAU-002
		 */
		types() {
			return [
				{ type: 'string', label: this.t('humaniq', 'Short answer') },
				{ type: 'textarea', label: this.t('humaniq', 'Long answer') },
				{ type: 'enum', label: this.t('humaniq', 'Choice') },
				{ type: 'boolean', label: this.t('humaniq', 'Yes or no') },
				{ type: 'number', label: this.t('humaniq', 'Number') },
			]
		},
	},
}
</script>
