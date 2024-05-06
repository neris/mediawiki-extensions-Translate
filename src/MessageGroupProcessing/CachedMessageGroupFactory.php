<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\Translate\MessageGroupProcessing;

use CacheDependency;
use MessageGroup;

/**
 * Interface for message group factories that use caching.
 * @since 2024.05
 * @license GPL-2.0-or-later
 * @author Niklas Laxström
 */
interface CachedMessageGroupFactory {
	public function getCacheKey(): string;

	public function getCacheVersion(): int;

	/** @return CacheDependency[] */
	public function getDependencies(): array;

	/**
	 * @see WANObjectCache::getWithSetCallback()
	 * @return mixed
	 */
	public function getData( array &$setOpts );

	/**
	 * @param mixed $data Data returned by `getData()`
	 * @return MessageGroup[]
	 */
	public function createGroups( $data ): array;
}
