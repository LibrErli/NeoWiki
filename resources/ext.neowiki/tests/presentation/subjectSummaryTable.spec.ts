import { describe, expect, it } from 'vitest';
import {
	PROPERTY_COLUMN_PREFIX,
	propertyColumnLabel,
	propertyColumns,
} from '@/presentation/subjectSummaryTable';
import { Schema } from '@/domain/Schema';
import { PropertyDefinitionList } from '@/domain/PropertyDefinitionList';
import { PropertyName } from '@/domain/PropertyDefinition';
import type { PropertyDefinition } from '@/domain/PropertyDefinition';
import { setupMwMock } from '../VueTestHelpers.ts';

function definition( name: string, labels?: Record<string, string> ): PropertyDefinition {
	return {
		name: new PropertyName( name ),
		type: 'text',
		description: '',
		labels,
		required: false,
	};
}

function schemaWith( ...properties: PropertyDefinition[] ): Schema {
	return new Schema( 'Person', '', new PropertyDefinitionList( properties ), false );
}

describe( 'propertyColumns', () => {

	it( 'gives one unsortable column per chosen property, after the fixed ones', () => {
		const schema = schemaWith( definition( 'Age' ), definition( 'Website' ) );

		expect( propertyColumns( schema, [ 'Age', 'Website' ] ) ).toEqual( [
			{ id: PROPERTY_COLUMN_PREFIX + 'Age', property: 'Age', label: 'Age', allowSort: false },
			{ id: PROPERTY_COLUMN_PREFIX + 'Website', property: 'Website', label: 'Website', allowSort: false },
		] );
	} );

	it( 'gives no columns without a Schema, as on every-Schema listings', () => {
		expect( propertyColumns( undefined, [ 'Age' ] ) ).toEqual( [] );
	} );

	it( 'names a column after the property when the Schema lacks it, as after a rename', () => {
		expect( propertyColumns( schemaWith( definition( 'Age' ) ), [ 'Nickname' ] ) ).toEqual( [
			{ id: PROPERTY_COLUMN_PREFIX + 'Nickname', property: 'Nickname', label: 'Nickname', allowSort: false },
		] );
	} );

} );

describe( 'propertyColumnLabel', () => {

	it( 'uses the property name when it has no label', () => {
		expect( propertyColumnLabel( schemaWith( definition( 'Age' ) ), 'Age' ) ).toBe( 'Age' );
	} );

	it( 'shows the label with the name beside it when they differ', () => {
		setupMwMock( { config: { wgUserLanguage: 'en', wgContentLanguage: 'en' } } );

		const schema = schemaWith( definition( 'Age', { en: 'Years' } ) );

		expect( propertyColumnLabel( schema, 'Age' ) ).toBe( 'Years (Age)' );
	} );

} );
