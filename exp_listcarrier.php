<?php 
// Set flag that this is a parent file
define( '_STS_INCLUDE', 1 );

// Setup Session
require_once( "include/sts_session_setup.php" );

require_once( "include/sts_config.php" );
$sts_debug = isset($_GET['debug']) && in_group( EXT_GROUP_DEBUG );
require_once( "include/sts_session_class.php" );

$my_session = sts_session::getInstance( $exspeedite_db, $sts_debug );
$my_session->access_check( $sts_table_access[CARRIER_TABLE] );	// Make sure we should be here

require_once( "include/sts_carrier_class.php" );
require_once( "include/sts_sw_carrier_class.php" );

function en( $val ) {
	return '"'.$val.'"';
}

function dt( $x ) {
	return isset($x) ? date("m/d/Y ", strtotime($x)):'';
}

//! SCR# 997 - Active CarrierContact Info Query
if( isset($_GET) && isset($_GET['export']) ) {
	$carrier_table = sts_carrier::getInstance($exspeedite_db, $sts_debug);
	$result = $carrier_table->database->get_multiple_rows("
		-- Step 1: Aggregate the contact information, ensuring ISDELETED = 0
		WITH aggregated_contact_info AS (
	    SELECT 
	        contact_code AS carrier_code,
	        MAX(contact_name) AS contact_name,
	        MAX(ADDRESS) AS ADDRESS,
	        MAX(city) AS city,
	        MAX(state) AS state,
	        MAX(zip_code) AS zip_code,
	        MAX(country) AS country,
	        MAX(phone_office) AS phone_office
	    FROM 
	        exp_contact_info
	    WHERE 
	        contact_type = 'carrier'
	        AND ISDELETED = 0
	    GROUP BY 
	        contact_code
	)
	
	-- Step 2: Combine with the carrier query
	SELECT 
	    c.carrier_code,
	    c.sage50_vendorid,
	    c.FED_CODE_NUM,
	    c.carrier_name,
	    aci.contact_name,
	    aci.ADDRESS,
	    aci.city,
	    aci.state,
	    aci.zip_code,
	    aci.country,
	    aci.phone_office
	FROM 
	    exp_carrier c
	LEFT JOIN 
	    aggregated_contact_info aci 
	ON 
	    c.carrier_code = aci.carrier_code
	WHERE 
	    c.carrier_code IN (
	        SELECT 
	            CARRIER_CODE 
	        FROM 
	            (SELECT 
	                CARRIER_CODE, 
	                CARRIER_NAME, 
	                CARRIER_EXPIRED(CARRIER_CODE) ISEXPIRED,
	                LIABILITY_DATE, 
	                AUTO_LIAB_DATE, 
	                CARGO_LIAB_DATE
	            FROM 
	                EXP_CARRIER
	            WHERE 
	                ISDELETED = 0 
	                AND CARRIER_TYPE != 'lumper') X
	        WHERE 
	            ISEXPIRED = 'GREEN'
	    )
	    AND c.ISDELETED = 0;
	");
	
	$csv_out = '';
	
	if( is_array($result) && count($result) > 0 ) {
		$labels = [];
		foreach( $result[0] as $label => $val ) {
			$labels[] = en($label);
		}
		$csv_out = implode(',', $labels).PHP_EOL;
		
		$trace =[];
		$mpids =[];
		foreach( $result as $row ) {
			$vals = [];
			foreach( $row as $label => $val ) {
				$vals[] = en($val);
			}
			$csv_out .= implode(',', $vals).PHP_EOL;
		}
		
		$filename = "\"Active_CarrierContact_".date("ymdHis") .".csv\"";
		header('Content-Type: text/csv; charset=utf-8');
		header ("Content-Disposition: attachment; filename=$filename");
		echo $csv_out;
		
	}
	
	die;
}

$sts_subtitle = "List Carriers";
require_once( "include/header_inc.php" );
require_once( "include/navbar_inc.php" );

?>
<div class="container-full" role="main">
<?php
require_once( "include/sts_result_class.php" );
require_once( "include/sts_setting_class.php" );

$carrier_table = sts_carrier::getInstance($exspeedite_db, $sts_debug);
$setting_table = sts_setting::getInstance($exspeedite_db, $sts_debug);
$sts_length_menu = $setting_table->get( 'option', 'LENGTH_MENU' );
$sts_export_sage50 = $setting_table->get( 'api', 'EXPORT_SAGE50_CSV' ) == 'true';
$sts_edi_enabled = $setting_table->get( 'api', 'EDI_ENABLED' ) == 'true';
$sts_list_carriers_both = $setting_table->get( 'option', 'LIST_CARRIERS_BOTH' ) == 'true';

$filters_html = '<div class="btn-group"><a class="btn btn-sm btn-success" href="exp_listcarrier.php"><span class="glyphicon glyphicon-refresh"></span></a>';

if( $sts_export_sage50 and in_group(EXT_GROUP_SAGE50) ) {
	$filters_html .= ' <a class="btn btn-sm btn-danger" href="exp_export_csv.php?pw=GoldUltimate&type=carrier"><span class="glyphicon glyphicon-list-alt"></span> <span class="glyphicon glyphicon-arrow-right"></span> Sage 50</a>';
}

//! SCR# 900 - list carrier screen
if( $sts_list_carriers_both && isset($_POST['SHOW_DELETED_EXP_CARRIER']) ) {
	$_SESSION['SHOW_DELETED_EXP_CARRIER'] = $_POST['SHOW_DELETED_EXP_CARRIER'];
}

if( $sts_list_carriers_both && ! isset($_SESSION['SHOW_DELETED_EXP_CARRIER']) ) {
	$_SESSION['SHOW_DELETED_EXP_CARRIER'] = "both";
}

//! SCR# 763 - add filter based on CARRIER_TYPE
if( ! isset($_SESSION['CARRIER_FILTER']) )
	$_SESSION['CARRIER_FILTER'] = 'all';
if( isset($_POST['CARRIER_FILTER']) ) $_SESSION['CARRIER_FILTER'] = $_POST['CARRIER_FILTER'];

$valid_types = $carrier_table->get_enum_choices( 'CARRIER_TYPE' );

$filters_html .= '<select class="form-control input-sm" name="CARRIER_FILTER"
	id="CARRIER_FILTER"   onchange="form.submit();">';
$filters_html .= '<option value="all" '.($_SESSION['CARRIER_FILTER'] == 'all' ? 'selected' : '').'>All Types</option>
		';
foreach( $valid_types as $value ) {
		$filters_html .= '<option value="'.$value.'" '.($_SESSION['CARRIER_FILTER'] == $value ? 'selected' : '').'>'.$value.'</option>
	';
}
$filters_html .= '</select>';

if( ! $sts_edi_enabled ) {
	unset($sts_result_carriers_layout['EDI_CONNECTION']);
}

if( ! isset($_SESSION['STATE_FILTER']) )
	$_SESSION['STATE_FILTER'] = 'all';
if( isset($_POST['STATE_FILTER']) ) $_SESSION['STATE_FILTER'] = $_POST['STATE_FILTER'];

// close the session here to avoid blocking
session_write_close();

$rslt = new sts_result( $carrier_table, false, $sts_debug );

$filters_html .= $carrier_table->usstates_menu( 'STATE_FILTER', $_SESSION['STATE_FILTER'] );

//! Add lookup carrier screen
$sw = sts_sw_carrier::getInstance( $exspeedite_db, $sts_debug );
if( $sw->enabled() ) {
	$filters_html .= '<a class="btn btn-sm btn-warning text-white tip" title="Search for carriers using FMCSA" href="exp_lookup_carrier.php"><span class="glyphicon glyphicon-plus-sign"></span></a>';
}

//! SCR# 939 - List Carrier Screen - show # with unexpired insurance
$expired = $carrier_table->alert_expired( true );
$filters_html .= $expired;

//! SCR# 997 - Active CarrierContact Info Query
$filters_html .= '<a class="btn btn-sm btn-primary tip" title="Active CarrierContact CSV export" href="exp_listcarrier.php?export"><span class="glyphicon glyphicon-th-list"></span></a>';

$sts_result_carriers_edit['filters_html'] = $filters_html;

$match = $rslt->get_match();

//! SCR# 763 - add filter based on CARRIER_TYPE
if( $_SESSION['CARRIER_FILTER'] != 'all' ) {
	$match .= ($match <> '' ? ' AND ' : '') . "CARRIER_TYPE = '".$_SESSION['CARRIER_FILTER']."'";
}

if( $_SESSION['STATE_FILTER'] <> 'all' ) {
	$match .= ($match <> '' ? ' and ' : '') . "(SELECT STATE FROM EXP_CONTACT_INFO".
			" WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = 'carrier'".
			" LIMIT 1) = '".$_SESSION['STATE_FILTER']."'";
}


echo $rslt->render( $sts_result_carriers_layout, $sts_result_carriers_edit, false, false );


?>
</div>
	<script language="JavaScript" type="text/javascript"><!--
		
		$(document).ready( function () {
			//alert(($(window).height() - 150) + "px");
			<?php if( ! $sts_debug ) { ?>
			document.documentElement.style.overflow = 'hidden';  // firefox, chrome
			document.body.scroll = "no"; // ie only
			<?php } ?>

			var opts = {
		        //"bLengthChange": false,
		        "bFilter": true,
		        stateSave: true,
		        "bSort": true,
		        "bInfo": true,
				//"bAutoWidth": false,
				//"bProcessing": true, 
				"sScrollX": "100%",
				"sScrollY": ($(window).height() - 275) + "px",
				//"sScrollXInner": "120%",
		        "lengthMenu": [<?php echo isset($sts_length_menu) ? $sts_length_menu : '25, 50, 100, 250'; ?>],
				"bPaginate": true,
				"bScrollCollapse": false,
				"bSortClasses": false,
				"order": [[ 1, "asc" ]],
				"processing": true,
				"serverSide": true,
				//"dom": "frtiS",
				"deferRender": true,
				"ajax": {
					"url": "exp_listcarrierajax.php",
					"data": function( d ) {
						d.match = encodeURIComponent("<?php echo $match; ?>");
					}

				},
				"columns": [
					{ "searchable": false, "orderable": false },
					<?php
						foreach( $sts_result_carriers_layout as $key => $row ) {
							if( $row["format"] <> 'hidden')
								echo '{ "data": "'.$key.'", "searchable": '.
								(isset($row["searchable"]) && ! $row["searchable"] ? 'false' : 'true' ).
								(isset($row["align"]) ? ', "className": "text-'.$row["align"].'"' : '').
									(isset($row["length"]) ? ', "width": "'.$row["length"].'px"' : '').
									(isset($row["format"]) && $row["format"] == 'hidden' ? ', "visible": false' : '').' },
						';
						}
					?>
				],
				"rowCallback": function( row, data ) {
					var expired = $(row).attr('expired');
					switch(expired) {
						case 'red':
							$(row).addClass("danger");
							break;
						
						case 'orange':
							$(row).addClass("inprogress2");
							break;
						
						case 'yellow':
							$(row).addClass("warning");
							break;
						
						case 'green':
						default:
							break;
					}
				}
						
			};
			
			var myTable = $('#EXP_CARRIER').dataTable(opts);
			$('#EXP_CARRIER').on( 'draw.dt', function () {
				myTable.$('.inform').popover({ 
					placement: 'top',
					html: 'true',
					container: 'body',
					trigger: 'hover',
					delay: { show: 50, hide: 3000 },
					title: '<strong>Information</strong> <button type="button" class="close" data-hide="confirm" data-delay="0" aria-hidden="true">&times;</button>' 
				});

				myTable.$('.confirm').popover({ 
					placement: 'top',
					html: 'true',
					container: 'body',
					trigger: 'hover',
					delay: { show: 50, hide: 3000 },
					title: '<strong>Confirm Action</strong> <button type="button" class="close" data-hide="confirm" data-delay="0" aria-hidden="true">&times;</button>' 
				});
			});
			//myTable.$("a[rel=popover]").popover().click(function(e) {e.preventDefault();});
			
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

require_once( "include/footer_inc.php" );
?>

