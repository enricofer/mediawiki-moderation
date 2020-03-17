<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2020 Edward Chernenko.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 3 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
*/

/**
 * @file
 * Verifies that registering a new user account has consequences.
 */

use MediaWiki\Auth\AuthManager;
use MediaWiki\Moderation\ForgetAnonIdConsequence;
use MediaWiki\Moderation\GiveAnonChangesToNewUserConsequence;
use MediaWiki\Moderation\MockConsequenceManager;

require_once __DIR__ . "/ConsequenceTestTrait.php";

/**
 * @group Database
 * @group medium
 */
class CreatingNewUserHasConsequencesTest extends MediaWikiTestCase {
	use ConsequenceTestTrait;

	/** @var string[] */
	protected $tablesUsed = [ 'user' ];

	/**
	 * Test consequences when user who already made some edit creates an account.
	 * @covers MediaWiki\Moderation\GiveAnonChangesToNewUserConsequence
	 * @covers ModerationPreload::onLocalUserCreated
	 */
	public function testCreateAccountAfterEditing() {
		$username = 'Newly registered user ' . rand( 0, 100000 );
		$anonId = 'SampleAnonId' . rand( 0, 1000000 );

		$newPreloadId = '[' . $username;
		$oldPreloadId = ']' . $anonId;

		// This session key is always created when edit by anonymous user is queued for moderation,
		// see RememberAnonIdConsequence and related tests.
		$session = RequestContext::getMain()->getRequest()->getSession();
		$session->set( 'anon_id', $anonId );
		$session->persist();

		list( $scope, $manager ) = MockConsequenceManager::install();
		$this->createAccount( $username );

		$this->assertConsequencesEqual( [
			new GiveAnonChangesToNewUserConsequence(
				User::newFromName( $username ),
				$oldPreloadId,
				$newPreloadId
			),
			// Should also forget anon_id
			new ForgetAnonIdConsequence()
		], $manager->getConsequences() );
	}

	/**
	 * Test consequences when user who never edited before creates an account.
	 * @covers MediaWiki\Moderation\GiveAnonChangesToNewUserConsequence
	 * @covers ModerationPreload::onLocalUserCreated
	 */
	public function testCreateAccountWithNoPriorEdits() {
		list( $scope, $manager ) = MockConsequenceManager::install();
		$this->createAccount( 'Newly registered user ' . rand( 0, 100000 ) );
		$this->assertConsequencesEqual( [], $manager->getConsequences() );
	}

	/**
	 * Create account properly (via AuthManager), as real users would do.
	 * @param string $username
	 */
	private function createAccount( $username ) {
		$user = User::newFromName( $username, false );
		$status = AuthManager::singleton()->autoCreateUser(
			$user,
			AuthManager::AUTOCREATE_SOURCE_SESSION,
			false
		);
		$this->assertTrue( $status->isOK(),
			"CreateAccount failed: " . $status->getMessage()->plain() );
	}
}