import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import SubjectPropertyCell from '@/components/SubjectsTable/SubjectPropertyCell.vue';
import type { SubjectSummary } from '@/application/SubjectSummaryLookup';
import { Schema } from '@/domain/Schema';
import { PropertyDefinitionList } from '@/domain/PropertyDefinitionList';
import { PropertyName } from '@/domain/PropertyDefinition';
import { setupMwMock } from '../../VueTestHelpers.ts';

function summary( statements: SubjectSummary['statements'] ): SubjectSummary {
	return {
		id: 's1a', displayName: 'Name s1a', displayNameIsGenerated: false, hostSubject: null, schema: 'Museum',
		pageId: 1, pageTitle: 'Some page', lastEdited: '2026-10-01T14:02:00Z', statements,
	};
}

const schema = new Schema( 'Museum', '', new PropertyDefinitionList( [
	{ name: new PropertyName( 'City' ), type: 'relation', description: '', required: false },
] ), false );

describe( 'SubjectPropertyCell', () => {

	it( 'names a relation\'s target instead of showing its id', () => {
		setupMwMock( { functions: [ 'config', 'msg', 'message', 'language', 'util' ], config: { wgNeoWikiSubjectFirst: false } } );

		const wrapper = mount( SubjectPropertyCell, {
			props: {
				schema,
				propertyName: 'City',
				summary: summary( {
					City: {
						propertyType: 'relation',
						value: [ { id: 'r11111111111111', target: 's1targetaaaaaa1' } ],
						targets: [ {
							id: 's1targetaaaaaa1', displayName: 'Vienna', schema: 'City',
							pageTitle: 'Vienna', isMain: true, dependent: false,
						} ],
					},
				} ),
			},
		} );

		const link = wrapper.find( 'a' );

		expect( link.exists() ).toBe( true );
		expect( link.text() ).toBe( 'Vienna' );
		expect( link.attributes( 'href' ) ).toContain( 'Vienna' );
		expect( link.attributes( 'href' ) ).toContain( 's1targetaaaaaa1' );
	} );

	it( 'counts Dependent Subject targets per Schema', () => {
		setupMwMock( {
			functions: [ 'config', 'msg', 'message', 'language', 'util' ],
			config: { wgNeoWikiSubjectFirst: false },
			messages: { 'neowiki-relation-dependent-count': ( count: string, schema: string ) => `${ count } × ${ schema }` },
		} );

		const wrapper = mount( SubjectPropertyCell, {
			props: {
				schema,
				propertyName: 'City',
				summary: summary( {
					City: {
						propertyType: 'relation',
						value: [ { id: 'r11111111111111', target: 's1dep1aaaaaaa1' } ],
						targets: [
							{
								id: 's1dep1aaaaaaa1', displayName: null, schema: 'Besucherzahlen',
								pageTitle: 'Host page', isMain: false, dependent: true,
							},
							{
								id: 's2dep2aaaaaaa2', displayName: null, schema: 'Besucherzahlen',
								pageTitle: 'Host page', isMain: false, dependent: true,
							},
						],
					},
				} ),
			},
		} );

		expect( wrapper.find( 'a' ).exists() ).toBe( false );
		expect( wrapper.text() ).toBe( '2 × Besucherzahlen' );
	} );

	it( 'shows an empty marker when the Subject has no Statement of the property', () => {
		setupMwMock( { functions: [ 'config', 'msg', 'message', 'language', 'util' ], config: { wgNeoWikiSubjectFirst: false } } );

		const wrapper = mount( SubjectPropertyCell, {
			props: { schema, propertyName: 'City', summary: summary( {} ) },
		} );

		expect( wrapper.text() ).toBe( '-' );
	} );

} );
