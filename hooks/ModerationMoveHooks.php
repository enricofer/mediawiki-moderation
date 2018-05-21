<?php

/*
	Extension:Moderation - MediaWiki extension.
	Copyright (C) 2018 Edward Chernenko.

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
	@file
	@brief Hooks related to moving (renaming) pages.
*/

class ModerationMoveHooks {

	/**
		@brief Intercept attempts to rename pages and queue them for moderation.
	*/
	public static function onMovePageCheckPermissions( Title $oldTitle, Title $newTitle, User $user, $reason, Status $status ) {
		global $wgModerationInterceptMoves;
		if ( !$wgModerationInterceptMoves ) {
			/* Disabled, page moves currently bypass moderation */
			return true;
		}

		if ( ModerationCanSkip::canSkip(
			$user,
			$oldTitle->getNamespace(),
			$newTitle->getNamespace()
		) ) {
			return true;
		}

		if ( !$status->isOK() ) {
			// $user is not allowed to move ($status is already fatal)
			return true;
		}

		$change = new ModerationNewChange( $oldTitle, $user );
		$fields = $change->move( $newTitle )
			->setSummary( $reason )
			->queue();

		$status->fatal( 'moderation-edit-queued' );
		return false;
	}
}
