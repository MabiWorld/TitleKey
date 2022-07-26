<?php
/**
 * Copyright (C) 2008 Brion Vibber <brion@pobox.com>
 * http://www.mediawiki.org/
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
 */

class TitleKey extends SearchMySQL {
	static $deleteIds = [];

	// Active functions...
	static function deleteKey( $id ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->delete(
			'titlekey',
			[ 'tk_page' => $id ],
			__METHOD__
		);
	}

	static function setKey( $id, $title ) {
		self::setBatchKeys( [ $id => $title ] );
	}

	static function setBatchKeys( $titles ) {
		$rows = [];
		foreach ( $titles as $id => $title ) {
			$title_text = $title->getText();
			$rows[] = [
				'tk_page' => $id,
				'tk_namespace' => $title->getNamespace(),
				'tk_subpages' => substr_count($title_text, '/'),
				'tk_key' => self::normalize( $title_text ),
			];
		}
		$dbw = wfGetDB( DB_MASTER );
		$dbw->replace(
			'titlekey',
			[ 'tk_page' ],
			$rows,
			__METHOD__
		);
	}

	// Normalization...
	static function normalize( $text ) {
		global $wgContLang;
		return str_replace(' ', '', $wgContLang->caseFold( $text ));
	}

	// Hook functions....

	// Delay setup to avoid compatibility problems with hook ordering
	// when coexisting with MWSearch... we want MWSearch to be able to
	// take over the PrefixSearchBackend hook without disabling the
	// SearchGetNearMatch hook point.
	public static function setup() {
		global $wgHooks;
		#$wgHooks['PrefixSearchBackend'][] = 'TitleKey::prefixSearchBackend';
		$wgHooks['SearchGetNearMatch'][] = 'TitleKey::searchGetNearMatch';
	}

	protected function normalizeNamespaces( $search ) {
		global $wgLang;
		// This doesn't seem to be working in core? Temporary override.
		$ns = [ NS_MAIN ];

		if ( $search ) {
			$parts = explode(':', $search);
			$search = array_pop($parts);
 
			$nst = array();
			foreach( $parts as $part ) {
				$nsi = $wgLang->getNsIndex($part);
				if ( $nsi !== false) $nst[] = $nsi;
			}
 
			if ( $nst ) $ns = $nst;
		}

		#$this->setNamespaces( $ns );
		$this->namespaces = $ns;
		return $search;
	}

	static function updateDeleteSetup( $article, $user, $reason ) {
		$title = $article->mTitle->getPrefixedText();
		self::$deleteIds[$title] = $article->getID();
		return true;
	}

	static function updateDelete( $article, $user, $reason ) {
		$title = $article->mTitle->getPrefixedText();
		if ( isset( self::$deleteIds[$title] ) ) {
			self::deleteKey( self::$deleteIds[$title] );
		}
		return true;
	}

	/**
	 * @param WikiPage $wikiPage
	 * @param User $user
	 * @param Content $content
	 * @param string $summary
	 * @param bool $isMinor
	 * @param $isWatch
	 * @param $section
	 * @param $flags
	 * @param Revision $revision
	 * @return bool
	 */
	static function updateInsert( $wikiPage, $user, $content, $summary, $isMinor, $isWatch,
		$section, $flags, $revision ) {
		self::setKey( $wikiPage->getId(), $wikiPage->getTitle() );
		return true;
	}

	static function updateMove( $from, $to, $user, $fromid, $toid ) {
		// FIXME
		self::setKey( $toid, $from );
		self::setKey( $fromid, $to );
		return true;
	}

	static function testTables( &$tables ) {
		$tables[] = 'titlekey';
		return true;
	}

	static function updateUndelete( $title, $isnewid ) {
		$article = new Article( $title );
		$id = $article->getID();
		self::setKey( $id, $title );
		return true;
	}

	/**
	 * Apply schema updates as necessary.
	 * If creating the titlekey table for the first time,
	 * will populate the table with all titles in the page table.
	 *
	 * Status info is sent to stdout.
	 */
	public static function schemaUpdates( $updater = null ) {
		$updater->addExtensionUpdate( [ [ __CLASS__, 'runUpdates' ] ] );
		require_once __DIR__ . '/rebuildTitleKeys.php';
		$updater->addPostDatabaseUpdateMaintenance( 'RebuildTitleKeys' );
		return true;
	}

	public static function runUpdates( $updater ) {
		$db = $updater->getDB();
		if ( $db->tableExists( 'titlekey' ) ) {
			$updater->output( "...titlekey table already exists.\n" );
		} else {
			$updater->output( 'Creating titlekey table...' );
			$sourceFile = $db->getType() == 'postgres' ? '/titlekey.pg.sql' : '/titlekey.sql';
			$err = $db->sourceFile( __DIR__ . $sourceFile );
			if ( $err !== true ) {
				throw new Exception( $err );
			}

			$updater->output( "ok.\n" );
		}
	}

	/**
	 * Override the default OpenSearch backend...
	 *
	 * @param string $search term
	 */
	function completionSearchBackend( $search ) {
		$results = self::prefixSearch( $this->namespaces, $search, $this->limit, $this->offset );
		return SearchSuggestionSet::fromTitles( $results );
	}

	static function buildPrefixSearchQuery( $dbr, $namespaces, $search, $limit, $offset, $method ) {
		$ns = array_shift( $namespaces ); // support only one namespace
		if ( in_array( NS_MAIN, $namespaces ) ) {
			$ns = NS_MAIN; // if searching on many always default to main
		}

		$key = self::normalize( $search );

		return $dbr->selectSQLText(
			[ 'page', 'titlekey', 'redirect' ],
			[
				'COALESCE(rd_namespace, page_namespace) AS namespace',
				'COALESCE(rd_title, page_title) AS title'
			],
			[
				'tk_page = page_id',
				'tk_namespace' => $ns,
				'tk_key ' . $dbr->buildLike( $key, $dbr->anyString() ),
			],
			$method,
			[
				'ORDER BY' => ['page_is_redirect', 'tk_subpages', 'tk_key'],
				'LIMIT' => $limit,
				'OFFSET' => $offset,
				'DISTINCT',
			],
			[
				'redirect' => [
					'LEFT JOIN',
					'tk_page = rd_from',
				],
			]
		);
	}

	static function prefixSearch( $namespaces, $search, $limit, $offset ) {
		$dbr = wfGetDB( DB_REPLICA );
		$sql = self::buildPrefixSearchQuery(
			$dbr, $namespaces, $search, $limit, $offset, __METHOD__
		);
		$result = $dbr->query( $sql, __METHOD__ );

		// Reformat useful data for future printing by JSON engine
		$srchres = [];
		foreach ( $result as $row ) {
			$title = Title::makeTitle( $row->namespace, $row->title );
			$srchres[] = $title; #->getPrefixedText();
		}
		$result->free();

		sort( $srchres, SORT_NATURAL | SORT_FLAG_CASE );

		return $srchres;
	}

	/**
	 * Find matching titles after the default 'go' search exact match fails.
	 * This'll let 'mcgee' match 'McGee' etc.
	 *
	 * @param string $term
	 * @param Title outparam &$title
	 */
	static function searchGetNearMatch( $term, &$title ) {
		$temp = Title::newFromText( $term );
		if ( $temp ) {
			$match = self::exactMatchTitle( $temp );
			if ( $match ) {
				// Yay!
				$title = $match;
				return false;
			}
		}
		// No matches. :(
		return true;
	}

	static function exactMatchTitle( $title ) {
		$ns = $title->getNamespace();
		return self::exactMatch( $ns, $title->getText() );
	}

	static function exactMatch( $ns, $text ) {
		$key = self::normalize( $text );

		$dbr = wfGetDB( DB_REPLICA );
		$row = $dbr->selectRow(
			[ 'titlekey', 'page' ],
			[ 'page_namespace', 'page_title' ],
			[
				'tk_page = page_id',
				'tk_namespace' => $ns,
				'tk_key' => $key,
			],
			__METHOD__
		);

		if ( $row ) {
			return Title::makeTitle( $row->page_namespace, $row->page_title );
		} else {
			return null;
		}
	}
}
