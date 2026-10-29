<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\NeoWiki\Tests\Domain\Schema;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\NeoWiki\Domain\Schema\PropertyCore;
use ProfessionalWiki\NeoWiki\Domain\Schema\PropertyDefinitions;
use ProfessionalWiki\NeoWiki\Domain\Schema\PropertyName;
use ProfessionalWiki\NeoWiki\Domain\Schema\Schema;
use ProfessionalWiki\NeoWiki\Domain\Schema\SchemaName;
use ProfessionalWiki\NeoWiki\Domain\Schema\SchemaPropertyRenames;
use ProfessionalWiki\NeoWiki\Domain\Schema\Property\RelationProperty;
use ProfessionalWiki\NeoWiki\Domain\Schema\Property\TextProperty;
use ProfessionalWiki\NeoWiki\Domain\Schema\SchemaReference;
use ProfessionalWiki\NeoWiki\Domain\Schema\SchemaReferenceParser;

/**
 * @covers \ProfessionalWiki\NeoWiki\Domain\Schema\SchemaPropertyRenames
 */
class SchemaPropertyRenamesTest extends TestCase {

	private function makeSchema( array $properties ): Schema {
		return new Schema(
			name: new SchemaName( 'TestSchema' ),
			description: '',
			properties: new PropertyDefinitions( $properties ),
			subjectsAreDependent: false,
		);
	}

	private function makeTextProperty(): TextProperty {
		return new TextProperty( new PropertyCore( description: '', required: false, default: null ) );
	}

	private function makeRelationProperty(): RelationProperty {
		$parser = new SchemaReferenceParser(
			new class implements \ProfessionalWiki\NeoWiki\Domain\Schema\SchemaReferenceNormalizer {
				public function normalize( SchemaReference $reference ): SchemaReference {
					return $reference;
				}
			}
		);

		return new RelationProperty(
			core: new PropertyCore( description: '', required: false, default: null ),
			targetSchemas: [ SchemaReference::local( new SchemaName( 'Target' ) ) ],
			multiple: false,
		);
	}

	public function testDetectsSimpleRename(): void {
		$oldSchema = $this->makeSchema( [
			'Attendance figure' => $this->makeRelationProperty(),
		] );

		$newSchema = $this->makeSchema( [
			'Annual attendance' => $this->makeRelationProperty(),
		] );

		$renames = SchemaPropertyRenames::detect( $oldSchema, $newSchema );

		$this->assertSame( [ 'Attendance figure' => 'Annual attendance' ], $renames );
	}

	public function testNoRenameWhenNothingChanged(): void {
		$oldSchema = $this->makeSchema( [
			'Name' => $this->makeTextProperty(),
		] );

		$newSchema = $this->makeSchema( [
			'Name' => $this->makeTextProperty(),
		] );

		$renames = SchemaPropertyRenames::detect( $oldSchema, $newSchema );

		$this->assertSame( [], $renames );
	}

	public function testNoRenameWhenTypeChanges(): void {
		$oldSchema = $this->makeSchema( [
			'Value' => $this->makeTextProperty(),
		] );

		$newSchema = $this->makeSchema( [
			'Value2' => $this->makeRelationProperty(), // Different type
		] );

		$renames = SchemaPropertyRenames::detect( $oldSchema, $newSchema );

		$this->assertSame( [], $renames );
	}

	public function testNoRenameWhenMultipleCandidatesWithSameType(): void {
		$oldSchema = $this->makeSchema( [
			'Name' => $this->makeTextProperty(),
		] );

		// Two new text properties, one removed - ambiguous
		$newSchema = $this->makeSchema( [
			'Name2' => $this->makeTextProperty(),
			'Name3' => $this->makeTextProperty(),
		] );

		$renames = SchemaPropertyRenames::detect( $oldSchema, $newSchema );

		// Should not detect a rename because there are two candidates
		$this->assertSame( [], $renames );
	}

	public function testDetectsMultipleRenames(): void {
		$oldSchema = $this->makeSchema( [
			'Old text' => $this->makeTextProperty(),
			'Old relation' => $this->makeRelationProperty(),
		] );

		$newSchema = $this->makeSchema( [
			'New text' => $this->makeTextProperty(),
			'New relation' => $this->makeRelationProperty(),
		] );

		$renames = SchemaPropertyRenames::detect( $oldSchema, $newSchema );

		$this->assertSame( [
			'Old text' => 'New text',
			'Old relation' => 'New relation',
		], $renames );
	}

	public function testKeepsUnchangedProperties(): void {
		$oldSchema = $this->makeSchema( [
			'Keep' => $this->makeTextProperty(),
			'Rename me' => $this->makeRelationProperty(),
		] );

		$newSchema = $this->makeSchema( [
			'Keep' => $this->makeTextProperty(),
			'Renamed' => $this->makeRelationProperty(),
		] );

		$renames = SchemaPropertyRenames::detect( $oldSchema, $newSchema );

		$this->assertSame( [ 'Rename me' => 'Renamed' ], $renames );
	}

}
