import type { RelationTargetJson } from '@/application/SubjectSummaryLookup';
import { subjectRowLinkUrl } from '@/presentation/subjectLinks';

export interface RelationTargetPart {
	text: string;
	url?: string;
}

/**
 * What a relation reads as in a listing's cell or CSV column: each standalone target as its name — its label,
 * else its page name when it is its page's Main Subject, else its Schema's — linked to the target, and the
 * Dependent Subjects, which are nested in their Host Subject rather than named, counted per Schema.
 */
export function relationTargetParts( targets: readonly RelationTargetJson[] ): RelationTargetPart[] {
	const parts: RelationTargetPart[] = [];
	const dependentCounts = new Map<string, number>();

	for ( const target of targets ) {
		if ( target.dependent ) {
			const schema = target.schema ?? '';
			dependentCounts.set( schema, ( dependentCounts.get( schema ) ?? 0 ) + 1 );
		} else {
			parts.push( {
				text: relationTargetText( target ),
				url: target.pageTitle === null ? undefined : subjectRowLinkUrl( target.pageTitle, target.id ),
			} );
		}
	}

	for ( const [ schema, count ] of dependentCounts ) {
		parts.push( {
			text: mw.message( 'neowiki-relation-dependent-count', String( count ), schema ).text(),
		} );
	}

	return parts;
}

/** One target's name: its label, else its page name when it is its page's Main Subject, else its Schema's. */
export function relationTargetText( target: RelationTargetJson ): string {
	if ( target.displayName !== null ) {
		return target.displayName;
	}

	if ( target.isMain && target.pageTitle !== null ) {
		return target.pageTitle;
	}

	return target.schema ?? target.id;
}

/** The whole relation as one line of text, for a CSV column. */
export function relationTargetsText( targets: readonly RelationTargetJson[] ): string {
	return relationTargetParts( targets ).map( ( part ) => part.text ).join( ', ' );
}
