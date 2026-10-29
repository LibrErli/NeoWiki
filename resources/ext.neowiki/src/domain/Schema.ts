import type { PropertyDefinition } from '@/domain/PropertyDefinition';
import { PropertyName } from '@/domain/PropertyDefinition';
import { PropertyDefinitionList } from '@/domain/PropertyDefinitionList';
import { Statement } from '@/domain/Statement';
import { StatementList } from '@/domain/StatementList';

export type SchemaName = string;

export class Schema {

	public constructor(
		private readonly name: SchemaName,
		private readonly description: string,
		private readonly properties: PropertyDefinitionList,
		private readonly subjectsDependent: boolean,
		private readonly renamedProperties: Record<string, string> = {},
	) {
	}

	public getName(): SchemaName {
		return this.name;
	}

	public getDescription(): string {
		return this.description;
	}

	public getPropertyDefinitions(): PropertyDefinitionList {
		return this.properties;
	}

	public subjectsAreDependent(): boolean {
		return this.subjectsDependent;
	}

	/**
	 * Map of old property name => stable property id, recorded when a property was renamed
	 * so that stored Subject data with the old name still resolves (issue #1802).
	 */
	public getRenamedProperties(): Record<string, string> {
		return this.renamedProperties;
	}

	/**
	 * If the given name is an old name of a renamed property, returns that property's stable id.
	 */
	public propertyIdForOldName( name: string|PropertyName ): string | undefined {
		const text = name instanceof PropertyName ? name.toString() : name;
		return this.renamedProperties[text];
	}

	/**
	 * Resolves an old property name to the current name, via the stable property id.
	 */
	public resolvePropertyName( propertyName: string|PropertyName ): string {
		const text = propertyName instanceof PropertyName ? propertyName.toString() : propertyName;
		const id = this.renamedProperties[text];
		if ( id !== undefined ) {
			const current = this.resolvePropertyId( id );
			if ( current !== undefined ) {
				return current;
			}
		}
		return text;
	}

	/**
	 * Looks up a statement by property name, resolving aliases: a statement stored under an
	 * old property name is found when the Schema declares the property under a new name.
	 */
	public findStatement( statements: StatementList, propertyName: string|PropertyName ): Statement | undefined {
		const resolved = this.resolvePropertyName( propertyName );
		if ( statements.has( new PropertyName( resolved ) ) ) {
			return statements.get( new PropertyName( resolved ) );
		}
		// Fall back to the original name (may be an alias pointing to a removed property)
		const original = propertyName instanceof PropertyName ? propertyName : new PropertyName( propertyName );
		return statements.has( original ) ? statements.get( original ) : undefined;
	}

	/**
	 * Looks up a statement by the stable property id of the given property definition.
	 * This is the rename-safe lookup: the statement is found regardless of the name it was
	 * stored under (issue #1802).
	 *
	 * Lookup order:
	 * 1. By property id (if the statement carries it)
	 * 2. By current property name
	 * 3. By any old name recorded in renamedProperties for this property's id
	 */
	public findStatementByPropertyId( statements: StatementList, property: PropertyDefinition ): Statement | undefined {
		// 1. By stable id
		if ( property.id !== undefined && statements.hasId( property.id ) ) {
			return statements.getById( property.id );
		}
		// 2. By current name
		if ( statements.has( property.name ) ) {
			return statements.get( property.name );
		}
		// 3. By old names recorded for this property's id
		if ( property.id !== undefined ) {
			for ( const [ oldName, id ] of Object.entries( this.renamedProperties ) ) {
				if ( id === property.id && statements.has( new PropertyName( oldName ) ) ) {
					return statements.get( new PropertyName( oldName ) );
				}
			}
		}
		return undefined;
	}

	/**
	 * Resolves a property id to the current property name, if the Schema knows this id.
	 */
	public resolvePropertyId( id: string ): string | undefined {
		for ( const property of this.properties ) {
			if ( property.id === id ) {
				return property.name.toString();
			}
		}
		return undefined;
	}

	public getPropertyById( id: string ): PropertyDefinition | undefined {
		return [ ...this.properties ].find( ( p ) => p.id === id );
	}

	public getPropertyDefinition( propertyName: string|PropertyName ): PropertyDefinition {
		return this.properties.get(
			propertyName instanceof PropertyName ? propertyName : new PropertyName( propertyName ),
		);
	}

	public withName( name: SchemaName ): Schema {
		return new Schema( name, this.description, this.properties, this.subjectsDependent, this.renamedProperties );
	}

	public withDescription( description: string ): Schema {
		return new Schema( this.name, description, this.properties, this.subjectsDependent, this.renamedProperties );
	}

	public withSubjectsAreDependent( subjectsAreDependent: boolean ): Schema {
		return new Schema( this.name, this.description, this.properties, subjectsAreDependent, this.renamedProperties );
	}

	public withAddedPropertyDefinition( property: PropertyDefinition ): Schema {
		return new Schema(
			this.name,
			this.description,
			new PropertyDefinitionList( [ ...this.properties, property ] ),
			this.subjectsDependent,
			this.renamedProperties,
		);
	}

	public withReorderedPropertyDefinitions( names: PropertyName[] ): Schema {
		return new Schema(
			this.name,
			this.description,
			this.properties.reordered( names ),
			this.subjectsDependent,
			this.renamedProperties,
		);
	}

	public withRemovedPropertyDefinition( propertyName: PropertyName ): Schema {
		// When a property is removed, drop rename entries that point to it
		const property = this.getPropertyById( this.properties.get( propertyName )?.id ?? '' );
		const renamed = { ...this.renamedProperties };
		if ( property?.id !== undefined ) {
			for ( const [ oldName, id ] of Object.entries( renamed ) ) {
				if ( id === property.id ) {
					delete renamed[oldName];
				}
			}
		}

		return new Schema(
			this.name,
			this.description,
			this.properties.withoutNames( [ propertyName ] ),
			this.subjectsDependent,
			renamed,
		);
	}

	public statementsFrom( existing: StatementList ): StatementList {
		return new StatementList(
			[ ...this.properties ].map( ( definition ) => {
				// 1. By stable property id (rename-safe)
				if ( definition.id !== undefined ) {
					const byId = existing.getById( definition.id );
					if ( byId !== undefined ) {
						// Re-key under the current name, keep the id
						return new Statement( definition.name, byId.propertyType, byId.value, definition.id );
					}
				}
				// 2. By current name
				if ( existing.has( definition.name ) ) {
					return existing.get( definition.name );
				}
				// 3. By old name via renamedProperties (old name => id)
				for ( const [ oldName, id ] of Object.entries( this.renamedProperties ) ) {
					if ( id === definition.id && existing.has( new PropertyName( oldName ) ) ) {
						const old = existing.get( new PropertyName( oldName ) );
						return new Statement( definition.name, old.propertyType, old.value, id );
					}
				}
				return new Statement( definition.name, definition.type, undefined, definition.id );
			} ),
		);
	}

	public blankStatements(): StatementList {
		return this.statementsFrom( new StatementList( [] ) );
	}

	/**
	 * The edited statements, then those of `existing` whose property the Schema does not declare, which a form
	 * built from statementsFrom() leaves out.
	 */
	public statementsAfterEdit( existing: StatementList, edited: StatementList ): StatementList {
		return new StatementList( [
			...edited,
			...[ ...existing ].filter( ( statement ) => !this.properties.has( statement.propertyName ) ),
		] );
	}

}
