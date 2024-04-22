<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\Translate;

use DatabaseUpdater;
use MediaWiki\Extension\Translate\Diagnostics\SyncTranslatableBundleStatusMaintenanceScript;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaHookHandler implements LoadExtensionSchemaUpdatesHook {

	/** @param DatabaseUpdater $updater */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dir = dirname( __DIR__, 1 ) . '/sql';
		$dbType = $updater->getDB()->getType();

		if ( $dbType === 'mysql' || $dbType === 'sqlite' ) {
			$updater->addExtensionTable(
				'translate_sections',
				"{$dir}/{$dbType}/translate_sections.sql"
			);
			$updater->addExtensionTable(
				'revtag',
				"{$dir}/{$dbType}/revtag.sql"
			);
			$updater->addExtensionTable(
				'translate_groupstats',
				"{$dir}/{$dbType}/translate_groupstats.sql"
			);
			$updater->addExtensionTable(
				'translate_reviews',
				"{$dir}/{$dbType}/translate_reviews.sql"
			);
			$updater->addExtensionTable(
				'translate_groupreviews',
				"{$dir}/{$dbType}/translate_groupreviews.sql"
			);
			$updater->addExtensionTable(
				'translate_tms',
				"{$dir}/{$dbType}/translate_tm.sql"
			);
			$updater->addExtensionTable(
				'translate_metadata',
				"{$dir}/{$dbType}/translate_metadata.sql"
			);
			$updater->addExtensionTable(
				'translate_messageindex',
				"{$dir}/{$dbType}/translate_messageindex.sql"
			);
			$updater->addExtensionTable(
				'translate_stash',
				"{$dir}/{$dbType}/translate_stash.sql"
			);
			$updater->addExtensionTable(
				'translate_translatable_bundles',
				"{$dir}/{$dbType}/translate_translatable_bundles.sql"
			);
			$updater->addExtensionTable(
				'translate_message_group_subscriptions',
				"{$dir}/{$dbType}/translate_message_group_subscriptions.sql"
			);

			$updater->addExtensionTable(
				'translate_cache',
				"{$dir}/{$dbType}/translate_cache.sql"
			);

			if ( $dbType === 'mysql' ) {
				// 1.38
				$updater->modifyExtensionField(
					'translate_cache',
					'tc_key',
					"{$dir}/{$dbType}/translate_cache-alter-varbinary.sql"
				);
			}
		} elseif ( $dbType === 'postgres' ) {
			$updater->addExtensionTable(
				'translate_sections',
				"{$dir}/{$dbType}/tables-generated.sql"
			);
			$updater->addExtensionUpdate( [
				'changeField', 'translate_cache', 'tc_exptime', 'TIMESTAMPTZ', 'th_timestamp::timestamp with time zone'
			] );
		}

		// 1.39
		$updater->dropExtensionIndex(
			'translate_messageindex',
			'tmi_key',
			"{$dir}/{$dbType}/patch-translate_messageindex-unique-to-pk.sql"
		);
		$updater->dropExtensionIndex(
			'translate_tmt',
			'tms_sid_lang',
			"{$dir}/{$dbType}/patch-translate_tmt-unique-to-pk.sql"
		);
		$updater->dropExtensionIndex(
			'revtag',
			'rt_type_page_revision',
			"{$dir}/{$dbType}/patch-revtag-unique-to-pk.sql"
		);

		$updater->addPostDatabaseUpdateMaintenance( SyncTranslatableBundleStatusMaintenanceScript::class );
	}
}
