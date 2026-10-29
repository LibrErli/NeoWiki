<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\NeoWiki\Persistence\MediaWiki;

use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Finds the page ids that publish Subjects of a given Schema, from the published Subject index.
 */
class DatabasePageIdsBySchemaLookup {

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
	) {
	}

	/**
	 * @return int[] Distinct page ids, ordered, of pages holding at least one Subject of $schemaName.
	 */
	public function getPageIdsWithSchema( string $schemaName ): array {
		$db = $this->connectionProvider->getReplicaDatabase();

		$pageIds = $db->newSelectQueryBuilder()
			->select( 'nws_page_id' )
			->distinct()
			->from( DatabasePublishedSubjectIndex::SUBJECT_TABLE )
			->where( [ 'nws_schema' => $schemaName ] )
			->orderBy( 'nws_page_id' )
			->caller( __METHOD__ )
			->fetchFieldValues();

		return array_map( 'intval', $pageIds );
	}

}
