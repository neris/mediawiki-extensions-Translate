<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\Translate\Statistics;

use MediaWiki\Extension\Translate\Utilities\Utilities;
use Wikimedia\Rdbms\IDatabase;

/**
 * Graph which provides statistics on number of reviews and reviewers.
 * @ingroup Stats
 * @license GPL-2.0-or-later
 * @since 2012.03
 */
class ReviewPerLanguageStats extends TranslatePerLanguageStats {
	public function preQuery(
		IDatabase $database,
		&$tables,
		&$fields,
		&$conds,
		&$type,
		&$options,
		&$joins,
		$start,
		$end
	) {
		global $wgTranslateMessageNamespaces;

		$tables = [ 'logging' ];
		$fields = [ 'log_timestamp' ];
		$joins = [];

		$conds = [
			'log_namespace' => $wgTranslateMessageNamespaces,
			'log_action' => 'message',
		];

		$timeConds = self::makeTimeCondition( $database, 'log_timestamp', $start, $end );
		$conds = array_merge( $conds, $timeConds );

		$options = [ 'ORDER BY' => 'log_timestamp' ];

		$this->groups = $this->opts->getGroups();

		$namespaces = self::namespacesFromGroups( $this->groups );
		if ( count( $namespaces ) ) {
			$conds['log_namespace'] = $namespaces;
		}

		$languages = [];
		foreach ( $this->opts->getLanguages() as $code ) {
			$languages[] = 'log_title ' . $database->buildLike( $database->anyString(), "/$code" );
		}
		if ( count( $languages ) ) {
			$conds[] = $database->makeList( $languages, LIST_OR );
		}

		$fields[] = 'log_title';

		if ( $this->groups ) {
			$fields[] = 'log_namespace';
		}

		if ( $this->opts->getValue( 'count' ) === 'reviewers' ) {
			$fields[] = 'log_actor';
		}

		$type .= '-reviews';
	}

	public function indexOf( $row ) {
		if ( $this->opts->getValue( 'count' ) === 'reviewers' ) {
			$date = $this->formatTimestamp( $row->log_timestamp );

			if ( isset( $this->seenUsers[$date][$row->log_actor] ) ) {
				return false;
			}

			$this->seenUsers[$date][$row->log_actor] = 1;
		}

		// Do not consider language-less pages.
		if ( !str_contains( $row->log_title, '/' ) ) {
			return false;
		}

		// No filters, just one key to track.
		if ( !$this->groups && !$this->opts->getLanguages() ) {
			return [ 'all' ];
		}

		// The key-building needs to be in sync with ::labels().
		[ $key, $code ] = Utilities::figureMessage( $row->log_title );

		$groups = [];
		$codes = [];

		if ( $this->groups ) {
			/* Get list of keys that the message belongs to, and filter
			 * out those which are not requested. */
			$groups = $this->messageIndex->getGroupIdsForDatabaseTitle( (int)$row->log_namespace, $key );
			$groups = array_intersect( $this->groups, $groups );
		}

		if ( $this->opts->getLanguages() ) {
			$codes = [ $code ];
		}

		return $this->combineTwoArrays( $groups, $codes );
	}

	public function labels() {
		return $this->combineTwoArrays( $this->groups, $this->opts->getLanguages() );
	}

	public function getTimestamp( $row ) {
		return $row->log_timestamp;
	}
}
