<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\NeoWiki\Domain;

use ProfessionalWiki\NeoWiki\Domain\Schema\PropertyName;
use ProfessionalWiki\NeoWiki\Domain\Value\NeoValue;

readonly class Statement {

	public function __construct(
		private PropertyName $property,
		private string $propertyType,
		private NeoValue $value,
		private ?string $propertyId = null,
	) {
	}

	public function getPropertyName(): PropertyName {
		return $this->property;
	}

	/**
	 * The stable id of the Schema property, when known. Null for statements written before
	 * property ids existed, or for properties whose Schema has none.
	 */
	public function getPropertyId(): ?string {
		return $this->propertyId;
	}

	public function getPropertyType(): string {
		return $this->propertyType;
	}

	public function getValue(): NeoValue {
		return $this->value;
	}

	/**
	 * Returns a copy with the given property id set.
	 */
	public function withPropertyId( ?string $id ): self {
		return new self( $this->property, $this->propertyType, $this->value, $id );
	}

}
