<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018-2021 Edward Chernenko.

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
 * Methods to manage "moderation" SQL table.
 */

use MediaWiki\MediaWikiServices;
use MediaWiki\Moderation\Hook\HookRunner;
use MediaWiki\Moderation\IConsequenceManager;
use MediaWiki\Moderation\InsertRowIntoModerationTableConsequence;
use MediaWiki\Moderation\PendingEdit;
use MediaWiki\Moderation\SendNotificationEmailConsequence;
use MediaWiki\Revision\RevisionRecord;

class ModerationNewChange {
	public const MOD_TYPE_EDIT = 'edit';
	public const MOD_TYPE_MOVE = 'move';

	/** @var Title Page to be edited */
	protected $title;

	/** @var User Author of the edit */
	protected $user;

	/** @var array All mod_* database fields */
	protected $fields = [];

	/**
	 * @var PendingEdit|false|null
	 * Edit of $user in $title that is currently awaiting moderation (false if there isn't one).
	 */
	private $pendingEdit = null;

	/** @var IConsequenceManager */
	protected $consequenceManager;

	/** @var ModerationPreload */
	protected $preload;

	/** @var HookRunner */
	protected $hookRunner;

	/** @var ModerationNotifyModerator */
	protected $notifyModerator;

	/** @var Language */
	protected $contentLanguage;

	public function __construct(
		Title $title,
		User $user,
		IConsequenceManager $consequenceManager,
		ModerationPreload $preload,
		HookRunner $hookRunner,
		ModerationNotifyModerator $notifyModerator,
		Language $contentLanguage
	) {
		$this->title = $title;
		$this->user = $user;

		$this->consequenceManager = $consequenceManager;
		$this->preload = $preload;
		$this->hookRunner = $hookRunner;
		$this->notifyModerator = $notifyModerator;
		$this->contentLanguage = $contentLanguage;

		$isBlocked = ModerationBlockCheck::isModerationBlocked( $user );

		$request = $user->getRequest();
		$dbr = wfGetDB( DB_REPLICA ); /* Only for $dbr->timestamp(), won't do any SQL queries */

		/* Prepare known values of $fields */
		$this->fields = [
			'mod_timestamp' => $dbr->timestamp(),
			'mod_user' => $user->getId(),
			'mod_user_text' => $user->getName(),
			'mod_cur_id' => 0, # Unknown, set by edit()
			'mod_namespace' => $title->getNamespace(),
			'mod_title' => $title->getDBKey(),
			'mod_comment' => '', # Unknown, set by setSummary()
			'mod_minor' => 0, # Unknown, set by setMinor()
			'mod_bot' => 0, # Unknown, set by setBot()
			'mod_new' => 0, # Unknown, set by edit()
			'mod_last_oldid' => 0, # Unknown, set by edit()
			'mod_ip' => $request->getIP(),
			'mod_old_len' => 0, # Unknown, set by edit()
			'mod_new_len' => 0, # Unknown, set by edit()
			'mod_header_xff' => ( $request->getHeader( 'X-Forwarded-For' ) ?: null ),
			'mod_header_ua' => ( $request->getHeader( 'User-Agent' ) ?: null ),
			'mod_preload_id' => $this->getPreload()->getId( true ),
			'mod_rejected' => $isBlocked ? 1 : 0,
			'mod_rejected_by_user' => 0,
			'mod_rejected_by_user_text' => $isBlocked ?
				wfMessage( 'moderation-blocker' )->inContentLanguage()->text() :
				null,
			'mod_rejected_batch' => 0,
			'mod_rejected_auto' => $isBlocked ? 1 : 0,
			'mod_preloadable' => 0,
			'mod_conflict' => 0,
			'mod_merged_revid' => 0,
			'mod_text' => '', # Unknown, set by edit()
			'mod_stash_key' => null,
			'mod_type' => self::MOD_TYPE_EDIT, # Default, can be changed by move()
			'mod_page2_namespace' => 0, # Unknown, set by move()
			'mod_page2_title' => '' # Unknown, set by move()
		];
	}

	/**
	 * @param WikiPage $wikiPage
	 * @param Content $newContent
	 * @param string $section
	 * @param string $sectionText
	 * @return self
	 */
	public function edit( WikiPage $wikiPage, Content $newContent, $section, $sectionText ) {
		$this->fields['mod_cur_id'] = $wikiPage->getId();
		$this->fields['mod_new'] = $wikiPage->exists() ? 0 : 1;
		$this->fields['mod_last_oldid'] = $wikiPage->getLatest();

		$oldContent = $wikiPage->getContent( RevisionRecord::RAW ); // current revision's content
		if ( $oldContent ) {
			$this->fields['mod_old_len'] = $oldContent->getSize();
		}

		// Check if we need to update existing row (if this edit is by the same user to the same page)
		if ( $section !== '' ) {
			$pendingEdit = $this->getPendingEdit();
			if ( $pendingEdit ) {
				#
				# We must recalculate $this->fields['mod_text'] here.
				# Otherwise if the user adds or modifies two (or more) different sections (in consequent edits),
				# then only modification to the last one will be saved,
				# because $this->newContent is [old content] PLUS [modified section from the edit].
				#
				$model = $newContent->getModel();
				$newContent = $this->makeContent(
					$pendingEdit->getText(),
					$model
				)->replaceSection(
					$section,
					$this->makeContent( $sectionText, $model ),
					''
				);
			}
		}

		// @phan-suppress-next-line PhanTypeMismatchArgumentNullable
		$pstContent = $this->preSaveTransform( $newContent );
		$this->fields['mod_text'] = $pstContent->serialize();
		$this->fields['mod_new_len'] = $pstContent->getSize();
		$this->addChangeTags( 'edit' );

		return $this;
	}

	/**
	 * @param Title $newTitle
	 * @return self
	 */
	public function move( Title $newTitle ) {
		$this->fields['mod_type'] = self::MOD_TYPE_MOVE;
		$this->fields['mod_page2_namespace'] = $newTitle->getNamespace();
		$this->fields['mod_page2_title'] = $newTitle->getDBKey();
		$this->addChangeTags( 'move' );

		return $this;
	}

	/**
	 * @param string $stashKey
	 * @return self
	 */
	public function upload( $stashKey ) {
		$this->fields['mod_stash_key'] = $stashKey;
		$this->addChangeTags( 'upload' );

		return $this;
	}

	/**
	 * @param bool $isMinor
	 * @return self
	 */
	public function setMinor( $isMinor ) {
		$this->fields['mod_minor'] = (int)$isMinor;
		return $this;
	}

	/**
	 * @param bool $isBot
	 * @return self
	 */
	public function setBot( $isBot ) {
		$this->fields['mod_bot'] = (int)$isBot;
		return $this;
	}

	/**
	 * @param string $summary
	 * @return self
	 */
	public function setSummary( $summary ) {
		$this->fields['mod_comment'] = $summary;
		return $this;
	}

	/*-------------------------------------------------------------------*/

	/**
	 * Replace things like "~~~~" in $content.
	 * @param Content $content
	 * @return Content object.
	 */
	protected function preSaveTransform( Content $content ) {
		$popts = ParserOptions::newFromUserAndLang(
			$this->user,
			$this->contentLanguage
		);

		return ModerationCompatTools::preSaveTransform(
			$content,
			$this->title,
			$this->user,
			$popts
		);
	}

	/**
	 * Add AbuseFilter tags to this change, if any.
	 * @param string $action AbuseFilter action, e.g. 'edit' or 'delete'.
	 */
	protected function addChangeTags( $action ) {
		$tagsArray = $this->findAbuseFilterTags(
			$this->title,
			$this->user,
			$action
		);
		$this->fields['mod_tags'] = $tagsArray ? implode( "\n", $tagsArray ) : null;
	}

	/**
	 * Calculate the value of mod_tags.
	 * @param Title $title
	 * @param User $user
	 * @param string $action AbuseFilter action, e.g. 'edit' or 'delete'.
	 * @return string[]
	 */
	protected function findAbuseFilterTags( Title $title, User $user, $action ) {
		$services = MediaWikiServices::getInstance();
		$serviceName = 'AbuseFilterChangeTagger';

		if ( !$services->hasService( $serviceName ) ) {
			// MediaWiki 1.35
			return $this->findAbuseFilterTags35( $title, $user, $action );
		}

		$changeTagger = $services->getService( $serviceName );

		// Construct a synthetic RecentChange object for AbuseFilter to determine the "action ID".
		$recentChange = RecentChange::newLogEntry(
			wfTimestampNow(), $title, $user, '', '', $action, $action, $title, '', '' );
		return $changeTagger->getTagsForRecentChange( $recentChange, false );
	}

	/**
	 * Calculate the value of mod_tags in AbuseFilter for MediaWiki 1.35 only (NOT in 1.36+).
	 * @param Title $title
	 * @param User $user
	 * @param string $action AbuseFilter action, e.g. 'edit' or 'delete'.
	 * @return string[]
	 */
	protected function findAbuseFilterTags35( Title $title, User $user, $action ) {
		// @phan-suppress-next-line PhanUndeclaredStaticProperty AbuseFilter::$tagsToSet
		if ( !class_exists( 'AbuseFilter' ) || empty( AbuseFilter::$tagsToSet ) ) {
			return []; /* No tags */
		}

		// @phan-suppress-next-line PhanUndeclaredStaticProperty AbuseFilter::$tagsToSet
		$tagsToSet = AbuseFilter::$tagsToSet;

		/* AbuseFilter wants to assign some tags to this edit.
			Let's store them (they will be used in modaction=approve).
		*/
		$afActionID = implode( '-', [
			$title->getPrefixedText(),
			$user->getName(),
			$action
		] );

		return $tagsToSet[$afActionID] ?? [];
	}

	/**
	 * @return ModerationPreload
	 */
	protected function getPreload() {
		$this->preload->setUser( $this->user );
		return $this->preload;
	}

	/**
	 * Get edit of $user in $title that is currently awaiting moderation (if any).
	 * @return PendingEdit|false
	 */
	protected function getPendingEdit() {
		if ( $this->pendingEdit === null ) {
			$this->pendingEdit = $this->getPreload()->findPendingEdit( $this->title );
		}

		return $this->pendingEdit;
	}

	/**
	 * Utility function: construct Content object from $text.
	 * @param string $text
	 * @param string|null $model Content model (e.g. CONTENT_MODEL_WIKITEXT).
	 * @return Content object.
	 */
	protected function makeContent( $text, $model = null ) {
		return ContentHandler::makeContent(
			$text,
			$this->title,
			$model
		);
	}

	/**
	 * Returns all mod_* fields for database INSERT.
	 * @return array (as expected by $dbw->insert())
	 */
	public function getFields() {
		return $this->fields;
	}

	/**
	 * Returns one of the fields for database INSERT.
	 * @param string $fieldName Field, e.g. "mod_timestamp".
	 * @return string|false
	 */
	public function getField( $fieldName ) {
		return $this->fields[$fieldName] ?? false;
	}

	/**
	 * Queue edit for moderation.
	 * @return int mod_id of affected row.
	 */
	public function queue() {
		$modid = $this->insert();

		// Run hook to allow other extensions be notified about pending changes
		$this->hookRunner->onModerationPending(
			$this->getFields(),
			$modid
		);

		$this->notify( $modid );

		return $modid;
	}

	/**
	 * Notify moderators about this newly saved pending change.
	 * @param int $modid mod_id of affected row.
	 */
	public function notify( $modid ) {
		if ( $this->getField( 'mod_rejected_auto' ) ) {
			// This change was placed into the Spam folder. No need to notify.
			return;
		}

		// Notify administrator by email
		$this->sendNotificationEmail( $modid );

		// Enable in-wiki notification "New changes await moderation" for moderators
		$this->notifyModerator->setPendingTime( $this->getField( 'mod_timestamp' ) );
	}

	/**
	 * Insert this change into the moderation SQL table.
	 * @return int mod_id of affected row.
	 */
	protected function insert() {
		return $this->consequenceManager->add(
			new InsertRowIntoModerationTableConsequence( $this->getFields() )
		);
	}

	/**
	 * Send email to moderators that new change has appeared.
	 * @param int $modid mod_id of affected row.
	 */
	public function sendNotificationEmail( $modid ) {
		global $wgModerationNotificationEnable,
			$wgModerationNotificationNewOnly,
			$wgModerationEmail;

		if ( !$wgModerationNotificationEnable || !$wgModerationEmail ) {
			return; /* Disabled */
		}

		if ( $wgModerationNotificationNewOnly && $this->getField( 'mod_new' ) == 0 ) {
			/* We only need to notify about new pages,
				and this is an edit in existing page */
			return;
		}

		$this->consequenceManager->add( new SendNotificationEmailConsequence(
			$this->title,
			$this->user,
			$modid
		) );
	}
}
