
<?php
	// $page is absolute
	function reload_page ( $page ) {
		global $sts_crm_root;
		echo "<script language=\"JavaScript\" type=\"text/javascript\">
//console.log('page = ', '".$page."');
window.location = \"".$page."\";

</script>";
	}

	function log_event( $message ) {
	//	$logfile = '/var/www/exspeedite.com/log/kt.txt';
		$logfile = '/var/www/html/log/kt.txt';
		
		if( (file_exists($logfile) && is_writable($logfile)) ||
			is_writable(dirname($logfile)) ) 
			file_put_contents($logfile, date('m/d/Y h:i:s A')." pid=".getmypid().
				" msg=".$message."\n\n", (file_exists($logfile) ? FILE_APPEND : 0) );
	}
	
	$default_redirect_url = 'https://www.exspeedite.com/';
	$dev_url = 'http://thefutureexperience.com/exp_kt_oauth2.php';
	$kt_url = 'https://keeptruckin.com/oauth/authorize';
	
	log_event( "exp_handle_kt.php: GET\n". print_r($_GET, true) );
	
	if( isset($_GET) && isset($_GET["code"]) && isset($_GET["state"]) ) {
		if( $_GET["state"] == 'dev')
			$url = $dev_url;
		else
			$url = 'https://'.$_GET["state"].'.exspeedite.net/exp_kt_oauth2.php';
		
		log_event( "exp_handle_kt.php: URL ".$url );
		
		reload_page( $url.'?code='.$_GET["code"] );
	} else {
		reload_page( $default_redirect_url ); 
	}
?>
