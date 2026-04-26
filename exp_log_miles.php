<?php 
// Set flag that this is a parent file
define( '_STS_INCLUDE', 1 );

if( php_sapi_name() == 'cli' ) {
	parse_str(implode('&', array_slice($argv, 1)), $_GET);
	
	if( isset($_GET['subdomain']) && ! isset($_SERVER['SERVER_NAME']))
		$sts_subdomain = $_SERVER['SERVER_NAME'] = $_GET['subdomain'];
}

// Setup Session
require_once( "include/sts_session_setup.php" );

require_once( "include/sts_config.php" );

error_reporting(E_ALL);
ini_set('display_errors', '1');

$sts_debug = isset($_GET['debug']); // && in_group( EXT_GROUP_DEBUG );
require_once( "include/sts_session_class.php" );

require_once('include/sts_setting_class.php');

if( ! isset($_SESSION) ) {
	$_SESSION = array();
}

if( php_sapi_name() == 'cli' || isset($_GET['debug']) || isset($_GET['cli']) ) { // run via CLI
	require_once( "PCMILER/exp_get_miles.php" );
	$pcm = sts_pcmiler_api::getInstance( $exspeedite_db, $sts_debug );
	
	$setting_table = sts_setting::getInstance($exspeedite_db, $sts_debug);
	$log_miles = ($setting_table->get("api", "PCM_LOG_IFTA_MILES") == 'true');

	$pcm->log_event("exp_log_miles.php: entry:". print_r($_GET, true), EXT_ERROR_DEBUG);
	
	if( $log_miles &&					// enabled via setting
		isset($_GET['load']) ) {		// LOAD_CODE
	
		if( isset($_GET['log'])) {		// Log the miles
			$pcm->log_miles( $_GET['load'] );
		} else
	
		if( isset($_GET['del'])) {		// Delete miles from the log
			require_once( "include/sts_ifta_log_class.php" );
			$ifta = sts_ifta_log::getInstance( $exspeedite_db, $sts_debug );
			$ifta->delete_row( "CD_ORIGIN = '".$_GET['del']."'");
		}
	} else {
		$pcm->log_event("exp_log_miles.php: DID NOT RUN. log_miles = ". print_r($log_miles, true), EXT_ERROR_DEBUG);
	}
} else {
	echo "<p>Run via CLI only</p>";
}
?>