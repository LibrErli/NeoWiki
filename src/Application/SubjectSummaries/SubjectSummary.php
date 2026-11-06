<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\NeoWiki\Application\SubjectSummaries;

use ProfessionalWiki\NeoWiki\Application\HostSubject;

/**
 * One row of a Subject listing. $displayName, $displayNameIsGenerated and $hostSubject mean what they mean in
 * GetSubjectResponseItem: the chosen name, else the Schema name, whether nobody chose one, and the Host Subject of
 * a Dependent Subject nobody named.
 */
readonly class SubjectSummary {

	public function __construct(
		public string $subjectId,
		public string $displayName,
		public bool $displayNameIsGenerated,
		public string $schemaName,
		public int $pageId,
		public string $pageTitle,
		/** ISO 8601 in UTC: when the Subject was last edited, which SubjectSummaryLookup takes from the page's last edit. */
		public string $lastEdited,
		public ?HostSubject $hostSubject,
		/**
		 * The Statements of the properties a listing asked for, by property name, as `{ propertyType, value }`
		 * JSON. Empty when none were asked for. A property the Subject has no Statement of is absent. A
		 * relation Statement also carries `targets`: each target's id, display name, Schema, page title,
		 * and whether it is a Main or Dependent Subject, read from the Subject index.
		 *
		 * @var array<string, array{propertyType: string, value: mixed, targets?: list<array{id: string, displayName: string|null, schema: string|null, pageTitle: string|null, isMain: bool, dependent: bool}>}>
		 */
		public array $statements = [],
	) {
	}

	/**
	 * @param array<string, array{propertyType: string, value: mixed, targets?: list<array{id: string, displayName: string|null, schema: string|null, pageTitle: string|null, isMain: bool, dependent: bool}>}> $statements
	 */
	public function withStatements( array $statements ): self {
		return new self(
			$this->subjectId,
			$this->displayName,
			$this->displayNameIsGenerated,
			$this->schemaName,
			$this->pageId,
			$this->pageTitle,
			$this->lastEdited,
			$this->hostSubject,
			$statements
		);
	}

}
