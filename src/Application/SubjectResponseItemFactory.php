<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\NeoWiki\Application;

use ProfessionalWiki\NeoWiki\Application\Queries\GetSubject\GetSubjectResponseItem;
use ProfessionalWiki\NeoWiki\Application\Source\SchemaResolver;
use ProfessionalWiki\NeoWiki\Domain\Page\PageIdentifiers;
use ProfessionalWiki\NeoWiki\Domain\Page\PageSubjects;
use ProfessionalWiki\NeoWiki\Domain\Schema\PropertyName;
use ProfessionalWiki\NeoWiki\Domain\Schema\Schema;
use ProfessionalWiki\NeoWiki\Domain\Schema\SchemaSubjectNormalizer;
use ProfessionalWiki\NeoWiki\Domain\Statement;
use ProfessionalWiki\NeoWiki\Domain\Subject\StatementList;
use ProfessionalWiki\NeoWiki\Domain\Subject\Subject;
use ProfessionalWiki\NeoWiki\Domain\Subject\SubjectDisplayName;

/**
 * Turns Subjects and the pages holding them into the response items every Subject-serving query
 * answers with, so a Subject is named the same way whichever query returned it.
 */
readonly class SubjectResponseItemFactory {

	public function __construct(
		private PageSubjectsAtRevisionLookup $pageSubjectsLookup,
		private HostSubjectBuilder $hostSubjectBuilder,
		private SchemaResolver $schemaResolver,
		private SchemaSubjectNormalizer $schemaSubjectNormalizer,
	) {
	}

	/**
	 * @param array<string, array{Subject, ?PageIdentifiers}> $placedSubjects
	 * @return array<string, GetSubjectResponseItem>
	 */
	public function createResponseItems( array $placedSubjects, bool $includePageIdentifiers ): array {
		$items = [];
		$pagesRead = [];

		foreach ( $placedSubjects as $idText => [ $subject, $pageIdentifiers ] ) {
			$items[$idText] = $this->newResponseItem(
				$subject,
				// Withholding the page fields must not withhold the fallback they feed: the display
				// name is built from the identifiers fetched either way.
				$includePageIdentifiers ? $pageIdentifiers : null,
				$this->pageSubjectsHolding( $subject, $pageIdentifiers, $pagesRead ),
				$pageIdentifiers?->getTitle() ?? ''
			);
		}

		return $items;
	}

	public function createResponseItem(
		Subject $subject,
		?PageIdentifiers $pageIdentifiers,
		PageSubjects $pageSubjects
	): GetSubjectResponseItem {
		return $this->newResponseItem( $subject, $pageIdentifiers, $pageSubjects, $pageIdentifiers?->getTitle() ?? '' );
	}

	/**
	 * @param ?PageSubjects $pageSubjects Null for a Subject whose name its page has no say in.
	 */
	private function newResponseItem(
		Subject $subject,
		?PageIdentifiers $pageIdentifiers,
		?PageSubjects $pageSubjects,
		string $pageName
	): GetSubjectResponseItem {
		$chosenName = $pageSubjects === null
			? $subject->getLabel()?->text
			: SubjectDisplayName::labelOrPageName( $subject, $pageSubjects, $pageName );

		// Normalize: attach the Schema's stable property ids to statements stored under a
		// (possibly old) property name, so the client can resolve them after a rename (#1802)
		$subject = $this->normalizeSubject( $subject );

		return GetSubjectResponseItem::fromSubject(
			$subject,
			$pageIdentifiers,
			$chosenName,
			$chosenName === null && $pageSubjects !== null
				? $this->hostSubjectBuilder->build( $subject, $pageSubjects, $pageName )
				: null
		);
	}

	/**
	 * Resolves the Subject's Schema and attaches stable property ids to statements:
	 * - Statements whose name matches a current Schema property get that property's id.
	 * - Statements stored under an old (renamed) property name get the id recorded in the
	 *   Schema's renamedProperties map, and are re-keyed to the current name (issue #1802).
	 * - Statements whose name matches no Schema property and no renamed entry fall back to
	 *   a type-based heuristic: a Schema property of the same type that has no statement yet.
	 */
	private function normalizeSubject( Subject $subject ): Subject {
		try {
			$schema = $this->schemaResolver->getSchema( $subject->getSchemaReference() );
		} catch ( \Throwable ) {
			return $subject;
		}

		if ( $schema === null ) {
			return $subject;
		}

		$newStatements = [];
		$changed = false;
		$claimedByName = $this->claimedPropertyNames( $subject, $schema );

		foreach ( $subject->getStatements()->asArray() as $key => $statement ) {
			[ $propertyId, $newKey ] = $this->resolvePropertyIdAndKey( $key, $statement, $schema, $claimedByName );

			if ( $propertyId !== null && $propertyId !== $statement->getPropertyId() ) {
				$statement = $statement->withPropertyId( $propertyId );
				$changed = true;
			}

			if ( $newKey !== $key ) {
				// Re-key the statement under the current property name
				$statement = new Statement(
					new PropertyName( $newKey ),
					$statement->getPropertyType(),
					$statement->getValue(),
					$propertyId,
				);
				$changed = true;
			}

			$newStatements[$newKey] = $statement;
		}

		if ( $changed ) {
			$subject = $subject->withStatements( new StatementList( array_values( $newStatements ) ) );
		}

		// Re-key statements that already carry a stable id under the property's current name
		return $this->schemaSubjectNormalizer->normalize( $subject, $schema );
	}

	/**
	 * @return array<string, true> Schema property names that already have a statement by name
	 */
	private function claimedPropertyNames( Subject $subject, Schema $schema ): array {
		$claimedByName = [];
		foreach ( $subject->getStatements()->asArray() as $key => $statement ) {
			if ( $schema->hasProperty( $key ) ) {
				$claimedByName[$key] = true;
			}
		}
		return $claimedByName;
	}

	/**
	 * Resolves the stable property id and the key to store the statement under:
	 * 1. Direct name match in the current Schema
	 * 2. Old name from a rename, via the Schema's renamedProperties map
	 * 3. Fallback heuristic: an unclaimed Schema property with the same type
	 *
	 * @param array<string, true> $claimedByName
	 * @return array{?string, string} The property id (null when none resolved) and the statement key
	 */
	private function resolvePropertyIdAndKey( string $key, Statement $statement, Schema $schema, array &$claimedByName ): array {
		$propertyId = $statement->getPropertyId();
		$newKey = $key;

		if ( $propertyId !== null ) {
			return [ $propertyId, $newKey ];
		}

		if ( $schema->hasProperty( $key ) ) {
			$propertyId = $schema->getProperty( $key )->getId();
		}

		if ( $propertyId === null ) {
			$renamedId = $schema->propertyIdForOldName( $key );
			if ( $renamedId !== null && $schema->hasPropertyWithId( $renamedId ) ) {
				$propertyId = $renamedId;
				$newKey = $schema->resolvePropertyKey( $renamedId );
			}
		}

		if ( $propertyId === null && !$schema->hasProperty( $key ) ) {
			foreach ( $schema->getAllProperties()->asMap() as $propName => $propDef ) {
				if ( isset( $claimedByName[$propName] ) ) {
					continue;
				}
				if ( $propDef->getPropertyType() === $statement->getPropertyType() && $propDef->getId() !== null ) {
					$propertyId = $propDef->getId();
					$newKey = $propName;
					$claimedByName[$propName] = true;
					break;
				}
			}
		}

		return [ $propertyId, $newKey ];
	}

	/**
	 * A page is read only for a label-less Subject it holds: a stored label is the chosen name whichever
	 * page holds it, and a Subject without a page of this wiki has no page name to fall back on. One
	 * read per distinct page, however many of the response's Subjects live on it.
	 *
	 * @param array<int, PageSubjects> $pagesRead Page ID → the Subjects that page holds, filled as
	 *   pages are reached.
	 */
	private function pageSubjectsHolding( Subject $subject, ?PageIdentifiers $pageIdentifiers, array &$pagesRead ): ?PageSubjects {
		if ( $subject->getLabel() !== null || $pageIdentifiers === null ) {
			return null;
		}

		$pageId = $pageIdentifiers->getId();
		$pagesRead[$pageId->id] ??= $this->pageSubjectsLookup->getPageSubjects( $pageId );

		return $pagesRead[$pageId->id];
	}

}
