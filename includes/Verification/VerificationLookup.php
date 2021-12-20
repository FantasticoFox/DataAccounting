<?php

namespace DataAccounting\Verification;

use Exception;
use MediaWiki\Storage\RevisionStore;
use Title;
use Wikimedia\Rdbms\ILoadBalancer;

class VerificationLookup {
	private const TABLE = 'revision_verification';

	/** @var ILoadBalancer */
	private $lb;
	/** @var RevisionStore */
	private $revisionStore;
	/** @var VerificationEntityFactory */
	private $verificationEntityFactory;

	/**
	 * @param ILoadBalancer $loadBalancer
	 * @param RevisionStore $revisionStore
	 * @param VerificationEntityFactory $entityFactory
	 */
	public function __construct(
		ILoadBalancer $loadBalancer, RevisionStore $revisionStore, VerificationEntityFactory $entityFactory
	) {
		$this->lb = $loadBalancer;
		$this->revisionStore = $revisionStore;
		$this->verificationEntityFactory = $entityFactory;
	}

	/**
	 * Gets the verification entity from verification hash
	 *
	 * @param string $hash Verification hash
	 * @return VerificationEntity|null
	 */
	public function verificationEntityFromHash( string $hash ): ?VerificationEntity {
		return $this->verificationEntityFromQuery( [ VerificationEntity::VERIFICATION_HASH => $hash ] );
	}

	/**
	 * @param int $revId
	 * @return VerificationEntity|null
	 */
	public function verificationEntityFromRevId( int $revId ): ?VerificationEntity {
		return $this->verificationEntityFromQuery( [ 'rev_id' => $revId ] );
	}

	/**
	 * Gets the latest verification entry for the given title
	 *
	 * @param Title $title
	 * @return VerificationEntity|null
	 */
	public function verificationEntityFromTitle( Title $title ): ?VerificationEntity {
		if ( !$title->exists() ) {
			return null;
		}
		// TODO: Replace with getPrefixedDBkey, once database enties use it
		return $this->verificationEntityFromQuery( [ 'page_title' => $title->getPrefixedText() ] );
	}

	/**
	 * Get VerificationEntity based on a custom query
	 * Caller must ensure query resolves to a single entity,
	 * otherwise the latest of the set will be retrieved
	 *
	 * @param array $query
	 * @return VerificationEntity|null
	 */
	public function verificationEntityFromQuery( array $query ): ?VerificationEntity {
		$res = $this->lb->getConnection( DB_REPLICA )->selectRow(
			static::TABLE,
			[ '*' ],
			$query,
			__METHOD__,
			[ 'ORDER BY' => [ 'rev_id DESC' ] ],
		);

		if ( !$res ) {
			return null;
		}

		return $this->verificationEntityFactory->newFromDbRow( $res );
	}

	/**
	 * @param string|Title $title
	 * @return array
	 */
	public function getAllRevisionIds( $title ): array {
		if ( $title instanceof Title ) {
			// TODO: Replace with getPrefixedDBkey, once database enties use it
			$title = $title->getPrefixedText();
		}
		$res = $this->lb->getConnection( DB_REPLICA )->select(
			'revision_verification',
			[ 'rev_id' ],
			[ 'page_title' => $title ],
			__METHOD__,
			[ 'ORDER BY' => 'rev_id' ]
		);

		$output = [];
		foreach ( $res as $row ) {
			$output[] = (int)$row->rev_id;
		}
		return $output;
	}

	/**
	 * Get all hashes that are same or newer than given entity
	 *
	 * @param VerificationEntity $verificationEntity
	 * @param string $type
	 * @return array
	 * @throws Exception
	 */
	public function newerHashesForEntity( VerificationEntity $verificationEntity, string $type ) {
		$this->assertVerificationHashValid( $type );
		$res = $this->lb->getConnection( DB_REPLICA )->select(
			static::TABLE,
			[ $type ],
			[
				'rev_id >= ' . $verificationEntity->getRevision()->getId(),
				VerificationEntity::GENESIS_HASH =>
					$verificationEntity->getHash( VerificationEntity::GENESIS_HASH ),
			],
			__METHOD__,
			[ 'ORDER BY' => 'rev_id' ]
		);

		$hashes = [];
		foreach ( $res as $row ) {
			$hashes[] = $row->$type;
		}

		return $hashes;
	}

	/**
	 * @param string $type
	 * @throws Exception
	 */
	private function assertVerificationHashValid( string $type ) {
		if ( !$this->verificationEntityFactory->isValidHashType( $type ) ) {
			throw new Exception( "Hash type \"$type\" is not valid" );
		}
	}
}
