<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2015-2018 Edward Chernenko.

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
 * @brief Verifies that modaction=approve(all) works as expected.
 */

require_once __DIR__ . "/framework/ModerationTestsuite.php";

/**
 * @covers ModerationActionApprove
 */
class ModerationApproveTest extends MediaWikiTestCase {
	public function testApprove() {
		$t = new ModerationTestsuite();

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();
		$t->fetchSpecial();

		$entry = $t->new_entries[0];
		$this->assertNotNull( $entry->approveLink,
			"testApprove(): Approve link not found" );

		$rev = $this->tryToApprove( $t, $entry, __FUNCTION__ );
		$t->fetchSpecial();

		$this->assertCount( 0, $t->new_entries,
			"testApprove(): Something was added into Pending folder during modaction=approve" );
		$this->assertCount( 1, $t->deleted_entries,
			"testApprove(): One edit was approved, but number of deleted entries ".
			"in Pending folder isn't 1" );
		$this->assertEquals( $entry->id, $t->deleted_entries[0]->id );
		$this->assertEquals( $t->lastEdit['User'], $t->deleted_entries[0]->user );
		$this->assertEquals( $t->lastEdit['Title'], $t->deleted_entries[0]->title );

		# Check the log entry
		$events = $t->apiLogEntries();
		$this->assertCount( 1, $events,
			"testApprove(): Number of log entries isn't 1." );
		$le = $events[0];

		$this->assertEquals( 'approve', $le['action'],
			"testApprove(): Most recent log entry is not 'approve'" );
		$this->assertEquals( $t->lastEdit['Title'], $le['title'] );
		$this->assertEquals( $t->moderator->getName(), $le['user'] );
		$this->assertEquals( $rev['revid'], $le['params']['revid'] );

		$events = $t->nonApiLogEntries( 1 );
		$this->assertEquals( 'approve', $events[0]['type'] );

		$this->assertEquals( $t->moderator->getName(),
			$events[0]['params'][1] );
		$this->assertEquals( $t->lastEdit['Title'],
			$events[0]['params'][2] );
		$this->assertEquals( "(moderation-log-diff: " . $rev['revid'] . ")",
			$events[0]['params'][3] );
	}

	public function testApproveAll() {
		$t = new ModerationTestsuite();

		# We edit with two users:
		#	$t->unprivilegedUser (A)
		#	and $t->unprivilegedUser2 (B)
		# We're applying approveall to one of the edits by A.
		# Expected result is:
		# 1) All edits by A were approved,
		# 2) No edits by B were touched during approveall.

		$t->doNTestEditsWith( $t->unprivilegedUser, $t->unprivilegedUser2 );
		$t->fetchSpecial();

		# Find edits by user A (they will be approved)
		$entries = ModerationTestsuiteEntry::findByUser(
			$t->new_entries,
			$t->unprivilegedUser
		);
		$this->assertNotNull( $entries[0]->approveAllLink,
			"testApproveAll(): ApproveAll link not found" );

		$t->html->loadFromURL( $entries[0]->approveAllLink );
		$this->assertRegExp( '/\(moderation-approved-ok: ' . $t->TEST_EDITS_COUNT . '\)/',
			$t->html->getMainText(),
			"testApproveAll(): Result page doesn't contain (moderation-approved-ok: N)" );

		$t->fetchSpecial();
		$this->assertCount( 0, $t->new_entries,
			"testApproveAll(): Something was added into Pending folder during modaction=approveall" );
		$this->assertCount( $t->TEST_EDITS_COUNT, $t->deleted_entries,
			"testApproveAll(): Several edits were approved, but number of deleted entries " .
			"in Pending folder doesn't match" );

		foreach ( $entries as $entry ) {
			$rev = $t->getLastRevision( $entry->title );
			$this->assertEquals( $t->unprivilegedUser->getName(), $rev['user'] );
		}

		# Check the log entries: there should be
		# - one 'approveall' log entry
		# - TEST_EDITS_COUNT 'approve' log entries.

		$events = $t->apiLogEntries();
		$this->assertCount( 1 + $t->TEST_EDITS_COUNT, $events,
			"testApproveAll(): Number of log entries doesn't match the number of " .
			"approved edits PLUS ONE (log entry for ApproveAll itself)." );

		# Per design, 'approveall' entry MUST be the most recent.
		$le = array_shift( $events );
		$this->assertEquals( 'approveall', $le['action'],
			"testApproveAll(): Most recent log entry is not 'approveall'" );
		$this->assertEquals( $t->moderator->getName(), $le['user'] );
		$this->assertEquals( $t->unprivilegedUser->getUserPage(), $le['title'] );

		foreach ( $events as $le ) {
			$this->assertEquals( 'approve', $le['action'] );
			$this->assertEquals( $t->moderator->getName(), $le['user'] );
		}

		# Only the formatting of 'approveall' line needs to be checked,
		# formatting of 'approve' lines already tested in testApprove()
		$events = $t->nonApiLogEntries( 1 );
		$this->assertEquals( 'approveall', $events[0]['type'] );

		$this->assertEquals( $t->moderator->getName(),
			$events[0]['params'][1] );
		$this->assertEquals( $t->unprivilegedUser->getUserPage()->getText(),
			$events[0]['params'][2] );
		$this->assertEquals( $t->TEST_EDITS_COUNT, $events[0]['params'][3] );
	}

	public function testApproveAllNotRejected() {
		$t = new ModerationTestsuite();

		$t->TEST_EDITS_COUNT = 10;
		$t->doNTestEditsWith( $t->unprivilegedUser );
		$t->fetchSpecial();

		# Already rejected edits must not be affected by ApproveAll.
		# So let's reject some edits and check...

		$approveAllLink = $t->new_entries[0]->approveAllLink;

		# Odd edits are rejected, even edits are approved.
		for ( $i = 1; $i < $t->TEST_EDITS_COUNT; $i += 2 ) {
			$t->httpGet( $t->new_entries[$i]->rejectLink );
		}

		$t->fetchSpecial( 'rejected' );
		$t->httpGet( $approveAllLink );
		$t->fetchSpecial( 'rejected' );

		$this->assertCount( 0, $t->new_entries,
			"testApproveAllNotRejected(): Something was added into Rejected folder " .
			"during modaction=approveall" );
		$this->assertCount( 0, $t->deleted_entries,
			"testApproveAllNotRejected(): Something was deleted from Rejected folder " .
			"during modaction=approveall" );
	}

	public function testApproveRejected() {
		$t = new ModerationTestsuite();

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();
		$t->fetchSpecial();

		$t->httpGet( $t->new_entries[0]->rejectLink );
		$t->fetchSpecial( 'rejected' );

		$entry = $t->new_entries[0];
		$this->assertNotNull( $entry->approveLink,
			"testApproveRejected(): Approve link not found" );
		$this->tryToApprove( $t, $entry, __FUNCTION__ );

		$t->fetchSpecial( 'rejected' );

		$this->assertCount( 0, $t->new_entries,
			"testApproveRejected(): Something was added into Rejected folder during modaction=approve" );
		$this->assertCount( 1, $t->deleted_entries,
			"testApproveRejected(): One rejected edit was approved, " .
			"but number of deleted entries in Rejected folder isn't 1" );
		$this->assertEquals( $entry->id, $t->deleted_entries[0]->id );
		$this->assertEquals( $t->lastEdit['User'], $t->deleted_entries[0]->user );
		$this->assertEquals( $t->lastEdit['Title'], $t->deleted_entries[0]->title );
	}

	public function testApproveNotExpiredRejected() {
		global $wgModerationTimeToOverrideRejection;
		$t = new ModerationTestsuite();

		# Rejected edits can only be approved if they are no older
		# than $wgModerationTimeToOverrideRejection.

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();
		$t->fetchSpecial();

		$entry = $t->new_entries[0];
		$t->httpGet( $entry->rejectLink );

		/* Modify mod_timestamp to make this edit 1 hour older than
			allowed by $wgModerationTimeToOverrideRejection. */

		$ts = new MWTimestamp( time() );
		$ts->timestamp->modify( '-' . intval( $wgModerationTimeToOverrideRejection ) . ' seconds' );
		$ts->timestamp->modify( '-1 hour' ); /* Should NOT be approvable */

		$entry->updateDbRow( [ 'mod_timestamp' => $ts->getTimestamp( TS_MW ) ] );

		$t->fetchSpecial( 'rejected' );

		$entry = $t->new_entries[0];
		$this->assertNull( $entry->approveLink,
			"testApproveNotExpiredRejected(): Approve link found for edit that was rejected " .
			"more than $wgModerationTimeToOverrideRejection seconds ago" );

		# Ensure that usual approve URL doesn't work:
		$error = $t->html->getModerationError( $entry->expectedActionLink( 'approve' ) );
		$this->assertEquals( '(moderation-rejected-long-ago)', $error,
			"testApproveNotExpiredRejected(): No expected error from modaction=approve" );

		/* Make the edit less ancient
			than $wgModerationTimeToOverrideRejection ago */

		$ts->timestamp->modify( '+2 hour' ); /* Should be approvable */
		$entry->updateDbRow( [ 'mod_timestamp' => $ts->getTimestamp( TS_MW ) ] );

		$t->assumeFolderIsEmpty( 'rejected' );
		$t->fetchSpecial( 'rejected' );

		$entry = $t->new_entries[0];
		$this->assertNotNull( $entry->approveLink,
			"testApproveNotExpiredRejected(): Approve link is missing for edit that was " .
			"rejected less than $wgModerationTimeToOverrideRejection seconds ago" );

		$this->tryToApprove( $t, $entry, __FUNCTION__ );
	}

	public function testApproveTimestamp() {
		$t = new ModerationTestsuite();
		$entry = $t->getSampleEntry();

		$TEST_TIME_CHANGE = '6 hours';
		$ACCEPTABLE_DIFFERENCE = 300; # in seconds

		$ts = new MWTimestamp( time() );
		$ts->timestamp->modify( '-' . $TEST_TIME_CHANGE );

		$entry->updateDbRow( [ 'mod_timestamp' => $ts->getTimestamp( TS_MW ) ] );
		$rev = $this->tryToApprove( $t, $entry, __FUNCTION__ );

		# Page history should mention the time when edit was made,
		# not when it was approved.

		$expected = $ts->getTimestamp( TS_ISO_8601 );
		$this->assertEquals( $expected, $rev['timestamp'],
			"testApproveTimestamp(): approved edit has incorrect timestamp in the page history" );

		# RecentChanges should mention the time when the edit was
		# approved, so that it won't "appear in the past", confusing
		# those who read RecentChanges.

		$ret = $t->query( [
			'action' => 'query',
			'list' => 'recentchanges',
			'rcprop' => 'timestamp',
			'rclimit' => 1,
			'rcuser' => $t->lastEdit['User']
		] );
		$rc_timestamp = $ret['query']['recentchanges'][0]['timestamp'];

		$this->assertNotEquals( $expected, $rc_timestamp,
			"testApproveTimestamp(): approved edit has \"appeared in the past\" in the RecentChanges" );

		# Does the time in RecentChanges match the time of approval?
		#
		# NOTE: we don't know the time of approval to the second, so
		# string comparison can't be used. Difference can be seconds
		# or even minutes (if system time is off).
		$ts->timestamp->modify( '+' . $TEST_TIME_CHANGE );
		$expected = $ts->getTimestamp( TS_UNIX );

		$ts_actual = new MWTimestamp( $rc_timestamp );
		$actual = $ts_actual->getTimestamp( TS_UNIX );

		$this->assertLessThan( $ACCEPTABLE_DIFFERENCE, abs( $expected - $actual ),
			"testApproveTimestamp(): timestamp of approved edit in RecentChanges is " .
			"too different from the time of approval" );
	}

	public function testApproveAllTimestamp() {
		/*
			Check that rev_timestamp and rc_ip are properly modified by modaction=approveall.
		*/
		$testPages = [
			'Page 16' => [
				'timestamp' => '20100101001600',
				'ip' => '127.0.0.16'
			],
			'Page 14' => [
				'timestamp' => '20100101001400',
				'ip' => '127.0.0.14'
			],
			'Page 12' => [
				'timestamp' => '20100101001200',
				'ip' => '127.0.0.12'
			]
		];

		$t = new ModerationTestsuite();

		$t->loginAs( $t->unprivilegedUser );

		foreach ( $testPages as $title => $task ) {
			$t->doTestEdit( $title );
		}

		$t->fetchSpecial();
		foreach ( $t->new_entries as $entry ) {
			$task = $testPages[$entry->title];
			$entry->updateDbRow( [
				'mod_timestamp' => $task['timestamp'],
				'mod_ip' => $task['ip']
			] );
		}

		$t->httpGet( $t->new_entries[0]->approveAllLink );

		# Check rev_timestamp/rc_ip.

		$dbw = wfGetDB( DB_MASTER );

		foreach ( $testPages as $title => $task ) {
			$row = $dbw->selectRow(
				[ 'page', 'revision', 'recentchanges' ],
				[
					'rev_timestamp',
					'rc_ip'
				],
				Title::newFromText( $title )->pageCond(),
				__METHOD__,
				[],
				[
					'revision' => [ 'INNER JOIN', [
						'rev_id=page_latest'
					] ],
					'recentchanges' => [ 'INNER JOIN', [
						'rc_this_oldid=page_latest'
					] ]
				]
			);

			$this->assertEquals( $task['timestamp'], $row->rev_timestamp,
				"testApproveAllTimestamp(): approved edit has incorrect timestamp in the page history" );

			$this->assertEquals( $task['ip'], $row->rc_ip,
				"testApproveAllTimestamp(): approved edit has incorrect IP in recentchanges" );
		}
	}

	/**
	 * @brief Test that approval still works if author of edit was deleted
		(e.g. via [maintenance/removeUnusedAccounts.php]).
	*/
	public function testApproveDeletedUser() {
		$t = new ModerationTestsuite();

		$t->loginAs( $t->unprivilegedUser );
		$t->doTestEdit();

		# Delete the author
		$dbw = wfGetDB( DB_MASTER );
		$dbw->delete( 'user', [
			'user_id' => $t->unprivilegedUser->getId()
		], __METHOD__ );
		$t->unprivilegedUser->invalidateCache();

		$t->fetchSpecial();

		$entry = $t->new_entries[0];
		$this->assertNotNull( $entry->approveLink,
			"testApproveDeletedUser(): Approve link not found" );

		$rev = $this->tryToApprove( $t, $entry, __FUNCTION__ );
	}

	private function tryToApprove( ModerationTestsuite $t, ModerationTestsuiteEntry $entry, $caller ) {
		$t->html->loadFromURL( $entry->approveLink );
		$this->assertRegExp( '/\(moderation-approved-ok: 1\)/',
			$t->html->getMainText(),
			"$caller(): Result page doesn't contain (moderation-approved-ok: 1)" );

		$rev = $t->getLastRevision( $entry->title );

		$this->assertEquals( $t->lastEdit['User'], $rev['user'] );
		$this->assertEquals( $t->lastEdit['Text'], $rev['*'] );
		$this->assertEquals( $t->lastEdit['Summary'], $rev['comment'] );

		return $rev;
	}
}
