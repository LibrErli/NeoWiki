<template>
	<span class="ext-neowiki-subject-property-cell">
		<!-- A relation names its targets, resolved by the listing; a Dependent Subject counts per Schema. -->
		<template v-for="( part, index ) in relationParts">
			<span
				v-if="index > 0"
				:key="`sep-${ index }`"
			>, </span>
			<a
				v-if="part.url !== undefined"
				:key="`link-${ index }`"
				:href="part.url"
			>{{ part.text }}</a
			>
			<span
				v-else
				:key="`text-${ index }`"
			>{{ part.text }}</span>
		</template>
		<component
			:is="cell.component"
			v-if="relationParts === null && cell !== null"
			:value="cell.value"
			:property="cell.property"
		/>
		<span
			v-else-if="relationParts === null"
			class="ext-neowiki-subject-property-cell__empty"
		>-</span>
	</span>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import type { Component } from 'vue';
import type { Schema } from '@/domain/Schema.ts';
import { PropertyName } from '@/domain/PropertyDefinition';
import type { PropertyDefinition } from '@/domain/PropertyDefinition';
import type { Value } from '@/domain/Value';
import type { SubjectSummary } from '@/application/SubjectSummaryLookup.ts';
import { NeoWikiServices } from '@/NeoWikiServices.ts';
import { Neo } from '@/Neo.ts';
import { relationTargetParts, type RelationTargetPart } from '@/presentation/relationTargets.ts';

const props = defineProps<{
	/** The Schema whose property the column shows; undefined on listings that show no property columns. */
	schema?: Schema;
	/** The property whose Statement this cell shows, by name. */
	propertyName: string;
	summary: SubjectSummary;
}>();

interface PropertyCell {
	component: Component;
	value: Value;
	property: PropertyDefinition;
}

const statementJson = computed( () => props.summary.statements?.[ props.propertyName ] );

/**
 * A relation's targets as the listing resolved them. Null for every other property, and for a relation the
 * listing did not resolve, which falls back to the value display.
 */
const relationParts = computed<RelationTargetPart[] | null>( () => {
	const json = statementJson.value;

	if ( json === undefined || json.propertyType !== 'relation' || json.targets === undefined ) {
		return null;
	}

	return relationTargetParts( json.targets );
} );

/**
 * What to render for a non-relation property: the Statement the query asked for, under the property as the
 * Schema defines it now. Null when the Subject has no Statement of the property, the value does not
 * deserialize, or the Schema no longer has the property, as after a rename: a column shows what the Subject
 * has, not an error for what it lacks.
 */
const cell = computed<PropertyCell | null>( () => {
	const json = statementJson.value;

	if ( json === undefined || props.schema === undefined ) {
		return null;
	}

	let statement;

	try {
		statement = Neo.getInstance().getStatementDeserializer().deserialize( props.propertyName, json );
	} catch {
		return null;
	}

	const name = new PropertyName( props.propertyName, true );

	if ( statement.value === undefined || !props.schema.getPropertyDefinitions().has( name ) ) {
		return null;
	}

	return {
		component: NeoWikiServices.getComponentRegistry().getValueDisplayComponent( statement.propertyType ),
		value: statement.value,
		property: props.schema.getPropertyDefinition( name )
	};
} );
</script>

<style lang="less">
@import ( reference ) '@wikimedia/codex-design-tokens/theme-wikimedia-ui.less';

.ext-neowiki-subject-property-cell__empty {
	color: @color-subtle;
	user-select: none;
}
</style>
