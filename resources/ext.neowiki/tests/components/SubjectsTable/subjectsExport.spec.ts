import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
	collectSubjectsForExport,
	exportFileName,
	subjectsToCsv,
	subjectsToJson,
} from '@/components/SubjectsTable/subjectsExport';
import type { SubjectSummaries, SubjectSummary } from '@/application/SubjectSummaryLookup';
import { Schema } from '@/domain/Schema';
import { PropertyDefinitionList } from '@/domain/PropertyDefinitionList';
import { PropertyName } from '@/domain/PropertyDefinition';
import type { PropertyDefinition } from '@/domain/PropertyDefinition';
import { setupMwMock } from '../../VueTestHelpers.ts';

function summary( id: string, overrides: Partial<SubjectSummary> = {} ): SubjectSummary {
	return {
		id, displayName: `Name ${ id }`, displayNameIsGenerated: false, hostSubject: null, schema: 'Computer',
		pageId: 1, pageTitle: 'Some page', lastEdited: '2026-10-01T14:02:00Z', ...overrides,
	};
}

function definition( name: string, type = 'text' ): PropertyDefinition {
	return { name: new PropertyName( name ), type, description: '', required: false };
}

function schemaWith( ...properties: PropertyDefinition[] ): Schema {
	return new Schema( 'Computer', '', new PropertyDefinitionList( properties ), false );
}

const QUERY = {
	schema: 'Computer',
	search: 'zx',
	sort: 'newest' as const,
	direction: 'desc' as const,
	properties: [ 'Age' ],
};

describe( 'collectSubjectsForExport', () => {

	it( 'walks every page of the REST listing with the query it is given', async () => {
		const getSubjectSummaries = vi.fn()
			.mockResolvedValueOnce( { subjects: [ summary( 's1a' ) ], nextCursor: 'page-2' } as SubjectSummaries )
			.mockResolvedValueOnce( { subjects: [ summary( 's1b' ) ], nextCursor: null } as SubjectSummaries );
		const lookup = { getSubjectSummaries };

		const result = await collectSubjectsForExport( lookup, QUERY );

		expect( result ).toEqual( { subjects: [ summary( 's1a' ), summary( 's1b' ) ], truncated: false } );
		expect( getSubjectSummaries.mock.calls.map( ( call ) => call[ 0 ] ) ).toEqual( [
			{ ...QUERY, cursor: null, limit: 50 },
			{ ...QUERY, cursor: 'page-2', limit: 50 },
		] );
	} );

	it( 'stops at the cap and says more Subjects matched', async () => {
		const page: SubjectSummaries = {
			subjects: Array.from( { length: 2 }, ( _v, i ) => summary( `s${ i }` ) ),
			nextCursor: 'more',
		};
		const lookup = { getSubjectSummaries: vi.fn().mockResolvedValue( page ) };

		const result = await collectSubjectsForExport( lookup, QUERY, 3 );

		expect( result.subjects ).toHaveLength( 3 );
		expect( result.truncated ).toBe( true );
	} );

} );

describe( 'subjectsToJson', () => {

	it( 'holds the listing the REST endpoint returns, with what narrowed it, and a relation\'s readable text', () => {
		setupMwMock( {
			functions: [ 'config', 'msg', 'message', 'language', 'util' ],
			config: { wgServerName: 'wiki.example', wgNeoWikiSubjectFirst: false },
			messages: { 'neowiki-relation-dependent-count': ( count: string, schema: string ) => `${ count } × ${ schema }` },
		} );

		const withRelation = summary( 's1a', {
			statements: {
				City: {
					propertyType: 'relation',
					value: [ { id: 'r11111111111111', target: 's1targetaaaaaa1' } ],
					targets: [ {
						id: 's1targetaaaaaa1', displayName: 'Vienna', schema: 'City',
						pageTitle: 'Vienna', isMain: true, dependent: false,
					} ],
				},
			},
		} );

		const json = JSON.parse( subjectsToJson( 'Computer', 'zx', [ withRelation ] ) );

		expect( json.schema ).toBe( 'Computer' );
		expect( json.search ).toBe( 'zx' );
		expect( json.subjects[ 0 ].id ).toBe( 's1a' );
		expect( json.subjects[ 0 ].statements.City.text ).toBe( 'Vienna' );
		expect( json.subjects[ 0 ].statements.City.targets ).toHaveLength( 1 );
	} );

} );

describe( 'subjectsToCsv', () => {

	beforeEach( () => {
		setupMwMock( {
			functions: [ 'config', 'msg', 'message', 'language', 'util' ],
			config: { wgServerName: 'wiki.example', wgNeoWikiSubjectFirst: false },
		} );
	} );

	it( 'has the table\'s columns and one line per Subject', () => {
		const csv = subjectsToCsv(
			schemaWith( definition( 'Age', 'number' ) ),
			[ summary( 's1a', { statements: { Age: { propertyType: 'number', value: 42 } } } ) ],
			[ 'Age' ],
		);

		expect( csv ).toBe(
			'neowiki-subjects-column-subject,neowiki-subjects-column-page,neowiki-subjects-column-edited,Age\n' +
			'Name s1a,Some page,2026-10-01T14:02:00Z,42\n',
		);
	} );

	it( 'leaves the Page column out on a subject-first wiki', () => {
		setupMwMock( {
			functions: [ 'config', 'msg', 'message', 'language', 'util' ],
			config: { wgServerName: 'wiki.example', wgNeoWikiSubjectFirst: true },
		} );

		const csv = subjectsToCsv( schemaWith(), [ summary( 's1a' ) ], [] );

		expect( csv ).toBe(
			'neowiki-subjects-column-subject,neowiki-subjects-column-edited\n' +
			'Name s1a,2026-10-01T14:02:00Z\n',
		);
	} );

	it( 'quotes cells with commas, quotes and line breaks, doubling the quotes', () => {
		const csv = subjectsToCsv(
			schemaWith( definition( 'Notes' ) ),
			[ summary( 's1a', { statements: { Notes: { propertyType: 'text', value: [ 'a "b", c\nd' ] } } } ) ],
			[ 'Notes' ],
		);

		expect( csv ).toContain( '"a ""b"", c\nd"' );
	} );

	it( 'writes a relation as its targets\' names and a missing Statement as an empty cell', () => {
		const csv = subjectsToCsv(
			schemaWith( definition( 'City', 'relation' ), definition( 'Notes' ) ),
			[ summary( 's1a', {
				statements: {
					City: {
						propertyType: 'relation',
						value: [ { id: 'r11111111111111', target: 's1targetaaaaaa1' } ],
						targets: [ {
							id: 's1targetaaaaaa1', displayName: 'Vienna', schema: 'City',
							pageTitle: 'Vienna', isMain: true, dependent: false,
						} ],
					},
				},
			} ) ],
			[ 'City', 'Notes' ],
		);

		expect( csv ).toContain( 'Vienna' );
		expect( csv.trim().split( '\n' )[ 1 ].endsWith( ',' ) ).toBe( true );
	} );

} );

describe( 'exportFileName', () => {

	it( 'names the wiki, the subjects and the Schema, and keeps the name file-safe', () => {
		setupMwMock( { config: { wgServerName: 'wiki.example' } } );

		expect( exportFileName( 'Museum of Modern Art', 'json' ) ).toBe( 'wiki.example-subjects-Museum_of_Modern_Art.json' );
		expect( exportFileName( 'Computer', 'csv' ) ).toBe( 'wiki.example-subjects-Computer.csv' );
	} );

} );
