<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\Translate\TtmServer;

use TTMServer;

/**
 * NO-OP readable version of TTMServer when it is disabled.
 * @ingroup TTMServer
 */
class FakeReadableTtmServer extends TTMServer implements ReadableTtmServer {
	public function query( string $sourceLanguage, string $targetLanguage, string $text ): array {
		return [];
	}

	public function isLocalSuggestion( array $suggestion ): bool {
		return false;
	}

	public function expandLocation( array $suggestion ): string {
		return '';
	}
}
