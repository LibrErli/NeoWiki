<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\NeoWiki\Domain\Schema;

use ProfessionalWiki\NeoWiki\Domain\Statement;
use ProfessionalWiki\NeoWiki\Domain\Subject\StatementList;
use ProfessionalWiki\NeoWiki\Domain\Subject\Subject;

/**
 * Aligns a Subject's statements with its Schema: a statement stored under a property's old
 * name (or keyed by an old name) is re-keyed under the name the property has now, using the
 * stable property id. This makes a property rename transparent to readers (issue #1802).
 */
readonly class SchemaSubjectNormalizer {

	/**
	 * Returns the subject with its statements re-keyed to the Schema's current property names.
	 * Statements without a property id, or whose id the Schema does not know, are left as they are.
	 */
	public function normalize( Subject $subject, Schema $schema ): Subject {
		$statements = $subject->getStatements();
		$changed = false;
		$newStatements = [];

		foreach ( $statements->asArray() as $key => $statement ) {
			$propertyId = $statement->getPropertyId();

			if ( $propertyId !== null && $schema->hasPropertyWithId( $propertyId ) ) {
				$currentName = $schema->getPropertyById( $propertyId );
				$currentNameText = $this->propertyNameOf( $currentName, $schema, $propertyId );

				if ( $currentNameText !== null && $currentNameText !== $key ) {
					// Re-key under the current name, keep the id
					$newStatements[$currentNameText] = new Statement(
						new PropertyName( $currentNameText ),
						$statement->getPropertyType(),
						$statement->getValue(),
						$propertyId,
					);
					$changed = true;
					continue;
				}
			}

			$newStatements[$key] = $statement;
		}

		if ( !$changed ) {
			return $subject;
		}

		return $subject->withStatements( new StatementList( array_values( $newStatements ) ) );
	}

	/**
	 * Resolves the current property name for a given id via the Schema's property map.
	 */
	private function propertyNameOf( ?PropertyDefinition $definition, Schema $schema, string $id ): ?string {
		// Find the name this id currently maps to
		foreach ( $schema->getAllProperties()->asMap() as $name => $property ) {
			if ( $property->getId() === $id ) {
				return $name;
			}
		}

		return null;
	}

}
