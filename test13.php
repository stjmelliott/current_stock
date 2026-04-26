<?php 
// Set flag that this is a parent file
define( '_STS_INCLUDE', 1 );
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

error_reporting(E_ALL);


if( php_sapi_name() == 'cli' ) {
	parse_str(implode('&', array_slice($argv, 1)), $_GET);
	
	if( isset($_GET['subdomain']) && ! isset($_SERVER['SERVER_NAME']))
		$sts_subdomain = $_SERVER['SERVER_NAME'] = $_GET['subdomain'];
	$sts_debug = false;
} else {
	// Setup Session
	require_once( "include/sts_session_setup.php" );
}

require_once( "include/sts_config.php" );

if( php_sapi_name() != 'cli' ) {
	$sts_debug = isset($_GET['debug']) && in_group( EXT_GROUP_DEBUG );
	require_once( "include/sts_session_class.php" );
	
	$my_session = sts_session::getInstance( $exspeedite_db, $sts_debug );
	$my_session->access_check( EXT_GROUP_ADMIN );	// Make sure we should be here
	
	$sts_subtitle = "Test11 - Inspection Report";
	require_once( "include/header_inc.php" );
	require_once( "include/sts_optimize_class.php" );
}

if( isset($_GET['IFTA'])) {
	$ifta_load_code = $_GET['IFTA'];
	require_once( "exp_spawn_log_miles.php" );	
} else
if( isset($_GET['IFTA2'])) {
	require_once( "include/sts_shipment_class.php" );
	$shipment_table = sts_shipment::getInstance($exspeedite_db, $sts_debug);
	require_once( "PCMILER/exp_get_miles.php" );
	$pcm = new sts_pcmiler_api( $exspeedite_db, $sts_debug );
	
	$check = $shipment_table->database->get_multiple_rows("
		select load_code
		from exp_load
		where year(COMPLETED_DATE) = 2024
		and current_status in( 19, 33, 68, 34)
        and exists (select stop_code from exp_stop
        where exp_stop.load_code = exp_load.load_code)
		and tractor > 0 OR carrier > 0
		and not exists (select IFTA_LOG_CODE from exp_ifta_log
		where cd_origin = load_code
		and IFTA_TRACTOR = tractor)");
	
	if( is_array($check) && count($check) > 0 ) {
		echo count($check)." Loads to Update\n";
		
		foreach($check as $row) {
			echo $row['load_code']."\n";
			$pcm->log_miles( $row['load_code']);
		//	sleep(1);
		}
	}

} else

if( isset($_GET['OPTIM'])) {
	
	$test = [ 1,2,3,4 ];
	//$test = [ 99,1,2,3,4 ];

	echo '<h2>OPTIM Test 2</h2>';
	
	$optim = sts_optimize::getInstance($exspeedite_db, $sts_debug);
	
	$optim->optimize( $_GET['OPTIM'] );
	/*
	$optim->get_permutations( $test, [] );
	
	echo '<h2>All Permutations</h2>';
	foreach( $optim->get_patterns() as $pattern ) {
		echo implode(', ', $pattern ).'<br>';
	}
	
	$optim->must_be_before(1, 2);
	$optim->must_be_before(3, 4);
	//$optim->must_be_first(99);

	echo '<h2>Without Invalid</h2>';
	foreach( $optim->get_patterns() as $pattern ) {
		echo implode(', ', $pattern ).'<br>';
	}
	*/
}
if( php_sapi_name() != 'cli' ) {
	require_once( "include/footer_inc.php" );
}
?>