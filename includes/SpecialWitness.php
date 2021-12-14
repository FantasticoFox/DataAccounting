<?php
/**
 * This Special Page is used to Generate Domain Manifests for Witness events
 * Input is the list of all verified pages with the latest revision id and verification hashes which are stored in the table page_list which are printed in section 1 of the Domain Manifest. This is used as input for generating and populating the table witness_merkle_tree.
 * The witness_merkle_tree is printed out on section 2 of the Domain Manifest.
 * Output is a Domain Manifest # as well as a redirect link to the SpecialPage:WitnessPublisher
 *
 * @file
 */

namespace DataAccounting;

use Exception;

use Config;
use HTMLForm;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionManager;
use PermissionsError;
use Rht\Merkle\FixedSizeTree;
use SpecialPage;
use Title;
use OutputPage;
use WikiPage;
use Wikimedia\Rdbms\DBConnRef;

require 'vendor/autoload.php';

require_once 'Util.php';
require_once 'ApiUtil.php';

//class HtmlContent extends TextContent {
//	protected function getHtml() {
//		return $this->getText();
//	}
//}

function shortenHash( $hash ) {
	return substr( $hash, 0, 6 ) . "..." . substr( $hash, -6, 6 );
}

function hrefifyHashW( $hash ) {
	return "<a href='" . $hash . "'>" . shortenHash( $hash ) . "</a>";
}

function wikilinkifyHash( $hash ) {
	$shortened = shortenHash( $hash );
	return "[http://$hash $shortened]";
}

function tree_pprint( $do_wikitext, $layers, $out = "", $prefix = "└─ ", $level = 0, $is_last = true ) {
	# The default prefix is for level 0
	$length = count( $layers );
	$idx = 1;
	foreach ( $layers as $key => $value ) {
		$is_last = $idx == $length;
		if ( $level == 0 ) {
			$out .= "Merkle root: " . $key . "\n";
		} else {
			$formatted_key = $do_wikitext ? wikilinkifyHash( $key ) : hrefifyHashW( $key );
			$glyph = $is_last ? "  └─ " : "  ├─ ";
			$out .= " " . $prefix . $glyph . $formatted_key . "\n";
		}
		if ( $value !== null ) {
			if ( $level == 0 ) {
				$new_prefix = "";
			} else {
				$new_prefix = $prefix . ( $is_last ? "   " : "  │" );
			}
			$out .= tree_pprint( $do_wikitext, $value, "", $new_prefix, $level + 1, $is_last );
		}
		$idx += 1;
	}
	return $out;
}

function storeMerkleTree( $dbw, $witness_event_id, $treeLayers, $depth = 0 ) {
	foreach ( $treeLayers as $parent => $children ) {
		if ( $children === null ) {
			continue;
		}
		$children_keys = array_keys( $children );
		$dbw->insert(
			"witness_merkle_tree",
			[
				"witness_event_id" => $witness_event_id,
				"depth" => $depth,
				"left_leaf" => $children_keys[0],
				"right_leaf" => ( count( $children_keys ) > 1 ) ? $children_keys[1] : null,
				"successor" => $parent,
			]
		);
		storeMerkleTree( $dbw, $witness_event_id, $children, $depth + 1 );
	}
}

class SpecialWitness extends SpecialPage {

	private PermissionManager $permManager;

	/**
	 * Initialize the special page.
	 */
	public function __construct(
		PermissionManager $permManager
	) {
		// A special page should at least have a name.
		// We do this by calling the parent class (the SpecialPage class)
		// constructor method with the name as first and only parameter.
		parent::__construct( 'Witness' );
		$this->permManager = $permManager;
	}

	/**
	 * Shows the page to the user.
	 *
	 * @param string|null $sub The subpage string argument (if any).
	 *
	 * @throws PermissionsError
	 */
	public function execute( $sub ) {
		$this->setHeaders();

		$user = $this->getUser();
		if ( !$this->permManager->userHasRight( $user, 'import' ) ) {
			throw new PermissionsError( 'import' );
		}

		$htmlForm = new HTMLForm( [], $this->getContext(), 'daDomainManifest' );
		$htmlForm->setSubmitText( 'Generate Domain Manifest' );
		$htmlForm->setSubmitCallback( [ $this, 'generateDomainManifest' ] );
		$htmlForm->show();
	}

	public function helperGenerateDomainManifestTable(
		DBConnRef $dbw,
		OutputPage $out,
		int $witness_event_id,
		string $output
	): array {
		// For the table
		$output .= <<<EOD

			{| class="wikitable"
			|-
			! Index
			! Page Title
			! Verification Hash
			! Revision

		EOD;

		$res = $dbw->select(
			'revision_verification',
			[ 'page_title', 'max(rev_id) as rev_id' ],
			[
				"page_title NOT LIKE 'Data Accounting:%'",
				"page_title NOT LIKE 'File:%'"
			],
			__METHOD__,
			[ 'GROUP BY' => 'page_title' ]
		);

		$tableIndexCount = 1;
		$verification_hashes = [];
		foreach ( $res as $row ) {
			$row3 = $dbw->selectRow(
				'revision_verification',
				[ 'verification_hash', 'domain_id' ],
				[ 'rev_id' => $row->rev_id ],
				__METHOD__,
			);

			$vhash = $row3->verification_hash;
			if ( $vhash === null ) {
				$title = $row->page_title;
				$out->addWikiTextAsContent(
					"Error: [[$title]] has an empty verification hash. This indicates a manipulation, database corruption, or a bug. Recover or delete page."
				);
				return false;
			}

			$dbw->insert(
				'witness_page',
				[
					'witness_event_id' => $witness_event_id,
					'domain_id' => $row3->domain_id,
					'page_title' => $row->page_title,
					'rev_id' => $row->rev_id,
					'revision_verification_hash' => $vhash,
				],
				""
			);

			array_push( $verification_hashes, $vhash );

			$revisionWikiLink = "[[Special:PermanentLink/{$row->rev_id}|See]]";
			$output .= "|-\n|" . $tableIndexCount . "\n| [[" . $row->page_title . "]]\n|" . wikilinkifyHash(
					$vhash
				) . "\n|" . $revisionWikiLink . "\n";
			$tableIndexCount++;
		}
		$output .= "|}\n";
		return [ true, $verification_hashes, $output ];
	}

	public function helperGenerateDomainManifestMerkleTree( array $verification_hashes ): array {
		$hasher = function ( $data ) {
			return hash( 'sha3-512', $data, false );
		};

		$tree = new FixedSizeTree( count( $verification_hashes ), $hasher, null, true );
		for ( $i = 0; $i < count( $verification_hashes ); $i++ ) {
			$tree->set( $i, $verification_hashes[$i] );
		}
		$treeLayers = $tree->getLayersAsObject();
		return $treeLayers;
	}

	public function helperMakeNewDomainManifestpage(
		DBConnRef $dbw,
		int $witness_event_id,
		array $treeLayers,
		string $output
	): array {
		//Generate the Domain Manifest as a new page
		//6942 is custom namespace. See namespace definition in extension.json.
		$title = Title::newFromText( "DomainManifest $witness_event_id", 6942 );
		$page = new WikiPage( $title );
		$merkleTreeHtmlText = '<br><pre>' . tree_pprint( false, $treeLayers ) . '</pre>';
		$merkleTreeWikitext = tree_pprint( true, $treeLayers );
		$pageContent = $output . '<br>' . $merkleTreeWikitext;
		editPageContent(
			$page,
			$pageContent,
			"Page created automatically by [[Special:Witness]]",
			$this->getUser()
		);

		//Get the freshly-generated Domain Manifest verification hash.
		$rowDMVH = $dbw->selectRow(
			'revision_verification',
			[ 'verification_hash' ],
			[ 'page_title' => $title ],
			__METHOD__,
		);
		if ( !$rowDMVH ) {
			throw new Exception( "Verification hash not found for $title" );
		}
		$domainManifestVH = $rowDMVH->verification_hash;

		return [ $title, $domainManifestVH, $merkleTreeHtmlText ];
	}

	public function helperMaybeInsertWitnessEvent(
		DBConnRef $dbw,
		string $domain_manifest_genesis_hash,
		int $witness_event_id,
		Title $title,
		string $merkle_root
	) {
		// Check if $witness_event_id is already present in the witness_events
		// table. If not, do insert.

		$row = $dbw->selectRow(
			'witness_events',
			[ 'witness_event_id' ],
			[ 'witness_event_id' => $witness_event_id ]
		);
		if ( !$row ) {
			$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'da' );
			$SmartContractAddress = $config->get( 'SmartContractAddress' );
			$WitnessNetwork = $config->get( 'WitnessNetwork' );
			// If witness_events table doesn't have it, then insert.
			$dbw->insert(
				'witness_events',
				[
					'witness_event_id' => $witness_event_id,
					'domain_id' => getDomainId(),
					'domain_manifest_title' => $title,
					'domain_manifest_genesis_hash' => $domain_manifest_genesis_hash,
					'merkle_root' => $merkle_root,
					'witness_event_verification_hash' => getHashSum(
						$domain_manifest_genesis_hash . $merkle_root
					),
					'smart_contract_address' => $SmartContractAddress,
					'witness_network' => $WitnessNetwork,
				],
				""
			);
		}
	}

	/**
	 * @see: HTMLForm::trySubmit
	 * @param array $formData
	 * @return bool|string|array|Status
	 *     - Bool true or a good Status object indicates success,
	 *     - Bool false indicates no submission was attempted,
	 *     - Anything else indicates failure. The value may be a fatal Status
	 *       object, an HTML string, or an array of arrays (message keys and
	 *       params) or strings (message keys)
	 */
	public function generateDomainManifest( array $formData ) {
		$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbw = $lb->getConnectionRef( DB_MASTER );

		$old_max_witness_event_id = getMaxWitnessEventId( $dbw );
		// Set to 0 if null.
		$old_max_witness_event_id = $old_max_witness_event_id === null ? 0 : $old_max_witness_event_id;
		$witness_event_id = $old_max_witness_event_id + 1;

		$output = 'This page is a summary of all verified pages within your domain and is used to generate a merkle tree to witness and timestamp them simultanously. Use the [[Special:WitnessPublisher | Domain Manifest Publisher]] to publish your generated Domain Manifest to your preffered witness network.' . '<br><br>';

		$out = $this->getOutput();
		[ $is_valid, $verification_hashes, $output ] = $this->helperGenerateDomainManifestTable(
			$dbw,
			$out,
			$witness_event_id,
			$output
		);
		if ( !$is_valid ) {
			// If there is a problem, we exit early.
			return false;
		}

		if ( empty( $verification_hashes ) ) {
			$out->addHTML( 'No verified page revisions available. Create a new page revision first.' );
			return true;
		}

		$treeLayers = $this->helperGenerateDomainManifestMerkleTree( $verification_hashes );

		$out->addWikiTextAsContent( $output );

		// Store the Merkle tree in the DB
		storeMerkleTree( $dbw, $witness_event_id, $treeLayers );

		//Generate the Domain Manifest as a new page
		[ $title, $domainManifestVH, $merkleTreeHtmlText ] = $this->helperMakeNewDomainManifestpage(
			$dbw,
			$witness_event_id,
			$treeLayers,
			$output
		);

		//Write results into the witness_events DB
		$merkle_root = array_keys( $treeLayers )[0];

		// Check if $witness_event_id is already present in the witness_events
		// table. If not, do insert.
		$this->helperMaybeInsertWitnessEvent( $dbw, $domainManifestVH, $witness_event_id, $title, $merkle_root );

		$out->addHTML( $merkleTreeHtmlText );
		$out->addWikiTextAsContent( "<br> Visit [[$title]] to see the Merkle proof." );
		return true;
	}

	protected function getGroupName(): string {
		return 'other';
	}

	public function getConfig(): Config {
		return MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'da' );
	}
}
