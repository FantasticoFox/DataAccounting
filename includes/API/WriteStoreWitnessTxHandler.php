<?php

namespace DataAccounting\API;

use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\Validator\JsonBodyValidator;
use RequestContext;
use Title;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;
use WikiPage;

require_once __DIR__ . "/../ApiUtil.php";
require_once __DIR__ . "/../Util.php";

function selectToArray( $db, string $table, string $col, array $conds ): array {
	$out = [];
	$res = $db->select(
		$table,
		[ $col ],
		$conds,
	);
	foreach ( $res as $row ) {
		array_push( $out, $row->{$col} );
	}
	return $out;
}

// TODO move to Util.php
function addReceiptToDomainManifest( $user, $witness_event_id, $db ) {
	$row = $db->selectRow(
		'witness_events',
		[
			"domain_id",
			"domain_manifest_title",
			"domain_manifest_genesis_hash",
			"merkle_root",
			"witness_event_verification_hash",
			"witness_network",
			"smart_contract_address",
			"witness_event_transaction_hash",
			"sender_account_address",
		],
		[ 'witness_event_id' => $witness_event_id ]
	);
	if ( !$row ) {
		throw new HttpException( "Witness event data is missing.", 400 );
	}

	$dm = "DomainManifest $witness_event_id";
	if ( "Data Accounting:$dm" !== $row->domain_manifest_title ) {
		throw new HttpException( "Domain manifest title is inconsistent.", 400 );
	}

	//6942 is custom namespace. See namespace definition in extension.json.
	$tentativeTitle = Title::newFromText( $dm, 6942 );
	$page = new WikiPage( $tentativeTitle );
	$text = "\n<h1> Witness Event Publishing Data </h1>\n";
	$text .= "<p> This means, that the Witness Event Verification Hash has been written to a Witness Network and has been Timestamped.\n";

	$text .= "* Witness Event: " . $witness_event_id . "\n";
	$text .= "* Domain ID: " . $row->domain_id . "\n";
	// We don't include witness hash.
	$text .= "* Page Domain Manifest verification Hash: " . $row->domain_manifest_genesis_hash . "\n";
	$text .= "* Merkle Root: " . $row->merkle_root . "\n";
	$text .= "* Witness Event Verification Hash: " . $row->witness_event_verification_hash . "\n";
	$text .= "* Witness Network: " . $row->witness_network . "\n";
	$text .= "* Smart Contract Address: " . $row->smart_contract_address . "\n";
	$text .= "* Transaction Hash: " . $row->witness_event_transaction_hash . "\n";
	$text .= "* Sender Account Address: " . $row->sender_account_address . "\n";
	// We don't include source.

	$pageText = $page->getContent()->getText();
	// We create a new content using the old content, and append $text to it.
	$newContent = $pageText . $text;
	editPageContent( $page, $newContent, "Domain Manifest witnessed", $user );

	// Rename from tentative title to final title.
	$domainManifestVH = $row->domain_manifest_genesis_hash;
	$finalTitle = Title::newFromText( "DomainManifest:$domainManifestVH", 6942 );
	$mp = MediaWikiServices::getInstance()->getMovePageFactory()->newMovePage( $tentativeTitle, $finalTitle );
	$reason = "Changed from tentative title to final title";
	$createRedirect = false;
	$mp->move( $user, $reason, $createRedirect );
	$db->update(
		'witness_events',
		[ 'domain_manifest_title' => $finalTitle->getPrefixedText() ],
		[
			'domain_manifest_title' => $tentativeTitle->getPrefixedText(),
			'witness_event_id' => $witness_event_id,
		],
	);
}

class WriteStoreWitnessTxHandler extends SimpleHandler {

	/** @var PermissionManager */
	private $permissionManager;

	/** @var User */
	private $user;

	/**
	 * @param PermissionManager $permissionManager
	 */
	public function __construct(
		PermissionManager $permissionManager
	) {
		$this->permissionManager = $permissionManager;

		// @todo Inject this, when there is a good way to do that
		$this->user = RequestContext::getMain()->getUser();
	}

	/** @inheritDoc */
	public function run() {
		// Only user and sysop have the 'move' right. We choose this so that
		// the DataAccounting extension works as expected even when not run via
		// micro-PKC Docker. As in, it shouldn't depend on the configuration of
		// an external, separate repo.
		if ( !$this->permissionManager->userHasRight( $this->user, 'move' ) ) {
			throw new LocalizedHttpException(
				MessageValue::new( 'rest-permission-denied-revision' )->plaintextParams(
					'You are not allowed to use the REST API'
				),
				403
			);
		}

		$body = $this->getValidatedBody();
		$witness_event_id = $body['witness_event_id'];
		$account_address = $body['account_address'];
		$transaction_hash = $body['transaction_hash'];

		$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbw = $lb->getConnectionRef( DB_MASTER );

		$table = 'witness_events';

		$verification_hashes = selectToArray(
			$dbw,
			'witness_page',
			'revision_verification_hash',
			[ 'witness_event_id' => $witness_event_id ]
		);
		if ( count( $verification_hashes ) == 0 ) {
			throw new HttpException( "witness_event_id not found in the witness_page table.", 404 );
		}

		// If witness ID exists, don't write witness_id, if it does not
		// exist update with witness id as oldest witness event has biggest
		// value (proof of existence)
		foreach ( $verification_hashes as $vh ) {
			$row = $dbw->selectRow(
				'revision_verification',
				[ 'witness_event_id' ],
				[ 'verification_hash' => $vh ]
			);
			if ( $row->witness_event_id === null ) {
				$dbw->update(
					'revision_verification',
					[ 'witness_event_id' => $witness_event_id ],
					[ 'verification_hash' => $vh ]
				);
			}
		}

		// Generate the witness_hash
		$row = $dbw->selectRow(
			'witness_events',
			[ 'domain_manifest_genesis_hash', 'merkle_root', 'witness_network' ],
			[ 'witness_event_id' => $witness_event_id ]
		);
		if ( !$row ) {
			throw new HttpException( "witness_event_id not found in the witness_events table.", 404 );
		}
		$witness_hash = getHashSum(
			$row->domain_manifest_genesis_hash .
			$row->merkle_root .
			$row->witness_network .
			$transaction_hash
		);
		/** write data to database */
		// Write the witness_hash into the witness_events table
		$dbw->update(
			$table,
			[
				'sender_account_address' => $account_address,
				'witness_event_transaction_hash' => $transaction_hash,
				'source' => 'default',
				'witness_hash' => $witness_hash,
			],
			"witness_event_id = $witness_event_id"
		);

		// Patch witness data into domain manifest page.
		$dbw->update(
			'revision_verification',
			[
				'witness_event_id' => $witness_event_id,
			],
			[ "verification_hash" => $row->domain_manifest_genesis_hash ]
		);

		// Add receipt to the domain manifest
		addReceiptToDomainManifest( $this->user, $witness_event_id, $dbw );

		return true;
	}

	/** @inheritDoc */
	public function needsWriteAccess() {
		return true;
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
			'witness_event_id' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'account_address' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'transaction_hash' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		] );
	}
}
