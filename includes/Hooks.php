<?php
/**
 * Hooks for Example extension.
 *
 * @file
 */

namespace DataAccounting;

use FormatJson;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\OutputPageParserOutputHook;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Hook\ParserGetVariableValueSwitchHook;
use MediaWiki\Hook\SkinTemplateNavigationHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Revision\RevisionRecord;
use MovePage;
use MWException;
use OutputPage;
use Parser;
use PPFrame;
use RequestContext;
use Skin;
use SkinTemplate;
use stdClass;
use Title;
use XMLReader;

require_once 'ApiUtil.php';

class Hooks implements
	BeforePageDisplayHook,
	ParserFirstCallInitHook,
	ParserGetVariableValueSwitchHook,
	SkinTemplateNavigationHook,
	OutputPageParserOutputHook
{

	private PermissionManager $permissionManager;

	public function __construct( PermissionManager $permissionManager ) {
		$this->permissionManager = $permissionManager;
	}

	/**
	 * Customisations to OutputPage right before page display.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/BeforePageDisplay
	 *
	 * @param OutputPage $out
	 * @param Skin $skin
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( $this->permissionManager->userCan( 'read', $out->getUser(), $out->getTitle() ) ) {
			global $wgExampleEnableWelcome;
			if ( $wgExampleEnableWelcome ) {
				// Load our module on all pages
				$out->addModules( 'ext.DataAccounting.signMessage' );
				$out->addModules( 'publishDomainManifest' );
			}
		}
	}

	public function onOutputPageParserOutput( $out, $parserOutput ): void {
		global $wgServer;
		$out->addMeta( "data-accounting-mediawiki", $wgServer );
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ParserGetVariableValueSwitch
	 *
	 * @param Parser $parser
	 * @param array &$cache
	 * @param string $magicWordId
	 * @param string &$ret
	 * @param PPFrame $frame
	 */
	public function onParserGetVariableValueSwitch( $parser, &$cache, $magicWordId, &$ret, $frame ) {
		if ( $magicWordId === 'myword' ) {
			// Return value and cache should match. Cache is used to save
			// additional call when it is used multiple times on a page.
			$ret = $cache['myword'] = wfEscapeWikiText( $GLOBALS['wgExampleMyWord'] );
		}
	}

	/**
	 * Register parser hooks.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ParserFirstCallInit
	 * @see https://www.mediawiki.org/wiki/Manual:Parser_functions
	 *
	 * @param Parser $parser
	 *
	 * @throws MWException
	 */
	public function onParserFirstCallInit( $parser ) {
		// Add the following to a wiki page to see how it works:
		// <dump>test</dump>
		// <dump foo="bar" baz="quux">test content</dump>
		$parser->setHook( 'dump', [ self::class, 'parserTagDump' ] );

		// Add the following to a wiki page to see how it works:
		// {{#echo: hello }}
		$parser->setFunctionHook( 'echo', [ self::class, 'parserFunctionEcho' ] );

		// Add the following to a wiki page to see how it works:
		// {{#showme: hello | hi | there }}
		$parser->setFunctionHook( 'showme', [ self::class, 'parserFunctionShowme' ] );
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/SkinTemplateNavigation
	 *
	 * @param SkinTemplate $skin
	 * @param array &$cactions
	 */
	public function onSkinTemplateNavigation( $skin, &$cactions ): void {
		$action = $skin->getRequest()->getText( 'action' );

		if ( $skin->getTitle()->getNamespace() !== NS_SPECIAL ) {
			if ( !$this->permissionManager->userCan( 'edit', $skin->getUser(), $skin->getTitle() ) ) {
				return;
			}
			$cactions['actions']['daact'] = [
				'class' => $action === 'daact' ? 'selected' : false,
				'text' => $skin->msg( 'contentaction-daact' )->text(),
				'href' => $skin->getTitle()->getLocalURL( 'action=daact' ),
			];
		}
	}

	/**
	 * Parser hook handler for <dump>
	 *
	 * @param string $data The content of the tag.
	 * @param array $attribs The attributes of the tag.
	 * @param Parser $parser Parser instance available to render
	 *  wikitext into html, or parser methods.
	 * @param PPFrame $frame Can be used to see what template
	 *  arguments ({{{1}}}) this hook was used with.
	 *
	 * @return string HTML to insert in the page.
	 */
	public static function parserTagDump( $data, $attribs, $parser, $frame ) {
		$dump = [
			'content' => $data,
			'atributes' => (object)$attribs,
		];
		// Very important to escape user data with htmlspecialchars() to prevent
		// an XSS security vulnerability.
		$html = '<pre>Dump Tag: '
			. htmlspecialchars( FormatJson::encode( $dump, /*prettyPrint=*/ true ) )
			. '</pre>';

		return $html;
	}

	/**
	 * Parser function handler for {{#echo: .. }}
	 *
	 * @param Parser $parser
	 * @param string $value
	 *
	 * @return string HTML to insert in the page.
	 */
	public static function parserFunctionEcho( Parser $parser, $value ) {
		return '<strong>Echo says: ' . htmlspecialchars( $value ) . '</strong>';
	}

	/**
	 * Parser function handler for {{#showme: .. | .. }}
	 *
	 * @param Parser $parser
	 * @param string $value
	 * @param string ...$args
	 *
	 * @return string HTML to insert in the page.
	 */
	public static function parserFunctionShowme( Parser $parser, string $value, ...$args ) {
		$showme = [
			'value' => $value,
			'arguments' => $args,
		];

		return '<pre>Showme Function: '
			. htmlspecialchars( FormatJson::encode( $showme, /*prettyPrint=*/ true ) )
			. '</pre>';
	}

	public static function onXmlDumpWriterOpenPage( \XmlDumpWriter $dumpWriter, string &$output, stdClass $page, \Title $title ): void {
		$output .= \Xml::element(
			'data_accounting_chain_height',
			[],
			(string)getPageChainHeight( $title->getText() )
		);
	}

	public static function onXmlDumpWriterWriteRevision( \XmlDumpWriter $dumpWriter, string &$output, stdClass $page, string $text, RevisionRecord $revision ): void {
		$xmlBuilder = new RevisionXmlBuilder(
			MediaWikiServices::getInstance()->getDBLoadBalancer()
		);

		$output .= $xmlBuilder->getPageMetadataByRevId( $revision->getId() );
	}

	public static function onImportHandlePageXMLTag( \WikiImporter $importer, array &$pageInfo ): bool {
		// This method is for verified importer.
		if ( $importer->getReader()->localName !== 'data_accounting_chain_height' ) {
			return true;
		}

		$own_chain_height = getPageChainHeight( $pageInfo['title'] );

		if ( $own_chain_height == 0 ) {
			return false;
		}

		$imported_chain_height = $importer->nodeContents();
		if ( $own_chain_height <= $imported_chain_height ) {
			// Move and rename own page
			// Rename the page that is about to be imported
			$now = date( 'Y-m-d-H-i-s', time() );
			$newTitle = $pageInfo['title'] . "_ChainHeight_{$own_chain_height}_$now";

			$ot = Title::newFromText( $pageInfo['title'] );
			$nt = Title::newFromText( $newTitle );
			$mp = new MovePage( $ot, $nt );

			$mp->moveIfAllowed(
				RequestContext::getMain()->getUser(),
				"Resolving naming collision because imported page has longer verified chain height.",
				false
			);
		}

		// This prevents continuing down the else-if statements in WikiImporter, which would reach `$tag != '#text'`
		return false;
	}

	public static function onImportHandleRevisionXMLTag( \WikiImporter $importer, array $pageInfo, array $revisionInfo ): bool {
		// This method is for verified importer.
		if ( $importer->getReader()->localName !== 'verification' ) {
			return true;
		}

		self::processVerification(
			self::handleVerification( $importer ),
			$pageInfo['_title']
		);

		return false;
	}

	private static function handleVerification( \WikiImporter $importer ): ?array {
		// This method is for verified importer.
		if ( $importer->getReader()->isEmptyElement ) {
			return null;
		}
		$verificationInfo = [];
		$normalFields = [
			'domain_id',
			'rev_id',
			'verification_hash',
			'time_stamp',
			'signature',
			'public_key',
			'wallet_address' ];
		while ( $importer->getReader()->read() ) {
			if ( $importer->getReader()->nodeType == XMLReader::END_ELEMENT &&
				$importer->getReader()->localName == 'verification' ) {
				break;
			}

			$tag = $importer->getReader()->localName;

			if ( in_array( $tag, $normalFields ) ) {
				$verificationInfo[$tag] = $importer->nodeContents();
			} elseif ( $tag == 'witness' ) {
				$verificationInfo[$tag] = self::handleWitness( $importer );
			}
		}

		return $verificationInfo;
	}

	private static function handleWitness( \WikiImporter $importer ): ?array {
		// This method is for verified importer.
		if ( $importer->getReader()->isEmptyElement ) {
			return null;
		}

		$witnessInfo = [];
		$normalFields = [
			"domain_id",
			"witness_event_verification_hash",
			"witness_network",
			"smart_contract_address",
			"domain_manifest_verification_hash",
			"merkle_root",
			"structured_merkle_proof",
			"witness_event_transaction_hash",
			"sender_account_address"
		];
		while ( $importer->getReader()->read() ) {
			if ( $importer->getReader()->nodeType == XMLReader::END_ELEMENT &&
				$importer->getReader()->localName == 'witness' ) {
				break;
			}
			$tag = $importer->getReader()->localName;
			if ( in_array( $tag, $normalFields ) ) {
				$witnessInfo[$tag] = $importer->nodeContents();
			}
		}
		return $witnessInfo;
	}

	private static function processVerification( ?array $verificationInfo, string $title ) {
		// This method is for verified importer.
		$table = 'revision_verification';
		$lb = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$dbw = $lb->getConnectionRef( DB_MASTER );

		if ( $verificationInfo !== null ) {
			$verificationInfo['page_title'] = $title;
			$verificationInfo['source'] = 'imported';
			unset( $verificationInfo["rev_id"] );

			$res = $dbw->select(
				$table,
				[ 'revision_verification_id', 'rev_id', 'page_title', 'source' ],
				[ 'page_title' => $title ],
				__METHOD__,
				[ 'ORDER BY' => 'revision_verification_id' ]
			);
			$last_row = [];
			foreach ( $res as $row ) {
				$last_row = $row;
			}
			if ( empty( $last_row ) ) {
				// Do nothing if empty
				return;
			}

			// Witness-specific
			if ( isset( $verificationInfo['witness'] ) ) {
				$witnessInfo = $verificationInfo['witness'];
				$structured_merkle_proof = json_decode( $witnessInfo['structured_merkle_proof'], true );
				unset( $witnessInfo['structured_merkle_proof'] );

				//Check if witness_event_verification_hash is already present,
				//if so skip import into witness_events

				$rowWitness = $dbw->selectRow(
					'witness_events',
					[ 'witness_event_id', 'witness_event_verification_hash' ],
					[ 'witness_event_verification_hash' => $witnessInfo['witness_event_verification_hash'] ]
				);
				if ( !$rowWitness ) {
					$witnessInfo['source'] = 'imported';
					$witnessInfo['domain_manifest_title'] = 'N/A';
					$dbw->insert(
						'witness_events',
						$witnessInfo,
					);
					$local_witness_event_id = getMaxWitnessEventId( $dbw );
					if ( $local_witness_event_id === null ) {
						$local_witness_event_id = 1;
					}
				} else {
					$local_witness_event_id = $rowWitness->witness_event_id;
				}

				// Patch revision_verification table to use the local version of
				// witness_event_id instead of from the foreign version.
				$dbw->update(
					'revision_verification',
					[ 'witness_event_id' => $local_witness_event_id ],
					[ 'revision_verification_id' => $last_row->revision_verification_id ],
				);

				// Check if merkle tree proof is present, if so skip, if not
				// import AND attribute to the correct witness_id
				$revision_verification_hash = $verificationInfo['verification_hash'];

				$rowProof = $dbw->selectRow(
					'witness_merkle_tree',
					[ 'witness_event_id' ],
					[
						'left_leaf=\'' . $revision_verification_hash . '\'' .
						' OR right_leaf=\'' . $revision_verification_hash . '\''
					]
				);

				if ( !$rowProof ) {
					$latest_witness_event_id = $dbw->selectRow(
						'witness_events',
						[ 'max(witness_event_id) as witness_event_id' ],
						''
					)->witness_event_id;

					foreach ( $structured_merkle_proof as $row ) {
						$row["witness_event_id"] = $latest_witness_event_id;
						$dbw->insert(
							'witness_merkle_tree',
							$row,
						);
					}
				}

				// This unset is important, otherwise the dbw->update for
				// revision_verification accidentally includes witness.
				unset( $verificationInfo["witness"] );
			}
			// End of witness-specific

			$dbw->update(
				$table,
				$verificationInfo,
				[ 'revision_verification_id' => $last_row->revision_verification_id ],
				__METHOD__
			);
		} else {
			$dbw->delete(
				$table,
				[ 'page_title' => $title ]
			);
		}
	}

}
