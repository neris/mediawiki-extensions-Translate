<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\Translate\Synchronization;

use ContentHandler;
use FileBasedMessageGroup;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\TextContent;
use MediaWiki\Extension\Translate\Jobs\GenericTranslateJob;
use MediaWiki\Extension\Translate\MessageGroupProcessing\MessageGroups;
use MediaWiki\Extension\Translate\MessageGroupProcessing\RevTagStore;
use MediaWiki\Extension\Translate\MessageLoading\MessageHandle;
use MediaWiki\Extension\Translate\MessageProcessing\TranslateReplaceTitle;
use MediaWiki\Extension\Translate\Services;
use MediaWiki\Extension\Translate\SystemUsers\FuzzyBot;
use MediaWiki\Extension\Translate\Utilities\Utilities;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Storage\PageUpdater;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use RecentChange;

/**
 * Job for updating translation pages when translation or message definition changes.
 *
 * @author Niklas Laxström
 * @copyright Copyright © 2008-2013, Niklas Laxström
 * @license GPL-2.0-or-later
 * @ingroup JobQueue
 */
class UpdateMessageJob extends GenericTranslateJob {
	private User $fuzzyBot;

	/** Create a normal message update job without a rename process */
	public static function newJob(
		Title $target, string $content, bool $fuzzy = false
	): self {
		$params = [
			'content' => $content,
			'fuzzy' => $fuzzy,
		];

		return new self( $target, $params );
	}

	/**
	 * Create a message update job containing a rename process
	 * @param Title $target
	 * @param string $targetStr
	 * @param string $replacement
	 * @param string|false $fuzzy
	 * @param string $content
	 * @param array $otherLangContents
	 * @return self
	 */
	public static function newRenameJob(
		Title $target,
		string $targetStr,
		string $replacement,
		$fuzzy,
		string $content,
		array $otherLangContents = []
	): self {
		$params = [
			'target' => $targetStr,
			'replacement' => $replacement,
			'fuzzy' => $fuzzy,
			'rename' => 'rename',
			'content' => $content,
			'otherLangs' => $otherLangContents
		];

		return new self( $target, $params );
	}

	public function __construct( Title $title, array $params = [] ) {
		parent::__construct( 'UpdateMessageJob', $title, $params );
	}

	public function run(): bool {
		$params = $this->params;
		$isRename = $params['rename'] ?? false;
		$isFuzzy = $params['fuzzy'] ?? false;
		$otherLangs = $params['otherLangs'] ?? [];
		$originalTitle = Title::newFromLinkTarget( $this->title->getTitleValue(), Title::NEW_CLONE );

		if ( $isRename ) {
			$this->title = $this->handleRename( $params['target'], $params['replacement'] );
			if ( $this->title === null ) {
				// There was a failure, return true, but don't proceed further.
				$this->logWarning(
					'Rename process could not find the source title.',
					[
						'replacement' => $params['replacement'],
						'target' => $params['target']
					]
				);

				$this->removeFromCache( $originalTitle );
				return true;
			}
		}
		$title = $this->title;
		$updater = $this->fuzzyBotEdit( $title, $params['content'] );
		if ( !$updater->getStatus()->isOK() ) {
			$this->logError(
				'Failed to update content for source message',
				[
					'content' => ContentHandler::makeContent( $params['content'], $this->title ),
					'errors' => $updater->getStatus()->getMessages()
				]
			);
		}

		if ( $isRename ) {
			// Update other language content if present.
			$this->processTranslationChanges( $otherLangs, $params['replacement'], $params['namespace'] );
		}

		$this->handleFuzzy( $title, $isFuzzy, $updater );

		$this->removeFromCache( $originalTitle );
		return true;
	}

	private function handleRename( string $target, string $replacement ): ?Title {
		$newSourceTitle = null;

		$sourceMessageHandle = new MessageHandle( $this->title );
		$movableTitles = TranslateReplaceTitle::getTitlesForMove( $sourceMessageHandle, $replacement );

		if ( $movableTitles === [] ) {
			$this->logError(
				'No movable titles found with target text.',
				[
					'title' => $this->title->getPrefixedText(),
					'replacement' => $replacement,
					'target' => $target
				]
			);
			return null;
		}

		$renameSummary = wfMessage( 'translate-manage-import-rename-summary' )
			->inContentLanguage()->plain();

		foreach ( $movableTitles as [ $sourceTitle, $replacementTitle ] ) {
			$mv = MediaWikiServices::getInstance()
				->getMovePageFactory()
				->newMovePage( $sourceTitle, $replacementTitle );

			$status = $mv->move( $this->getFuzzyBot(), $renameSummary, false );
			if ( !$status->isOK() ) {
				$this->logError(
					'Error moving message',
					[
						'target' => $sourceTitle->getPrefixedText(),
						'replacement' => $replacementTitle->getPrefixedText(),
						'errors' => $status->getMessages()
					]
				);
			}

			[ , $targetCode ] = Utilities::figureMessage( $replacementTitle->getText() );
			if ( !$newSourceTitle && $sourceMessageHandle->getCode() === $targetCode ) {
				$newSourceTitle = $replacementTitle;
			}
		}

		if ( $newSourceTitle ) {
			return $newSourceTitle;
		} else {
			// This means that the old source Title was never moved
			// which is not possible but handle it.
			$this->logError(
				'Source title was not in the list of movable titles.',
				[ 'title' => $this->title->getPrefixedText() ]
			);
			return null;
		}
	}

	/**
	 * Handles fuzzying. Message documentation and the source language are excluded from
	 * fuzzying. The source language is the identified via the $title parameter
	 *
	 * If the edit to the source translation unit is a manual revert, then any translations
	 * whose tp:transver is set to the revision being reverted to are marked **unfuzzy**
	 * unless they have an explicit !!FUZZY!! or fail validation.
	 *
	 * Any revisions with other tp:transvers are marked fuzzy, unless invalidation skipping is used.
	 */
	private function handleFuzzy( Title $title, bool $invalidate, PageUpdater $updater ): void {
		global $wgTranslateDocumentationLanguageCode;
		$editResult = $updater->getEditResult();
		if ( !$invalidate && !$editResult->isExactRevert() ) {
			return;
		}
		$oldRevId = $editResult->getOriginalRevisionId();
		$handle = new MessageHandle( $title );

		$languages = Utilities::getLanguageNames( 'en' );

		// Don't fuzzy the message documentation or the source language
		unset( $languages[$wgTranslateDocumentationLanguageCode] );
		unset( $languages[$handle->getCode()] );

		$languages = array_keys( $languages );

		$fuzzies = [];
		$unfuzzies = [];
		$mwInstance = MediaWikiServices::getInstance();
		$revTagStore = Services::getInstance()->getRevTagStore();
		$revStore = $mwInstance->getRevisionStore();

		if ( $oldRevId || $invalidate ) {
			// We'll need to check if each possible tunit exists later on, so do that now
			// as a batch
			$batch = $mwInstance->getLinkBatchFactory()->newLinkBatch();
			$batch->setCaller( __METHOD__ );
			foreach ( $languages as $code ) {
				$batch->addObj( $handle->getTitleForLanguage( $code ) );
			}
			$batch->execute();
		}
		$targetSha = $updater->getNewRevision()->getSha1();

		foreach ( $languages as $code ) {
			$otherTitle = $handle->getTitleForLanguage( $code );
			$shouldUnfuzzy = false;
			if ( $oldRevId && $otherTitle->exists() ) {
				$transver = $revTagStore->getTransver( $otherTitle );
				if ( $oldRevId == $transver ) {
					// It's a straightforward revert
					$shouldUnfuzzy = true;
				} elseif ( $transver ) {
					$transverSha = $revStore->getRevisionById( $transver, 0, $title )->getSha1();
					if ( $transverSha == $targetSha ) {
						// It's a deeper revert or otherwise wasn't detected by MediaWiki's builtin revert detection
						$shouldUnfuzzy = true;
					} // Else it's not a revert at all so leave shouldUnfuzzy false
				} // Else the page doesn't have a transver set for some reason so bail and leave shouldUnfuzzy false
			}
			if ( $shouldUnfuzzy ) {
				// In principle it's a revert so should unfuzzy, first check for validation failures
				// or manual fuzzying
				$otherHandle = new MessageHandle( $otherTitle );
				$wikiPage = $mwInstance->getWikiPageFactory()->newFromTitle( $otherTitle );
				$content = $wikiPage->getContent();
				if ( !$content instanceof TextContent ) {
					// This should never happen (translation units should always be wikitext) but Phan complains
					// otherwise
					continue;
				}
				$text = $content->getText();
				if ( $otherHandle->isFuzzy() && !$otherHandle->needsFuzzy( $text ) ) {
					$unfuzzies[] = $otherTitle;
				}
				// If it's not already fuzzy then that means the original change was done without invalidating
				// translations and while the new change probably should have been done that way as well
				// even if it wasn't it never makes sense to re-fuzzy in that case so just leave the fuzzy status alone
			} elseif ( $invalidate ) {
				$fuzzies[] = $otherTitle;
			}
		}

		$dbw = $mwInstance->getDBLoadBalancer()->getMaintenanceConnectionRef( DB_PRIMARY );

		if ( $fuzzies !== [] ) {
			$inserts = [];
			foreach ( $fuzzies as $otherTitle ) {
				$inserts[] = [
					'rt_type' => RevTagStore::FUZZY_TAG,
					'rt_page' => $otherTitle->getId(),
					'rt_revision' => $otherTitle->getLatestRevID(),
				];
			}
			$dbw->replace(
				'revtag',
				[ [ 'rt_type', 'rt_page', 'rt_revision' ] ],
				$inserts,
				__METHOD__
			);
		}
		if ( $unfuzzies !== [] ) {
			foreach ( $unfuzzies as $otherTitle ) {
				$dbw->delete( 'revtag', [
					'rt_type' => RevTagStore::FUZZY_TAG,
					'rt_page' => $otherTitle->getId(),
					'rt_revision' => $otherTitle->getLatestRevID(),
				], __METHOD__ );
			}
		}
	}

	/** Updates the translation unit pages in non-source languages. */
	private function processTranslationChanges(
		array $langChanges,
		string $baseTitle,
		int $groupNamespace
	): void {
		foreach ( $langChanges as $code => $contentStr ) {
			$titleStr = Utilities::title( $baseTitle, $code, $groupNamespace );
			$title = Title::newFromText( $titleStr, $groupNamespace );
			$updater = $this->fuzzyBotEdit( $title, $contentStr );

			if ( !$updater->getStatus()->isOK() ) {
				$this->logError(
					'Failed to update content for non-source message',
					[
						'title' => $title->getPrefixedText(),
						'errors' => $updater->getStatus()->getMessages()
					]
				);
			}
		}
	}

	private function removeFromCache( Title $title ): void {
		$config = MediaWikiServices::getInstance()->getMainConfig();

		if ( !$config->get( 'TranslateGroupSynchronizationCache' ) ) {
			return;
		}

		$currentTitle = $title;
		// Check if the current title, is equal to the title passed. This condition will be
		// true in case of rename where the old title would have been renamed.
		if ( $this->title && $this->title->getPrefixedDBkey() !== $title->getPrefixedDBkey() ) {
			$currentTitle = $this->title;
		}

		$sourceMessageHandle = new MessageHandle( $currentTitle );
		$groupIds = $sourceMessageHandle->getGroupIds();
		if ( !$groupIds ) {
			$this->logWarning(
				"Could not find group Id for message title: {$currentTitle->getPrefixedDBkey()}",
				$this->getParams()
			);
			return;
		}

		$groupId = $groupIds[0];
		$group = MessageGroups::getGroup( $groupId );

		if ( !$group instanceof FileBasedMessageGroup ) {
			return;
		}

		$groupSyncCache = Services::getInstance()->getGroupSynchronizationCache();
		$messageKey = $title->getPrefixedDBkey();

		if ( $groupSyncCache->isMessageBeingProcessed( $groupId, $messageKey ) ) {
			$groupSyncCache->removeMessages( $groupId, $messageKey );
			$groupSyncCache->extendGroupExpiryTime( $groupId );
		} else {
			$this->logWarning(
				"Did not find key: $messageKey; in group: $groupId in group sync cache",
				$this->getParams()
			);
		}
	}

	private function fuzzyBotEdit( Title $title, string $content ): PageUpdater {
		$wikiPageFactory = MediaWikiServices::getInstance()->getWikiPageFactory();
		$content = ContentHandler::makeContent( $content, $title );
		$page = $wikiPageFactory->newFromTitle( $title );
		$updater = $page->newPageUpdater( $this->getFuzzyBot() )
			->setContent( SlotRecord::MAIN, $content );

		if ( $this->getFuzzyBot()->authorizeWrite( 'autopatrol', $title ) ) {
			$updater->setRcPatrolStatus( RecentChange::PRC_AUTOPATROLLED );
		}

		$summary = wfMessage( 'translate-manage-import-summary' )
			->inContentLanguage()->plain();
		$updater->saveRevision(
			CommentStoreComment::newUnsavedComment( $summary ),
			EDIT_FORCE_BOT
		);
		return $updater;
	}

	private function getFuzzyBot(): User {
		$this->fuzzyBot ??= FuzzyBot::getUser();
		return $this->fuzzyBot;
	}
}
