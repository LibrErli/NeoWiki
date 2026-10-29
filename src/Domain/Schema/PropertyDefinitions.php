<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\NeoWiki\Domain\Schema;

use OutOfBoundsException;
use ProfessionalWiki\NeoWiki\Domain\PropertyType\Types\RelationType;

class PropertyDefinitions {

	/**
	 * @var array<string, PropertyDefinition>
	 */
	private array $properties = [];

	/**
	 * @var array<string, string> Property id => property name
	 */
	private array $idsToNames = [];

	/**
	 * @param array<string, PropertyDefinition> $properties Property definitions indexed by name
	 */
	public function __construct( array $properties ) {
		$this->properties = $properties;

		foreach ( $properties as $name => $property ) {
			$id = $property->getId();
			if ( $id !== null ) {
				$this->idsToNames[$id] = $name;
			}
		}
	}

	/**
	 * @throws OutOfBoundsException
	 */
	public function getProperty( string|PropertyName $name ): PropertyDefinition {
		if ( array_key_exists( (string)$name, $this->properties ) ) {
			return $this->properties[(string)$name];
		}

		throw new OutOfBoundsException( "Property '$name' does not exist" );
	}

	public function hasProperty( string|PropertyName $name ): bool {
		return array_key_exists( (string)$name, $this->properties );
	}

	/**
	 * Looks up a property by its stable id. Returns null when no property has this id
	 * (e.g. the Schema was saved before property ids existed).
	 */
	public function getPropertyById( string $id ): ?PropertyDefinition {
		$name = $this->idsToNames[$id] ?? null;

		if ( $name === null ) {
			return null;
		}

		return $this->properties[$name] ?? null;
	}

	public function hasPropertyWithId( string $id ): bool {
		return isset( $this->idsToNames[$id] );
	}

	/**
	 * Resolves a name or id to the property name it currently has.
	 * If $key is an id, returns the current name. Otherwise returns $key unchanged.
	 */
	public function resolveName( string $key ): string {
		return $this->idsToNames[$key] ?? $key;
	}

	/**
	 * @param callable(PropertyDefinition):bool $filter
	 */
	public function filter( callable $filter ): self {
		return new self( array_filter( $this->properties, $filter ) );
	}

	public function getRelations(): self {
		return $this->filter(
			fn( PropertyDefinition $property ) => $property->getPropertyType() === RelationType::NAME
		);
	}

	/**
	 * @return array<string, PropertyDefinition>
	 */
	public function asMap(): array {
		return $this->properties;
	}

	/**
	 * @return array<string, string> Property id => property name
	 */
	public function getIdMap(): array {
		return $this->idsToNames;
	}

}
