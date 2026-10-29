import { Schema } from '@/domain/Schema';
import { PropertyDefinitionList } from '@/domain/PropertyDefinitionList';
import { generatePropertyId, type PropertyDefinition } from '@/domain/PropertyDefinition';
import { applySeverities } from '@/domain/SeverityNormalizer';
import { valueToJson } from '@/domain/Value';

export class SchemaSerializer {

	public serializeSchema( schema: Schema ): string {
		return JSON.stringify( this.schemaToJson( schema ), null, 4 );
	}

	public schemaToJson( schema: Schema ): Record<string, unknown> {
		const result: Record<string, unknown> = {
			description: schema.getDescription(),
			...( schema.subjectsAreDependent() ? { subjectsAreDependent: true } : {} ),
			propertyDefinitions: this.serializePropertyDefinitions( schema.getPropertyDefinitions() ),
		};

		const renamed = schema.getRenamedProperties();
		if ( Object.keys( renamed ).length > 0 ) {
			result.renamedProperties = renamed;
		}

		return result;
	}

	private serializePropertyDefinitions( propertyDefinitions: PropertyDefinitionList ): Record<string, any> {
		return Object.fromEntries(
			[ ...propertyDefinitions ].map(
				( property ) => [ property.name.toString(), this.serializePropertyDefinition( property ) ],
			),
		);
	}

	private serializePropertyDefinition( property: PropertyDefinition ): any {
		const { name, constraintSeverities, ...propertyWithoutName } = property;
		return applySeverities(
			{
				...propertyWithoutName,
				// Ensure a property always has a stable id when saved: generate one if missing
				// (e.g. Schemas saved before property ids existed)
				id: property.id ?? generatePropertyId(),
				default: property.default !== undefined ? valueToJson( property.default ) : undefined,
			},
			constraintSeverities ?? {},
		);
	}
}
