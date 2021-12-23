<?php

namespace DataAccounting\Verification;

use MediaWiki\Revision\RevisionRecord;

class Hasher {
	public function calculateWitnessHash(
		string $domain_manifest_genesis_hash,
		string $merkle_root,
		string $witness_network,
		string $witness_tx_hash
	): string {
		return $this->getHashSum(
			$domain_manifest_genesis_hash . $merkle_root . $witness_network . $witness_tx_hash
		);
	}

	public function calculateVerificationHash(
		string $contentHash,
		string $metadataHash,
		string $signature_hash,
		string $witness_hash
	): string {
		return $this->getHashSum( $contentHash . $metadataHash . $signature_hash . $witness_hash );
	}

	/**
	 * @param string $input
	 * @return string
	 */
	public function getHashSum( $input ): string {
		if ( $input == '' ) {
			return '';
		}
		return hash( "sha3-512", $input, false );
	}

	/**
	 * @return string
	 */
	public function generateDomainId(): string {
		$domainIdFull = $this->generateRandomHash();
		return substr( $domainIdFull, 0, 10 );
	}

	/**
	 * @return string
	 */
	private function generateRandomHash(): string {
		// Returns a hash sum (calculated using getHashSum) of n characters.
		$randomval = '';
		for ( $i = 0; $i < 128; $i++ ) {
			$randomval .= chr( rand( 65, 90 ) );
		}
		return $this->getHashSum( $randomval );
	}

	/**
	 * @param RevisionRecord $rev
	 * @return string
	 */
	public function calculateContentHash( RevisionRecord $rev ) {
		$pageContent = '';
		foreach ( $rev->getSlots()->getSlotRoles() as $slot ) {
			$pageContent .= $rev->getContent( $slot )->serialize();
		}
		return $this->getHashSum( $pageContent );
	}
}