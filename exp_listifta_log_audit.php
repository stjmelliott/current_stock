<?php 

// $Id: exp_listifta_log_audit.php 5449 2025-03-10 23:59:48Z dev $
// Show IFTA Audit log

// Set flag that this is a parent file
define( '_STS_INCLUDE', 1 );

// Setup Session
require_once( "include/sts_session_setup.php" );

require_once( "include/sts_config.php" );
$sts_debug = isset($_GET['debug']) && in_group( EXT_GROUP_DEBUG );
require_once( "include/sts_session_class.php" );

$my_session = sts_session::getInstance( $exspeedite_db, $sts_debug );
$my_session->access_check( EXT_GROUP_ADMIN );	// Make sure we should be here

//require_once( "include/header_inc.php" );
//require_once( "include/navbar_inc.php" );

?>
<div class="container-full" role="main">
<?php
require_once( "include/sts_result_class.php" );
require_once( "include/sts_ifta_log_class.php" );

if( ! empty($_GET['load']) ) {
	$match = "CD_ORIGIN = '".$_GET['load']."'";

	$audit = sts_ift_log_audit::getInstance($exspeedite_db, $sts_debug);
	$rslt = new sts_result( $audit, $match, $sts_debug );
	echo $rslt->render( $sts_result_ifta_log_audit_layout, $sts_result_ifta_log_audit_edit );

} else if( ! empty($_GET['tractor']) ) {
	$match = "L.IFTA_TRACTOR = '".$_GET['tractor']."'";

	$audit = sts_ift_log_audit::getInstance($exspeedite_db, $sts_debug);
	$rslt = new sts_result( $audit, $match, $sts_debug );
	echo $rslt->render( $sts_result_ifta_log_audit_layout, $sts_result_ifta_log_audit_edit );

}


?>
</div>

	<script language="JavaScript" type="text/javascript"><!--
		
		$(document).ready( function () {
			//alert(($(window).height() - 150) + "px");

			$('#EXP_IFTA_LOG_AUDIT').dataTable({
		        //"bLengthChange": false,
		        "bFilter": false,
		        "bSort": false,
		        "bInfo": false,
				"bAutoWidth": true,
				//"bProcessing": true, 
			//	"sScrollX": "100%",
				"sScrollY": ($(window).height() - 355) + "px",
			//	"sScrollXInner": "120%",
		        "lengthMenu": [<?php echo isset($sts_length_menu) ? $sts_length_menu : '25, 50, 100, 250'; ?>],
				"bPaginate": true,
				"bScrollCollapse": false,
				"bSortClasses": false		
			});
			
			
		});
	//--></script>

<?php

require_once( "include/footer_inc.php" );
?>

