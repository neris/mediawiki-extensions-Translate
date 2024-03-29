<?php

namespace MediaWiki\Extension\Translate\TranslatorInterface\Aid;

use HashBagOStuff;
use MediaWiki\Extension\Translate\MessageGroupProcessing\MessageGroups;
use MediaWiki\Extension\Translate\Services;
use MediaWiki\HookContainer\HookContainer;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use MessageHandle;
use MockWikiMessageGroup;
use WANObjectCache;

/**
 * @group Database
 * @covers \MediaWiki\Extension\Translate\TranslatorInterface\Aid\TranslationAidDataProvider
 */
class TranslationAidDataProviderTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->setMwGlobals( [
			'wgTranslateMessageNamespaces' => [ NS_MEDIAWIKI ]
		] );
		$this->setTemporaryHook( 'TranslateInitGroupLoaders', HookContainer::NOOP );
		$this->setTemporaryHook( 'TranslatePostInitGroups', [ $this, 'getTestGroups' ] );

		$mg = MessageGroups::singleton();
		$mg->setCache( new WANObjectCache( [ 'cache' => new HashBagOStuff() ] ) );
		$mg->recache();
		$index = Services::getInstance()->getMessageIndex()->rebuild();
	}

	public function getTestGroups( &$list ) {
		$messages = [
			'TestPage' => 'bunny',
		];
		$list['test-group'] = new MockWikiMessageGroup( 'test-group', $messages );
		return false;
	}

	/** @covers \MediaWiki\Extension\Translate\TranslatorInterface\Aid\TranslationAidDataProvider::getGoodTranslations */
	public function testGetGoodTranslations() {
		$title = 'MediaWiki:TestPage';
		// Create some translations
		$this->assertTrue(
			$this->editPage( $title . '/fi', 'Test Finnish Translation' )->isGood(),
			'Sanity: must successfully edit ' . $title . '/fi page'
		);
		$this->assertTrue(
			$this->editPage( $title . '/ru', 'Test Russian Translation' )->isGood(),
			'Sanity: must successfully edit ' . $title . '/ru page'
		);

		$messageHandle = new MessageHandle( Title::newFromText( $title ) );
		$this->assertTrue( $messageHandle->isValid(), 'Sanity: MessageHandle must be valid' );
		$dataProvider = new TranslationAidDataProvider( $messageHandle );
		$this->assertEquals( [
			'ru' => 'Test Russian Translation',
			'fi' => 'Test Finnish Translation'
		], $dataProvider->getGoodTranslations() );
	}
}
