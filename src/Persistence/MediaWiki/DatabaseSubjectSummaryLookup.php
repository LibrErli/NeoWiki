<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\NeoWiki\Persistence\MediaWiki;

use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use ProfessionalWiki\NeoWiki\Application\PageReadAuthorizer;
use ProfessionalWiki\NeoWiki\Application\SubjectSummaries\SortDirection;
use ProfessionalWiki\NeoWiki\Application\SubjectSummaries\SubjectSummaries;
use ProfessionalWiki\NeoWiki\Application\SubjectSummaries\SubjectSummary;
use ProfessionalWiki\NeoWiki\Application\SubjectSummaries\SubjectSummaryCursor;
use ProfessionalWiki\NeoWiki\Application\SubjectSummaries\SubjectSummaryLookup;
use ProfessionalWiki\NeoWiki\Application\SubjectSummaries\SubjectSummaryQuery;
use ProfessionalWiki\NeoWiki\Application\SubjectSummaries\SubjectSummarySearchTimeoutException;
use ProfessionalWiki\NeoWiki\Application\SubjectSummaries\SubjectSummarySort;
use ProfessionalWiki\NeoWiki\Domain\PropertyType\Types\RelationType;
use stdClass;
use Wikimedia\Rdbms\DBQueryTimeoutError;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\Rdbms\RawSQLExpression;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Rows on pages the caller may not read are dropped as they are reached, and the request reads on past them, up to
 * the scan bound. Reaching the bound ends the listing: the rows found so far come without a next cursor.
 *
 * Ties break by Subject id in the direction of the sort, so that every order with an index walks it from the cursor
 * on: all but the page and edit time orders, which sort every row they match.
 */
class DatabaseSubjectSummaryLookup implements SubjectSummaryLookup {

	private const string ALIAS = 'subject';
	private const string SUBJECT_ID = self::ALIAS . '.nws_subject_id';
	private const string NAME_KEY = self::ALIAS . '.nws_display_name_key';

	private const int OVERFETCH_FACTOR = 4;

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly PageReadAuthorizer $readAuthorizer,
		private readonly TitleFactory $titleFactory,
		/** The most rows one request reads looking for readable ones. */
		private readonly int $scanBound,
		/** Milliseconds a search may run on MySQL and MariaDB, 0 for no limit. Other databases do not limit it. */
		private readonly int $searchTimeLimit,
	) {
	}

	public function getSubjectSummaries( SubjectSummaryQuery $query ): SubjectSummaries {
		$summaries = [];
		$lastServed = null;
		$position = $query->after;
		$scanned = 0;
		$requested = min( ( $query->limit + 1 ) * self::OVERFETCH_FACTOR, $this->scanBound );
		$readablePages = [];

		// A second fetch, if any, reads the rest of the bound, so a request makes at most two.
		while ( $requested > 0 ) {
			$rows = $this->fetchRows( $query, $position, $requested );

			foreach ( $rows as $row ) {
				$scanned++;
				$position = $row['cursor'];

				if ( !$this->mayRead( $row['title'], $readablePages ) ) {
					continue;
				}

				// A readable row past a full page is what says there is a next page.
				if ( count( $summaries ) === $query->limit ) {
					return new SubjectSummaries( $this->withStatements( $summaries, $query ), $lastServed );
				}

				$summaries[] = $row['summary'];
				$lastServed = $row['cursor'];
			}

			if ( count( $rows ) < $requested ) {
				break;
			}

			$requested = $this->scanBound - $scanned;
		}

		return new SubjectSummaries( $this->withStatements( $summaries, $query ), null );
	}

	/**
	 * Attaches the Statements of the properties the query asks for, read from the Statement index: one read per
	 * request, not one per Subject, since the index is what keeps a listing from opening every page.
	 *
	 * @param list<SubjectSummary> $summaries
	 * @return list<SubjectSummary>
	 */
	private function withStatements( array $summaries, SubjectSummaryQuery $query ): array {
		if ( $query->properties === [] || $summaries === [] ) {
			return $summaries;
		}

		$db = $this->connectionProvider->getReplicaDatabase();
		$propertiesByCutName = [];

		foreach ( $query->properties as $property ) {
			$propertiesByCutName[DatabasePublishedSubjectIndex::fitNameColumn( $property )] = $property;
		}

		$pairs = [];

		foreach ( $summaries as $summary ) {
			$pairs[] = [ 'nwst_page_id' => $summary->pageId, 'nwst_subject_id' => $summary->subjectId ];
		}

		$result = $db->newSelectQueryBuilder()
			->select( [ 'nwst_subject_id', 'nwst_property', 'nwst_statement' ] )
			->from( DatabasePublishedSubjectIndex::STATEMENT_TABLE )
			->where( $db->makeList(
				array_map(
					static fn ( array $pair ): string => $db->makeList( $pair, IDatabase::LIST_AND ),
					$pairs
				),
				IDatabase::LIST_OR
			) )
			->andWhere( [ 'nwst_property' => array_keys( $propertiesByCutName ) ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$statementsBySubject = [];

		/** @var stdClass $row */
		foreach ( $result as $row ) {
			$property = $propertiesByCutName[(string)$row->nwst_property] ?? (string)$row->nwst_property;

			/** @var array{propertyType: string, value: mixed} $statement */
			$statement = json_decode( (string)$row->nwst_statement, true );

			$statementsBySubject[(string)$row->nwst_subject_id][$property] = $statement;
		}

		$this->attachRelationTargets( $db, $statementsBySubject );

		return array_map(
			static fn ( SubjectSummary $summary ): SubjectSummary =>
				$summary->withStatements( $statementsBySubject[$summary->subjectId] ?? [] ),
			$summaries
		);
	}

	/**
	 * A relation's targets read as ids alone, so a listing that shows one as a column also gets each target's
	 * name, Schema and page, read from the Subject index in one query: the index holds what naming a target
	 * takes, and opening every target's page does not.
	 *
	 * @param array<string, array<string, array{propertyType: string, value: mixed}>> $statementsBySubject
	 */
	private function attachRelationTargets( IReadableDatabase $db, array &$statementsBySubject ): void {
		$targetIds = [];

		foreach ( $statementsBySubject as $statements ) {
			foreach ( $statements as $statement ) {
				foreach ( self::targetIdsOfStatement( $statement ) as $id ) {
					$targetIds[$id] = true;
				}
			}
		}

		if ( $targetIds === [] ) {
			return;
		}

		$targets = $this->relationTargetsOf( $db, array_keys( $targetIds ) );

		foreach ( $statementsBySubject as $subjectId => $statements ) {
			foreach ( $statements as $property => $statement ) {
				if ( $statement['propertyType'] === RelationType::NAME ) {
					$statementsBySubject[$subjectId][$property]['targets'] = self::resolvedTargetsOf( $statement, $targets );
				}
			}
		}
	}

	/**
	 * @param array{propertyType: string, value: mixed} $statement
	 * @return list<string>
	 */
	private static function targetIdsOfStatement( array $statement ): array {
		if ( $statement['propertyType'] !== RelationType::NAME || !is_array( $statement['value'] ) ) {
			return [];
		}

		$ids = [];

		foreach ( $statement['value'] as $part ) {
			if ( is_array( $part ) && isset( $part['target'] ) && is_string( $part['target'] ) ) {
				$ids[] = $part['target'];
			}
		}

		return $ids;
	}

	/**
	 * @param array{propertyType: string, value: mixed} $statement
	 * @param array<string, array{id: string, displayName: string|null, schema: string|null, pageTitle: string|null, isMain: bool, dependent: bool}> $targets
	 * @return list<array{id: string, displayName: string|null, schema: string|null, pageTitle: string|null, isMain: bool, dependent: bool}>
	 */
	private static function resolvedTargetsOf( array $statement, array $targets ): array {
		$resolved = [];

		foreach ( self::targetIdsOfStatement( $statement ) as $id ) {
			// A target nobody created resolves to nothing but its id.
			$resolved[] = $targets[$id] ?? [
				'id' => $id,
				'displayName' => null,
				'schema' => null,
				'pageTitle' => null,
				'isMain' => false,
				'dependent' => false,
			];
		}

		return $resolved;
	}

	/**
	 * @param list<string> $targetIds
	 * @return array<string, array{id: string, displayName: string|null, schema: string|null, pageTitle: string|null, isMain: bool, dependent: bool}>
	 */
	private function relationTargetsOf( IReadableDatabase $db, array $targetIds ): array {
		$result = $db->newSelectQueryBuilder()
			->select( [
				'target.nws_subject_id', 'target.nws_display_name', 'target.nws_schema',
				'target.nws_is_main', 'target.nws_dependent', 'page_id', 'page_namespace', 'page_title',
			] )
			->from( DatabasePublishedSubjectIndex::SUBJECT_TABLE, 'target' )
			->join( 'page', null, 'page_id = target.nws_page_id' )
			->where( [ 'target.nws_subject_id' => $targetIds ] )
			// A Subject id on two pages resolves to the lowest page id's row, as the listing itself does.
			->andWhere( LowestPageRows::condition( $db, 'target' ) )
			->caller( __METHOD__ )
			->fetchResultSet();

		$targets = [];

		/** @var stdClass $row */
		foreach ( $result as $row ) {
			$id = (string)$row->nws_subject_id;
			$targets[$id] = [
				'id' => $id,
				'displayName' => $row->nws_display_name === null ? null : (string)$row->nws_display_name,
				'schema' => (string)$row->nws_schema,
				'pageTitle' => $this->titleFactory->newFromRow( $row )->getPrefixedText(),
				'isMain' => (int)$row->nws_is_main === 1,
				'dependent' => (int)$row->nws_dependent === 1,
			];
		}

		return $targets;
	}

	/**
	 * @param array<int, bool> $readablePages Page id → readable, filled as pages are reached.
	 */
	private function mayRead( Title $page, array &$readablePages ): bool {
		$readablePages[$page->getId()] ??= $this->readAuthorizer->authorizeReadByPageTitle( $page );

		return $readablePages[$page->getId()];
	}

	/**
	 * @return list<array{summary: SubjectSummary, title: Title, cursor: SubjectSummaryCursor}>
	 * @throws SubjectSummarySearchTimeoutException
	 */
	private function fetchRows( SubjectSummaryQuery $query, ?SubjectSummaryCursor $after, int $limit ): array {
		$db = $this->connectionProvider->getReplicaDatabase();

		try {
			return $query->sort === SubjectSummarySort::Name
				? $this->fetchByName( $db, $query, $after, $limit )
				: $this->fetchRun( $db, $query, $this->sortColumn( $query->sort ), [], $after, $limit );
		} catch ( DBQueryTimeoutError $timeout ) {
			if ( $query->search === '' ) {
				throw $timeout;
			}

			throw new SubjectSummarySearchTimeoutException( previous: $timeout );
		}
	}

	private function sortColumn( SubjectSummarySort $sort ): ?string {
		return match ( $sort ) {
			SubjectSummarySort::Newest => null,
			SubjectSummarySort::Name => self::NAME_KEY,
			SubjectSummarySort::Schema => self::ALIAS . '.nws_schema',
			SubjectSummarySort::Page => self::ALIAS . '.nws_page_key',
			SubjectSummarySort::Edited => self::ALIAS . '.nws_edited',
		};
	}

	/**
	 * Generated names come after chosen ones whichever way the names run, so the two are read as two runs, each in
	 * the order of the name index.
	 *
	 * @return list<array{summary: SubjectSummary, title: Title, cursor: SubjectSummaryCursor}>
	 */
	private function fetchByName(
		IReadableDatabase $db,
		SubjectSummaryQuery $query,
		?SubjectSummaryCursor $after,
		int $limit
	): array {
		$afterAGeneratedName = $after !== null && $after->sortValue === null;

		$rows = $afterAGeneratedName
			? []
			: $this->fetchRun( $db, $query, self::NAME_KEY, [ $db->expr( self::NAME_KEY, '!=', null ) ], $after, $limit );

		if ( count( $rows ) === $limit ) {
			return $rows;
		}

		return array_merge(
			$rows,
			$this->fetchRun(
				$db,
				$query,
				null,
				[ $db->expr( self::NAME_KEY, '=', null ) ],
				$afterAGeneratedName ? $after : null,
				$limit - count( $rows )
			)
		);
	}

	/**
	 * @param ?string $sortColumn Null to order by Subject id alone
	 * @param IExpression[] $runConditions
	 * @return list<array{summary: SubjectSummary, title: Title, cursor: SubjectSummaryCursor}>
	 */
	private function fetchRun(
		IReadableDatabase $db,
		SubjectSummaryQuery $query,
		?string $sortColumn,
		array $runConditions,
		?SubjectSummaryCursor $after,
		int $limit
	): array {
		$direction = $query->direction === SortDirection::Ascending
			? SelectQueryBuilder::SORT_ASC
			: SelectQueryBuilder::SORT_DESC;
		$orderColumns = $sortColumn === null ? [ self::SUBJECT_ID ] : [ $sortColumn, self::SUBJECT_ID ];

		$builder = $db->newSelectQueryBuilder()
			->select( [
				'nws_subject_id', 'nws_schema', 'nws_display_name', 'nws_display_name_key', 'nws_page_key', 'nws_edited',
				'page_id', 'page_namespace', 'page_title',
			] )
			->from( DatabasePublishedSubjectIndex::SUBJECT_TABLE, self::ALIAS )
			->join( 'page', null, 'page_id = ' . self::ALIAS . '.nws_page_id' )
			->where( $runConditions )
			->orderBy( $orderColumns, $direction )
			->limit( $limit )
			// Without it, MariaDB reads all of `page` and sorts every row once Subjects outnumber pages a few
			// times over, instead of walking the sort's index and stopping at the limit.
			->straightJoinOption()
			->caller( __METHOD__ );

		if ( $query->schemaName === null ) {
			$builder->andWhere( [ self::ALIAS . '.nws_dependent' => 0 ] );
		} else {
			$builder->andWhere( [ self::ALIAS . '.nws_schema' => $query->schemaName->getText() ] );
		}

		if ( $query->search !== '' ) {
			$builder->andWhere( $this->searchCondition( $db, $query ) );
			$builder->setMaxExecutionTime( $this->searchTimeLimit );
		}

		if ( $after !== null ) {
			$position = $sortColumn === null ? [] : [ $sortColumn => $this->sortColumnValue( $db, $after ) ];
			$position[self::SUBJECT_ID] = $after->subjectId;

			$builder->andWhere(
				$db->buildComparison( $direction === SelectQueryBuilder::SORT_ASC ? '>' : '<', $position )
			);
		}

		// Last: MariaDB tests the conditions in the order they are added, and this one is a lookup per row.
		$builder->andWhere( LowestPageRows::condition( $db, self::ALIAS ) );

		$rows = [];

		/** @var stdClass $row */
		foreach ( $builder->fetchResultSet() as $row ) {
			$rows[] = $this->toRow( $row, $query );
		}

		return $rows;
	}

	private function searchCondition( IReadableDatabase $db, SubjectSummaryQuery $query ): IExpression {
		$search = $query->search;
		$containingKey = new LikeValue( $db->anyString(), CaseInsensitiveKey::of( $search ), $db->anyString() );

		$condition = $db->expr( self::NAME_KEY, IExpression::LIKE, $containingKey )
			->or( self::ALIAS . '.nws_page_key', IExpression::LIKE, $containingKey )
			->or( self::SUBJECT_ID, IExpression::LIKE, new LikeValue( $search, $db->anyString() ) );

		// Within one Schema, a search also matches what the Statements of the chosen properties hold: the
		// columns a Schema page's Subject table shows. Without them, it stays with the name, page and ID.
		if ( $query->schemaName !== null && $query->properties !== [] ) {
			$condition = $condition->orExpr(
				new RawSQLExpression( $this->statementSearchCondition( $db, $query ) )
			);
		}

		return $condition;
	}

	/**
	 * A Subject matches when one of its Statements of the chosen properties has search text containing the
	 * term, in any case: the index stores that text folded, and the term is folded the same way.
	 */
	private function statementSearchCondition( IReadableDatabase $db, SubjectSummaryQuery $query ): string {
		$properties = array_map(
			DatabasePublishedSubjectIndex::fitNameColumn( ... ),
			$query->properties
		);

		$matchingStatement = $db->newSelectQueryBuilder()
			->select( '1' )
			->from( DatabasePublishedSubjectIndex::STATEMENT_TABLE, 'statement' )
			->where( 'statement.nwst_page_id = ' . self::ALIAS . '.nws_page_id' )
			->andWhere( 'statement.nwst_subject_id = ' . self::ALIAS . '.nws_subject_id' )
			->andWhere( [ 'statement.nwst_property' => $properties ] )
			->andWhere( $db->expr(
				'statement.nwst_search_text',
				IExpression::LIKE,
				new LikeValue( $db->anyString(), CaseInsensitiveKey::fold( $query->search ), $db->anyString() )
			) )
			->getSQL();

		return "EXISTS ($matchingStatement)";
	}

	private function sortColumnValue( IReadableDatabase $db, SubjectSummaryCursor $after ): string|int|null {
		return $after->sort === SubjectSummarySort::Edited
			? $db->timestamp( (int)$after->sortValue )
			: $after->sortValue;
	}

	/**
	 * @return array{summary: SubjectSummary, title: Title, cursor: SubjectSummaryCursor}
	 */
	private function toRow( stdClass $row, SubjectSummaryQuery $query ): array {
		$id = (string)$row->nws_subject_id;
		$schemaName = (string)$row->nws_schema;
		$name = $row->nws_display_name === null ? null : (string)$row->nws_display_name;
		$title = $this->titleFactory->newFromRow( $row );

		return [
			'summary' => new SubjectSummary(
				subjectId: $id,
				displayName: $name ?? $schemaName,
				displayNameIsGenerated: $name === null,
				schemaName: $schemaName,
				pageId: $title->getId(),
				pageTitle: $title->getPrefixedText(),
				lastEdited: (string)ConvertibleTimestamp::convert( TS_ISO_8601, $row->nws_edited ),
				hostSubject: null,
			),
			'title' => $title,
			'cursor' => new SubjectSummaryCursor( $query->sort, $query->direction, $this->sortValueOf( $row, $query->sort ), $id ),
		];
	}

	private function sortValueOf( stdClass $row, SubjectSummarySort $sort ): string|int|null {
		return match ( $sort ) {
			SubjectSummarySort::Newest => null,
			SubjectSummarySort::Name => $row->nws_display_name_key === null ? null : (string)$row->nws_display_name_key,
			SubjectSummarySort::Schema => (string)$row->nws_schema,
			SubjectSummarySort::Page => (string)$row->nws_page_key,
			SubjectSummarySort::Edited => (int)ConvertibleTimestamp::convert( TS_UNIX, $row->nws_edited ),
		};
	}

}
