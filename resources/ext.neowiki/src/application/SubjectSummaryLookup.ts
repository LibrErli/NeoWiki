import type { HostSubject } from '@/domain/HostSubject';

export type SubjectSummarySort = 'newest' | 'name' | 'schema' | 'page' | 'edited';

export type SortDirection = 'asc' | 'desc';

export interface SubjectSummaryQuery {
	/** Only this Schema's Subjects; null for every Schema. */
	schema: string | null;
	search: string;
	sort: SubjectSummarySort;
	/** Ignored by the newest sort, which is always newest first. */
	direction: SortDirection;
	/** The previous page's nextCursor; null for the first page. */
	cursor: string | null;
	limit: number;
	/** Property names whose Statements each summary carries; empty for none. */
	properties?: string[];
}

/** One Statement as the wire format carries it: its Property Type, its raw value, and for a relation its targets. */
export interface SubjectStatementJson {
	propertyType: string;
	value: unknown;
	/**
	 * A relation Statement's targets, each with its id, display name, Schema, page title and Main/Dependent
	 * flags, read from the Subject index. Absent for other property types.
	 */
	targets?: RelationTargetJson[];
}

/** A relation target as a listing resolves it, so a column can name it instead of showing its id. */
export interface RelationTargetJson {
	id: string;
	displayName: string | null;
	schema: string | null;
	pageTitle: string | null;
	isMain: boolean;
	dependent: boolean;
}

export interface SubjectSummary {
	id: string;
	displayName: string;
	displayNameIsGenerated: boolean;
	/** Null except for a Dependent Subject nobody named, which is named after it. */
	hostSubject: HostSubject | null;
	schema: string;
	pageId: number;
	pageTitle: string;
	/** ISO 8601, UTC: when the Subject was last edited, which getSubjectSummaries takes from the page's last edit. */
	lastEdited: string;
	/** The Statements of the properties the query asked for, by property name; absent when none were asked for. */
	statements?: Record<string, SubjectStatementJson>;
}

export interface SubjectSummaries {
	subjects: SubjectSummary[];
	/** Null once the listing is exhausted. */
	nextCursor: string | null;
}

export interface SubjectSummaryLookup {
	/** Throws SubjectSummarySearchTimeoutError when the wiki stops a search that runs too long. */
	getSubjectSummaries( query: SubjectSummaryQuery ): Promise<SubjectSummaries>;

	/**
	 * The Subjects whose data was edited last, newest first. Adding a Subject counts as editing it.
	 * Looks only at the newest edits, so the list can be short or empty if the newest edits are to non-Subjects.
	 */
	getRecentlyEditedSubjects( limit: number ): Promise<SubjectSummary[]>;
}
