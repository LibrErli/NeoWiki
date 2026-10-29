<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\NeoWiki\Domain\Schema;

/**
 * Compares an old and a new Schema to detect property renames.
 *
 * A rename is detected when a property name exists in the old Schema but not in the new one,
 * and exactly one property name exists in the new Schema but not in the old one, and both
 * properties have the same type. This heuristic works for the common case of renaming a
 * single property without changing its type.
 */
readonly class SchemaPropertyRenames {

	/**
	 * @param Schema $oldSchema
	 * @param Schema $newSchema
	 * @return array<string, string> Map of old property name => new property name
	 */
	public static function detect( Schema $oldSchema, Schema $newSchema ): array {
		$oldProperties = $oldSchema->getAllProperties()->asMap();
		$newProperties = $newSchema->getAllProperties()->asMap();

		$removedNames = array_diff( array_keys( $oldProperties ), array_keys( $newProperties ) );
		$addedNames = array_diff( array_keys( $newProperties ), array_keys( $oldProperties ) );

		$renames = [];

		foreach ( $removedNames as $oldName ) {
			$oldType = $oldProperties[$oldName]->getPropertyType();

			// Find added properties with the same type
			$matchingAdded = array_filter(
				$addedNames,
				fn( string $newName ) => $newProperties[$newName]->getPropertyType() === $oldType
			);

			// Only treat as rename when there is exactly one candidate with the same type
			if ( count( $matchingAdded ) === 1 ) {
				$newName = array_values( $matchingAdded )[0];
				$renames[$oldName] = $newName;
				// Remove from added names so it's not matched again
				$addedNames = array_diff( $addedNames, [ $newName ] );
			}
		}

		return $renames;
	}

}
