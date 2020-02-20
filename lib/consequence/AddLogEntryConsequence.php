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
 * Consequence that creates a new LogEntry in Special:Log/moderation.
 */

namespace MediaWiki\Moderation;

use ManualLogEntry;
use Title;
use User;

class AddLogEntryConsequence implements IConsequence {
	/** @var string */
	protected $subtype;

	/** @var User */
	protected $user;

	/** @var Title */
	protected $title;

	/** @var array */
	protected $params;

	/**
	 * @param string $subtype
	 * @param User $user
	 * @param Title $title
	 * @param array $params
	 */
	public function __construct( $subtype, User $user, Title $title, array $params = [] ) {
		$this->subtype = $subtype;
		$this->user = $user;
		$this->title = $title;
		$this->params = $params;
	}

	/**
	 * Execute the consequence.
	 * @return array
	 * @phan-return array{0:int,1:ManualLogEntry}
	 */
	public function run() {
		$logEntry = new ManualLogEntry( 'moderation', $this->subtype );
		$logEntry->setPerformer( $this->user );
		$logEntry->setTarget( $this->title );
		$logEntry->setParameters( $this->params );

		$logid = $logEntry->insert();
		$logEntry->publish( $logid );

		return [ $logid, $logEntry ];
	}
}
