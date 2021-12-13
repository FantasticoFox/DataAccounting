<?php

declare( strict_types = 1 );

namespace DataAccounting;

use DOMDocument;
use SimpleXMLElement;
use Wikimedia\Rdbms\ILoadBalancer;

class RevisionXmlBuilder {
	private ILoadBalancer $loadBalancer;

	public function __construct(
		ILoadBalancer $loadBalancer
	) {
		$this->loadBalancer = $loadBalancer;
	}

	public function getPageMetadataByRevId( int $revId ): string {
		// This is based on the case of 'verify_page' API call in StandardRestApi.php.
		$row = $this->loadBalancer->getConnectionRef( DB_REPLICA )->selectRow(
			'revision_verification',
			[
				'domain_id',
				'genesis_hash',
				'rev_id',
				'verification_hash',
				'time_stamp',
				'witness_event_id',
				'signature',
				'public_key',
				'wallet_address'
			],
			[ 'rev_id' => $revId ],
			__METHOD__
		);

		if ( !$row ) {
			return '';
		}

		$output = [
			'domain_id' => $row->domain_id,
			'genesis_hash' => $row->genesis_hash,
			'rev_id' => $revId,
			'verification_hash' => $row->verification_hash,
			'time_stamp' => $row->time_stamp,
			'witness_event_id' => $row->witness_event_id,
			'signature' => $row->signature,
			'public_key' => $row->public_key,
			'wallet_address' => $row->wallet_address,
		];

		// Convert the $output array to XML string
		$xmlString = $this->convertArray2XMLString( $output, "<verification/>" );

		//Inject <witness> data in case witness id is present
		if ( $output['witness_event_id'] !== null ) {
			$wdXmlString = $this->getPageWitnessData(
				$output['witness_event_id'],
				$output['verification_hash'],
			);
			$xmlString = str_replace( '</verification>', "\n", $xmlString ) . $wdXmlString . "\n</verification>";
		}

		return $xmlString;
	}

	private function getPageWitnessData( $witness_event_id, $revision_verification_hash ) {
		$witness_data = getWitnessData( $witness_event_id );
		if ( empty( $witness_data ) ) {
			return '';
		}
		$structured_merkle_proof = json_encode( requestMerkleProof( $witness_event_id, $revision_verification_hash ) );
		$witness_data["structured_merkle_proof"] = $structured_merkle_proof;
		$xmlString = $this->convertArray2XMLString( $witness_data, "<witness/>" );
		return $xmlString;
	}

	private function convertArray2XMLString( $arr, $tag ) {
		$xml = new SimpleXMLElement( $tag );
		$filtered = array_filter( $arr );
		foreach ( $filtered as $key => $value ) {
			$xml->addChild( $key, (string)$value );
		}
		// We have to do these steps to ensure there are proper newlines in the XML
		// string.
		$dom = new DOMDocument();
		$dom->loadXML( $xml->asXML() );
		$dom->formatOutput = true;
		$xmlString = $dom->saveXML();
		// Remove the first line which has 'xml version="1.0"'
		$xmlString = preg_replace( '/^.+\n/', '', $xmlString );
		return $xmlString;
	}

}
