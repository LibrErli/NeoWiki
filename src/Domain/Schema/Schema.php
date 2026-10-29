<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\NeoWiki\Domain\Schema;

use OutOfBoundsException;

readonly class Schema {

	public function __construct(
		private SchemaName $name,
		private string $description,
		private PropertyDefinitions $properties,
		private bool $subjectsAreDependent,
		private array $renamedProperties = [],
	) {
	}

	public function getName(): SchemaName {
		return $this->name;
	}

	public function getDescription(): string {
		return $this->description;
	}

	/**
	 * @throws OutOfBoundsException
	 */
	public function getProperty( string|PropertyName $name ): PropertyDefinition {
		return $this->properties->getProperty( $name );
	}

	public function hasProperty( string|PropertyName $name ): bool {
		return $this->properties->hasProperty( $name );
	}

	/**
	 * Looks up a property by its stable id. Returns null when no property has this id.
	 */
	public function getPropertyById( string $id ): ?PropertyDefinition {
		return $this->properties->getPropertyById( $id );
	}

	public function hasPropertyWithId( string $id ): bool {
		return $this->properties->hasPropertyWithId( $id );
	}

	/**
	 * Resolves a key that may be a property id or name to the current property name.
	 * Statements stored with a property id resolve to the name the property has now,
	 * so a rename does not orphan them (issue #1802).
	 */
	public function resolvePropertyKey( string $key ): string {
		return $this->properties->resolveName( $key );
	}

	/**
	 * @return array<string, string> Map of old property name => stable property id.
	 * Recorded when a property is renamed, so statements stored under the old name
	 * can still be resolved to the property via its id.
	 */
	public function getRenamedProperties(): array {
		return $this->renamedProperties;
	}

	/**
	 * If $name is an old name of a renamed property, returns the property's stable id.
	 * Otherwise returns null.
	 */
	public function propertyIdForOldName( string $name ): ?string {
		return $this->renamedProperties[$name] ?? null;
	}

	/**
	 * Resolves an old property name to the current property name, via the stable id.
	 * Returns the input unchanged when the name is not a known old name.
	 */
	public function resolveOldPropertyName( string $name ): string {
		$id = $this->renamedProperties[$name] ?? null;

		if ( $id === null ) {
			return $name;
		}

		// Find the current name for this id
		foreach ( $this->properties->asMap() as $currentName => $property ) {
			if ( $property->getId() === $id ) {
				return $currentName;
			}
		}

		return $name;
	}

	/**
	 * Alias for resolveOldPropertyName — resolves a possibly-old property name to the current name.
	 */
	public function resolvePropertyName( string|PropertyName $name ): string {
		return $this->resolveOldPropertyName( (string)$name );
	}

	public function getRelationProperties(): PropertyDefinitions {
		return $this->properties->getRelations();
	}

	public function getAllProperties(): PropertyDefinitions {
		return $this->properties;
	}

	public function subjectsAreDependent(): bool {
		return $this->subjectsAreDependent;
	}

}
