<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
 SurveyQuestionsWidget (talent-engagement-surveys D1, REQ-SRV-001).

 HR builds a survey's questions with the library's CnFormBuilder. The list is
 stored as Survey.questions; employees answer it from their invitation. The
 questions can change only while the survey is a draft, so every answer to an
 open survey belongs to the same questions.
-->
<template>
	<ObjectQuestionsEditor :objectId="objectId"
		objectSchema="Survey"
		:types="types"
		:description="t('humaniq', 'Employees answer these anonymously. A recommend question (0 to 10) gives the eNPS.')"
		:readError="t('humaniq', 'The questions of this survey could not be read.')"
		draftOnly
		:lockedNote="t('humaniq', 'The questions of an open or closed survey cannot change, so every answer belongs to the same questions.')" />
</template>

<script>
import ObjectQuestionsEditor from './ObjectQuestionsEditor.vue'

export default {
	name: 'SurveyQuestionsWidget',

	components: { ObjectQuestionsEditor },

	props: {
		/** The survey's id, handed in by CnDetailWidgetHost's detail context. */
		objectId: { type: [String, Number], default: '' },
	},

	computed: {
		/**
		 * The kinds of question a survey can ask.
		 *
		 * @return {Array<{type: string, label: string}>}
		 * @spec openspec/changes/talent-engagement-surveys/specs/engagement-surveys/spec.md#REQ-SRV-001
		 */
		types() {
			return [
				{ type: 'scale', label: this.t('humaniq', 'Scale from 1 to 5') },
				{ type: 'enum', label: this.t('humaniq', 'Choice') },
				{ type: 'textarea', label: this.t('humaniq', 'Free text') },
				{ type: 'recommend', label: this.t('humaniq', 'Recommend (0 to 10)') },
			]
		},
	},
}
</script>
