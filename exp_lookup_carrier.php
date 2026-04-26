<?php
	
// $Id: exp_lookup_carrier.php 5449 2025-03-10 23:59:48Z dev $
// Look up carriers in FMCSA API, add them with SaferWatch API

// Set flag that this is a parent file
define( '_STS_INCLUDE', 1 );

// Setup Session
require_once( "include/sts_session_setup.php" );

require_once( "include/sts_config.php" );
$sts_debug = (isset($_GET['debug'])  || isset($_POST['debug'])) && in_group( EXT_GROUP_DEBUG );
//$sts_debug = true;
require_once( "include/sts_session_class.php" );

$my_session = sts_session::getInstance( $exspeedite_db, $sts_debug );
$my_session->access_check( EXT_GROUP_PROFILES );	// Make sure we should be here

$sts_subtitle = "Lookup Carrier";
require_once( "include/header_inc.php" );
require_once( "include/navbar_inc.php" );

?>
<div class="container-full" role="main">
<?php
	require_once( "include/sts_sw_carrier_class.php" );
	
	$sw = sts_sw_carrier::getInstance( $exspeedite_db, $sts_debug );
	
	if( ! $sw->enabled() ) {
		echo '<h2>SaferWatch API Not Enabled</h2>
		<p>This feature uses the SaferWatch API. Check with your Exspeedite admin.</p>';
		
	} else if( isset($_GET) && ! empty($_GET['DOTNUM']) ) {
		$sw->addDB();
		$new_code = $sw->saferwatchCarrierLookup("", $_GET['DOTNUM']);
		
		sleep(2);
		reload_page ( "exp_editcarrier.php?CODE=".$new_code );

		
	
	} else if( ! isset($_POST)  || empty($_POST['CARRIER_NAME']) ) {
		echo '<form class="form-horizontal" role="form" action="exp_lookup_carrier.php" 
		method="post" enctype="multipart/form-data" 
		name="LOOKUP_CARRIER" id="LOOKUP_CARRIER">
		'.( $sts_debug ? '<input name="debug" type="hidden" value="on">' : '').'
	
	<h2><img src="images/FMCSA-logo.png" alt="FMCSA-logo" width="" height="">Find Carrier <a class="btn btn-lg btn-default" id="LOOKUP_CARRIER_CANCEL" href="exp_listcarrier.php"><span class="glyphicon glyphicon-remove"></span> Back to Carriers</a></h2>
	
	<div class="form-group h3">
		<label class="col-sm-3" for="CARRIER_NAME">Carrier Name: </label>
		<div class="col-sm-4">
			<input class="form-control" name="CARRIER_NAME" id="CARRIER_NAME" type="text" placeholder="Prefix of Carrier name" maxlength="40">
		</div>
		<div class="col-sm-2">
			<button class="btn btn-lg btn-success" name="search" type="submit">Search</button>

		</div>
	</div>
	
	</form>
	</div>
	';
	} else {
		$results = $sw->fmcsa_lookup_carrier($_POST['CARRIER_NAME']);
		
		echo '<h2>Lookup Carrier <a class="btn btn-lg btn-default" id="LOOKUP_CARRIER_CANCEL" href="exp_lookup_carrier.php"><span class="glyphicon glyphicon-arrow-left"></span> Back</a></h2>
		';
		
		if( is_array($results) && count($results) > 0 ) {
			echo '<div class="table-responsive well well-sm">
			<table class="display table table-striped table-condensed table-bordered table-hover" id="CARRIER_SEARCH">
			<thead><tr class="exspeedite-bg">
			<th></th>
			<th>DOT#</th>
			<th>Legal Name</th>
			<th>DBA Name</th>
			<th>Address</th>
			</thead>
			<tbody>';
			
			foreach( $results as $carrier ) {
				list($code, $name, $legal_name) = $sw->lookup_carrier( $carrier["dotNumber"], false );
				
				echo '<tr><td>'.($code ? '<a class="btn btn-md btn-success tip" title="Carrier exists in Exspeedite" href="exp_editcarrier.php?CODE='.$code.'">EXISTS</a>' : '<a class="btn btn-md btn-danger tip" title="Add carrier to Exspeedite" href="exp_lookup_carrier.php?DOTNUM='.$carrier["dotNumber"].'">ADD</a>').'</td>
				<td>'.$carrier["dotNumber"].'</td>
				<td>'.$carrier["legalName"].'</td>
				<td>'.$carrier["dbaName"].'</td>
				<td>'.$carrier["phyCity"].', '.$carrier["phyState"].'</td>
				</tr>
				';
			}
			
			echo '</tbody>
			</table>
			</div>
			';
		} else {
			echo '<h3>No results returned from FMCSA.</h3>';
		}

					
	}

	

?>
</div>

	<script language="JavaScript" type="text/javascript"><!--
		
		$(document).ready( function () {

			$('#CARRIER_SEARCH').dataTable({
		        //"bLengthChange": false,
		        "bFilter": false,
		        "bSort": false,
		        "bInfo": false,
				"bAutoWidth": false,
				//"bProcessing": true, 
				"sScrollX": "100%",
				"sScrollY": ($(window).height() - 280) + "px",
				//"sScrollXInner": "200%",
		        //"lengthMenu": [[-1, 25, 50], ["All", 25, 50]],
				"bPaginate": false,
				"bScrollCollapse": true,
				"bSortClasses": false		
			});
		});
		
	//--></script>

<?php

require_once( "include/footer_inc.php" );
?>
