import type {
	SubjectSummary,
	SubjectSummaryLookup,
	SubjectSummaryQuery,
} from '@/application/SubjectSummaryLookup';
import type { Schema } from '@/domain/Schema';
import { PropertyName } from '@/domain/PropertyDefinition';
import { Neo } from '@/Neo';
import { valueText } from '@/presentation/valueText';
import { relationTargetsText } from '@/presentation/relationTargets';
import { propertyColumnLabel, summaryName } from '@/presentation/subjectSummaryTable';
import { isSubjectFirst } from '@/wikiMode';

/** The page size the listing endpoint allows at most. */
const EXPORT_PAGE_LIMIT = 50;

/**
 * How many matching Subjects one download walks at most, so a listing of a huge Schema cannot hang the
 * browser.
 */
export const MAX_EXPORT_SUBJECTS = 10000;

export interface ExportQuery {
	schema: string | null;
	search: string;
	sort: SubjectSummaryQuery['sort'];
	direction: SubjectSummaryQuery['direction'];
	/** The properties shown as columns; with a schema, also the ones the search matches. */
	properties: string[];
}

export interface SubjectsExport {
	subjects: SubjectSummary[];
	/** True when the download stopped at the cap while more Subjects matched. */
	truncated: boolean;
}

/**
 * Every Subject the query matches, walked page by page through the REST listing, so a download holds what
 * the table's search narrowed the listing down to, not only the page shown.
 */
export async function collectSubjectsForExport(
	lookup: Pick<SubjectSummaryLookup, 'getSubjectSummaries'>,
	query: ExportQuery,
	maxSubjects: number = MAX_EXPORT_SUBJECTS,
): Promise<SubjectsExport> {
	const subjects: SubjectSummary[] = [];
	let cursor: string | null = null;

	do {
		const page = await lookup.getSubjectSummaries( { ...query, cursor, limit: EXPORT_PAGE_LIMIT } );
		subjects.push( ...page.subjects );
		cursor = page.nextCursor;
	} while ( cursor !== null && subjects.length < maxSubjects );

	return { subjects: subjects.slice( 0, maxSubjects ), truncated: cursor !== null };
}

/**
 * The JSON download: the listing the REST endpoint returns, all pages of it in one array, with what narrowed
 * it recorded beside it. A relation Statement also gets the text it reads as — its targets' names, Dependent
 * Subjects counted per Schema — so the file is readable without re-resolving the targets.
 */
export function subjectsToJson(
	schema: string | null,
	search: string,
	subjects: readonly SubjectSummary[],
): string {
	return JSON.stringify(
		{
			schema,
			search,
			subjects: subjects.map( ( subject ) => ( {
				...subject,
				statements: subject.statements === undefined ? undefined : Object.fromEntries(
					Object.entries( subject.statements ).map( ( [ property, statement ] ) => [
						property,
						statement.propertyType === 'relation' && statement.targets !== undefined ?
							{ ...statement, text: relationTargetsText( statement.targets ) } :
							statement,
					] ),
				),
			} ) ),
		},
		null,
		2,
	);
}

/**
 * The CSV download: one line per Subject, with the columns the table shows — Subject, Page unless the wiki is
 * subject-first, Edited, then one column per chosen property.
 */
export function subjectsToCsv(
	schema: Schema | undefined,
	subjects: readonly SubjectSummary[],
	propertyNames: readonly string[],
): string {
	const showPage = !isSubjectFirst();
	const headers = [
		mw.msg( 'neowiki-subjects-column-subject' ),
		...( showPage ? [ mw.msg( 'neowiki-subjects-column-page' ) ] : [] ),
		mw.msg( 'neowiki-subjects-column-edited' ),
		...( schema === undefined ? [] : propertyNames.map( ( name ) => propertyColumnLabel( schema, name ) ) ),
	];

	const lines = [ headers.map( csvCell ).join( ',' ) ];

	for ( const summary of subjects ) {
		lines.push( [
			summaryName( summary ),
			...( showPage ? [ summary.pageTitle ] : [] ),
			summary.lastEdited,
			...( schema === undefined ? [] : propertyNames.map( ( name ) => propertyCellText( schema, summary, name ) ) ),
		].map( csvCell ).join( ',' ) );
	}

	return lines.join( '\n' ) + '\n';
}

/** RFC 4180: a cell with a quote, comma or line break is quoted, and its quotes are doubled. */
function csvCell( text: string ): string {
	return /[",\r\n]/u.test( text ) ? `"${ text.replace( /"/g, '""' ) }"` : text;
}

/**
 * What a property's Statement reads as in a CSV cell: the text its Property Type shows, for a relation its
 * targets' names (counted per Schema when they are Dependent Subjects), and nothing when the Subject has no
 * Statement of the property or it does not deserialize.
 */
function propertyCellText( schema: Schema, summary: SubjectSummary, propertyName: string ): string {
	const json = summary.statements?.[ propertyName ];

	if ( json === undefined ) {
		return '';
	}

	if ( json.propertyType === 'relation' ) {
		return json.targets === undefined ? '' : relationTargetsText( json.targets );
	}

	let statement;

	try {
		statement = Neo.getInstance().getStatementDeserializer().deserialize( propertyName, json );
	} catch {
		return '';
	}

	if ( statement.value === undefined ) {
		return '';
	}

	const name = new PropertyName( propertyName, true );

	if ( !schema.getPropertyDefinitions().has( name ) ) {
		return '';
	}

	return valueText( schema.getPropertyDefinition( name ), statement.value ) ?? '';
}

export function exportFileName( schemaName: string, extension: 'json' | 'csv' ): string {
	const server = String( mw.config.get( 'wgServerName' ) );
	const safeSchema = schemaName.replace( /[^\w.-]+/gu, '_' );

	return `${ server }-subjects-${ safeSchema }.${ extension }`;
}
