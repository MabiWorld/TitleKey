<?php

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../..';
}

require_once "$IP/maintenance/Maintenance.php";

require "$IP/includes/search/SearchEngine.php";
require "$IP/includes/search/SearchDatabase.php";
require "$IP/includes/search/SearchMySQL.php";
require __DIR__ . '/TitleKey_body.php';

class TestTitleKeys extends Maintenance {
	function __construct() {
		parent::__construct();
		$this->mDescription = "Test titlekey search query.";
		$this->setBatchSize( 1000 );
		$this->addOption( 'query', 'Query', false, true );

		$this->requireExtension( 'TitleKey' );
	}

	function execute() {
		$query = TitleKey::normalize( $this->getOption( 'query', 'appl' ) );
		$dbr = $this->getDB( DB_REPLICA );
		$sql = TitleKey::buildPrefixSearchQuery( $dbr, [0], $query, 10, 0, __METHOD__ );
		$this->output( "$sql\n" );
		$result = $dbr->query( $sql, __METHOD__ );
		foreach ( $result as $row ) {
			$title = Title::makeTitle( $row->namespace, $row->title );
			$this->output( '  ' . $title->getFullText() . "\n" );
		}
		$this->output("Done\n");
	}
}

$maintClass = 'TestTitleKeys';
require_once RUN_MAINTENANCE_IF_MAIN;
