<?php 

// $Id: exp_suggest_city.php 5449 2025-03-10 23:59:48Z dev $
// Suggest cities based on a prefix

// Set flag that this is a parent file
define( '_STS_INCLUDE', 1 );

// Set flag that this is an ajax call
define( '_STS_SESSION_AJAX', 1 );

// Setup Session
require_once( "include/sts_session_setup.php" );

require_once( "include/sts_config.php" );
$sts_debug = isset($_GET['debug']);

require_once( "include/sts_zip_class.php" );

if( isset($_GET['query']) && isset($_GET['code']) && $_GET['code'] == 'Chicago') {

	$zip_table = new sts_zip($exspeedite_db, $sts_debug);
	$result = $zip_table->suggest_city( $_GET['query'] );

	if( $sts_debug ) {
		echo "<p>result = </p>
		<pre>";
		var_dump($result);
		echo "</pre>";
	} else {
		echo json_encode( $result );
	}
}

?>

