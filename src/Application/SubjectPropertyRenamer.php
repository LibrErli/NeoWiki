<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\NeoWiki\Application;

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Revision\RevisionLookup;
use ProfessionalWiki\NeoWiki\Domain\Page\PageId;
use ProfessionalWiki\NeoWiki\Domain\Page\PageSubjects;
use ProfessionalWiki\NeoWiki\Domain\Schema\PropertyName;
use ProfessionalWiki\NeoWiki\Domain\Statement;
use ProfessionalWiki\NeoWiki\Domain\Subject\StatementList;
use ProfessionalWiki\NeoWiki\Domain\Subject\Subject;
use ProfessionalWiki\NeoWiki\EntryPoints\Content\SubjectContent;
use ProfessionalWiki\NeoWiki\Persistence\MediaWiki\PageContentSaver;
use ProfessionalWiki\NeoWiki\Persistence\MediaWiki\Subject\MediaWikiSubjectRepository;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Applies property renames to all Subjects of a Schema: renames the statement keys in the
 * stored Subject JSON and updates the relation index rows.
 *
 * Triggered when a Schema page is saved with renamed properties, so that existing data
 * keeps matching the Schema (see issue #1802).
 */
class SubjectPropertyRenamer {

	public function __construct(
		private readonly IConnectionProvider $connectionProvider,
		private readonly RevisionLookup $revisionLookup,
		private readonly PageContentSaver $pageContentSaver,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @param string $schemaName The Schema whose properties were renamed
	 * @param array<string, string> $renames Map of old property name => new property name
	 */
	public function renameInAllSubjectsOfSchema( string $schemaName, array $renames ): void {
		if ( $renames === [] ) {
			return;
		}

		$pageIds = $this->findPageIdsWithSchema( $schemaName );

		$this->logger->info(
			'NeoWiki: renaming properties {renames} in {count} page(s) holding Subjects of Schema "{schema}"',
			[
				'renames' => $renames,
				'count' => count( $pageIds ),
				'schema' => $schemaName,
			]
		);

		foreach ( $pageIds as $pageId ) {
			try {
				$this->renameInPage( new PageId( $pageId ), $renames );
			} catch ( \Throwable $e ) {
				$this->logger->error(
					'NeoWiki: failed to rename properties in page {pageId}: {message}',
					[ 'pageId' => $pageId, 'message' => $e->getMessage(), 'exception' => $e ]
				);
			}
		}

		$this->updateRelationIndex( $renames );
	}

	/**
	 * @return int[]
	 */
	private function findPageIdsWithSchema( string $schemaName ): array {
		$db = $this->connectionProvider->getReplicaDatabase();

		$pageIds = $db->newSelectQueryBuilder()
			->select( 'nws_page_id' )
			->distinct()
			->from( 'neowiki_subject' )
			->where( [ 'nws_schema' => $schemaName ] )
			->caller( __METHOD__ )
			->fetchFieldValues();

		return array_map( 'intval', $pageIds );
	}

	/**
	 * @param array<string, string> $renames
	 */
	private function renameInPage( PageId $pageId, array $renames ): void {
		$revision = $this->revisionLookup->getRevisionByPageId( $pageId->id );

		if ( $revision === null ) {
			return;
		}

		try {
			$content = $revision->getContent( MediaWikiSubjectRepository::SLOT_NAME );
		} catch ( \MediaWiki\Revision\RevisionAccessException ) {
			return;
		}

		if ( !$content instanceof SubjectContent ) {
			return;
		}

		$pageSubjects = $content->getPageSubjects();
		$changed = false;

		$newPageSubjects = $pageSubjects->withEachSubject(
			function ( Subject $subject ) use ( $renames, &$changed ): Subject {
				$newStatements = $this->renameStatements( $subject->getStatements(), $renames, $changed );

				return $newStatements === $subject->getStatements() ? $subject : $subject->withStatements( $newStatements );
			}
		);

		if ( !$changed ) {
			return;
		}

		$content->setPageSubjects( $newPageSubjects );

		$this->pageContentSaver->saveContent(
			$pageId,
			[ MediaWikiSubjectRepository::SLOT_NAME => $content ],
			CommentStoreComment::newUnsavedComment(
				'Rename property in Subject data to match Schema change: '
				. implode( ', ', array_map(
					static fn( string $old, string $new ): string => "\"$old\" → \"$new\"",
					array_keys( $renames ),
					$renames
				) )
			)
		);
	}

	/**
	 * @param array<string, string> $renames
	 */
	private function renameStatements( StatementList $statements, array $renames, bool &$changed ): StatementList {
		$result = $statements;

		foreach ( $renames as $oldName => $newName ) {
			$oldProperty = new PropertyName( $oldName );
			$statement = $result->getStatement( $oldProperty );

			if ( $statement !== null ) {
				// Remove old, add new
				$result = $result->withoutStatement( $oldProperty );
				$newStatement = new Statement(
					new PropertyName( $newName ),
					$statement->getPropertyType(),
					$statement->getValue()
				);
				$result = $result->withStatement( $newStatement );
				$changed = true;
			}
		}

		return $result;
	}

	/**
	 * @param array<string, string> $renames
	 */
	private function updateRelationIndex( array $renames ): void {
		$db = $this->connectionProvider->getPrimaryDatabase();

		foreach ( $renames as $oldName => $newName ) {
			$db->newUpdateQueryBuilder()
				->update( 'neowiki_relation' )
				->set( [ 'nwr_property' => $newName ] )
				->where( [ 'nwr_property' => $oldName ] )
				->caller( __METHOD__ )
				->execute();
		}
	}

}
