<?php
/**
 * Behaviral description of SpecialPage:WitnessPublisher
 * The SpecialPage prints out the database witness_events in decending order (starting with the latest witness event). If a Domain Manifest was not yet published, the page should hold a publish button instead of the empty field for 'page_witness_transaction_hash'. In this list it is easy to see how many Witness Events have taken place and which receipts in the form of the domain_manifests have been generated and whats their page link (they should be clickable to be redireted to the respective Domain Manifest).
 */

namespace DataAccounting;

use Config;
use DataAccounting\Verification\VerificationEngine;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionManager;
use PermissionsError;
use SpecialPage;
use Wikimedia\Rdbms\LoadBalancer;

class SpecialWitnessPublisher extends SpecialPage {

	private PermissionManager $permManager;

	private LoadBalancer $lb;

	private VerificationEngine $verificationEngine;

	/**
	 * Initialize the special page.
	 */
	public function __construct( PermissionManager $permManager, LoadBalancer $lb,
		VerificationEngine $verificationEngine ) {
		// A special page should at least have a name.
		// We do this by calling the parent class (the SpecialPage class)
		// constructor method with the name as first and only parameter.
		parent::__construct( 'WitnessPublisher' );
		$this->permManager = $permManager;
		$this->lb = $lb;
		$this->verificationEngine = $verificationEngine;
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

		$this->getOutput()->setPageTitle( 'Domain Manifest Publisher' );

		$dbw = $this->lb->getConnectionRef( DB_PRIMARY );

		$res = $dbw->select(
			'witness_events',
			[ 'witness_event_id, domain_id, domain_manifest_title, witness_event_verification_hash, witness_network, smart_contract_address, witness_event_transaction_hash, sender_account_address' ],
			'',
			__METHOD__,
			[ 'ORDER BY' => ' witness_event_id DESC' ]
		);

		$output = 'The Domain Manifest Publisher shows you a list of all Domain Manifests. You can publish a Domain Manifest from here to the Ethereum Network to timestamp all page verification hashes and the related page revisions included in the manifest. After publishing the manifest, this will be written into the revision_verification data and included into the page history.<br><br>';
		$out = $this->getOutput();
		$out->addHTML( $output );

		$output = '<table class="wikitable">';
		$output .= <<<EOD
            <tr>
                <th>Witness Event</th>
                <th>Domain ID</th>
                <th>Domain Manifest</th>
                <th>Verification Hash</th>
                <th>Witness Network</th>
                <th>Transaction ID</th>
            </tr>
        EOD;

		$WitnessNetwork = $this->getConfig()->get( 'WitnessNetwork' );
		$witnessNetworkMap = [
			'mainnet' => 'https://etherscan.io/tx/',
			'ropsten' => 'https://ropsten.etherscan.io/tx/',
			'kovan' => 'https://kovan.etherscan.io/tx/',
			'rinkeby' => 'https://rinkeby.etherscan.io/tx/',
			'goerli' => 'https://goerli.etherscan.io/tx/',
		];

		foreach ( $res as $row ) {
			$hrefWEVH = $this->hrefifyHash( $row->witness_event_verification_hash );
			// Color taken from https://www.schemecolor.com/warm-autumn-2.php
			// #B33030 is Chinese Orange
			// #B1C97F is Sage

			if ( $this->verificationEngine->getDomainId() != $row->domain_id ) {
				$publishingStatus = '<td style="background-color:#DDDDDD">Imported</td>';
			} else {
				if ( $row->witness_event_transaction_hash == 'PUBLISH WITNESS HASH TO BLOCKCHAIN TO POPULATE' ) {
					$publishingStatus = '<td style="background-color:#F27049"><button type="button" class="publish-domain-manifest" id="' . $row->witness_event_id . '">Publish!</button></td>';
				} else {
					$publishingStatus = '<td style="background-color:#B1C97F">' . $this->hrefifyHash(
							$row->witness_event_transaction_hash,
							$witnessNetworkMap[$WitnessNetwork]
						) . '</td>';
				}
			}

			if ( $row->domain_manifest_title === null ) {
				$linkedDomainManifest = 'N/A';
			} else {
				$linkedDomainManifest = '<a href=\'/index.php/' . $row->domain_manifest_title . '\'>' . $this->shortenDomainManifestTitle(
						$row->domain_manifest_title
					) . '</a>';
			}

			$output .= <<<EOD
                <tr>
                    <td>{$row->witness_event_id}</td>
                    <td>{$row->domain_id}</td>
                    <td>{$linkedDomainManifest}</td>
                    <td>$hrefWEVH</td>
                    <td>{$row->witness_network}</td>
                    $publishingStatus
                </tr>
            EOD;
		}
		$output .= '</table>';
		$out->addHTML( $output );
	}

	/** @inheritDoc */
	protected function getGroupName(): string {
		return 'other';
	}

	public function getConfig(): Config {
		return MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'da' );
	}

	private function shortenHash( string $hash ): string {
		return substr( $hash, 0, 6 ) . "..." . substr( $hash, -6, 6 );
	}

	private function hrefifyHash( string $hash, string $prefix = "" ): string {
		// TODO this function is duplicated in SpecialWitness
		return "<a href='" . $prefix . $hash . "'>" . $this->shortenHash( $hash ) . "</a>";
	}

	private function shortenDomainManifestTitle( string $dm ): string {
		// TODO we are hardcoding the name space here. Fix!
		// 6942
		$withoutNameSpace = str_replace( "Data Accounting:", "", $dm );
		$hashOnly = str_replace( "DomainManifest:", "", $withoutNameSpace );
		return "DomainManifest:" . $this->shortenHash( $hashOnly );
	}
}
