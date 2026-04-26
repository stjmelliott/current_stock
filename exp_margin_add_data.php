<?php 

// $Id: exp_margin_add_data.php 5617 2026-01-12 20:23:55Z dev $
// Update margin report data

// Set flag that this is a parent file
define( '_STS_INCLUDE', 1 );

// Setup Session
require_once( "include/sts_session_setup.php" );

require_once( "include/sts_config.php" );
set_time_limit(0);
ini_set('memory_limit', '1024M');
ini_set('implicit_flush', 1);
ini_set('output_buffering', 0);

//echo "<pre>";
//var_dump(ini_get('output_buffering'));
//var_dump(ini_get('zlib.output_compression'));
//echo "</pre>";


$sts_debug = isset($_GET['debug']); // && in_group( EXT_GROUP_DEBUG );
require_once( "include/sts_session_class.php" );

$my_session = sts_session::getInstance( $exspeedite_db, $sts_debug );
$my_session->access_check( EXT_GROUP_ADMIN );	// Make sure we should be here

if( isset($_GET['PW']) && $_GET['PW'] == 'VerySlow' ) {
	$sts_subtitle = "Gather Margin Report Data";
	require_once( "include/header_inc.php" );
	require_once( "include/navbar_inc.php" );
	require_once( "include/sts_shipment_class.php" );
	require_once( "include/sts_margin_report_class.php" );
	
	$mr = sts_margin_report::getInstance($exspeedite_db, $sts_debug);
	$shipment_table = sts_shipment::getInstance($exspeedite_db, $sts_debug);
	
	$filter = "AND NOT EXISTS (SELECT SHIPMENT_CODE FROM EXP_MARGIN_REPORT_DATA d
		WHERE sh.SHIPMENT_CODE = d.SHIPMENT_CODE)";
	$filter1a = '';
	
	if( isset($_GET['YEAR']) ) {
		$filter = "AND YEAR(PICKUP_DATE) = ".$_GET['YEAR'];
		$filter1a = "AND YEAR(PICKUP_DATE) = ".$_GET['YEAR'];
	} else if( isset($_GET['MONTH']) ) {
		$filter = $filter1a = "AND YEAR(PICKUP_DATE) = YEAR(NOW())
			AND MONTH(PICKUP_DATE) = ".$_GET['MONTH'];
	}
	
	$check = $mr->database->get_one_row("
		SELECT COUNT(*) NUM
		FROM EXP_SHIPMENT sh
	--	LEFT JOIN EXP_LOAD l
	--	ON l.LOAD_CODE = sh.load_code
		WHERE sh.CURRENT_STATUS != ".$shipment_table->behavior_state["cancel"]."
		and sh.BILLING_STATUS in (".$shipment_table->billing_behavior_state["approved"].", ".
		$shipment_table->billing_behavior_state["billed"].")
	--	and (sh.TOTAL_CHARGES > 0 -- remove zero revenue shipments
	--	 	OR COALESCE(CASE WHEN l.LOAD_REVENUE IS NULL THEN
	--				LOAD_REVENUE_CUR(l.LOAD_CODE, l.currency)
	--			ELSE l.LOAD_REVENUE END, 0) = 0)
		AND PICKUP_DATE > DATE_SUB(NOW(), INTERVAL 5 YEAR)
		".$filter."
		");
	
	$check1a = $mr->database->get_one_row("
		SELECT COUNT(*) NUM
		FROM EXP_SHIPMENT sh
		WHERE sh.CURRENT_STATUS = ".$shipment_table->behavior_state["cancel"]."
		AND PICKUP_DATE > DATE_SUB(NOW(), INTERVAL 5 YEAR)
		".$filter1a."
		");
		
	$check2 = $mr->database->get_one_row("
		SELECT COUNT(*) NUM
		FROM EXP_SHIPMENT sh, exp_margin_report_data d
		WHERE sh.SHIPMENT_CODE = d.SHIPMENT_CODE
		AND sh.pickup_date is not null
		");
			
	if( is_array($check) && count($check) == 1 && isset($check["NUM"]) ) {

		for ($i = 0; $i < ob_get_level(); $i++)
			ob_end_flush();
		ob_implicit_flush(1);

		if (ob_get_level() == 0) ob_start();
			
		echo '<div class="container" role="main">
		
		<h3>Get margin data for all '.(isset($_GET['YEAR']) ? $_GET['YEAR'].' ' : '').
		(isset($_GET['MONTH']) ? $_GET['MONTH'].' ' : '').
		$check["NUM"].' Shipments '.(is_array($check1a) && count($check1a) == 1 && isset($check1a["NUM"]) ? ' ('.$check1a["NUM"].' cancelled)' : '').' in the last 5 years'.(is_array($check2) && count($check2) == 1 && isset($check2["NUM"]) ? ' ('.$check2["NUM"].' already done)' : '').'</h3>
		<p>This may take a while.</p>'.str_pad('',4096);
		sleep(1);
		ob_flush(); flush();
		
		$result = $mr->update_all( isset($_GET['YEAR']) ? $_GET['YEAR'] : false,
			isset($_GET['MONTH']) ? $_GET['MONTH'] : false );
	
		echo '<p>Updated '.$result.' entries.</p>
		'.($my_session->superadmin() ? '
		<h2><a class="btn btn-sm btn-success" href="exp_margin_add_data.php?PW=VerySlow"><span class="glyphicon glyphicon-refresh"> Refresh</span></a></h2>
		' : '').'
			</div>';
		ob_flush(); flush();
	}
	
	require_once( "include/footer_inc.php" );
}
?>
