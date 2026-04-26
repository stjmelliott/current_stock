<?php 
// Set flag that this is a parent file
define( '_STS_INCLUDE', 1 );

// Set flag that this is session readonly
define( '_STS_SESSION_READONLY', 1 );

// Set flag that this is an ajax call
define( '_STS_SESSION_AJAX', 1 );

// Expiry period for cache
define( '_STS_EXPIRY', 'now -1 hour' );
//define( '_STS_EXPIRY', 'now -5 minute' );

// Setup Session
require_once( "include/sts_session_setup.php" );

require_once( "include/sts_config.php" );
$sts_debug = isset($_GET['debug']);

// close the session here to avoid blocking
session_write_close();

function write_homedata_cache( $data, $debug = false ) {
	$contents_string = serialize($data);
	$session_path = str_replace('\\', '/', ini_get('session.save_path'));
	$full_path = $session_path.'/homedata_cache';
	if( $debug ) echo "<p>".__FUNCTION__.": entry, $full_path</p>";

	if( file_exists($full_path) )
		unlink($full_path);
	
	$result = file_put_contents( $full_path, $contents_string, LOCK_EX );
	chmod($full_path, 0777 );
	
	$created = filemtime($full_path);
	$expiry = strtotime(_STS_EXPIRY) ;
	if( $debug ) echo "<p>".__FUNCTION__.": created ".date('m/d/Y H:i', $created)." expiry ".date('m/d/Y H:i', $expiry)."</p>";
	
	if( $debug ) echo "<p>".__FUNCTION__.": exit return ".($result === false ? 'false' : $result.' bytes written')."</p>";
	return $result;
}

function read_homedata_cache( $debug = false ) {
	$session_path = str_replace('\\', '/', ini_get('session.save_path'));
	$full_path = $session_path.'/homedata_cache';
	if( $debug ) echo "<p>".__FUNCTION__.": entry, $full_path ".(file_exists($full_path) ? 'exists' : 'missing').', '.(is_readable($full_path) ? 'readable' : 'unreadable')."</p>";

	if( file_exists($full_path) && is_readable($full_path) ) {
		$created = filemtime($full_path);
		$expiry = strtotime(_STS_EXPIRY) ;
		if( $debug ) echo "<p>".__FUNCTION__.": created ".date('m/d/Y H:i', $created)." expiry ".date('m/d/Y H:i', $expiry)."</p>";
		if( $created > $expiry ) {
			$contents_string = file_get_contents( $full_path );
				
			if( ! empty($contents_string) ) {
				if( $debug ) echo "<p>".__FUNCTION__.": unserialize ".strlen($contents_string)." bytes</p>";
				$result = unserialize($contents_string, ['allowed_classes' => false]);
			} else {
				$result = false;
			}		
		} else {
			if( $debug ) echo "<p>".__FUNCTION__.": expired</p>";
			$result = false;
		}		
	} else {
		$result = false;
	}
	if( $debug ) {
		echo "<p>".__FUNCTION__.": exit return ".($result === false ? 'false' : 'ok')."</p>";
		flush();
	}
	return $result;
}

if( isset($_GET['pw']) && $_GET['pw'] == 'William') {
	
	require_once( "include/sts_session_class.php" );
	require_once( "include/sts_setting_class.php" );

	$my_session = sts_session::getInstance( $exspeedite_db, $sts_debug );
	
	//! If true display only my clients
	$my_sales_clients =  $my_session->cms_restricted_salesperson();

	$setting_table = sts_setting::getInstance( $exspeedite_db, false );
	$default_active = ($setting_table->get("option", "DEFAULT_ACTIVE") == 'true');	//! SCR# 533

	if( $data = read_homedata_cache( $sts_debug ) ) {
		$result = $data;
	} else {
		
		//! SCR# 533 - only show active if setting is true
		if( $default_active ) 
			$df = " AND ISACTIVE = 'Active'";
		else
			$df = "";
		
		$result = $exspeedite_db->get_one_row("
			select *
			from (select count(*) as USERS
				from ".USER_TABLE." ) UT,
			(select count(*) as COMPANIES
				from ".COMPANY_TABLE." ) CT,
			(select count(*) as OFFICES
				from ".OFFICE_TABLE." ) OT,
			(select count(*) as DRIVERS
				from ".DRIVER_TABLE." 
				where ISDELETED = false ".$df.") DT,
			(select count(*) as DRIVERS_EXPIRED
				from ".DRIVER_TABLE." 
				where ISDELETED = false
				AND DRIVER_EXPIRED(DRIVER_CODE, true) <> 'GREEN' ".$df.") DTE,
			(select count(*) as CARRIERS_EXPIRED
				from ".CARRIER_TABLE." 
				where ISDELETED = false
				AND CARRIER_EXPIRED(CARRIER_CODE) <> 'GREEN') CTE,
			(select count(*) as TRACTORS
				from ".TRACTOR_TABLE." 
				where ISDELETED = false ".$df.") TT,
			(select count(*) as TRACTORS_EXPIRED
				from ".TRACTOR_TABLE." 
				where ISDELETED = false
				AND TRACTOR_EXPIRED(TRACTOR_CODE) <> 'GREEN' ".$df.") TTE,
			(select count(*) as TRAILERS
				from ".TRAILER_TABLE." 
				where ISDELETED = false ".$df.") TT2,
			(select count(*) as TRAILERS_EXPIRED
				from ".TRAILER_TABLE." 
				where ISDELETED = false
				AND TRAILER_EXPIRED(TRAILER_CODE) <> 'GREEN' ".$df.") TT2E,
			(select count(*) as CARRIERS
				from ".CARRIER_TABLE." 
				where ISDELETED = false ) TT3,
			(select count(*) as CLIENTS
				from ".CLIENT_TABLE." 
				where ISDELETED = false
				".($my_sales_clients ? "AND SALES_PERSON = ".$_SESSION['EXT_USER_CODE'] : "")." ) TT4,
			(select count(*) as CLIENT_LEADS
				from ".CLIENT_TABLE." 
				where ISDELETED = false
				AND CLIENT_TYPE = 'lead' ) TT4a,
			(select count(*) as CLIENT_PROSPECTS
				from ".CLIENT_TABLE." 
				where ISDELETED = false
				AND CLIENT_TYPE = 'prospect'
				".($my_sales_clients ? "AND SALES_PERSON = ".$_SESSION['EXT_USER_CODE'] : "")." ) TT4b,
			(select count(*) as CLIENT_CLIENTS
				from ".CLIENT_TABLE." 
				where ISDELETED = false
				AND CLIENT_TYPE = 'client'
				".($my_sales_clients ? "AND SALES_PERSON = ".$_SESSION['EXT_USER_CODE'] : "")." ) TT4c,
		
			(select count(*) as PALLETS
				from ".PALLET_TABLE." ) TT5" );
		
		write_homedata_cache( $result, $sts_debug );
	}	
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
