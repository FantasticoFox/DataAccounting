<?php

namespace DataAccounting\API;

use MediaWiki\Rest\HttpException;
use Wikimedia\ParamValidator\ParamValidator;
use Title;

use function DataAccounting\get_page_all_revs as get_page_all_revs;

require_once __DIR__ . "/../ApiUtil.php";

class GetPageAllRevsHandler extends ContextAuthorized {

	/** @inheritDoc */
	public function run( string $page_title ) {
		# Expects Page Title and return all of its verified revision ids.
		$output = get_page_all_revs( $page_title );
		if ( count( $output ) == 0 ) {
			throw new HttpException( "$page_title not found in the database", 404 );
		}
		return $output;
	}

	/** @inheritDoc */
	public function getParamSettings() {
		return [
			'page_title' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	/** @inheritDoc */
	protected function provideTitle( string $pageName ): ?Title {
		return $this->titleFactory->newFromText( $pageName );
	}
}
