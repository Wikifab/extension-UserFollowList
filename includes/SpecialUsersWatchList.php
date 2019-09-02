<?php
/**
 * Implements Special:UsersWatchList
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup SpecialPage
 */

/**
 * A special page that lists last changes made to the wiki,
 * limited to user-defined list of titles.
 *
 * @ingroup SpecialPage
 */
class SpecialUsersWatchList extends ChangesListSpecialPage {
	public function __construct( $page = 'UsersWatchList', $restriction = 'viewmywatchlist' ) {
		parent::__construct( $page, $restriction );
	}

	/**
	 * Main execution point
	 *
	 * @param string $subpage
	 */
	function execute( $subpage ) {
		// Anons don't get a userswatchlist
		$this->requireLogin( 'userswatchlistanontext' );

		$output = $this->getOutput();
		$request = $this->getRequest();

		$mode = SpecialEditWatchlist::getMode( $request, $subpage );
		if ( $mode !== false ) {
			if ( $mode === SpecialEditWatchlist::EDIT_RAW ) {
				$title = SpecialPage::getTitleFor( 'EditUsersWatchList', 'raw' );
			} elseif ( $mode === SpecialEditWatchlist::EDIT_CLEAR ) {
				$title = SpecialPage::getTitleFor( 'EditUsersWatchList', 'clear' );
			} else {
				$title = SpecialPage::getTitleFor( 'EditUsersWatchList' );
			}

			$output->redirect( $title->getLocalURL() );

			return;
		}

		$this->checkPermissions();

		$user = $this->getUser();
		$opts = $this->getOptions();

		$config = $this->getConfig();
		if ( ( $config->get( 'EnotifWatchlist' ) || $config->get( 'ShowUpdatedMarker' ) )
			&& $request->getVal( 'reset' )
			&& $request->wasPosted()
		) {
			$user->clearAllNotifications();
			$output->redirect( $this->getPageTitle()->getFullURL( $opts->getChangedValues() ) );

			return;
		}

		parent::execute( $subpage );
	}

	/**
	 * Return an array of subpages beginning with $search that this special page will accept.
	 *
	 * @param string $search Prefix to search for
	 * @param int $limit Maximum number of results to return
	 * @param int $offset Number of results to skip
	 * @return string[] Matching subpages
	 */
	public function prefixSearchSubpages( $search, $limit, $offset ) {
		// See also SpecialEditWatchlist::prefixSearchSubpages
		return self::prefixSearchArray(
			$search,
			$limit,
			array(
				'clear',
				'edit',
				'raw',
			),
			$offset
		);
	}

	/**
	 * Get a FormOptions object containing the default options
	 *
	 * @return FormOptions
	 */
	public function getDefaultOptions() {
		$opts = parent::getDefaultOptions();
		$user = $this->getUser();

		$opts->add( 'days', $user->getOption( 'watchlistdays' ), FormOptions::FLOAT );

		$opts->add( 'hideminor', $user->getBoolOption( 'watchlisthideminor' ) );
		$opts->add( 'hidebots', $user->getBoolOption( 'watchlisthidebots' ) );
		$opts->add( 'hideanons', $user->getBoolOption( 'watchlisthideanons' ) );
		$opts->add( 'hideliu', $user->getBoolOption( 'watchlisthideliu' ) );
		$opts->add( 'hidepatrolled', $user->getBoolOption( 'watchlisthidepatrolled' ) );
		$opts->add( 'hidemyself', $user->getBoolOption( 'watchlisthideown' ) );

		$opts->add( 'extended', $user->getBoolOption( 'extendwatchlist' ) );

		return $opts;
	}

	/**
	 * Get custom show/hide filters
	 *
	 * @return array Map of filter URL param names to properties (msg/default)
	 */
	protected function getCustomFilters() {
		if ( $this->customFilters === null ) {
			$this->customFilters = parent::getCustomFilters();
			wfRunHooks( 'SpecialWatchlistFilters', array( $this, &$this->customFilters ), '1.23' );
		}

		return $this->customFilters;
	}

	/**
	 * Fetch values for a FormOptions object from the WebRequest associated with this instance.
	 *
	 * Maps old pre-1.23 request parameters Watchlist used to use (different from Recentchanges' ones)
	 * to the current ones.
	 *
	 * @param FormOptions $opts
	 * @return FormOptions
	 */
	protected function fetchOptionsFromRequest( $opts ) {
		static $compatibilityMap = array(
			'hideMinor' => 'hideminor',
			'hideBots' => 'hidebots',
			'hideAnons' => 'hideanons',
			'hideLiu' => 'hideliu',
			'hidePatrolled' => 'hidepatrolled',
			'hideOwn' => 'hidemyself',
		);

		$params = $this->getRequest()->getValues();
		foreach ( $compatibilityMap as $from => $to ) {
			if ( isset( $params[$from] ) ) {
				$params[$to] = $params[$from];
				unset( $params[$from] );
			}
		}

		// Not the prettiest way to achieve this… FormOptions internally depends on data sanitization
		// methods defined on WebRequest and removing this dependency would cause some code duplication.
		$request = new DerivativeRequest( $this->getRequest(), $params );
		$opts->fetchValuesFromRequest( $request );

		return $opts;
	}

	/**
	 * Return an array of conditions depending of options set in $opts
	 *
	 * @param FormOptions $opts
	 * @return array
	 */
	public function buildMainQueryConds( FormOptions $opts ) {
		$dbr = $this->getDB();
		$conds = $this->buildMainQueryConds128( $opts );

		// Calculate cutoff
		if ( $opts['days'] > 0 ) {
			$conds[] = 'rc_timestamp > ' .
				$dbr->addQuotes( $dbr->timestamp( time() - intval( $opts['days'] * 86400 ) ) );
		}

		return $conds;
	}
	/**
	 * Return an array of conditions depending of options set in $opts
	 *
	 * this wa the methode ChangeListSpecialPAge::buildMainQueryConds in mediawiki 1.28
	 *
	 * @param FormOptions $opts
	 * @return array
	 */
	public function buildMainQueryConds128( FormOptions $opts ) {
		$dbr = $this->getDB();
		$user = $this->getUser();
		$conds = [];

		// It makes no sense to hide both anons and logged-in users. When this occurs, try a guess on
		// what the user meant and either show only bots or force anons to be shown.
		$botsonly = false;
		$hideanons = $opts['hideanons'];
		if ( $opts['hideanons'] && $opts['hideliu'] ) {
			if ( $opts['hidebots'] ) {
				$hideanons = false;
			} else {
				$botsonly = true;
			}
		}

		// Toggles
		if ( $opts['hideminor'] ) {
			$conds['rc_minor'] = 0;
		}
		if ( $opts['hidebots'] ) {
			$conds['rc_bot'] = 0;
		}
		if ( $user->useRCPatrol() && $opts['hidepatrolled'] ) {
			$conds['rc_patrolled'] = 0;
		}
		if ( $botsonly ) {
			$conds['rc_bot'] = 1;
		} else {
			if ( $opts['hideliu'] ) {
				$conds[] = 'rc_user = 0';
			}
			if ( $hideanons ) {
				$conds[] = 'rc_user != 0';
			}
		}
		if ( $opts['hidemyself'] ) {
			if ( $user->getId() ) {
				$conds[] = 'rc_user != ' . $dbr->addQuotes( $user->getId() );
			} else {
				$conds[] = 'rc_user_text != ' . $dbr->addQuotes( $user->getName() );
			}
		}
		if ( $this->getConfig()->get( 'RCWatchCategoryMembership' )
			&& $opts['hidecategorization'] === true
		) {
			$conds[] = 'rc_type != ' . $dbr->addQuotes( RC_CATEGORIZE );
		}

		// Namespace filtering
		if ( $opts['namespace'] !== '' ) {
			$selectedNS = $dbr->addQuotes( $opts['namespace'] );
			$operator = $opts['invert'] ? '!=' : '=';
			$boolean = $opts['invert'] ? 'AND' : 'OR';

			// Namespace association (bug 2429)
			if ( !$opts['associated'] ) {
				$condition = "rc_namespace $operator $selectedNS";
			} else {
				// Also add the associated namespace
				$associatedNS = $dbr->addQuotes(
					MWNamespace::getAssociated( $opts['namespace'] )
				);
				$condition = "(rc_namespace $operator $selectedNS "
					. $boolean
					. " rc_namespace $operator $associatedNS)";
			}

			$conds[] = $condition;
		}

		return $conds;
	}

	/**
	 * Get the database result for this special page instance. Used by ApiFeedRecentChanges.
	 *
	 * @return bool|ResultWrapper Result or false
	 */
	public function getRows() {
		$opts = $this->getOptions();
		$conds = $this->buildMainQueryConds( $opts );

		return $this->doUsersWatchListQuery( $conds, $opts );
	}

	public function doUsersWatchListQuery($conds, $opts) {
		$dbr = $this->getDB();
		$user = $this->getUser();

		# Toggle userswatchlist content (all recent edits or just the latest)
		if ( $opts['extended'] ) {
			$limitWatchlist = $user->getIntOption( 'wllimit' );
			$usePage = false;
		} else {
			# Top log Ids for a page are not stored
			$nonRevisionTypes = array( RC_LOG );
			wfRunHooks( 'SpecialWatchlistGetNonRevisionTypes', array( &$nonRevisionTypes ) );
			if ( $nonRevisionTypes ) {
				$conds[] = $dbr->makeList(
					array(
						'rc_this_oldid=page_latest',
						'rc_type' => $nonRevisionTypes,
					),
					LIST_OR
				);
			}
			$limitWatchlist = 0;
			$usePage = true;
		}

		$tables = array( 'recentchanges', 'userswatchlist' );
		$fields = RecentChange::selectFields();
		$query_options = array( 'ORDER BY' => 'rc_timestamp DESC' );
		$join_conds = array(
			'userswatchlist' => array(
				'INNER JOIN',
				array(
					'fl_user' => $user->getId(),
					'fl_user_followed=rc_user',
				),
			),
		);

		if ( $this->getConfig()->get( 'ShowUpdatedMarker' ) ) {
			$fields[] = 'fl_notificationtimestamp';
		}
		if ( $limitWatchlist ) {
			$query_options['LIMIT'] = $limitWatchlist;
		}

		$rollbacker = $user->isAllowed( 'rollback' );
		if ( $usePage || $rollbacker ) {
			$tables[] = 'page';
			$join_conds['page'] = array( 'LEFT JOIN', 'rc_cur_id=page_id' );
			if ( $rollbacker ) {
				$fields[] = 'page_latest';
			}
		}

		// Log entries with DELETED_ACTION must not show up unless the user has
		// the necessary rights.
		if ( !$user->isAllowed( 'deletedhistory' ) ) {
			$bitmask = LogPage::DELETED_ACTION;
		} elseif ( !$user->isAllowedAny( 'suppressrevision', 'viewsuppressed' ) ) {
			$bitmask = LogPage::DELETED_ACTION | LogPage::DELETED_RESTRICTED;
		} else {
			$bitmask = 0;
		}
		if ( $bitmask ) {
			$conds[] = $dbr->makeList( array(
				'rc_type != ' . RC_LOG,
				$dbr->bitAnd( 'rc_deleted', $bitmask ) . " != $bitmask",
			), LIST_OR );
		}

		ChangeTags::modifyDisplayQuery(
			$tables,
			$fields,
			$conds,
			$join_conds,
			$query_options,
			''
		);

		$this->runMainQueryHook( $tables, $fields, $conds, $query_options, $join_conds, $opts );

		return $dbr->select(
			$tables,
			$fields,
			$conds,
			__METHOD__,
			$query_options,
			$join_conds
		);

	}

	/**
	 * Return a DatabaseBase object for reading
	 *
	 * @return DatabaseBase
	 */
	protected function getDB() {
		return wfGetDB( DB_SLAVE, 'userswatchlist' );
	}

	/**
	 * Build and output the actual changes list.
	 *
	 * @param ResultWrapper $rows Database rows
	 * @param FormOptions $opts
	 */
	public function outputChangesList( $rows, $opts ) {
		$dbr = $this->getDB();
		$user = $this->getUser();
		$output = $this->getOutput();

		# Show a message about slave lag, if applicable
		$lag = wfGetLB()->safeGetLag( $dbr );
		if ( $lag > 0 ) {
			$output->showLagWarning( $lag );
		}

		# If no rows to display, show message before try to render the list
		if ( $rows->numRows() == 0 ) {
			$output->wrapWikiMsg(
				"<div class='mw-changeslist-empty'>\n$1\n</div>", 'recentchanges-noresult'
			);
			return;
		}

		$dbr->dataSeek( $rows, 0 );

		$list = ChangesList::newFromContext( $this->getContext() );
		$list->setWatchlistDivs();
		$list->initChangesListRows( $rows );
		$dbr->dataSeek( $rows, 0 );

		$s = $list->beginRecentChangesList();
		$counter = 1;
		foreach ( $rows as $obj ) {
			# Make RC entry
			$rc = RecentChange::newFromRow( $obj );
			$rc->counter = $counter++;

			if ( $this->getConfig()->get( 'ShowUpdatedMarker' ) ) {
				$updated = $obj->fl_notificationtimestamp;
			} else {
				$updated = false;
			}

			if ( $this->getConfig()->get( 'RCShowWatchingUsers' ) && $user->getOption( 'shownumberswatching' ) ) {
				$rc->numberofWatchingusers = $dbr->selectField( 'watchlist',
					'COUNT(*)',
					array(
						'wl_namespace' => $obj->rc_namespace,
						'wl_title' => $obj->rc_title,
					),
					__METHOD__ );
			} else {
				$rc->numberofWatchingusers = 0;
			}

			$changeLine = $list->recentChangesLine( $rc, $updated, $counter );
			if ( $changeLine !== false ) {
				$s .= $changeLine;
			}
		}
		$s .= $list->endRecentChangesList();

		$output->addHTML( $s );
	}

	/**
	 * Set the text to be displayed above the changes
	 *
	 * @param FormOptions $opts
	 * @param int $numRows Number of rows in the result to show after this header
	 */
	public function doHeader( $opts, $numRows ) {
		$user = $this->getUser();

		$this->getOutput()->addSubtitle(
			$this->msg( 'watchlistfor2', $user->getName() )
				->rawParams( SpecialEditUsersWatchList::buildTools( null ) )
		);

		$this->setTopText( $opts );

		$lang = $this->getLanguage();
		$wlInfo = '';
		if ( $opts['days'] > 0 ) {
			$timestamp = wfTimestampNow();
			$wlInfo = $this->msg( 'wlnote' )->numParams( $numRows, round( $opts['days'] * 24 ) )->params(
				$lang->userDate( $timestamp, $user ), $lang->userTime( $timestamp, $user )
			)->parse() . "<br />\n";
		}

		$nondefaults = $opts->getChangedValues();
		$cutofflinks = $this->cutoffLinks( $opts['days'], $nondefaults ) . "<br />\n";

		# Spit out some control panel links
		$filters = array(
			'hideminor' => 'rcshowhideminor',
			'hidebots' => 'rcshowhidebots',
			'hideanons' => 'rcshowhideanons',
			'hideliu' => 'rcshowhideliu',
			'hidemyself' => 'rcshowhidemine',
			'hidepatrolled' => 'rcshowhidepatr'
		);
		foreach ( $this->getCustomFilters() as $key => $params ) {
			$filters[$key] = $params['msg'];
		}
		// Disable some if needed
		if ( !$user->useNPPatrol() ) {
			unset( $filters['hidepatrolled'] );
		}

		$links = array();
		foreach ( $filters as $name => $msg ) {
			$links[] = $this->showHideLink( $nondefaults, $msg, $name, $opts[$name] );
		}

		$hiddenFields = $nondefaults;
		unset( $hiddenFields['namespace'] );
		unset( $hiddenFields['invert'] );
		unset( $hiddenFields['associated'] );

		# Create output
		$form = '';

		# Namespace filter and put the whole form together.
		$form .= $wlInfo;
		$form .= $cutofflinks;
		$form .= $lang->pipeList( $links ) . "\n";
		$form .= "<hr />\n<p>";
		$form .= Html::namespaceSelector(
			array(
				'selected' => $opts['namespace'],
				'all' => '',
				'label' => $this->msg( 'namespace' )->text()
			), array(
				'name' => 'namespace',
				'id' => 'namespace',
				'class' => 'namespaceselector',
			)
		) . '&#160;';
		$form .= Xml::checkLabel(
			$this->msg( 'invert' )->text(),
			'invert',
			'nsinvert',
			$opts['invert'],
			array( 'title' => $this->msg( 'tooltip-invert' )->text() )
		) . '&#160;';
		$form .= Xml::checkLabel(
			$this->msg( 'namespace_association' )->text(),
			'associated',
			'nsassociated',
			$opts['associated'],
			array( 'title' => $this->msg( 'tooltip-namespace_association' )->text() )
		) . '&#160;';
		$form .= Xml::submitButton( $this->msg( 'allpagessubmit' )->text() ) . "</p>\n";
		foreach ( $hiddenFields as $key => $value ) {
			$form .= Html::hidden( $key, $value ) . "\n";
		}
		$form .= Xml::closeElement( 'fieldset' ) . "\n";
		$form .= Xml::closeElement( 'form' ) . "\n";
		$this->getOutput()->addHTML( $form );

		$this->setBottomText( $opts );
	}

	function setTopText( FormOptions $opts ) {
		$nondefaults = $opts->getChangedValues();
		$form = "";
		$user = $this->getUser();

		$dbr = $this->getDB();
		$numItems = $this->countItems( $dbr );
		$showUpdatedMarker = $this->getConfig()->get( 'ShowUpdatedMarker' );

		// Show watchlist header
		$form .= "<p>";
		if ( $numItems == 0 ) {
			$form .= $this->msg( 'nowatchlist' )->parse() . "\n";
		} else {
			$form .= $this->msg( 'watchlist-details' )->numParams( $numItems )->parse() . "\n";
			if ( $this->getConfig()->get( 'EnotifWatchlist' ) && $user->getOption( 'enotifwatchlistpages' ) ) {
				$form .= $this->msg( 'wlheader-enotif' )->parse() . "\n";
			}
			if ( $showUpdatedMarker ) {
				$form .= $this->msg( 'wlheader-showupdated' )->parse() . "\n";
			}
		}
		$form .= "</p>";

		if ( $numItems > 0 && $showUpdatedMarker ) {
			$form .= Xml::openElement( 'form', array( 'method' => 'post',
				'action' => $this->getPageTitle()->getLocalURL(),
				'id' => 'mw-watchlist-resetbutton' ) ) . "\n" .
			Xml::submitButton( $this->msg( 'enotif_reset' )->text(), array( 'name' => 'dummy' ) ) . "\n" .
			Html::hidden( 'reset', 'all' ) . "\n";
			foreach ( $nondefaults as $key => $value ) {
				$form .= Html::hidden( $key, $value ) . "\n";
			}
			$form .= Xml::closeElement( 'form' ) . "\n";
		}

		$form .= Xml::openElement( 'form', array(
			'method' => 'post',
			'action' => $this->getPageTitle()->getLocalURL(),
			'id' => 'mw-watchlist-form'
		) );
		$form .= Xml::fieldset(
			$this->msg( 'watchlist-options' )->text(),
			false,
			array( 'id' => 'mw-watchlist-options' )
		);

		$form .= parent::makeLegend();

		$this->getOutput()->addHTML( $form );
	}

	protected function showHideLink( $options, $message, $name, $value ) {
		$label = $this->msg( $value ? 'show' : 'hide' )->escaped();
		$options[$name] = 1 - (int)$value;

		return $this->msg( $message )
			->rawParams( Linker::linkKnown( $this->getPageTitle(), $label, array(), $options ) )
			->escaped();
	}

	protected function hoursLink( $h, $options = array() ) {
		$options['days'] = ( $h / 24.0 );

		return Linker::linkKnown(
			$this->getPageTitle(),
			$this->getLanguage()->formatNum( $h ),
			array(),
			$options
		);
	}

	protected function daysLink( $d, $options = array() ) {
		$options['days'] = $d;
		$message = $d ? $this->getLanguage()->formatNum( $d )
			: $this->msg( 'watchlistall2' )->escaped();

		return Linker::linkKnown(
			$this->getPageTitle(),
			$message,
			array(),
			$options
		);
	}

	/**
	 * Returns html
	 *
	 * @param int $days This gets overwritten, so is not used
	 * @param array $options Query parameters for URL
	 * @return string
	 */
	protected function cutoffLinks( $days, $options = array() ) {
		$hours = array( 1, 2, 6, 12 );
		$days = array( 1, 3, 7 );
		$i = 0;
		foreach ( $hours as $h ) {
			$hours[$i++] = $this->hoursLink( $h, $options );
		}
		$i = 0;
		foreach ( $days as $d ) {
			$days[$i++] = $this->daysLink( $d, $options );
		}

		return $this->msg( 'userswatchlist-showlast' )->rawParams(
			$this->getLanguage()->pipeList( $hours ),
			$this->getLanguage()->pipeList( $days ),
			$this->daysLink( 0, $options ) )->parse();
	}

	/**
	 * Count the number of items on a user's watchlist
	 *
	 * @param DatabaseBase $dbr A database connection
	 * @return int
	 */
	protected function countItems( $dbr ) {
		# Fetch the raw count
		$rows = $dbr->select( 'watchlist', array( 'count' => 'COUNT(*)' ),
			array( 'wl_user' => $this->getUser()->getId() ), __METHOD__ );
		$row = $dbr->fetchObject( $rows );
		$count = $row->count;

		return floor( $count / 2 );
	}
}
