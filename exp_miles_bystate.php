<?php 

// $Id: exp_miles_bystate.php 5449 2025-03-10 23:59:48Z dev $
// Miles By State report

// Set flag that this is a parent file
define( '_STS_INCLUDE', 1 );

// Setup Session
require_once( "include/sts_session_setup.php" );

require_once( "include/sts_config.php" );
$sts_debug = (isset($_GET['debug'])  || isset($_POST['debug'])) && in_group( EXT_GROUP_DEBUG );
//$sts_debug = true;
require_once( "include/sts_session_class.php" );

$my_session = sts_session::getInstance( $exspeedite_db, $sts_debug );
$my_session->access_check( EXT_GROUP_FINANCE );	// Make sure we should be here

require_once( "include/sts_office_class.php" );

$office = sts_office::getInstance($exspeedite_db, $sts_debug);

	$sts_subtitle = "Miles By State";
	require_once( "include/header_inc.php" );
	require_once( "include/navbar_inc.php" );

function nm0( $x ) {
	return isset($x) && is_numeric($x) && $x != 0 ? number_format($x,0):'';
}

function nm1( $x ) {
	return isset($x) && is_numeric($x) && $x != 0 ? number_format($x,1):'';
}

function curr( $x ) {
	return isset($x) && is_numeric($x) && $x != 0 ? '$'.number_format($x,2):'';
}

function currz( $x ) {
	return isset($x) && is_numeric($x) ? '$'.number_format($x,2):'';
}

function dt( $x ) {
	return isset($x) ? str_replace(' ', '&nbsp;', date("m/d/Y", strtotime($x))):'';
}

function process_offices() {
	global $_POST;
	$matches = [];
	
	foreach( $_POST as $label => $value ) {
		if( strpos($label, 'OFFICE_') !== false ) {
			$matches[] = $value;
		}
	}
	
	sort($matches);
	
	return implode(',', $matches);
}

if( ! isset($_POST['MILES_FROM']) ) {	//! Display form
	$start = date("Y-m-d", strtotime( 'now - 1 month' ));
	$end = date("Y-m-d", strtotime( 'now' ));
		
	echo '<div class="container" role="main">
	<form class="form-horizontal" role="form" action="exp_miles_bystate.php" 
		method="post" enctype="multipart/form-data" 
		name="MILES" id="MILES">
		'.( $sts_debug ? '<input name="debug" type="hidden" value="on">' : '').'
	<h2>Miles By State <a class="btn btn-lg btn-default" id="MILES_CANCEL" href="index.php"><span class="glyphicon glyphicon-remove"></span> Back</a> <a class="btn btn-lg btn-success" id="DRIVER_PAY_REFRESH" href="exp_miles_bystate.php"><span class="glyphicon glyphicon-refresh"></span> Reset</a></h2>
	<div class="form-group">
		<div class="col-sm-6">
			'.$office->user_checkboxes( '<!-- OFFICES -->', false, true ).'
		</div>
		<div class="col-sm-6">
			<h3>FROM: <input type="date" value="'.$start.'" name="MILES_FROM"></h3>
			<h3>TO: <input type="date" value="'.$end.'" name="MILES_TO"></h3>
			
			<h3><button class="btn btn-lg btn-default" id="continue" name="getmiles" type="submit">Select And Continue <span class="glyphicon glyphicon-arrow-right"></span></button></h3>
		</div>
	</div>
	</form>
	
	<div class="alert alert-info">
		<h4><span class="glyphicon glyphicon-exclamation-sign"></span> Information</h4>
		<p>Some offices and time periods may not have sufficient data.</p>
		<p>Distance data per state is only collected for IFTA purposes for company tractors. Such data <span class="text-danger"><strong>does not exist for carriers</strong></span>.</p>
		<p>PC* Miler is used to collect distance by state once a load is completed. This can fail if the addresses trigger <span class="text-danger"><strong>Geocode errors</strong></span>.</p>
		<p>We have reported known Geocode errors to PC* Miler to resolve.</p>
		<p>Known addresses that cause <span class="text-danger"><strong>Geocode errors</strong></span>.</p>
		<ul>
		<li>283009 LOGISTICS DRIVE, Rocky View County, AB  T1Z 0A9</li>
		<li>16 FIMA CRESENT, Etobicoke, ON  M8W 3P9</li>
		</ul>
	
	</div>

	</div>
	';
?>
	<script language="JavaScript" type="text/javascript"><!--
		
		$(document).ready( function () {
			
			function toggle_all() {
				$('input.office').each(function() {
					if( $(this).prop('checked') )
						$(this).prop('checked', false);
					else
						$(this).prop('checked', 'checked');
				});
				
				validate_office();
			}
			
			
			$('#ALL_OFFICE').click(function () {
				toggle_all();
			});
			
			function validate_office() {
				if( '<?php echo $office_table->multi_company() ? 'true' : 'false'; ?>'
					== 'true' && $('#OFFICES').length &&
					! $('input[name=USER_GROUPS_driver]').prop('checked') &&
					! $('input[name=USER_GROUPS_mechanic]').prop('checked') ) {
					var count = 0;
					$('input.office').each(function() {
						if( $(this).prop('checked') ) count++;
					});
					//console.log('validate_office: ', count);
					if( count > 0 ) {
						office_valid = true;
						$('#OFFICE_HELP').prop('hidden', 'hidden');
						$('#continue').prop('disabled',false);
					} else {
						office_valid = false;
						$('#OFFICE_HELP').prop('hidden',false);
						$('#continue').prop('disabled', true);
					}
				} else {
					office_valid = true;
					$('#OFFICE_HELP').prop('hidden', 'hidden');
				}
			}

			$('input.office').change(function () {
				validate_office();
			});

			validate_office();
			
		});
	//--></script>
<?php

} else {	//! Process form
	$offices = process_offices();
	if( $sts_debug ) {
		echo "<pre>";
		var_dump($_POST, $offices);
		echo "</pre>";
	}

	$results = $office->database->get_multiple_rows("
		with fleet_table as
		(SELECT IFTA_JURISDICTION STATE, SUM(DIST_TRAVELED) DIST_FLEET
			FROM EXP_IFTA_LOG
			WHERE IFTA_DATE BETWEEN '".$_POST['MILES_FROM']."' AND '".$_POST['MILES_TO']."'
			AND CD_ORIGIN REGEXP '^[0-9]+$'
			AND (SELECT OFFICE_CODE FROM EXP_LOAD WHERE LOAD_CODE = CD_ORIGIN) IN (".$offices.")
			GROUP BY IFTA_JURISDICTION
			ORDER BY 1 ASC),
		
		carrier_table as
		(SELECT IFTA_JURISDICTION STATE, SUM(DIST_TRAVELED) DIST_CARRIER
			FROM EXP_CARRIER_LOG
			WHERE IFTA_DATE BETWEEN '".$_POST['MILES_FROM']."' AND '".$_POST['MILES_TO']."'
			AND (SELECT OFFICE_CODE FROM EXP_LOAD WHERE LOAD_CODE = CD_ORIGIN) IN (".$offices.")
			GROUP BY IFTA_JURISDICTION
			ORDER BY 1 ASC)
		
		select fleet_table.STATE, DIST_FLEET, DIST_CARRIER, (COALESCE(DIST_FLEET,0) + COALESCE(DIST_CARRIER,0)) AS TOTAL
		FROM fleet_table
		left join carrier_table
		on fleet_table.STATE = carrier_table.STATE
		union
		select carrier_table.STATE, DIST_FLEET, DIST_CARRIER, (COALESCE(DIST_FLEET,0) + COALESCE(DIST_CARRIER,0)) AS TOTAL
		FROM fleet_table
		right join carrier_table
		on fleet_table.STATE = carrier_table.STATE

		ORDER BY 1 ASC");
		
	echo '<div class="container" role="main">
<h2>Miles By State <a class="btn btn-lg btn-default" id="MILES_CANCEL" href="index.php"><span class="glyphicon glyphicon-remove"></span> Back</a> <a class="btn btn-lg btn-success" id="DRIVER_PAY_REFRESH" href="exp_miles_bystate.php"><span class="glyphicon glyphicon-refresh"></span> Reset</a></h2>
	';
		
	if( is_array($results) && count($results) > 0 ) {
	//	echo "<pre>";
	//	var_dump($results);
	//	echo "</pre>";

		echo '<div class="table-responsive well well-sm">
		<table class="display table table-condensed table-bordered table-hover" style="width: 50%;"  id="MILES">
		<thead><tr class="exspeedite-bg">
			<th>State/Province</th>
			<th class="text-right">Distance Fleet</th>
			<th class="text-right">Distance Carrier</th>
			<th class="text-right">Distance Total</th>
		</tr></thead>
		<tbody>
		';
		
		foreach( $results as $row ) {
			echo '<tr><td>'.$row['STATE'].'</td>
			<td class="text-right">'.$row['DIST_FLEET'].'</td>
			<td class="text-right">'.$row['DIST_CARRIER'].'</td>
			<td class="text-right">'.$row['TOTAL'].'</td>
			</tr>
			';
		}
		echo '</tbody>
		</table>
		</div>
		';
?>
	<script language="JavaScript" type="text/javascript"><!--
		
		$(document).ready( function () {
			<?php if( ! $sts_debug ) { ?>
			document.documentElement.style.overflow = 'hidden';  // firefox, chrome
			document.body.scroll = "no"; // ie only
			<?php } ?>

			$('#MILES').dataTable({
		        //"bLengthChange": false,
		        "bFilter": false,
		        "bSort": true,
		        "bInfo": false,
				"bAutoWidth": false,
				//"bProcessing": true, 
				"sScrollX": "100%",
				"sScrollY": ($(window).height() - 285) + "px",
				//"sScrollXInner": "120%",
				"bPaginate": false,
				"bScrollCollapse": false,
				"bSortClasses": false		
			});
			
			if( window.HANDLE_RESIZE_EVENTS ) {
				$(window).bind('resize', function(e) {
					console.log('resize event triggered');
					if (window.RT) clearTimeout(window.RT);
					window.RT = setTimeout(function() {
						this.location.reload(false); /* false to get page from cache */
					}, 100);
				});
			}
			
			
		});
	//--></script>
<?php
		
	} else {
		echo '<h3>No data for that period.</h3>';
	}
		
}

	require_once( "include/footer_inc.php" );
?>