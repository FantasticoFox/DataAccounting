<?php

use DataAccounting\VerifiedWikiImporterFactory;
use MediaWiki\MediaWikiServices;

// This file is needed for verified importer.
// If not needed anymore, delete ServiceWiringFiles in extension.json.

return [
	'VerifiedWikiImporterFactory' => static function ( MediaWikiServices $services ): VerifiedWikiImporterFactory {
		return new VerifiedWikiImporterFactory(
			$services->getconfigFactory()->makeConfig( 'da' ),
			$services->getHookContainer(),
			$services->getContentLanguage(),
			$services->getNamespaceInfo(),
			$services->getTitleFactory(),
			$services->getWikiPageFactory(),
			$services->getWikiRevisionUploadImporter(),
			$services->getPermissionManager(),
			$services->getContentHandlerFactory(),
			$services->getSlotRoleRegistry()
		);
	}
];
