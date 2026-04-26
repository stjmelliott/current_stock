<?php

// $Id: exp_kt_hos_violations.php 5636 2026-01-27 23:48:01Z dev $
// Show HOS violations for driver

// Set flag that this is a parent file
define( '_STS_INCLUDE', 1 );

// Set flag that this is session readonly
define( '_STS_SESSION_READONLY', 1 );

// Setup Session
require_once( "include/sts_session_setup.php" );

require_once( "include/sts_config.php" );
$sts_debug = isset($_GET['debug']) && in_group( EXT_GROUP_DEBUG );
require_once( "include/sts_session_class.php" );

$my_session = sts_session::getInstance( $exspeedite_db, $sts_debug );
$my_session->access_check( $sts_table_access[TRACTOR_TABLE] );	// Make sure we should be here

if( isset($_GET['pw']) && $_GET['pw'] == 'PalaFer' && isset($_GET['code']) ) {
	require_once( "include/sts_driver_class.php" );
	require_once( "include/sts_setting_class.php" );
	require_once( "include/sts_ifta_log_class.php" );
	
	$kt = sts_keeptruckin::getInstance($exspeedite_db, $sts_debug);

	$driver_table = sts_driver::getInstance($exspeedite_db, $sts_debug);
	$setting_table = sts_setting::getInstance($exspeedite_db, $sts_debug);
	$kt_api_key = $setting_table->get( 'api', 'KEEPTRUCKIN_KEY' );
	$kt_enabled = ! empty($kt_api_key);
	
	if( $kt_enabled ) {
		$driver_number = false;
		$check = $driver_table->fetch_rows($driver_table->primary_key." = ".$_GET['code'], "COALESCE(KT_DRIVER_ID, 0) AS KT_DRIVER_ID, DRIVER_NUMBER");
		if( is_array($check) && count($check) == 1 ) {
			if( ! empty($check[0]["DRIVER_NUMBER"]) )
				$driver_number = $check[0]["DRIVER_NUMBER"];
			
			if( isset($check[0]["KT_DRIVER_ID"]) && $check[0]["KT_DRIVER_ID"] > 0 ) {
				$driver_id = $check[0]["KT_DRIVER_ID"];
			} else {
				$driver_id = $kt->try_to_find_driver_id( $_GET['code'] );
			}
		}
		
		if( $driver_id ) {
			
			$start_date = date('Y-m-d', strtotime('now - 1 year + 1 day'));
			$end_date = date('Y-m-d', strtotime('now'));
			$url = 'https://api.keeptruckin.com/v1//hos_violations?driver_ids%5B%5D='.
				$driver_id.
				'&min_start_time='.$start_date.'&max_start_time='.$end_date;

			$data = $kt->fetch( $url );

			//echo "<pre>hos_violations\n";
			//var_dump($data);
			//echo "</pre>";
			
			if( is_array($data) && count($data) > 0 ) {
				$kt->log_event( "exp_kt_hos_violations.php: ".count($data)." violations FOUND for driver ".$_GET['code'], EXT_ERROR_DEBUG);

				echo '<div class="table-responsive">
					<table class="display table table-striped table-condensed table-bordered table-hover" id="KT_VIOLATIONS">
					<thead><tr class="exspeedite-bg">
					<th>Start</th><th>End</th><th>Driver</th><th>Violation</th>
					</tr>
					</thead>
					<tbody>';
				foreach( $data as $row ) {
					$report = $row["hos_violation"];
					
					echo '<tr>
						<td>'.date("m/d/Y H:i", strtotime($report['start_time'])).'</td>
						<td>'.date("m/d/Y H:i", strtotime($report['end_time'])).'</td>
						<td>'.$report['user']["first_name"].' '.$report['user']["last_name"].'</td>
						<td>'.$report['name'].'</td>
					</tr>';
				}
				
				echo '</tbody>
			</table>
			</div>';
			} else if($data === false) {
				$kt->log_event( "exp_kt_hos_violations.php: Error Connecting to Motive, error = ".$kt->errmsg, EXT_ERROR_DEBUG);
				echo '<div class="container">
					<h2>Error Connecting to KeepTrukin</h2>
					<p>Details: '.$kt->errmsg.'</p>
					<p>This may be because you need to 
					<a href="exp_kt_oauth2.php" class="btn btn-success">re-authenticate with OAuth 2.0</a></p>
				</div>';
			} else {
				echo '<br><h4 class="text-center">No violations found for driver '.$_GET['code'].' (Motive ID = '.$driver_id.')</h4>';
				$kt->log_event( "exp_kt_hos_violations.php: No violations found for driver ".$_GET['code'], EXT_ERROR_DEBUG);

			}
		} else {
			echo '<br><h4 class="text-center">No matching Motive Driver ID for driver '.$_GET['code'].
			'<br>Unable to find violations.<br>Check Motive, and ensure Driver# matches'.
			($driver_number ? ' ('.$driver_number.' on Exspeedite)' : '').'</h4>';
			$kt->log_event( "exp_kt_hos_violations.php: No Motive Driver ID for driver ".$_GET['code'], EXT_ERROR_DEBUG);
			
			
		}
	}
	
	
}


?>