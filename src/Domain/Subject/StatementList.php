<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\NeoWiki\Domain\Subject;

use InvalidArgumentException;
use ProfessionalWiki\NeoWiki\Domain\Relation\TypedRelation;
use ProfessionalWiki\NeoWiki\Domain\Relation\TypedRelationList;
use ProfessionalWiki\NeoWiki\Domain\Schema\PropertyName;
use ProfessionalWiki\NeoWiki\Domain\Statement;
use ProfessionalWiki\NeoWiki\Domain\Value\NeoValue;
use ProfessionalWiki\NeoWiki\Domain\Value\RelationValue;
use ProfessionalWiki\NeoWiki\Domain\PropertyType\Types\RelationType;

readonly class StatementList {

	/**
	 * @var array<string, Statement> Key is property name
	 */
	private array $statements;

	/**
	 * @var array<string, string> Property id => property name, for statements that carry an id
	 */
	private array $idsToNames;

	/**
	 * @param Statement[] $statements
	 */
	public function __construct( array $statements = [] ) {
		$statementsByPropertyName = [];
		$idsToNames = [];

		foreach ( $statements as $statement ) {
			if ( !( $statement instanceof Statement ) ) {
				throw new InvalidArgumentException( 'StatementList can only be constructed with Statement objects' );
			}
			$statementsByPropertyName[$statement->getPropertyName()->text] = $statement;

			$propertyId = $statement->getPropertyId();
			if ( $propertyId !== null ) {
				$idsToNames[$propertyId] = $statement->getPropertyName()->text;
			}
		}

		$this->statements = $statementsByPropertyName;
		$this->idsToNames = $idsToNames;
	}

	/**
	 * Looks up a statement by property id, resolving to the name the property currently has.
	 * Returns null when no statement carries this id.
	 */
	public function getStatementById( string $propertyId ): ?Statement {
		$name = $this->idsToNames[$propertyId] ?? null;

		if ( $name === null ) {
			return null;
		}

		return $this->statements[$name] ?? null;
	}

	public function hasStatementWithId( string $propertyId ): bool {
		return isset( $this->idsToNames[$propertyId] );
	}

	public function getTypedRelations(): TypedRelationList {
		/**
		 * @var TypedRelation[] $relations
		 */
		$relations = [];

		foreach ( $this->statements as $statement ) {
			if ( $statement->getPropertyType() !== RelationType::NAME ) {
				continue;
			}

			/**
			 * @var RelationValue $value
			 */
			$value = $statement->getValue();

			foreach ( $value->relations as $relation ) {
				$relations[] = $relation->withPropertyName( $statement->getPropertyName() );
			}
		}

		return new TypedRelationList( $relations );
	}

	public function getReferencedSubjects(): SubjectIdList {
		$ids = [];

		foreach ( $this->getAllValues() as $value ) {
			if ( $value instanceof RelationValue ) {
				foreach ( $value->relations as $relation ) {
					$ids[] = $relation->targetId;
				}
			}
		}

		return new SubjectIdList( $ids );
	}

	/**
	 * @return NeoValue[]
	 */
	private function getAllValues(): array {
		return array_map(
			static function ( Statement $statement ): NeoValue {
				return $statement->getValue();
			},
			$this->statements
		);
	}

	/**
	 * @return array<string, Statement> Keys are property names
	 */
	public function asArray(): array {
		return $this->statements;
	}

	public function getStatement( PropertyName $property ): ?Statement {
		return $this->statements[$property->text] ?? null;
	}

	/**
	 * Resolves a key that may be a property id to the property name used as key in this list.
	 */
	public function resolveKey( string $key ): string {
		return $this->idsToNames[$key] ?? $key;
	}

	/**
	 * Replaces the Statement for the property, keeping its position in the list, or appends it
	 * when the property has none yet.
	 */
	public function withStatement( Statement $statement ): self {
		$statements = $this->statements;
		$statements[$statement->getPropertyName()->text] = $statement;

		return new self( $statements );
	}

	public function withoutStatement( PropertyName $property ): self {
		$statements = $this->statements;
		unset( $statements[$property->text] );

		return new self( $statements );
	}

}
