<?php

namespace DataAccounting\Util;

use DataAccounting\Verification\VerificationEngine;
use DataAccounting\Verification\Entity\VerificationEntity;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Storage\SlotRecord;
use MWException;
use ParserOutput;
use Title;
use TitleFactory;

class TransclusionHashExtractor {
	/** @var string */
	private $rawText;
	/** @var LinkTarget */
	private $subject;
	/** @var ParserOutput */
	private $parserOutput;
	/** @var TitleFactory */
	private $titleFactory;
	/** @var VerificationEngine */
	private $verifcationEngine;
	/** @var array|null */
	private $hashMap = null;

	/**
	 * @param string $rawText
	 * @param LinkTarget $subject
	 * @param ParserOutput $po
	 * @param TitleFactory $titleFactory
	 * @param VerificationEngine $verificationEngine
	 */
	public function __construct(
		string $rawText, LinkTarget $subject, ParserOutput $po,
		TitleFactory $titleFactory, VerificationEngine $verificationEngine
	) {
		$this->rawText = $rawText;
		$this->subject = $subject;
		$this->parserOutput = $po;
		$this->titleFactory = $titleFactory;
		$this->verifcationEngine = $verificationEngine;
	}

	/**
	 * @return array
	 */
	public function getHashmap(): array {
		if ( $this->hashMap === null ) {
			$this->parsePageResources();
		}
		return $this->hashMap;
	}

	/**
	 * @throws MWException
	 */
	private function parsePageResources() {
		$this->hashMap = [];

		$titles = [];
		$this->parseImages( $titles );
		$this->parseTemplates( $titles );
		// This is not necessary since it does not change content,
		// but we might need it in the future
		$this->parseLinks( $titles );
		$this->retrieveHashes( $titles );
	}

	/**
	 * @param array $titles
	 */
	private function parseImages( array &$titles ) {
		foreach ( $this->parserOutput->getImages() as $name => $const ) {
			$title = $this->titleFactory->makeTitle( NS_FILE, $name );
			if ( $title->equals( $this->subject ) ) {
				continue;
			}
			$titles[$title->getPrefixedDBkey()] = $title;
		}
	}

	/**
	 * @param array $titles
	 */
	private function parseTemplates( array &$titles ) {
		// We do this elaborate parsing instead of just calling `ParserOutput::getTemplates`
		// because we only want direct transclusions, instead of transclusions of transclusions.
		// Every page handles only its own transclusions
		$templates = [];
		$pp = new \Preprocessor_Hash( MediaWikiServices::getInstance()->getParserFactory()->create() );
		$hashTree = $pp->preprocessToObj( $this->rawText );
		for ( $node = $hashTree->getFirstChild(); $node; $node = $node->getNextSibling() ) {
			if ( !( $node instanceof \PPNode_Hash_Attr ) && $node->getName() === 'template' ) {
				for ( $templateNode = $node->getFirstChild(); $templateNode; $templateNode = $templateNode->getNextSibling() ) {
					if ( !( $templateNode instanceof \PPNode_Hash_Attr ) && $templateNode->getName() === 'title' ) {
						$templates[] = $templateNode->getRawChildren()[0];
					}
				}
			}
		}

		$templates = array_map( function( $templatePage ) {
			$templatePage = trim( preg_replace( '/\s\s+/', ' ', $templatePage ) );
			if ( strpos( $templatePage, ':' ) === 0 ) {
				return $this->titleFactory->newFromText( trim( $templatePage, ':' ) );
			}

			if ( strpos( $templatePage, ':' ) !== false ) {
				$title = $this->titleFactory->newFromText( $templatePage );
				if ( $title->getNamespace() !== NS_MAIN ) {
					// If name has a colon in the name, it cannot be NS_MAIN. If it is, its invalid NS
					return $title;
				}
			}

			return $this->titleFactory->makeTitle( NS_TEMPLATE, $templatePage );
		}, $templates );

		$templates = array_filter( $templates, function( $templateTitle ) {
			return $templateTitle instanceof Title;
		} );

		foreach ( $templates as $title ) {
			$titles[$title->getPrefixedDBkey()] = $title;
		}
	}

	/**
	 * @param array $titles
	 */
	private function parseLinks( array &$titles ) {
		$this->parseNested( $this->parserOutput->getLinks(), $titles );
	}

	/**
	 * @param array $data
	 * @param array $titles
	 */
	private function parseNested( array $data, array &$titles ) {
		foreach ( $data as $ns => $links ) {
			foreach ( $links as $name => $id ) {
				$title = $this->titleFactory->makeTitle( $ns, $name );
				if ( $title->equals( $this->subject ) ) {
					continue;
				}
				$titles[$title->getPrefixedDBkey()] = $title;
			}
		}
	}

	/**
	 * TODO: Move this to TransclusionManager
	 * @param array $titles
	 * @throws MWException
	 */
	private function retrieveHashes( array $titles ) {
		/**
		 * @var string $dbKey
		 * @var Title $title
		 */
		foreach ( $titles as $dbKey => $title ) {
			$transclusion = [
				'dbkey' => $title->getDBkey(),
				'ns' => $title->getNamespace(),
				VerificationEntity::VERIFICATION_HASH => null,
			];
			if ( $title->exists() ) {
				$entity = $this->verifcationEngine->getLookup()
					->verificationEntityFromRevId( $title->getLatestRevID() );
				if ( $entity ) {
					$transclusion[VerificationEntity::VERIFICATION_HASH] =
						$entity->getHash( VerificationEntity::VERIFICATION_HASH );
				}
			}

			$this->hashMap[] = $transclusion;
		}
	}
}
