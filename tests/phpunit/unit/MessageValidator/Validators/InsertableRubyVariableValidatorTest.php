<?php
/**
 * @file
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

use MediaWiki\Extensions\Translate\MessageValidator\Validators\InsertableRubyVariableValidator;

/** @covers \MediaWiki\Extensions\Translate\MessageValidator\Validators\InsertableRubyVariableValidator */
class InsertableRubyValidatorTest extends BaseValidatorTestCase {

	/** @dataProvider provideTestCases */
	public function test( ...$params ) {
		$this->runValidatorTests( new InsertableRubyVariableValidator(), 'variable', ...$params );
	}

	public function provideTestCases() {
		yield [
			'Test variable - %{ruby} %{ruby2}',
			'%{hello} - Testing translation',
			[ 'missing', 'unknown' ],
			'should return proper notices for missing and non-matching variables.'
		];

		yield [
			'Testing variables - %{ruby} %{php}',
			'Another testing - %{ruby} %{ruby2}',
			[ 'missing' ],
			'should see a notice set when parameter names don\'t match.'
		];

		yield [
			'Testing variables - %{ruby} %{php}',
			'Another testing - %{ruby} %{php}',
			[],
			'should not set any notice for a valid message.'
		];
	}
}