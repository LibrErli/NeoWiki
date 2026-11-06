<template>
	<div
		class="ext-neowiki-subjects-table"
		:class="{ 'ext-neowiki-subjects-table--hidden-caption': fixedSchema === undefined }"
	>
		<CdxTable
			ref="table"
			v-model:sort="sort"
			:columns="columns"
			:data="rows"
			:caption="$i18n( 'neowiki-special-subjects' ).text()"
			:hide-caption="fixedSchema === undefined"
			:pending="loading"
			:paginate="true"
			:server-pagination="true"
			:total-rows="totalRows"
			:pagination-size-default="PAGE_SIZES[ 0 ].value"
			:pagination-size-options="PAGE_SIZES"
			@load-more="fetchPage"
		>
			<template #header>
				<div class="ext-neowiki-subjects-table__controls">
					<CdxSelect
						v-if="fixedSchema === undefined"
						v-model:selected="schemaChoice"
						:menu-items="schemaMenuItems"
						:aria-label="$i18n( 'neowiki-subjects-schema-label' ).text()"
					/>
					<CdxMultiselectLookup
						v-if="schema !== undefined"
						v-model:input-value="propertyInput"
						class="ext-neowiki-subjects-table__columns"
						:input-chips="propertyChips"
						:selected="selectedProperties"
						:menu-items="propertyMenuItems"
						:placeholder="$i18n( 'neowiki-subjects-columns-placeholder' ).text()"
						:aria-label="$i18n( 'neowiki-subjects-columns-label' ).text()"
						@update:selected="onPropertiesSelected"
						@update:input-chips="onPropertyChips"
					/>
					<CdxSearchInput
						v-model="searchText"
						class="ext-neowiki-subjects-table__search"
						:placeholder="searchPlaceholder"
						:aria-label="searchPlaceholder"
					/>
					<CdxMenuButton
						v-if="schema !== undefined"
						v-model:selected="downloadSelection"
						class="ext-neowiki-subjects-table__download"
						:menu-items="downloadMenuItems"
						:disabled="exporting"
						:aria-label="$i18n( 'neowiki-subjects-download' ).text()"
						:title="$i18n( 'neowiki-subjects-download' ).text()"
						@update:selected="download"
					>
						<CdxIcon :icon="cdxIconDownload" />
						{{ $i18n( 'neowiki-subjects-download' ).text() }}
					</CdxMenuButton>
					<CdxButton
						v-if="fixedSchema === undefined && canCreate && createsWithoutHost"
						class="ext-neowiki-subjects-table__create"
						@click="emit( 'create', selectedSchema ?? undefined )"
					>
						<CdxIcon :icon="cdxIconAdd" />
						{{ createLabel }}
					</CdxButton>
				</div>
			</template>

			<template #item-name="{ row }">
				<SubjectSummaryCell
					column="name"
					:summary="row"
				/>
			</template>

			<template #item-schema="{ row }">
				<SubjectSummaryCell
					column="schema"
					:summary="row"
				/>
			</template>

			<template #item-page="{ row }">
				<SubjectSummaryCell
					column="page"
					:summary="row"
				/>
			</template>

			<template #item-edited="{ row }">
				<SubjectSummaryCell
					column="edited"
					:summary="row"
				/>
			</template>

			<!-- One slot per property column, named after it. The name is dynamic, so vue-tsc types the slot
				props as the component instance; the compiler does render one slot per column. The key belongs on
				the template: a slot has no element of its own to key. -->
			<!-- eslint-disable vue/no-v-for-template-key -->
			<template
				v-for="column in propertyColumnList"
				:key="column.id"
				#[`item-${column.id}`]="{ row }"
			>
				<!-- @vue-ignore -->
				<SubjectPropertyCell
					:schema="schema"
					:property-name="column.property"
					:summary="row"
				/>
			</template>
			<!-- eslint-enable vue/no-v-for-template-key -->

			<!-- Codex shows the empty state whenever the table has no rows, so also while the first ones load. -->
			<template
				v-if="!loading"
				#empty-state
			>
				{{ emptyText }}
			</template>
		</CdxTable>
	</div>
</template>

<script setup lang="ts">
import { computed, onMounted, onScopeDispose, ref, watch } from 'vue';
import {
	CdxButton,
	CdxIcon,
	CdxMenuButton,
	CdxMultiselectLookup,
	CdxSearchInput,
	CdxSelect,
	CdxTable
} from '@wikimedia/codex';
import type { ChipInputItem, MenuButtonItemData, MenuItemData, TableSort } from '@wikimedia/codex';
import { cdxIconAdd, cdxIconDownload } from '@wikimedia/codex-icons';
import SubjectSummaryCell from './SubjectSummaryCell.vue';
import SubjectPropertyCell from './SubjectPropertyCell.vue';
import type { Schema } from '@/domain/Schema.ts';
import { NeoWikiServices } from '@/NeoWikiServices.ts';
import { useCursorPagination } from '@/composables/useCursorPagination.ts';
import { useSchemaStore } from '@/stores/SchemaStore.ts';
import { isSubjectFirst } from '@/wikiMode.ts';
import type { SubjectSummary } from '@/application/SubjectSummaryLookup.ts';
import type { SchemaSummary } from '@/application/SchemaLookup.ts';
import { propertyDisplayName } from '@/presentation/propertyDisplayName.ts';
import { downloadFile } from '@/presentation/downloadFile.ts';
import {
	collectSubjectsForExport,
	exportFileName,
	MAX_EXPORT_SUBJECTS,
	subjectsToCsv,
	subjectsToJson
} from './subjectsExport.ts';
import {
	emptyStateMessage,
	failureMessage,
	propertyColumnLabel,
	propertyColumns,
	schemaMenuNames,
	subjectColumns,
	summaryOrder
} from '@/presentation/subjectSummaryTable.ts';

const props = withDefaults( defineProps<{
	/** Lists only this Schema's Subjects, with no menu to pick another: a Schema's own page. */
	fixedSchema?: string;
	/** The Schema the menu starts on; null starts on every Schema. */
	initialSchema?: string | null;
	canCreate?: boolean;
	/** The Schema whose properties the reader can add as columns; only a Schema's own page passes it. */
	schema?: Schema;
}>(), {
	fixedSchema: undefined,
	initialSchema: null,
	canCreate: false,
	schema: undefined
} );

const emit = defineEmits<{
	'update:schema': [ schema: string | null ];
	create: [ schema: string | undefined ];
}>();

const SEARCH_DELAY_MS = 300;
const PAGE_SIZES = [ { value: 10 }, { value: 20 }, { value: 50 } ];
// CdxSelect needs a value for the "every Schema" item; no Schema has an empty name.
const ALL_SCHEMAS = '';

const lookup = NeoWikiServices.getSubjectSummaryLookup();
const schemaStore = useSchemaStore();
const pagination = useCursorPagination();

const table = ref<InstanceType<typeof CdxTable> | null>( null );
const rows = ref<SubjectSummary[]>( [] );
const loading = ref( true );
const failureText = ref<string | null>( null );
// Undefined while the end of the listing is unknown, which keeps the pager at "of many" (ADR 27).
const totalRows = ref<number | undefined>( undefined );
const sort = ref<TableSort>( {} );
const selectedSchema = ref<string | null>( props.fixedSchema ?? props.initialSchema );
const searchText = ref( '' );
const appliedSearch = ref( '' );
const selectedProperties = ref<string[]>( [] );
const propertyInput = ref<string | number>( '' );
const schemaSummaries = ref<SchemaSummary[]>( [] );
const schemaNames = computed( (): string[] => schemaSummaries.value.map( ( summary ) => summary.name ) );
let searchTimer: ReturnType<typeof setTimeout> | null = null;
let requestSequence = 0;

const propertyColumnList = computed( () => propertyColumns( props.schema, selectedProperties.value ) );

const columns = computed( () => [
	...subjectColumns( {
		showSchema: selectedSchema.value === null,
		showPage: !isSubjectFirst(),
		sortable: true
	} ),
	...propertyColumnList.value
] );

// Property values are searched only while they are shown as columns, so the placeholder says so only then.
const searchPlaceholder = computed( () => props.fixedSchema === undefined || selectedProperties.value.length === 0 ?
	mw.msg( 'neowiki-subjects-search-placeholder' ) :
	mw.msg( 'neowiki-subjects-search-placeholder-schema' ) );

/** The Schema's properties as menu items: those matching the typed text, and not already a column. */
const propertyMenuItems = computed( (): MenuItemData[] => {
	const schema = props.schema;

	if ( schema === undefined ) {
		return [];
	}

	const query = String( propertyInput.value ).trim().toLowerCase();

	return [ ...schema.getPropertyDefinitions() ]
		.filter( ( property ) => !selectedProperties.value.includes( property.name.toString() ) )
		.filter( ( property ) => {
			if ( query === '' ) {
				return true;
			}

			return propertyDisplayName( property ).toLowerCase().includes( query ) ||
				property.name.toString().toLowerCase().includes( query );
		} )
		.map( ( property ) => ( {
			value: property.name.toString(),
			label: propertyColumnLabel( schema, property.name.toString() )
		} ) );
} );

const propertyChips = computed( (): ChipInputItem[] =>
	selectedProperties.value.map( ( name ) => ( {
		value: name,
		label: props.schema === undefined ? name : propertyColumnLabel( props.schema, name )
	} ) )
);

function onPropertiesSelected( names: readonly string[] ): void {
	if ( names.some( ( name ) => !selectedProperties.value.includes( name ) ) ) {
		propertyInput.value = '';
	}

	// Assigned only when the choice changed: Codex answers a new `selected` with another update, and an
	// unchanged new array would keep the watchers firing.
	if ( names.length !== selectedProperties.value.length ||
		names.some( ( name ) => !selectedProperties.value.includes( name ) ) ) {
		selectedProperties.value = [ ...names ];
	}
}

// A chip the field removes takes its column with it; one it adds of its own accord picks nothing.
function onPropertyChips( items: ChipInputItem[] ): void {
	const values = new Set( items.map( ( chip ) => String( chip.value ) ) );
	const kept = selectedProperties.value.filter( ( name ) => values.has( name ) );

	if ( kept.length !== selectedProperties.value.length ) {
		selectedProperties.value = kept;
	}
}

const exporting = ref( false );
const downloadSelection = ref<string | number | null>( null );

const downloadMenuItems = computed<MenuButtonItemData[]>( () => [
	{ value: 'json', label: mw.msg( 'neowiki-subjects-download-json' ) },
	{ value: 'csv', label: mw.msg( 'neowiki-subjects-download-csv' ) }
] );

/**
 * Downloads every Subject the current search narrows the listing down to — through the REST listing, page by
 * page — as JSON or as CSV with the table's columns. The listing's read checks apply, so a download holds
 * only what its reader may see.
 */
async function download( format: string | number | null ): Promise<void> {
	downloadSelection.value = null;

	if ( format !== 'json' && format !== 'csv' ) {
		return;
	}

	exporting.value = true;

	try {
		const query = {
			schema: selectedSchema.value,
			search: appliedSearch.value,
			...summaryOrder( sort.value ),
			properties: selectedProperties.value
		};
		const { subjects, truncated } = await collectSubjectsForExport( lookup, query );
		const schemaName = selectedSchema.value ?? 'all';

		if ( format === 'json' ) {
			downloadFile(
				exportFileName( schemaName, 'json' ),
				subjectsToJson( selectedSchema.value, appliedSearch.value, subjects ),
				'application/json'
			);
		} else {
			downloadFile(
				exportFileName( schemaName, 'csv' ),
				subjectsToCsv( props.schema, subjects, selectedProperties.value ),
				'text/csv;charset=utf-8'
			);
		}

		if ( truncated ) {
			mw.notify(
				mw.message( 'neowiki-subjects-export-truncated' ).params( [ String( MAX_EXPORT_SUBJECTS ) ] ).text(),
				{ type: 'warn' }
			);
		}
	} catch ( error ) {
		mw.notify(
			error instanceof Error ? error.message : String( error ),
			{ title: mw.msg( 'neowiki-subjects-export-error' ), type: 'error' }
		);
	} finally {
		exporting.value = false;
	}
}

const schemaChoice = computed( {
	get: (): string => selectedSchema.value ?? ALL_SCHEMAS,
	set: ( value: string ): void => {
		selectedSchema.value = value === ALL_SCHEMAS ? null : value;
	}
} );

const schemaMenuItems = computed( (): MenuItemData[] => [
	{ value: ALL_SCHEMAS, label: mw.msg( 'neowiki-subjects-all-schemas' ) },
	...schemaMenuNames( schemaNames.value, selectedSchema.value ).map( ( name ) => ( { value: name, label: name } ) )
] );

const createsWithoutHost = computed( (): boolean => selectedSchema.value === null ||
	schemaSummaries.value.some( ( summary ) => summary.name === selectedSchema.value && !summary.subjectsAreDependent ) );

const createLabel = computed( () => selectedSchema.value === null ?
	mw.msg( 'neowiki-createsubject-button' ) :
	mw.msg( 'neowiki-schema-create-subject', selectedSchema.value ) );

const emptyText = computed( () => failureText.value ?? emptyStateMessage( appliedSearch.value, selectedSchema.value ) );

async function fetchPage( offset: number, limit: number ): Promise<void> {
	// Rows no response led to, as Previous asks for after a page-size change: nothing resumes the listing there.
	if ( !pagination.hasCursorFor( offset ) ) {
		restart();
		return;
	}

	const sequence = ++requestSequence;
	loading.value = true;

	try {
		const result = await lookup.getSubjectSummaries( {
			schema: selectedSchema.value,
			search: appliedSearch.value,
			...summaryOrder( sort.value ),
			cursor: pagination.cursorFor( offset ),
			limit,
			// Only asked for when the reader chose columns: the query stays as it was otherwise.
			...( selectedProperties.value.length > 0 ? { properties: selectedProperties.value } : {} )
		} );

		if ( sequence !== requestSequence ) {
			return;
		}

		rows.value = result.subjects;
		failureText.value = null;
		pagination.recordNextCursor( offset, limit, result.nextCursor );
		totalRows.value = result.nextCursor === null ? offset + result.subjects.length : undefined;
	} catch ( error ) {
		if ( sequence !== requestSequence ) {
			return;
		}

		rows.value = [];
		failureText.value = failureMessage( error, selectedSchema.value );
	}

	loading.value = false;
}

/**
 * Lists from the first page again. CdxTable keeps its page offset to itself, so the table is sent to its first page
 * by its own handler, undocumented but typed, which asks for that page through load-more. Remounting the table would
 * reset it too, but would take the keyboard focus from the search field or sort button the user just used.
 */
function restart(): void {
	pagination.reset();
	totalRows.value = undefined;
	table.value?.onFirst();
}

function clearSearchTimer(): void {
	if ( searchTimer !== null ) {
		clearTimeout( searchTimer );
		searchTimer = null;
	}
}

watch( searchText, ( text ) => {
	clearSearchTimer();
	searchTimer = setTimeout( () => {
		appliedSearch.value = text.trim();
	}, SEARCH_DELAY_MS );
} );

// A sort on the Schema column means nothing once one Schema is chosen, so a new Schema starts unsorted.
watch( selectedSchema, ( schema ) => {
	sort.value = {};
	emit( 'update:schema', schema );
} );

// A column the Schema no longer has, as after a rename, comes down; its rows would show nothing anyway.
watch( () => props.schema, ( schema ) => {
	if ( schema === undefined ) {
		selectedProperties.value = [];
		return;
	}

	const names = new Set( [ ...schema.getPropertyDefinitions() ].map( ( property ) => property.name.toString() ) );
	const kept = selectedProperties.value.filter( ( name ) => names.has( name ) );

	if ( kept.length !== selectedProperties.value.length ) {
		selectedProperties.value = kept;
	}
} );

watch( [ selectedSchema, appliedSearch, sort, selectedProperties ], restart );

onScopeDispose( clearSearchTimer );

onMounted( async () => {
	fetchPage( 0, PAGE_SIZES[ 0 ].value );

	if ( props.fixedSchema !== undefined ) {
		return;
	}

	try {
		schemaSummaries.value = await schemaStore.fetchAllSchemaSummaries();
	} catch ( error ) {
		mw.notify( error instanceof Error ? error.message : String( error ), { type: 'error' } );
	}
} );
</script>

<style lang="less">
@import ( reference ) '@wikimedia/codex-design-tokens/theme-wikimedia-ui.less';

.ext-neowiki-subjects-table {
	// Required to start the controls at the inline-start of the table header
	&--hidden-caption {
		.cdx-table__header__caption {
			display: none;
		}

		.cdx-table__header__content {
			flex-grow: 1;
		}
	}

	&__controls {
		display: flex;
		flex-wrap: wrap;
		gap: @spacing-50;
		align-items: center;
	}

	&__search {
		min-width: 16rem;
	}

	&__columns {
		min-width: 16rem;
	}

	&__download {
		margin-inline-start: auto;
	}

	// Scoped under the controls to outrank `.cdx-button`'s margin, which MediaWiki's Codex loads after this.
	&__controls &__create {
		margin-inline-start: auto;
	}
}
</style>
