<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\NeoWiki\Tests\Domain\Schema;

use PHPUnit\Framework\TestCase;
use ProfessionalWiki\NeoWiki\Domain\Schema\PropertyCore;
use ProfessionalWiki\NeoWiki\Domain\Schema\PropertyDefinitions;
use ProfessionalWiki\NeoWiki\Domain\Schema\PropertyName;
use ProfessionalWiki\NeoWiki\Domain\Schema\Schema;
use ProfessionalWiki\NeoWiki\Domain\Schema\SchemaName;
use ProfessionalWiki\NeoWiki\Domain\Schema\Property\TextProperty;
use ProfessionalWiki\NeoWiki\Domain\Statement;
use ProfessionalWiki\NeoWiki\Domain\Subject\StatementList;
use ProfessionalWiki\NeoWiki\Domain\Value\StringValue;

/**
 * Tests that property ids provide a stable identity across renames (issue #1802).
 *
 * @covers \ProfessionalWiki\NeoWiki\Domain\Schema\PropertyDefinitions
 * @covers \ProfessionalWiki\NeoWiki\Domain\Subject\StatementList
 */
class PropertyIdTest extends TestCase {

	private function makeTextProperty( ?string $id ): TextProperty {
		return new TextProperty( new PropertyCore(
			description: '',
			required: false,
			default: null,
			id: $id,
		) );
	}

	private function makeSchema( array $properties ): Schema {
		return new Schema(
			name: new SchemaName( 'Test' ),
			description: '',
			properties: new PropertyDefinitions( $properties ),
			subjectsAreDependent: false,
		);
	}

	public function testGenerateIdProducesUniqueIds(): void {
		$ids = [];
		for ( $i = 0; $i < 100; $i++ ) {
			$id = PropertyCore::generateId();
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $id );
			$ids[$id] = true;
		}
		$this->assertCount( 100, $ids, 'All generated ids should be unique' );
	}

	public function testPropertyDefinitionsResolvesIdToName(): void {
		$properties = new PropertyDefinitions( [
			'Attendance figure' => $this->makeTextProperty( 'abc123' ),
		] );

		$this->assertTrue( $properties->hasPropertyWithId( 'abc123' ) );
		$this->assertSame( 'Attendance figure', $properties->resolveName( 'abc123' ) );
		$this->assertNotNull( $properties->getPropertyById( 'abc123' ) );
	}

	public function testSchemaResolvesPropertyKey(): void {
		$schema = $this->makeSchema( [
			'Old name' => $this->makeTextProperty( 'id-001' ),
		] );

		// After a rename, the id still resolves to the new name
		$renamedSchema = $this->makeSchema( [
			'New name' => $this->makeTextProperty( 'id-001' ),
		] );

		$this->assertSame( 'New name', $renamedSchema->resolvePropertyKey( 'id-001' ) );
		$this->assertTrue( $renamedSchema->hasPropertyWithId( 'id-001' ) );
		$this->assertNotNull( $renamedSchema->getPropertyById( 'id-001' ) );
	}

	public function testStatementListFindsStatementById(): void {
		$statement = new Statement(
			property: new PropertyName( 'Old name' ),
			propertyType: 'text',
			value: new StringValue( 'test' ),
			propertyId: 'id-001',
		);

		$list = new StatementList( [ $statement ] );

		$this->assertTrue( $list->hasStatementWithId( 'id-001' ) );
		$this->assertSame( $statement, $list->getStatementById( 'id-001' ) );
		$this->assertSame( 'Old name', $list->resolveKey( 'id-001' ) );
	}

	public function testStatementListResolvesIdAfterRename(): void {
		// Statement stored under old name with id
		$statement = new Statement(
			property: new PropertyName( 'Old name' ),
			propertyType: 'text',
			value: new StringValue( 'test' ),
			propertyId: 'id-001',
		);

		$list = new StatementList( [ $statement ] );

		// Schema now has the property under a new name, same id
		$schema = $this->makeSchema( [
			'New name' => $this->makeTextProperty( 'id-001' ),
		] );

		// The id resolves to the new name
		$currentName = $schema->resolvePropertyKey( 'id-001' );
		$this->assertSame( 'New name', $currentName );

		// And the statement can be found by id regardless of stored name
		$this->assertNotNull( $list->getStatementById( 'id-001' ) );
	}

	public function testStatementWithoutIdFallsBackToName(): void {
		$statement = new Statement(
			property: new PropertyName( 'Name' ),
			propertyType: 'text',
			value: new StringValue( 'test' ),
			// no propertyId
		);

		$list = new StatementList( [ $statement ] );

		$this->assertFalse( $list->hasStatementWithId( 'any-id' ) );
		$this->assertNull( $list->getStatementById( 'any-id' ) );
		$this->assertSame( $statement, $list->getStatement( new PropertyName( 'Name' ) ) );
	}

}
