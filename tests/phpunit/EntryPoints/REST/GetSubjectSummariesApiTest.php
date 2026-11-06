<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\NeoWiki\Tests\EntryPoints\REST;

use MediaWiki\Context\RequestContext;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use ProfessionalWiki\NeoWiki\Application\SubjectSummaries\SubjectSummaries;
use ProfessionalWiki\NeoWiki\Application\SubjectSummaries\SubjectSummaryLookup;
use ProfessionalWiki\NeoWiki\Application\SubjectSummaries\SubjectSummaryQuery;
use ProfessionalWiki\NeoWiki\Application\SubjectSummaries\SubjectSummarySearchTimeoutException;
use ProfessionalWiki\NeoWiki\Domain\Relation\RelationId;
use ProfessionalWiki\NeoWiki\Domain\Schema\SchemaName;
use ProfessionalWiki\NeoWiki\Domain\Subject\StatementList;
use ProfessionalWiki\NeoWiki\Domain\Subject\SubjectMap;
use ProfessionalWiki\NeoWiki\Domain\Value\NumberValue;
use ProfessionalWiki\NeoWiki\EntryPoints\REST\GetSubjectSummariesApi;
use ProfessionalWiki\NeoWiki\NeoWikiExtension;
use ProfessionalWiki\NeoWiki\Tests\Data\TestRelation;
use ProfessionalWiki\NeoWiki\Tests\Data\TestStatement;
use ProfessionalWiki\NeoWiki\Tests\Data\TestSubject;
use ProfessionalWiki\NeoWiki\Tests\NeoWikiIntegrationTestCase;

/**
 * Needs the integration base class for the pages the Subjects are saved on and the database the list is read from.
 *
 * @covers \ProfessionalWiki\NeoWiki\EntryPoints\REST\GetSubjectSummariesApi
 * @group Database
 */
class GetSubjectSummariesApiTest extends NeoWikiIntegrationTestCase {

	use HandlerTestTrait;

	public function setUp(): void {
		$this->createSchema( 'Computer' );
	}

	public function testListsSubjectsWithTheirPages(): void {
		$this->createPageWithSubjects(
			'Sinclair ZX Spectrum',
			TestSubject::build( id: 'sTestGSS1111111', label: 'ZX Spectrum 48K', schemaName: new SchemaName( 'Computer' ) ),
			new SubjectMap()
		);

		$subject = $this->get( [] )['subjects'][0];

		$this->assertSame( 'sTestGSS1111111', $subject['id'] );
		$this->assertSame( 'ZX Spectrum 48K', $subject['displayName'] );
		$this->assertFalse( $subject['displayNameIsGenerated'] );
		$this->assertSame( 'Computer', $subject['schema'] );
		$this->assertSame( 'Sinclair ZX Spectrum', $subject['pageTitle'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $subject['lastEdited'] );
	}

	public function testFollowingTheCursorWalksAllSubjects(): void {
		$this->createSubjectsOnPages( 'sTestGSS1111111', 'sTestGSS1111112', 'sTestGSS1111113' );

		$first = $this->get( [ 'limit' => '2' ] );
		$second = $this->get( [ 'limit' => '2', 'cursor' => $first['nextCursor'] ] );

		$this->assertSame( [ 'sTestGSS1111113', 'sTestGSS1111112' ], array_column( $first['subjects'], 'id' ) );
		$this->assertSame( [ 'sTestGSS1111111' ], array_column( $second['subjects'], 'id' ) );
		$this->assertNull( $second['nextCursor'] );
	}

	public function testListsTheNewestFirstWhateverDirectionIsAsked(): void {
		$this->createSubjectsOnPages( 'sTestGSS1111111', 'sTestGSS1111112', 'sTestGSS1111113' );

		$this->assertSame(
			[ 'sTestGSS1111113', 'sTestGSS1111112', 'sTestGSS1111111' ],
			array_column( $this->get( [ 'sort' => 'newest', 'direction' => 'asc' ] )['subjects'], 'id' )
		);
	}

	/**
	 * @dataProvider nameOrderProvider
	 */
	public function testSortsByNameInTheDirectionAsked( string $direction, array $expectedNames ): void {
		// Newest first, Gamma, Alpha, Beta, is neither name order.
		$this->createSubjectsNamed( [
			'sTestGSS1111111' => 'Beta',
			'sTestGSS1111112' => 'Alpha',
			'sTestGSS1111113' => 'Gamma',
		] );

		$this->assertSame(
			$expectedNames,
			array_column( $this->get( [ 'sort' => 'name', 'direction' => $direction ] )['subjects'], 'displayName' )
		);
	}

	public static function nameOrderProvider(): iterable {
		yield 'ascending' => [ 'asc', [ 'Alpha', 'Beta', 'Gamma' ] ];
		yield 'descending' => [ 'desc', [ 'Gamma', 'Beta', 'Alpha' ] ];
	}

	public function testSearchesForTheTextWithoutItsSurroundingSpaces(): void {
		$this->createSubjectsNamed( [ 'sTestGSS1111111' => 'ZX Spectrum', 'sTestGSS1111112' => 'Commodore 64' ] );

		$this->assertSame(
			[ 'sTestGSS1111111' ],
			array_column( $this->get( [ 'search' => ' spectrum ' ] )['subjects'], 'id' )
		);
	}

	public function testSearchWithinASchemaMatchesAStatementValueOfAChosenProperty(): void {
		$this->createPageWithSubjects(
			'Page one',
			TestSubject::build(
				id: 'sTestGSS1111111',
				label: 'Galaksija',
				schemaName: new SchemaName( 'Computer' ),
				statements: new StatementList( [ TestStatement::build( 'Notes', 'A ZX Spectrum clone', 'text' ) ] )
			),
			new SubjectMap()
		);

		$this->assertSame(
			[ 'sTestGSS1111111' ],
			array_column( $this->get( [ 'schema' => 'Computer', 'search' => 'SPECTRUM', 'properties' => 'Notes' ] )['subjects'], 'id' )
		);
		// Without the property chosen, and across every Schema, the value is not searched.
		$this->assertSame( [], array_column( $this->get( [ 'schema' => 'Computer', 'search' => 'SPECTRUM' ] )['subjects'], 'id' ) );
		$this->assertSame( [], array_column( $this->get( [ 'search' => 'SPECTRUM' ] )['subjects'], 'id' ) );
	}

	public function testCarriesTheStatementsOfTheRequestedProperties(): void {
		$this->createPageWithSubjects(
			'Page one',
			TestSubject::build(
				id: 'sTestGSS1111111',
				label: 'ZX Spectrum 48K',
				schemaName: new SchemaName( 'Computer' ),
				statements: new StatementList( [
					TestStatement::build( 'Age', new NumberValue( 42 ), 'number' ),
					TestStatement::build( 'Notes', 'A clone', 'text' ),
				] )
			),
			new SubjectMap()
		);

		$subject = $this->get( [ 'schema' => 'Computer', 'properties' => 'Age,Website' ] )['subjects'][0];

		$this->assertSame( [ 'Age' => [ 'propertyType' => 'number', 'value' => 42 ] ], $subject['statements'] );
	}

	public function testCarriesARelationsTargets(): void {
		$this->createPageWithSubjects(
			'Vienna',
			TestSubject::build( id: 'sTestGSS2222222', label: 'Vienna', schemaName: new SchemaName( 'City' ) ),
			new SubjectMap()
		);
		$this->createPageWithSubjects(
			'Page one',
			TestSubject::build(
				id: 'sTestGSS1111111',
				label: 'KHM',
				schemaName: new SchemaName( 'Computer' ),
				statements: new StatementList( [ TestStatement::buildRelation(
					'City',
					[ TestRelation::build( id: new RelationId( 'r11111111111111' ), targetId: 'sTestGSS2222222' ) ]
				) ] )
			),
			new SubjectMap()
		);

		$subject = $this->get( [ 'schema' => 'Computer', 'properties' => 'City' ] )['subjects'][0];

		$this->assertSame( 'relation', $subject['statements']['City']['propertyType'] );
		$this->assertSame(
			[ [
				'id' => 'sTestGSS2222222',
				'displayName' => 'Vienna',
				'schema' => 'City',
				'pageTitle' => 'Vienna',
				'isMain' => true,
				'dependent' => false,
			] ],
			$subject['statements']['City']['targets']
		);
	}

	public function testCarriesNoStatementsWhenNoPropertiesAreRequested(): void {
		$this->createPageWithSubjects(
			'Page one',
			TestSubject::build(
				id: 'sTestGSS1111111',
				label: 'ZX Spectrum 48K',
				schemaName: new SchemaName( 'Computer' ),
				statements: new StatementList( [ TestStatement::build( 'Age', new NumberValue( 42 ), 'number' ) ] )
			),
			new SubjectMap()
		);

		$this->assertArrayNotHasKey( 'statements', $this->get( [ 'schema' => 'Computer' ] )['subjects'][0] );
	}

	public function testTheMaximumLimitIsServed(): void {
		// The frontend offers pages of 50, so a maximum lowered below it would fail those pages.
		$this->assertSame( [], $this->get( [ 'limit' => '50' ] )['subjects'] );
	}

	public function testLimitBeyondTheMaximumIsRejected(): void {
		$this->expectException( HttpException::class );
		$this->expectExceptionCode( 400 );

		$this->get( [ 'limit' => '51' ] );
	}

	/**
	 * @dataProvider impossibleSchemaNameProvider
	 */
	public function testASchemaNameNoSchemaCanHaveListsNothing( string $schema ): void {
		$this->createSubjectsOnPages( 'sTestGSS1111111' );

		$this->assertSame( [], $this->get( [ 'schema' => $schema ] )['subjects'] );
	}

	public static function impossibleSchemaNameProvider(): iterable {
		yield 'empty' => [ '' ];
		yield 'a reserved name' => [ 'Subject' ];
	}

	public function testASearchThatRunsTooLongAnswersThatItTimedOut(): void {
		$response = $this->executeHandler(
			new GetSubjectSummariesApi( new class implements SubjectSummaryLookup {
				public function getSubjectSummaries( SubjectSummaryQuery $query ): SubjectSummaries {
					throw new SubjectSummarySearchTimeoutException();
				}
			} ),
			new RequestData( [ 'method' => 'GET', 'queryParams' => [ 'search' => 'spectrum' ] ] )
		);

		$this->assertSame( 503, $response->getStatusCode() );
		$this->assertSame( 'searchTimeout', json_decode( $response->getBody()->getContents(), true )['errorType'] );
	}

	public function testRejectsAnUnknownSort(): void {
		$this->expectException( HttpException::class );
		$this->expectExceptionCode( 400 );

		$this->get( [ 'sort' => 'random' ] );
	}

	public function testRejectsAMalformedCursor(): void {
		$this->expectException( HttpException::class );
		$this->expectExceptionCode( 400 );

		$this->get( [ 'cursor' => 'not-a-cursor' ] );
	}

	public function testRejectsACursorFromAnotherSort(): void {
		$this->createSubjectsOnPages( 'sTestGSS1111111', 'sTestGSS1111112' );
		$cursor = $this->get( [ 'limit' => '1' ] )['nextCursor'];

		$this->expectException( HttpException::class );
		$this->expectExceptionCode( 400 );

		$this->get( [ 'limit' => '1', 'cursor' => $cursor, 'sort' => 'name' ] );
	}

	public function testLeavesOutSubjectsOnPagesTheUserCannotRead(): void {
		$this->createPageWithSubjects( 'Open', TestSubject::build( id: 'sTestGSS1111111', label: 'Open', schemaName: new SchemaName( 'Computer' ) ), new SubjectMap() );
		$this->createPageWithSubjects( 'Secret', TestSubject::build( id: 'sTestGSS1111112', label: 'Secret', schemaName: new SchemaName( 'Computer' ) ), new SubjectMap() );

		RequestContext::getMain()->setUser( $this->getTestUser()->getUser() );
		NeoWikiExtension::resetInstance();
		$this->setTemporaryHook(
			'getUserPermissionsErrors',
			static function ( $title, $user, $action, &$result ): bool {
				if ( $action === 'read' && $title->getText() === 'Secret' ) {
					$result = [ 'badaccess-group0' ];
					return false;
				}
				return true;
			}
		);

		$this->assertSame( [ 'sTestGSS1111111' ], array_column( $this->get( [] )['subjects'], 'id' ) );
	}

	/**
	 * @param array<string, string> $labelsById
	 */
	private function createSubjectsNamed( array $labelsById ): void {
		foreach ( $labelsById as $id => $label ) {
			$this->createPageWithSubjects(
				'Page ' . $id,
				TestSubject::build( id: $id, label: $label, schemaName: new SchemaName( 'Computer' ) ),
				new SubjectMap()
			);
		}
	}

	private function createSubjectsOnPages( string ...$ids ): void {
		$this->createSubjectsNamed(
			array_combine( $ids, array_map( static fn ( string $id ): string => 'Subject ' . $id, $ids ) )
		);
	}

	/**
	 * @param array<string, string> $queryParams
	 * @return array<string, mixed>
	 */
	private function get( array $queryParams ): array {
		return json_decode( $this->executeHandler(
			NeoWikiExtension::newGetSubjectSummariesApi(),
			new RequestData( [ 'method' => 'GET', 'queryParams' => $queryParams ] )
		)->getBody()->getContents(), true );
	}

}
