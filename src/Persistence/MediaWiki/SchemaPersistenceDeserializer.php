<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\NeoWiki\Persistence\MediaWiki;

use InvalidArgumentException;
use ProfessionalWiki\NeoWiki\Domain\Schema\PropertyDefinition;
use ProfessionalWiki\NeoWiki\Domain\Schema\PropertyDefinitions;
use ProfessionalWiki\NeoWiki\Domain\Schema\Schema;
use ProfessionalWiki\NeoWiki\Domain\Schema\SchemaName;
use ProfessionalWiki\NeoWiki\Domain\PropertyType\PropertyTypeLookup;
use ProfessionalWiki\NeoWiki\Presentation\SchemaPresentationSerializer;

/**
 * MediaWiki revision SPECIFIC deserializer. Not for general use such as in the presentation layer.
 *
 * Related @see SchemaPresentationSerializer
 */
class SchemaPersistenceDeserializer {

	public function __construct(
		private readonly PropertyTypeLookup $propertyTypeLookup,
	) {
	}

	/**
	 * @throws InvalidArgumentException
	 */
	public function deserialize( SchemaName $schemaName, string $json ): Schema {
		$json = json_decode( $json, true );

		if ( !is_array( $json ) ) {
			throw new InvalidArgumentException( 'Invalid JSON' );
		}

		$renamed = [];
		if ( isset( $json['renamedProperties'] ) && is_array( $json['renamedProperties'] ) ) {
			foreach ( $json['renamedProperties'] as $oldName => $propertyId ) {
				if ( is_string( $oldName ) && is_string( $propertyId ) ) {
					$renamed[$oldName] = $propertyId;
				}
			}
		}

		return new Schema(
			name: $schemaName,
			description: $json['description'] ?? '',
			properties: $this->propertiesFromJson( $json ),
			subjectsAreDependent: ( $json['subjectsAreDependent'] ?? false ) === true,
			renamedProperties: $renamed,
		);
	}

	/**
	 * Properties of an unregistered type are not dropped here: PropertyDefinition::fromJson
	 * preserves them. Only structurally invalid definitions are skipped.
	 */
	private function propertiesFromJson( array $json ): PropertyDefinitions {
		$properties = [];

		foreach ( $json['propertyDefinitions'] ?? [] as $propertyName => $property ) {
			if ( is_string( $propertyName ) ) {
				try {
					$properties[$propertyName] = PropertyDefinition::fromJson( $property, $this->propertyTypeLookup );
				}
				catch ( InvalidArgumentException ) {
					// TODO: log error
				}
			}
			else {
				// TODO: log error
			}
		}

		return new PropertyDefinitions( $properties );
	}

}
