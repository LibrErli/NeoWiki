<?php

declare( strict_types = 1 );

namespace ProfessionalWiki\NeoWiki\EntryPoints\REST;

use MediaWiki\Request\WebResponse;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use ProfessionalWiki\NeoWiki\Domain\Schema\SchemaName;
use ProfessionalWiki\NeoWiki\EntryPoints\Content\SchemaContent;
use ProfessionalWiki\NeoWiki\NeoWikiExtension;
use ProfessionalWiki\NeoWiki\Presentation\DocumentationUrl;
use UtfNormal\Validator as UtfNormalValidator;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

class SaveSchemaApi extends SchemaPageWriteApi {

	private ?string $schemaJson = null;

	protected function getActionParameters(): array {
		return [
			'action' => 'edit',
			'contentmodel' => SchemaContent::CONTENT_MODEL_ID,
			'text' => $this->getSchemaJson(),
			'summary' => $this->getComment(),
		];
	}

	/**
	 * Read from the raw body because MediaWiki's REST layer decodes JSON objects into PHP arrays, and an empty
	 * object, such as an empty `propertyDefinitions`, would come back out as a list. Unicode-normalized here as that
	 * layer does before decoding: the edit cannot, as the JSON passed to it escapes all non-ASCII text.
	 *
	 * Property renames are recorded as `propertyAliases` (old name => new name), so that stored Subject
	 * data with the old property name still resolves (issue #1802).
	 */
	private function getSchemaJson(): string {
		if ( $this->schemaJson === null ) {
			$body = json_decode( UtfNormalValidator::cleanUp( (string)$this->getRequest()->getBody() ) );
			$schemaData = is_object( $body ) ? $body->schema ?? null : null;

			if ( is_object( $schemaData ) ) {
				$schemaData = $this->addPropertyAliases( $schemaData );
			}

			$this->schemaJson = (string)json_encode( $schemaData );
		}

		return $this->schemaJson;
	}

	/**
	 * Compares the new Schema with the currently stored one and records any property renames
	 * in a `renamedProperties` map (old name => stable property id). The id makes the mapping
	 * unambiguous even when different properties shared a name at different times (issue #1802).
	 */
	private function addPropertyAliases( object $newSchemaData ): object {
		$schemaName = $this->getSchemaPage()->getText();
		$extension = NeoWikiExtension::getInstance();

		// Load the currently stored Schema (if any)
		$storedSchema = $extension->getSchemaLookup()->getSchema( new SchemaName( $schemaName ) );

		if ( $storedSchema === null ) {
			return $newSchemaData; // New Schema, no renames possible
		}

		$newJson = json_encode( $newSchemaData );
		if ( $newJson === false ) {
			return $newSchemaData;
		}

		try {
			$newSchema = $extension->getPersistenceSchemaDeserializer()->deserialize(
				new SchemaName( $schemaName ),
				$newJson
			);
		} catch ( \InvalidArgumentException ) {
			return $newSchemaData;
		}

		// Detect renames. Two strategies:
		// 1. By property id (preferred): if the stored Schema already has ids, a property in the
		//    new Schema whose id existed under a different name was renamed.
		// 2. By type heuristic (fallback): if the stored Schema has no ids (saved before the id
		//    feature), fall back to matching by type — a removed name and an added name with the
		//    same type is treated as a rename.
		$renamed = $storedSchema->getRenamedProperties();

		$storedHasIds = false;
		foreach ( $storedSchema->getAllProperties()->asMap() as $oldProperty ) {
			if ( $oldProperty->getId() !== null ) {
				$storedHasIds = true;
				break;
			}
		}

		if ( $storedHasIds ) {
			// Strategy 1: match by id
			foreach ( $newSchema->getAllProperties()->asMap() as $newName => $newProperty ) {
				$id = $newProperty->getId();
				if ( $id === null ) {
					continue;
				}

				foreach ( $storedSchema->getAllProperties()->asMap() as $oldName => $oldProperty ) {
					if ( $oldProperty->getId() === $id && $oldName !== $newName ) {
						$renamed[$oldName] = $id;
						break;
					}
				}
			}
		} else {
			// Strategy 2: fallback to type-based heuristic when old schema has no ids
			$typeBasedRenames = \ProfessionalWiki\NeoWiki\Domain\Schema\SchemaPropertyRenames::detect( $storedSchema, $newSchema );

			foreach ( $typeBasedRenames as $oldName => $newName ) {
				// Find the new property's id
				if ( $newSchema->hasProperty( $newName ) ) {
					$newProperty = $newSchema->getProperty( $newName );
					if ( $newProperty->getId() !== null ) {
						$renamed[$oldName] = $newProperty->getId();
					}
				}
			}
		}

		// Only keep entries whose id still exists in the new Schema
		$renamed = array_filter(
			$renamed,
			fn( string $id ) => $newSchema->hasPropertyWithId( $id )
		);

		if ( $renamed === [] ) {
			unset( $newSchemaData->renamedProperties );
		} else {
			$newSchemaData->renamedProperties = (object)$renamed;
		}

		return $newSchemaData;
	}

	protected function mapActionModuleResult( array $data ): array {
		if ( ( $data['edit']['result'] ?? null ) !== 'Success' ) {
			// An extension can hold an edit back with a result instead of an error, as ConfirmEdit does to ask
			// for a CAPTCHA.
			throw new LocalizedHttpException(
				new MessageValue( 'hookaborted' ),
				400,
				[ 'actionModuleResult' => $data['edit'] ?? null ]
			);
		}

		return [ 'schema' => $this->presentSavedSchema() ];
	}

	/**
	 * Built from the JSON just saved, which the page stores reformatted but unchanged. The Schema lookup would
	 * serve the revision before it: a database replica's until the request ends, or the revision policy's until
	 * the new one is approved.
	 */
	private function presentSavedSchema(): array {
		$extension = NeoWikiExtension::getInstance();

		return $extension->getSchemaPresentationSerializer()->toArray(
			$extension->getPersistenceSchemaDeserializer()->deserialize(
				new SchemaName( $this->getSchemaPage()->getText() ),
				$this->getSchemaJson()
			)
		);
	}

	protected function mapActionModuleResponse(
		WebResponse $actionModuleResponse,
		array $actionModuleResult,
		Response $response
	): void {
		parent::mapActionModuleResponse( $actionModuleResponse, $actionModuleResult, $response );

		if ( $actionModuleResult['edit']['new'] ?? false ) {
			$response->setStatus( 201 );
		}
	}

	public function getBodyParamSettings(): array {
		return [
			'schema' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'array',
				ParamValidator::PARAM_REQUIRED => true,
				self::PARAM_DESCRIPTION => 'The whole Schema, in the shape GET /neowiki/v0/schema/{schemaName} returns under '
					. '`schema`. Format documented at ' . DocumentationUrl::SchemaFormat->value . '.',
			],
		] + parent::getBodyParamSettings();
	}

	protected function getCommentDescription(): string {
		return 'Optional edit summary.';
	}

}
