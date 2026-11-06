<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\NeoWiki\Persistence\MediaWiki;

use UtfNormal\Validator;

/**
 * The form `neowiki_subject` matches and orders names in: Unicode case-folded and in NFC. MediaWiki's columns
 * are binary, where LIKE and LOWER() compare bytes or know ASCII only, so search terms and cursor values go
 * through this too.
 */
class CaseInsensitiveKey {

	/**
	 * The key columns' width. Cut on a character boundary, a longer key stays a prefix of the full one.
	 */
	private const int MAX_BYTES = 255;

	public static function of( string $text ): string {
		return mb_strcut( self::fold( $text ), 0, self::MAX_BYTES, 'UTF-8' );
	}

	/**
	 * The case-folded form a text is stored in to be matched without case, uncut: for columns too wide for
	 * {@see self::of()}, where a cut would let a term past the cut match nothing.
	 */
	public static function fold( string $text ): string {
		// Normalized before folding, so that canonically equivalent spellings fold alike, and after it, since
		// folding can leave a text outside NFC.
		return Validator::cleanUp( (string)mb_convert_case( Validator::cleanUp( $text ), MB_CASE_FOLD, 'UTF-8' ) );
	}

}
