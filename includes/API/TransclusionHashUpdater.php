<?php

namespace DataAccounting\API;

use DataAccounting\TransclusionManager;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Revision\RevisionStore;
use RequestContext;
use Title;
use TitleFactory;
use Wikimedia\ParamValidator\ParamValidator;
use MediaWiki\Rest\Validator\JsonBodyValidator;

class TransclusionHashUpdater extends SimpleHandler {
	/** @var TransclusionManager */
	private TransclusionManager $transclusionManager;
	/** @var TitleFactory */
	private TitleFactory $titleFactory;
	/** @var RevisionStore  */
	private RevisionStore $revisionStore;

	/**
	 * @param TransclusionManager $transclusionManager
	 * @param TitleFactory $titleFactory
	 * @param RevisionStore $revisionStore
	 */
	public function __construct(
		TransclusionManager $transclusionManager, TitleFactory $titleFactory, RevisionStore $revisionStore
	) {
		$this->transclusionManager = $transclusionManager;
		$this->titleFactory = $titleFactory;
		$this->revisionStore = $revisionStore;
	}

	/** @inheritDoc */
	public function run() {
		$body = $this->getValidatedBody();
		$pageTitle = $body['page_title'];
		$resourcePage = $body['resource'];

		$subject = $this->titleFactory->newFromText( $pageTitle );
		if ( !( $subject instanceof Title ) || !$subject->exists() ) {
			throw new HttpException( 'Invalid subject title' );
		}
		// Always operate on latest revision
		$latestRevision = $this->revisionStore->getRevisionByTitle( $subject );
		if ( $latestRevision === null ) {
			throw new HttpException( 'Could not retrieve revision for ' . $subject->getPrefixedDBkey() );
		}
		$res = $this->transclusionManager->updateResource(
			$latestRevision, $resourcePage, RequestContext::getMain()->getUser()
		);

		return $this->getResponseFactory()->createJson( [
			'success' => $res
		] );
	}

	/**
	 * @inheritDoc
	 */
	public function getBodyValidator( $contentType ) {
		if ( $contentType !== 'application/json' ) {
			throw new HttpException( "Unsupported Content-Type",
				415,
				[ 'content_type' => $contentType ]
			);
		}

		return new JsonBodyValidator( [
			'page_title' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'resource' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		] );
	}
}
