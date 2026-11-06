import { beforeEach, describe, expect, it } from 'vitest';
import { relationTargetParts, relationTargetText, relationTargetsText } from '@/presentation/relationTargets';
import type { RelationTargetJson } from '@/application/SubjectSummaryLookup';
import { setupMwMock } from '../VueTestHelpers.ts';

function target( overrides: Partial<RelationTargetJson> ): RelationTargetJson {
	return {
		id: 's1targetaaaaaa1',
		displayName: null,
		schema: 'City',
		pageTitle: 'Vienna',
		isMain: false,
		dependent: false,
		...overrides,
	};
}

describe( 'relationTargetText', () => {

	it( 'is the target\'s label', () => {
		expect( relationTargetText( target( { displayName: 'Vienna' } ) ) ).toBe( 'Vienna' );
	} );

	it( 'is the page name for a Main Subject nobody named', () => {
		expect( relationTargetText( target( { isMain: true, pageTitle: 'Vienna' } ) ) ).toBe( 'Vienna' );
	} );

	it( 'is the Schema name for any other unnamed target', () => {
		expect( relationTargetText( target( { schema: 'City' } ) ) ).toBe( 'City' );
	} );

} );

describe( 'relationTargetParts', () => {

	beforeEach( () => {
		setupMwMock( { functions: [ 'config', 'msg', 'message', 'language', 'util' ], config: { wgNeoWikiSubjectFirst: false } } );
	} );

	it( 'links each standalone target to its Subject, named after its label', () => {
		const parts = relationTargetParts( [ target( { displayName: 'Vienna' } ) ] );

		expect( parts ).toHaveLength( 1 );
		expect( parts[ 0 ].text ).toBe( 'Vienna' );
		expect( parts[ 0 ].url ).toContain( 'Vienna' );
		expect( parts[ 0 ].url ).toContain( 's1targetaaaaaa1' );
	} );

	it( 'counts Dependent Subjects per Schema instead of naming them', () => {
		setupMwMock( {
			functions: [ 'config', 'msg', 'message', 'language', 'util' ],
			messages: { 'neowiki-relation-dependent-count': ( count: string, schema: string ) => `${ count } × ${ schema }` },
		} );

		const parts = relationTargetParts( [
			target( { dependent: true, schema: 'Besucherzahlen', id: 's1dep1aaaaaaa1', pageTitle: 'Host page' } ),
			target( { dependent: true, schema: 'Besucherzahlen', id: 's2dep2aaaaaaa2', pageTitle: 'Host page' } ),
			target( { dependent: true, schema: 'Birth', id: 's3dep3aaaaaaa3', pageTitle: 'Host page' } ),
			target( { displayName: 'Vienna' } ),
		] );

		expect( parts.map( ( part ) => part.text ) ).toEqual( [ 'Vienna', '2 × Besucherzahlen', '1 × Birth' ] );
		expect( parts[ 1 ].url ).toBeUndefined();
	} );

	it( 'reads as one line of text for a CSV column', () => {
		setupMwMock( {
			functions: [ 'config', 'msg', 'message', 'language', 'util' ],
			messages: { 'neowiki-relation-dependent-count': ( count: string, schema: string ) => `${ count } × ${ schema }` },
		} );

		expect( relationTargetsText( [
			target( { displayName: 'Vienna' } ),
			target( { displayName: 'Prague', id: 's2targetaaaaaa2' } ),
		] ) ).toBe( 'Vienna, Prague' );
	} );

} );
