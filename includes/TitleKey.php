<?php
/**
 * Copyright (C) 2008 Brion Vibber <brion@pobox.com>
 * https://www.mediawiki.org/
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

use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserIdentity;

class TitleKey extends SearchMySQL {
	/** @var array */
	static $deleteIds = [];

	/**
	 * Active functions...
	 *
	 * @param int $id
	 */
	private static function deleteKey( $id ) {
		$dbw = wfGetDB( DB_PRIMARY );
		$dbw->delete(
			'titlekey',
			[ 'tk_page' => $id ],
			__METHOD__
		);
	}

	/**
	 * @param int $id
	 * @param LinkTarget[] $title
	 */
	private static function setKey( $id, LinkTarget $title ) {
		self::setBatchKeys( [ $id => $title ] );
	}

	/**
	 * @param LinkTarget[] $titles
	 */
	public static function setBatchKeys( $titles ) {
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
		$dbw = wfGetDB( DB_PRIMARY );
		$dbw->replace(
			'titlekey',
			[ 'tk_page' ],
			$rows,
			__METHOD__
		);
	}

	/**
	 * Normalization...
	 *
	 * @param string $text
	 * @return string
	 */
	static function normalize( $text ) {
		return str_replace(' ', '', MediaWikiServices::getInstance()->getContentLanguage()->caseFold( $text ));
	}

	// Hook functions....

	/**
	 * Delay setup to avoid compatibility problems with hook ordering
	 * when coexisting with MWSearch... we want MWSearch to be able to
	 * take over the PrefixSearchBackend hook without disabling the
	 * SearchGetNearMatch hook point.
	 */
	public static function setup() {
		$hookContainer = MediaWikiServices::getInstance()->getHookContainer();
		#$hookContainer->register( 'PrefixSearchBackend', 'TitleKey::prefixSearchBackend' );
		$hookContainer->register( 'SearchGetNearMatch', 'TitleKey::searchGetNearMatch' );
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


	/**
	 * @param Article $article
	 * @param User $user
	 * @param string $reason
	 * @return bool
	 */
	public static function updateDeleteSetup( $article, $user, $reason ) {
		$title = $article->getTitle()->getPrefixedText();
		self::$deleteIds[$title] = $article->getID();
		return true;
	}

	/**
	 * @param Article $article
	 * @param User $user
	 * @param string $reason
	 * @return bool
	 */
	public static function updateDelete( $article, $user, $reason ) {
		$title = $article->getTitle()->getPrefixedText();
		if ( isset( self::$deleteIds[$title] ) ) {
			self::deleteKey( self::$deleteIds[$title] );
		}
		return true;
	}

	/**
	 * @param WikiPage $wikiPage
	 * @return bool
	 */
	public static function updateInsert( $wikiPage ) {
		self::setKey( $wikiPage->getId(), $wikiPage->getTitle() );
		return true;
	}

	/**
	 * @param LinkTarget $from
	 * @param LinkTarget $to
	 * @param UserIdentity $user
	 * @param int $fromid
	 * @param int $toid
	 * @return bool
	 */
	public static function updateMove( LinkTarget $from, LinkTarget $to, $user, $fromid, $toid ) {
		// FIXME
		self::setKey( $toid, $from );
		self::setKey( $fromid, $to );
		return true;
	}

	/**
	 * @param Title $title
	 * @param int $isnewid
	 * @return bool
	 */
	public static function updateUndelete( $title, $isnewid ) {
		if ( method_exists( MediaWikiServices::class, 'getWikiPageFactory' ) ) {
			// MW 1.36+
			$id = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title )->getID();
		} else {
			$id = WikiPage::factory( $title )->getID();
		}
		self::setKey( $id, $title );
		return true;
	}

	/**
	 * Apply schema updates as necessary.
	 * If creating the titlekey table for the first time,
	 * will populate the table with all titles in the page table.
	 *
	 * Status info is sent to stdout.
	 *
	 * @param DatabaseUpdater $updater
	 */
	public static function schemaUpdates( $updater ) {
		$updater->addExtensionUpdate( [ [ __CLASS__, 'runUpdates' ] ] );
		$updater->addPostDatabaseUpdateMaintenance( RebuildTitleKeys::class );
	}

	/**
	 * @param DatabaseUpdater $updater
	 */
	public static function runUpdates( $updater ) {
		$db = $updater->getDB();
		if ( $db->tableExists( 'titlekey' ) ) {
			$updater->output( "...titlekey table already exists.\n" );
		} else {
			$updater->output( 'Creating titlekey table...' );
			$sourceFile = $db->getType() == 'postgres' ? '/../sql/titlekey.pg.sql' : '/../sql/titlekey.sql';
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
	 * @param int[] $ns
	 * @param string $search term
	 */
	function completionSearchBackend( $search ) {
		$results = self::prefixSearch( $this->namespaces, $search, $this->limit, $this->offset );
		return SearchSuggestionSet::fromTitles( $results );
	}

	static function buildPrefixSearchQuery( $dbr, $namespaces, $search, $limit, $offset, $method ) {
		// support only one namespace
		$ns = array_shift( $namespaces );
		if ( in_array( NS_MAIN, $namespaces ) ) {
			// if searching on many always default to main
			$ns = NS_MAIN;
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

	/**
	 * @param array $namespaces
	 * @param string $search
	 * @param int $limit
	 * @param int $offset
	 * @return string
	 */
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
	 * @return bool
	 */
	public static function searchGetNearMatch( $term, &$title ) {
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

	/**
	 * @param Title $title
	 * @return Title|null
	 */
	private static function exactMatchTitle( $title ) {
		$ns = $title->getNamespace();
		return self::exactMatch( $ns, $title->getText() );
	}

	/**
	 * @param array $ns
	 * @param string $text
	 * @return Title|null
	 */
	private static function exactMatch( $ns, $text ) {
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
