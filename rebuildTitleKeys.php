<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../..';
}
require_once "$IP/maintenance/Maintenance.php";

// In case we want to do offline initialization...
if ( !class_exists( 'TitleKey' ) ) {
	require "$IP/includes/search/SearchEngine.php";
	require "$IP/includes/search/SearchDatabase.php";
	require "$IP/includes/search/SearchMySQL.php";
	require __DIR__ . '/TitleKey_body.php';
}

class RebuildTitleKeys extends Maintenance {
	function __construct() {
		parent::__construct();
		$this->mDescription = "Rebuilds titlekey table entries for all pages in DB.";
		$this->setBatchSize( 1000 );
		$this->addOption( 'start', 'Page ID to start from', false, true );

		$this->requireExtension( 'TitleKey' );
	}

	function execute() {
		$start = $this->getOption( 'start', 0 );
		$this->output( "Rebuilding titlekey table...\n" );
		$dbr = $this->getDB( DB_REPLICA );

		$maxId = $dbr->selectField( 'page', 'MAX(page_id)', '', __METHOD__ );

		$lastId = 0;
		for ( ; $start <= $maxId; $start += $this->mBatchSize ) {
			if ( $start != 0 ) {
				$this->output( "... $start...\n" );
			}
			$result = $dbr->select(
				'page',
				[ 'page_id', 'page_namespace', 'page_title' ],
				[ 'page_id > ' . intval( $start ) ],
				__METHOD__,
				[
					'ORDER BY' => 'page_id',
					'LIMIT' => $this->mBatchSize
				]
			);

			$titles = [];
			foreach ( $result as $row ) {
				$titles[$row->page_id] =
					Title::makeTitle( $row->page_namespace, $row->page_title );
				$lastId = $row->page_id;
			}
			$result->free();

			TitleKey::setBatchKeys( $titles );

			wfWaitForSlaves( 20 );
		}

		if ( $lastId ) {
			$this->output( "... $lastId ok.\n" );
		} else {
			$this->output( "... no pages.\n" );
		}
	}
}

$maintClass = 'RebuildTitleKeys';
require_once RUN_MAINTENANCE_IF_MAIN;
