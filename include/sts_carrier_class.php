<?php

// $Id: sts_carrier_class.php 5449 2025-03-10 23:59:48Z dev $
// Carrier class, all things carrier.

// no direct access
defined('_STS_INCLUDE') or die('Restricted access');

require_once( "sts_table_class.php" );

require_once( "sts_setting_class.php" );

class sts_carrier extends sts_table {

	private $setting_table;
	private $warnings;
	private $expire_carriers;
	private $usstates;
	private $usstates_rev;
	private $req_general_ins;

	// Constructor does not need the table name
	public function __construct( $database, $debug = false ) {

		$this->debug = $debug;
		$this->primary_key = "CARRIER_CODE";
		if( $this->debug ) echo "<p>Create sts_carrier</p>";
		$this->setting_table = sts_setting::getInstance($database, $debug);
		$this->warnings = ($this->setting_table->get( 'option', 'CARRIER_WARNINGS' ) == 'true');
		$this->expire_carriers = $this->setting_table->get( 'option', 'EXPIRE_CARRIERS_ENABLED' ) == 'true';
		$this->req_general_ins = $this->setting_table->get( 'option', 'CARRIER_REQUIRE_GENERAL_INS' ) == 'true';
		parent::__construct( $database, CARRIER_TABLE, $debug);
	}
	
	// Allow re-use of objects - singleton function
	public static function getInstance( $database, $debug = false ) {
		static $instance = null;
		$myclass = get_class ();
		if( $debug ) echo "<p>Get instance of $myclass</p>";
		if (null === $instance) {
			$instance = new $myclass( $database, $debug );
		}
		return $instance;
    }	

	public function get_ins_req( $load) {
		$output = '';
		$result = $this->database->multi_query("
			SET @HC = HOME_CURRENCY(), @AO = GET_LOAD_ASOF($load);

			SELECT ASOF, MAX(GENERAL_LIAB_INS) AS GENERAL_LIAB_INS,
	            MAX(AUTO_LIAB_INS) AS AUTO_LIAB_INS,
	            MAX(CARGO_LIAB_INS) AS CARGO_LIAB_INS
            FROM (
			SELECT @AO AS ASOF,
				ROUND(COALESCE(MAX(C.GENERAL_LIAB_INS) *
					CONVERT_RATE( GET_ASOF(S.SHIPMENT_CODE), C.INSURANCE_CURRENCY, @HC),0)-5,0) AS GENERAL_LIAB_INS,
				ROUND(COALESCE(MAX(C.AUTO_LIAB_INS) *
					CONVERT_RATE( GET_ASOF(S.SHIPMENT_CODE), C.INSURANCE_CURRENCY, @HC),0)-5,0) AS AUTO_LIAB_INS,
				ROUND(COALESCE(MAX(C.CARGO_LIAB_INS) *
					CONVERT_RATE( GET_ASOF(S.SHIPMENT_CODE), C.INSURANCE_CURRENCY, @HC),0)-5,0) AS CARGO_LIAB_INS
			FROM EXP_LOAD L, EXP_SHIPMENT S, EXP_CLIENT C
			WHERE L.LOAD_CODE = $load
			AND L.LOAD_CODE = S.LOAD_CODE
			AND S.BILLTO_CLIENT_CODE = C.CLIENT_CODE
			GROUP BY S.SHIPMENT_CODE, C.INSURANCE_CURRENCY) X
            GROUP BY ASOF;");
		
		if( is_array($result) && count($result) == 1 ) {
			$output = "'".$result[0]["ASOF"]."', ".$result[0]["GENERAL_LIAB_INS"].", ".$result[0]["AUTO_LIAB_INS"].", ".$result[0]["CARGO_LIAB_INS"];
		}
		
		
		if( $this->debug ) {
			echo "<p>".__METHOD__.": result, output = </p>
			<pre>";
			var_dump($result, $output);
			echo "</pre>";
		}
		
		return $output;
	}	


	public function suggest( $load, $query ) {

		if( $this->debug ) echo "<p>suggest $query</p>";
		$result = $this->database->multi_query("
			SET @HC = HOME_CURRENCY(), @AO = GET_LOAD_ASOF($load);

			SELECT MAX(ROUND(COALESCE(C.GENERAL_LIAB_INS *
					CONVERT_RATE( GET_ASOF(S.SHIPMENT_CODE), C.INSURANCE_CURRENCY, @HC),0)-5,0)) AS GENERAL_LIAB_INS,
				MAX(ROUND(COALESCE(C.AUTO_LIAB_INS *
					CONVERT_RATE( GET_ASOF(S.SHIPMENT_CODE), C.INSURANCE_CURRENCY, @HC),0)-5,0)) AS AUTO_LIAB_INS,
				MAX(ROUND(COALESCE(C.CARGO_LIAB_INS *
					CONVERT_RATE( GET_ASOF(S.SHIPMENT_CODE), C.INSURANCE_CURRENCY, @HC),0)-5,0)) AS CARGO_LIAB_INS
			INTO @GEN, @AUTO, @CARGO			
			FROM EXP_LOAD L, EXP_SHIPMENT S, EXP_CLIENT C
			WHERE L.LOAD_CODE = $load
			AND L.LOAD_CODE = S.LOAD_CODE
			AND S.BILLTO_CLIENT_CODE = C.CLIENT_CODE
			GROUP BY L.LOAD_CODE;
			
			
			select CARRIER_CODE AS RESOURCE_CODE, 
			CARRIER_NAME AS RESOURCE_NAME,
			CONCAT_WS(', ',CARRIER_TYPE,
				(SELECT CONCAT_WS(', ',CONCAT_WS('x',PHONE_OFFICE,PHONE_EXT),
				PHONE_CELL,CITY,STATE) FROM EXP_CONTACT_INFO
				WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = 'carrier'
				AND CONTACT_TYPE in ('company', 'carrier')
				AND ISDELETED = false
				LIMIT 1)			
			 ) AS RESOURCE_EXTRA
			from EXP_CARRIER 
			where CARRIER_NAME like '".$query."%' AND ISDELETED = false
			AND CHECK_CARRIER_INS3(CARRIER_CODE, @AO, @GEN, @AUTO, @CARGO)
			AND CHECK_CTPAT($load, CARRIER_CODE)
			".($this->expire_carriers ? "AND CARRIER_EXPIRED(CARRIER_CODE) <> 'red'" : "")."
			limit 0, 20;");
		
		if( $this->debug ) {
			echo "<p>".__METHOD__.": result = </p>
			<pre>";
			var_dump($result);
			echo "</pre>";
		}


		return $result;
	}

	//! Find matching carriers for a given load
	// Used for modal display on adding resources screen 
	// See also exp_pick_asset.php
	public function matching( $load ) {

		$output = '';
		if( $this->debug ) echo "<p>".__METHOD__.": load = $load</p>";
		
		$carriers = $this->fetch_rows( "ISDELETED = false", 
			"CARRIER_CODE AS RESOURCE_CODE, 
			CARRIER_NAME AS RESOURCE_NAME,
			CARRIER_TYPE, CARRIER_SINCE, CURRENCY_CODE,
			(SELECT CONCAT_WS(', ', ADDRESS, CITY, STATE, ZIP_CODE, COUNTRY) FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = 'carrier'
			AND ISDELETED = false
			LIMIT 1) AS ADDRESS,
			".($this->expire_carriers ? "CARRIER_EXPIRED(CARRIER_CODE)" : "'green'")." AS ISEXPIRED" );
		
		if( is_array($carriers) && count($carriers) > 0) {
			$output .= '<div class="table-responsive">
			<table class="display table table-striped table-condensed table-bordered table-hover" id="CARRIERS">
			<thead><tr class="exspeedite-bg"><th>Select</th><th>Name</th><th>Type</th><th>Address</th><th>Since</th><th>Currency</th></tr>
			</thead>
			<tbody>';
			foreach( $carriers as $carrier ) {
				switch( $carrier['ISEXPIRED'] ) {
					case 'red':
						$color = ' class="danger"';
						break;
					case 'orange':
						$color = ' class="inprogress2"';
						break;
					case 'yellow':
						$color = ' class="warning"';
						break;
					default:
						$color = '';
						break;
				}
				if( $carrier['ISEXPIRED'] <> 'red' )
					$button = '<a class="btn btn-sm btn-success carrier_pick" id="CARRIER_'.$carrier['RESOURCE_CODE'].'"
					data-resource_code = "'.$carrier['RESOURCE_CODE'].'"
					data-resource = "'.$carrier['RESOURCE_NAME'].'"
					><span class="glyphicon glyphicon-plus"></span></a>';
				else
					$button = '<a class="btn btn-sm btn-danger disabled" name="disabled"><span class="glyphicon glyphicon-remove"></span></a>';
				$output .= '<tr'.$color.'><td>'.$button.'</td>
				<td>'.$carrier['RESOURCE_NAME'].'</td>
				<td>'.$carrier['CARRIER_TYPE'].'</td>
				<td>'.$carrier['ADDRESS'].'</td>
				<td>'.date("m/d/Y", strtotime($carrier['CARRIER_SINCE'])).'</td>
				<td>'.$carrier['CURRENCY_CODE'].'</td>
				</tr>';
			}
		}
		$output .= '</tbody>
		</table>
		</div>
		';

		
		return $output;
	}
	
	//! Compare the insurance a carrier has with the insurance needed
	// if $carrier_code is false, use the one assigned.
	//! SCR# 639 - include currency in comparisons
	public function check_suitable( $load, $carrier_code = false ) {

		if( $this->debug ) echo "<p>check_suitable $load</p>";
		$response = '';
		if( $this->warnings ) {
			if( $carrier_code ) {
				$carrier = $this->database->get_one_row(
					"SELECT c.CARRIER_CODE, c.CARRIER_NAME,
					COALESCE(c.GENERAL_LIAB_INS, 0) GENERAL_LIAB_INS,
					COALESCE(c.AUTO_LIAB_INS, 0) AUTO_LIAB_INS,
					COALESCE(c.CARGO_LIAB_INS, 0) CARGO_LIAB_INS,
					LIABILITY_DATE, COALESCE(DATEDIFF(LIABILITY_DATE, CURRENT_DATE), 0) GENERAL_DAYS,
					AUTO_LIAB_DATE, COALESCE(DATEDIFF(AUTO_LIAB_DATE, CURRENT_DATE), 0) AUTO_DAYS,
					CARGO_LIAB_DATE, COALESCE(DATEDIFF(CARGO_LIAB_DATE, CURRENT_DATE), 0) CARGO_DAYS,
					CURRENCY_CODE
					FROM EXP_CARRIER c
					WHERE c.CARRIER_CODE = $carrier_code" );
			} else {
				$carrier = $this->database->get_one_row(
					"SELECT c.CARRIER_CODE, c.CARRIER_NAME,
					COALESCE(c.GENERAL_LIAB_INS, 0) GENERAL_LIAB_INS,
					COALESCE(c.AUTO_LIAB_INS, 0) AUTO_LIAB_INS,
					COALESCE(c.CARGO_LIAB_INS, 0) CARGO_LIAB_INS,
					LIABILITY_DATE, COALESCE(DATEDIFF(LIABILITY_DATE, CURRENT_DATE), 0) GENERAL_DAYS,
					AUTO_LIAB_DATE, COALESCE(DATEDIFF(AUTO_LIAB_DATE, CURRENT_DATE), 0) AUTO_DAYS,
					CARGO_LIAB_DATE, COALESCE(DATEDIFF(CARGO_LIAB_DATE, CURRENT_DATE), 0) CARGO_DAYS,
					CURRENCY_CODE
					FROM EXP_LOAD l, EXP_CARRIER c
					WHERE l.LOAD_CODE = $load
					AND l.CARRIER = c.CARRIER_CODE" );
			}
			
			if( $this->debug ) {
				echo "<pre>carrier\n";
				var_dump($carrier);
				echo "</pre>";
			}
			
			if( is_array($carrier) && isset($carrier["CARRIER_CODE"]) )	{
				$general = $carrier["GENERAL_LIAB_INS"];
				$auto = $carrier["AUTO_LIAB_INS"];
				$cargo = $carrier["CARGO_LIAB_INS"];
				$general_days = $carrier["GENERAL_DAYS"];
				$auto_days = $carrier["AUTO_DAYS"];
				$cargo_days = $carrier["CARGO_DAYS"];
				$curr = $carrier["CURRENCY_CODE"];
							
				$clients = $this->database->get_multiple_rows(
					"SELECT s.SHIPMENT_CODE, c.CLIENT_NAME, c.CLIENT_CODE,
					COALESCE(c.GENERAL_LIAB_INS,0) AS GENERAL_LIAB_INS,
					COALESCE(c.AUTO_LIAB_INS,0) AS AUTO_LIAB_INS,
					COALESCE(c.CARGO_LIAB_INS,0) AS CARGO_LIAB_INS,
					
					COALESCE(c.GENERAL_LIAB_INS,0) *
						CONVERT_RATE( get_asof(s.SHIPMENT_CODE), c.INSURANCE_CURRENCY, '".$curr."')
							AS GENERAL_LIAB_INS_CONV,
					COALESCE(c.AUTO_LIAB_INS,0) *
						CONVERT_RATE( get_asof(s.SHIPMENT_CODE), c.INSURANCE_CURRENCY, '".$curr."')
							AS AUTO_LIAB_INS_CONV,
					COALESCE(c.CARGO_LIAB_INS,0) *
						CONVERT_RATE( get_asof(s.SHIPMENT_CODE), c.INSURANCE_CURRENCY, '".$curr."')
							AS CARGO_LIAB_INS_CONV,
					INSURANCE_CURRENCY
					FROM EXP_LOAD l, EXP_SHIPMENT s, EXP_CLIENT c
					WHERE l.LOAD_CODE = $load
					AND l.LOAD_CODE = s.LOAD_CODE
					AND s.BILLTO_CLIENT_CODE = c.CLIENT_CODE" );
				
				if( is_array($clients) && count($clients) > 0 ) {
					$responses = array();
					foreach( $clients as $client ) {
						$ccurr = $client["INSURANCE_CURRENCY"];

						if($client["GENERAL_LIAB_INS_CONV"] > 0 && $general_days <= 0 )
							$responses[] =  '<span class="text-danger"><strong><span class="glyphicon glyphicon-warning-sign"></span></strong> Carrier <a href="exp_editcarrier.php?CODE='.$carrier_code.'">'.$carrier["CARRIER_NAME"].'</a> has EXPIRED/MISSING general insurance. <a href="exp_editclient.php?CODE='.$client["CLIENT_CODE"].'">'.$client["CLIENT_NAME"].'</a> (<a href="exp_addshipment.php?CODE='.$client["SHIPMENT_CODE"].'">'.$client["SHIPMENT_CODE"].'</a>) requires '.$ccurr.' $ '.number_format($client["GENERAL_LIAB_INS"]).'.</span>';

						else if($client["GENERAL_LIAB_INS_CONV"] > $general)
							$responses[] =  '<span class="text-danger"><strong><span class="glyphicon glyphicon-warning-sign"></span></strong> Carrier <a href="exp_editcarrier.php?CODE='.$carrier_code.'">'.$carrier["CARRIER_NAME"].'</a> has '.$curr.' $ '.number_format($general).' general insurance. <a href="exp_editclient.php?CODE='.$client["CLIENT_CODE"].'">'.$client["CLIENT_NAME"].'</a> (<a href="exp_addshipment.php?CODE='.$client["SHIPMENT_CODE"].'">'.$client["SHIPMENT_CODE"].'</a>) requires '.$ccurr.' $ '.number_format($client["GENERAL_LIAB_INS"]).'.</span>';
							
						if($client["AUTO_LIAB_INS_CONV"] > 0 && $auto_days <= 0)
							$responses[] =  '<span class="text-danger"><strong><span class="glyphicon glyphicon-warning-sign"></span></strong> Carrier <a href="exp_editcarrier.php?CODE='.$carrier_code.'">'.$carrier["CARRIER_NAME"].'</a> has EXPIRED/MISSING auto insurance. <a href="exp_editclient.php?CODE='.$client["CLIENT_CODE"].'">'.$client["CLIENT_NAME"].'</a> (<a href="exp_addshipment.php?CODE='.$client["SHIPMENT_CODE"].'">'.$client["SHIPMENT_CODE"].'</a>) requires '.$ccurr.' $ '.number_format($client["AUTO_LIAB_INS"]).'.</span>';
							
						else if($client["AUTO_LIAB_INS_CONV"] > $auto)
							$responses[] =  '<span class="text-danger"><strong><span class="glyphicon glyphicon-warning-sign"></span></strong> Carrier <a href="exp_editcarrier.php?CODE='.$carrier_code.'">'.$carrier["CARRIER_NAME"].'</a> has '.$curr.' $ '.number_format($auto).' auto insurance. <a href="exp_editclient.php?CODE='.$client["CLIENT_CODE"].'">'.$client["CLIENT_NAME"].'</a> (<a href="exp_addshipment.php?CODE='.$client["SHIPMENT_CODE"].'">'.$client["SHIPMENT_CODE"].'</a>) requires '.$ccurr.' $ '.number_format($client["AUTO_LIAB_INS"]).'.</span>';
							
						if($client["CARGO_LIAB_INS_CONV"] > 0 && $cargo_days <= 0)
							$responses[] =  '<span class="text-danger"><strong><span class="glyphicon glyphicon-warning-sign"></span></strong> Carrier <a href="exp_editcarrier.php?CODE='.$carrier_code.'">'.$carrier["CARRIER_NAME"].'</a> has EXPIRED/MISSING cargo insurance. <a href="exp_editclient.php?CODE='.$client["CLIENT_CODE"].'">'.$client["CLIENT_NAME"].'</a> (<a href="exp_addshipment.php?CODE='.$client["SHIPMENT_CODE"].'">'.$client["SHIPMENT_CODE"].'</a>) requires '.$ccurr.' $ '.number_format($client["CARGO_LIAB_INS"]).'.</span>';

						else if($client["CARGO_LIAB_INS_CONV"] > $cargo)
							$responses[] =  '<span class="text-danger"><strong><span class="glyphicon glyphicon-warning-sign"></span></strong> Carrier <a href="exp_editcarrier.php?CODE='.$carrier_code.'">'.$carrier["CARRIER_NAME"].'</a> has '.$curr.' $ '.number_format($cargo).' cargo insurance. <a href="exp_editclient.php?CODE='.$client["CLIENT_CODE"].'">'.$client["CLIENT_NAME"].'</a> (<a href="exp_addshipment.php?CODE='.$client["SHIPMENT_CODE"].'">'.$client["SHIPMENT_CODE"].'</a>) requires '.$ccurr.' $ '.number_format($client["CARGO_LIAB_INS"]).'.</span>';
					}
					
					if( count($responses) > 0 )
						$response = '<div class="alert alert-danger" role="alert"><p>'.implode("<br>\n", $responses).'</p></div>';
				}
			}
		}
			
		return $response;
	}

	public function check_expired( $pk, $wrap = true, $row = false ) {
		$response = '';
		$r = $responses = array();
		if( $row == false ) {
			$check = $this->fetch_rows( $this->primary_key." = ".$pk." AND ISDELETED = false
				AND CARRIER_TYPE != 'lumper'",
				"LIABILITY_DATE, GENERAL_LIAB_INS, DATEDIFF(LIABILITY_DATE, CURRENT_DATE) GENERAL_DAYS,
				AUTO_LIAB_DATE, DATEDIFF(AUTO_LIAB_DATE, CURRENT_DATE) AUTO_DAYS,
				CARGO_LIAB_DATE, DATEDIFF(CARGO_LIAB_DATE, CURRENT_DATE) CARGO_DAYS" );
			if( is_array($check) && count($check) == 1 ) {
				$r = $check[0];
			}
		} else {
			$r = $row;
		}
		
		if( $this->req_general_ins &&
			(empty($r["LIABILITY_DATE"]) || empty($r["GENERAL_LIAB_INS"])) )
			$responses[] =  '<span class="text-danger"><strong><span class="glyphicon glyphicon-warning-sign"></span></strong> Carrier mandatory general insurance missing.</span>';
		else if( isset($r["LIABILITY_DATE"]) && $r["GENERAL_DAYS"] <= 0 )
			$responses[] =  '<span class="text-danger"><strong><span class="glyphicon glyphicon-warning-sign"></span></strong> Carrier general insurance date ('.date("m/d/Y", strtotime($r["LIABILITY_DATE"])).') expired.</span>';
		else if( isset($r["LIABILITY_DATE"]) && $r["GENERAL_DAYS"] < 15 )
			$responses[] =  '<span class="text-inprogress2"><strong><span class="glyphicon glyphicon-warning-sign"></span></strong> Carrier general insurance date ('.date("m/d/Y", strtotime($r["LIABILITY_DATE"])).') expires < 15 days.</span>';
		else if( isset($r["LIABILITY_DATE"]) && $r["GENERAL_DAYS"] < 30 )
			$responses[] =  '<span class="text-warning"><strong><span class="glyphicon glyphicon-warning-sign"></span></strong> Carrier general insurance date ('.date("m/d/Y", strtotime($r["LIABILITY_DATE"])).') expires < 30 days.</span>';
		
		if( ! isset($r["AUTO_LIAB_DATE"]) || is_null($r["AUTO_LIAB_DATE"]))
			$responses[] =  '<span class="text-danger"><strong><span class="glyphicon glyphicon-warning-sign"></span></strong> Missing carrier auto insurance date.</span>';
		else if( $r["AUTO_DAYS"] <= 0 )
			$responses[] =  '<span class="text-danger"><strong><span class="glyphicon glyphicon-warning-sign"></span></strong> Carrier auto insurance date ('.date("m/d/Y", strtotime($r["AUTO_LIAB_DATE"])).') expired.</span>';
		else if( $r["AUTO_DAYS"] < 15 )
			$responses[] =  '<span class="text-inprogress2"><strong><span class="glyphicon glyphicon-warning-sign"></span></strong> Carrier auto insurance date ('.date("m/d/Y", strtotime($r["AUTO_LIAB_DATE"])).') expires < 15 days.</span>';
		else if( $r["AUTO_DAYS"] < 30 )
			$responses[] =  '<span class="text-warning"><strong><span class="glyphicon glyphicon-warning-sign"></span></strong> Carrier auto insurance date ('.date("m/d/Y", strtotime($r["AUTO_LIAB_DATE"])).') expires < 30 days.</span>';
		
		if( ! isset($r["CARGO_LIAB_DATE"]) || is_null($r["CARGO_LIAB_DATE"]))
			$responses[] =  '<span class="text-danger"><strong><span class="glyphicon glyphicon-warning-sign"></span></strong> Missing carrier cargo insurance date.</span>';
		else if( $r["CARGO_DAYS"] <= 0 )
			$responses[] =  '<span class="text-danger"><strong><span class="glyphicon glyphicon-warning-sign"></span></strong> Carrier cargo insurance date ('.date("m/d/Y", strtotime($r["CARGO_LIAB_DATE"])).') expired.</span>';
		else if( $r["CARGO_DAYS"] < 15 )
			$responses[] =  '<span class="text-inprogress2"><strong><span class="glyphicon glyphicon-warning-sign"></span></strong> Carrier cargo insurance date ('.date("m/d/Y", strtotime($r["CARGO_LIAB_DATE"])).') expires < 15 days.</span>';
		else if( $r["CARGO_DAYS"] < 30 )
			$responses[] =  '<span class="text-warning"><strong><span class="glyphicon glyphicon-warning-sign"></span></strong> Carrier cargo insurance date ('.date("m/d/Y", strtotime($r["CARGO_LIAB_DATE"])).') expires < 30 days.</span>';
		
		if( count($responses) > 0 ) {
			$response = implode("<br>\n", $responses);
			if( $wrap )
				$response = '<div class="panel panel-default tighter">
<div class="panel-body" style="padding: 5px;">'.$response.'</div></div>';
		}
		
		return $response;
	}

	//! Display expired carriers
	public function alert_expired( $count = false ) {
		$result = '';

		$expired = $this->database->get_multiple_rows("
			SELECT CARRIER_CODE, CARRIER_NAME, ISEXPIRED,
				LIABILITY_DATE, GENERAL_LIAB_INS, DATEDIFF(LIABILITY_DATE, CURRENT_DATE) GENERAL_DAYS,
				AUTO_LIAB_DATE, DATEDIFF(AUTO_LIAB_DATE, CURRENT_DATE) AUTO_DAYS,
				CARGO_LIAB_DATE, DATEDIFF(CARGO_LIAB_DATE, CURRENT_DATE) CARGO_DAYS
			FROM
			(SELECT CARRIER_CODE, CARRIER_NAME, CARRIER_EXPIRED(CARRIER_CODE) ISEXPIRED,
			LIABILITY_DATE, AUTO_LIAB_DATE, CARGO_LIAB_DATE, GENERAL_LIAB_INS
			FROM EXP_CARRIER
			WHERE ISDELETED = 0 AND CARRIER_TYPE != 'lumper') X
			WHERE ISEXPIRED <> 'GREEN'
			ORDER BY FIELD(ISEXPIRED, 'red', 'orange', 'yellow'), CARRIER_CODE ASC");
		if( is_array($expired) && count($expired) > 0 ) {
			$actions = array();
			foreach($expired as $row) {
				switch( $row["ISEXPIRED"] ) {
					case 'red': $colour = 'danger'; break;
					case 'orange': $colour = 'warning'; break;
					case 'yellow': $colour = 'yellow'; break;
					default: $colour = 'default'; break;
				}
				//! SCR# 616 - Get details for popup
				$details = $this->check_expired( $row["CARRIER_CODE"], false, $row );
				$actions[] = '<a class="btn btn-sm btn-'.$colour.' inform" href="exp_editcarrier.php?CODE='.$row["CARRIER_CODE"].'" title="<strong>'.$row["CARRIER_NAME"].'</strong>" data-content=\''.$details.'\'>'.$row["CARRIER_NAME"].'</a>';
			}
			if( $count ) {
				$num_expired = count($expired);
				$check = $this->database->get_one_row("SELECT COUNT(*) NUM
					FROM EXP_CARRIER
					WHERE ISDELETED = 0 AND CARRIER_TYPE != 'lumper'");
				if( is_array($check) && isset($check['NUM']) ) {
					$num_total = $check['NUM'];
					$num_valid = $num_total - $num_expired;
				} else {
					$num_total = $num_valid = 0;
				}
				
				
				$result = ' <span class="label label-default"><span class="glyphicon glyphicon-warning-sign"></span> '.$num_expired.' expired, <span class="glyphicon glyphicon-ok"></span> '.$num_valid.' valid insurance</span>';
			} else {
				$result = '<p style="margin-bottom: 5px;"><a class="btn btn-sm btn-primary" data-toggle="collapse" href="#collapseCarriers" aria-expanded="false" aria-controls="collapseCarriers"><span class="glyphicon glyphicon-warning-sign"></span> '.plural(count($expired), ' Carrier').' need attention</a> (red=expired, orange=under 15 days, yellow=needs attention soon)</p>
			<div class="collapse" id="collapseCarriers">
			<p>'.implode(" ", $actions).'</p>
			</div>';
			}

		}		
		return $result;
	}

	private function load_usstates() {
		
		$states_table = new sts_table($this->database, STATES_TABLE, false ); //$this->debug
		
		$this->usstates = array();
		foreach( $states_table->fetch_rows("", "*", "STATE_NAME ASC") as $row ) {
			$this->usstates[$row['abbrev']] = $row['STATE_NAME'];
			$this->usstates_rev[strtolower($row['STATE_NAME'])] = $row['abbrev'];
		}
	}

	public function usstates_menu( $name, $value ) {
		
		$output = '';
		if( ! is_array($this->usstates) )
			$this->load_usstates();
			
		$output .= '<select class="form-control input-sm" name="'.$name.'" id="'.$name.'"  onchange="form.submit();">
				<option value="all"'.($value=='all' ? ' selected' : '').'>All</option>';
		foreach( $this->usstates as $abbrev => $state_name ) {
			$output .= '
				<option value="'.$abbrev.'" '.($abbrev==$value ? 'selected' : '').'>'.$abbrev.'</option>';
		}
		$output .= '
			</select>';
			
		return $output;
	}
	
	public function mass_email() {
		$list = $this->database->get_multiple_rows("
			SELECT CARRIER_CODE, CARRIER_NAME, MASS_EMAIL,
			(SELECT PHONE_OFFICE FROM
			EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE
			AND CONTACT_SOURCE = 'carrier'
			AND CONTACT_TYPE IN ( 'carrier', 'company')
			LIMIT 1) AS PHONE_OFFICE,
			(SELECT PHONE_EXT FROM
			EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE
			AND CONTACT_SOURCE = 'carrier'
			AND CONTACT_TYPE IN ( 'carrier', 'company')
			LIMIT 1) AS PHONE_EXT,
			(SELECT STATE FROM
			EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE
			AND CONTACT_SOURCE = 'carrier'
			AND CONTACT_TYPE IN ( 'carrier', 'company')
			LIMIT 1) AS STATE
			
			FROM EXP_CARRIER,
			
			(SELECT DISTINCT CARRIER
			FROM EXP_LOAD
			WHERE COMPLETED_DATE > DATE_SUB(CURRENT_DATE, INTERVAL 1 YEAR)
			AND CARRIER IS NOT NULL) LOADS
			WHERE LOADS.CARRIER = EXP_CARRIER.CARRIER_CODE
			AND MASS_EMAIL IS NOT NULL
			AND MASS_OPT_OUT = 0
		");
		
		return $list;
	}
		
}

class sts_top20_carrier extends sts_carrier {

	// Constructor does not need the table name
	public function __construct( $database, $debug = false ) {

		if( $debug ) echo "<p>Create sts_top20_carrier</p>";
		parent::__construct( $database, $debug);
	}
	
	// Allow re-use of objects - singleton function
	public static function getInstance( $database, $debug = false ) {
		static $instance = null;
		$myclass = get_class ();
		if( $debug ) echo "<p>Get instance of $myclass</p>";
		if (null === $instance) {
			$instance = new $myclass( $database, $debug );
		}
		return $instance;
    }

	// Fetch one or more rows
	public function fetch_rows( $match = "", $fields = "*", $order = "", $limit = "", $groupby = "", $match2 = "" ) {
		if( $this->debug ) echo "<p>sts_mileage > fetch_rows m = $match<br>m2 = $match2</p>";
		$result = $this->database->get_multiple_rows("SELECT $fields FROM
	(
        SELECT C.CARRIER_CODE,
    C.NUM,
    C.REVENUE,
    C.EXPENSE,
    C.AVG_REVENUE,
    C.AVG_EXPENSE,
    C.MARGIN,
    C.AVG_MARGIN
		FROM (

	    SELECT 
	        L.CARRIER AS CARRIER_CODE,
	        COUNT(*) AS NUM,
	        ROUND(SUM(L.LOAD_REVENUE), 0) AS REVENUE,
	        ROUND(SUM(L.LOAD_EXPENSE), 0) AS EXPENSE,
	        ROUND(AVG(L.LOAD_REVENUE), 0) AS AVG_REVENUE,
	        ROUND(AVG(L.LOAD_EXPENSE), 0) AS AVG_EXPENSE,
	        ROUND(SUM(L.LOAD_REVENUE) - SUM(L.LOAD_EXPENSE), 0) AS MARGIN,
	        ROUND(AVG(L.LOAD_REVENUE) - AVG(L.LOAD_EXPENSE), 0) AS AVG_MARGIN
	    FROM EXP_LOAD L
	    WHERE L.CARRIER > 0
	      AND L.CURRENT_STATUS IN (33, 34)
	      AND L.COMPLETED_DATE > DATE_SUB(NOW(), INTERVAL 12 MONTH)
	    GROUP BY L.CARRIER
	    ORDER BY REVENUE DESC -- Sort by revenue to get top carriers
	    LIMIT 20 -- Apply the limit after sorting
	) C
    ) EXP_CARRIER");
		
		
	/*	$result = $this->database->get_multiple_rows("SELECT $fields FROM
			(SELECT CARRIER_CODE, NUM, REVENUE, EXPENSE,
			    ROUND(REVENUE-EXPENSE,0) MARGIN,
			    AVG_REVENUE, AVG_EXPENSE,
			    ROUND(AVG_REVENUE-AVG_EXPENSE,0) AVG_MARGIN
			
			FROM (
			SELECT CARRIER_CODE, COUNT(*) AS NUM,
				ROUND(SUM(REVENUE),0) AS REVENUE,
				ROUND(SUM(EXPENSE),0) AS EXPENSE,
				ROUND(AVG(REVENUE),0) AS AVG_REVENUE,
				ROUND(AVG(EXPENSE),0) AS AVG_EXPENSE
                
			FROM ( SELECT L.LOAD_CODE, L.CARRIER AS CARRIER_CODE, 
				COALESCE(L.LOAD_EXPENSE,0) EXPENSE,
				COALESCE(L.LOAD_REVENUE,0) REVENUE
				FROM EXP_LOAD L
				WHERE L.CARRIER > 0
				AND L.CURRENT_STATUS IN (33, 34)
				AND L.COMPLETED_DATE > DATE_SUB(NOW(), INTERVAL 12 MONTH)
				) C
                GROUP BY CARRIER_CODE
				LIMIT 20) D
				".($order <> "" ? "ORDER BY $order" : "")."
			) EXP_CARRIER
			");
			*/
		
		if( $this->debug ) {
			echo "<p>result for $this->table_name = </p>
			<pre>";
			var_dump($result);
			echo "</pre>";
		}
		return $result;
	}
}

class sts_bidding extends sts_carrier {

	// Constructor does not need the table name
	public function __construct( $database, $debug = false ) {

		if( $debug ) echo "<p>Create sts_bidding</p>";
		parent::__construct( $database, $debug);
	}
	
	// Allow re-use of objects - singleton function
	public static function getInstance( $database, $debug = false ) {
		static $instance = null;
		$myclass = get_class ();
		if( $debug ) echo "<p>Get instance of $myclass</p>";
		if (null === $instance) {
			$instance = new $myclass( $database, $debug );
		}
		return $instance;
    }

	// Fetch one or more rows - original version
	public function fetch_rows1( $match = "", $fields = "*", $order = "", $limit = "", $groupby = "", $match2 = "" ) {
		global $_SESSION;
		if( $this->debug ) echo "<h2>".__METHOD__.": m = $match<br>m2 = $match2</h2>";
		$result = $this->database->get_multiple_rows("SELECT $fields FROM
			(select CARRIER_CODE, CARRIER_NAME, ROUND(CARRIER_TOTAL,0) as CARRIER_RATE,

			(SELECT GROUP_CONCAT(CONCAT(SHIPPER_CITY, ',', SHIPPER_STATE)
			ORDER BY SHIPMENT_CODE ASC SEPARATOR ' ')
			FROM EXP_SHIPMENT
			WHERE EXP_SHIPMENT.LOAD_CODE = EXP_LOAD.LOAD_CODE
			) AS PICK_UPS,
			
			(SELECT GROUP_CONCAT(CONCAT(CONS_CITY, ',', CONS_STATE)
			ORDER BY SHIPMENT_CODE ASC SEPARATOR ' ')
			FROM EXP_SHIPMENT
			WHERE EXP_SHIPMENT.LOAD_CODE = EXP_LOAD.LOAD_CODE
			) AS DELIVERIES,
			
			(SELECT GROUP_CONCAT(DISTINCT COMMODITY_NAME
			ORDER BY EXP_SHIPMENT.SHIPMENT_CODE ASC SEPARATOR ', ')
			FROM EXP_SHIPMENT, EXP_DETAIL, EXP_COMMODITY
			WHERE EXP_SHIPMENT.LOAD_CODE = EXP_LOAD.LOAD_CODE
			AND EXP_DETAIL.SHIPMENT_CODE = EXP_SHIPMENT.SHIPMENT_CODE
			AND EXP_DETAIL.COMMODITY = EXP_COMMODITY.COMMODITY_CODE) AS COMMODITIES
			
			FROM EXP_LOAD, EXP_CARRIER
			WHERE CARRIER > 0
			AND EXISTS (SELECT SHIPMENT_CODE FROM EXP_SHIPMENT
			WHERE EXP_SHIPMENT.LOAD_CODE = EXP_LOAD.LOAD_CODE
			".(is_array($_SESSION) && is_array($_SESSION['BIDDING_PICKUP_STATE']) &&
				count($_SESSION['BIDDING_PICKUP_STATE']) > 0 ?
				"AND SHIPPER_STATE IN ('".implode("', '", $_SESSION['BIDDING_PICKUP_STATE'])."')"
				: "")."
			".(is_array($_SESSION) && is_array($_SESSION['BIDDING_DELIVER_STATE']) &&
				count($_SESSION['BIDDING_DELIVER_STATE']) > 0 ?
				"AND CONS_STATE IN ('".implode("', '", $_SESSION['BIDDING_DELIVER_STATE'])."')"
				: "")."
			)
			AND EXP_CARRIER.CARRIER_CODE = EXP_LOAD.CARRIER
			".($match <> "" ? "AND $match" : "")."

			".($order <> "" ? "ORDER BY $order" : "")."
			
			) EXP_CARRIER
			");
		//			AND COMPLETED_DATE > DATE_SUB(CURRENT_DATE, INTERVAL 1 YEAR)
		//		ORDER BY 2 ASC
		
		if( $this->debug ) {
			echo "<p>result for $this->table_name = </p>
			<pre>";
			var_dump($result);
			echo "</pre>";
		}
		return $result;
	}

	// Fetch one or more rows
	public function fetch_rows( $match = "", $fields = "*", $order = "", $limit = "", $groupby = "", $match2 = "" ) {
		global $_SESSION;
		if( $this->debug ) echo "<h2>".__METHOD__.": m = $match<br>m2 = $match2</h2>";
		$result = $this->database->get_multiple_rows("SELECT $fields FROM
			(select DISTINCT EXP_LOAD.LOAD_CODE, CARRIER_CODE, CARRIER_NAME, 
			CARRIER_TOTAL as CARRIER_RATE,

			(SELECT GROUP_CONCAT(DISTINCT CONCAT(SHIPPER_CITY, ',', SHIPPER_STATE)
			ORDER BY SHIPMENT_CODE ASC SEPARATOR ' ')
			FROM EXP_SHIPMENT
			WHERE EXP_SHIPMENT.LOAD_CODE = EXP_LOAD.LOAD_CODE
			) AS PICK_UPS,
			
			(SELECT GROUP_CONCAT(DISTINCT CONCAT(CONS_CITY, ',', CONS_STATE)
			ORDER BY SHIPMENT_CODE ASC SEPARATOR ' ')
			FROM EXP_SHIPMENT
			WHERE EXP_SHIPMENT.LOAD_CODE = EXP_LOAD.LOAD_CODE
			) AS DELIVERIES,
            
            (SELECT MIN(PICKUP_DATE) FROM EXP_SHIPMENT
            WHERE EXP_SHIPMENT.LOAD_CODE = EXP_LOAD.LOAD_CODE) AS PICKUP_DATE,
            (SELECT MIN(DELIVER_DATE) FROM EXP_SHIPMENT
            WHERE EXP_SHIPMENT.LOAD_CODE = EXP_LOAD.LOAD_CODE) AS DELIVER_DATE,
			EXP_LOAD.CURRENCY,
          			
			(SELECT GROUP_CONCAT(DISTINCT COMMODITY_NAME
			ORDER BY EXP_SHIPMENT.SHIPMENT_CODE ASC SEPARATOR ', ')
			FROM EXP_DETAIL, EXP_COMMODITY, EXP_SHIPMENT
			WHERE EXP_DETAIL.SHIPMENT_CODE = EXP_SHIPMENT.SHIPMENT_CODE
            AND EXP_SHIPMENT.LOAD_CODE = EXP_LOAD.LOAD_CODE
			AND EXP_DETAIL.COMMODITY = EXP_COMMODITY.COMMODITY_CODE) AS COMMODITIES
			
			FROM EXP_LOAD, EXP_CARRIER, EXP_SHIPMENT
			WHERE CARRIER > 0
            AND EXP_SHIPMENT.LOAD_CODE = EXP_LOAD.LOAD_CODE
			AND EXISTS (SELECT SHIPMENT_CODE FROM EXP_SHIPMENT
				WHERE EXP_SHIPMENT.LOAD_CODE = EXP_LOAD.LOAD_CODE)

			".(is_array($_SESSION) && is_array($_SESSION['BIDDING_PICKUP_STATE']) &&
				count($_SESSION['BIDDING_PICKUP_STATE']) > 0 ?
				"AND SHIPPER_STATE IN ('".implode("', '", $_SESSION['BIDDING_PICKUP_STATE'])."')"
				: "")."
			".(is_array($_SESSION) && is_array($_SESSION['BIDDING_DELIVER_STATE']) &&
				count($_SESSION['BIDDING_DELIVER_STATE']) > 0 ?
				"AND CONS_STATE IN ('".implode("', '", $_SESSION['BIDDING_DELIVER_STATE'])."')"
				: "")."
			AND EXP_CARRIER.CARRIER_CODE = EXP_LOAD.CARRIER
			".($match <> "" ? "AND $match" : "")."

			".($order <> "" ? "ORDER BY $order" : "")."
			
			) EXP_CARRIER
			");
		//			AND COMPLETED_DATE > DATE_SUB(CURRENT_DATE, INTERVAL 1 YEAR)
		//		ORDER BY 2 ASC
		
		if( $this->debug ) {
			echo "<p>result for $this->table_name = </p>
			<pre>";
			var_dump($result);
			echo "</pre>";
		}
		return $result;
	}
}


//! Form Specifications - For use with sts_form

$sts_form_addcarrier_form = array(	//! $sts_form_addcarrier_form
	'title' => '<img src="images/carrier_icon.png" alt="carrier_icon" height="24"> Add Carrier',
	'action' => 'exp_addcarrier.php',
	//'actionextras' => 'disabled',
	'cancel' => 'exp_listcarrier.php',
	'name' => 'addcarrier',
	'okbutton' => 'Save Changes',
	'cancelbutton' => 'Cancel',
		'layout' => '
	<div class="form-group">
		<div class="col-sm-4">
			<div class="form-group tighter">
				<label for="CARRIER_NAME" class="col-sm-4 control-label">#CARRIER_NAME#</label>
				<div class="col-sm-8">
					%CARRIER_NAME%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="LEGAL_NAME" class="col-sm-4 control-label">#LEGAL_NAME#</label>
				<div class="col-sm-8">
					%LEGAL_NAME%
				</div>
			</div>
			<!-- SAGE50_1 -->
			<div class="form-group tighter">
				<label for="SAGE50_VENDORID" class="col-sm-4 control-label">#SAGE50_VENDORID#</label>
				<div class="col-sm-8">
					%SAGE50_VENDORID%
				</div>
			</div>
			<!-- SAGE50_2 -->
			<div class="form-group tighter">
				<label for="CARRIER_TYPE" class="col-sm-4 control-label">#CARRIER_TYPE#</label>
				<div class="col-sm-8">
					%CARRIER_TYPE%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="DANG_GOODS_ALLOWED" class="col-sm-4 control-label">#DANG_GOODS_ALLOWED#</label>
				<div class="col-sm-8">
					%DANG_GOODS_ALLOWED%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="TEMP_CONTR_ALLOWED" class="col-sm-4 control-label">#TEMP_CONTR_ALLOWED#</label>
				<div class="col-sm-8">
					%TEMP_CONTR_ALLOWED%
				</div>
			</div>

			<div class="form-group tighter">
				<label for="DEFAULT_ZONE" class="col-sm-4 control-label">#DEFAULT_ZONE#</label>
				<div class="col-sm-8">
					%DEFAULT_ZONE%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="TERMINAL_ZONE" class="col-sm-4 control-label">#TERMINAL_ZONE#</label>
				<div class="col-sm-8">
					%TERMINAL_ZONE%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="OPEN_TIME" class="col-sm-4 control-label">#OPEN_TIME#</label>
				<div class="col-sm-8">
					%OPEN_TIME%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="CLOSE_TIME" class="col-sm-4 control-label">#CLOSE_TIME#</label>
				<div class="col-sm-8">
					%CLOSE_TIME%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="DEFAULT_COMMODITY" class="col-sm-4 control-label">#DEFAULT_COMMODITY#</label>
				<div class="col-sm-8">
					%DEFAULT_COMMODITY%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="DEFAULT_TRAILER_TYPE" class="col-sm-4 control-label">#DEFAULT_TRAILER_TYPE#</label>
				<div class="col-sm-8">
					%DEFAULT_TRAILER_TYPE%
				</div>
			</div>
		</div>
		<div class="col-sm-4">
			<div class="form-group tighter">
				<label for="CURRENCY_CODE" class="col-sm-4 control-label">#CURRENCY_CODE#</label>
				<div class="col-sm-8">
					%CURRENCY_CODE%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="FED_CODE_NUM" class="col-sm-4 control-label">#FED_CODE_NUM#</label>
				<div class="col-sm-8">
					%FED_CODE_NUM%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="DISCOUNT" class="col-sm-4 control-label">#DISCOUNT#</label>
				<div class="col-sm-8">
					%DISCOUNT%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="TERMS" class="col-sm-4 control-label">#TERMS#</label>
				<div class="col-sm-8">
					%TERMS%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="PMT_HOLD" class="col-sm-4 control-label">#PMT_HOLD#</label>
				<div class="col-sm-8">
					%PMT_HOLD%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="DUNS_CODE" class="col-sm-4 control-label">#DUNS_CODE#</label>
				<div class="col-sm-8">
					%DUNS_CODE%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="SCAC_CODE" class="col-sm-4 control-label">#SCAC_CODE#</label>
				<div class="col-sm-8">
					%SCAC_CODE%
				</div>
			</div>
			<div class="well well-sm tighter">
				<div class="form-group tighter">
					<div class="col-sm-6">
						<a href="https://en.wikipedia.org/wiki/Customs-Trade_Partnership_Against_Terrorism" target="_blank" class="tip" title="Customs-Trade Partnership Against Terrorism"><img src="images/logo-ctpat.jpg" alt="logo-ctpat" width="100%" /></a>
					</div>
					<div class="col-sm-6">
						%CTPAT_CERTIFIED%<br>
						#CTPAT_CERTIFIED#
					</div>
				</div>
				<div class="form-group tighter" id="CTPAT_SVI" hidden>
					<label for="CTPAT_SVI_NUM" class="col-sm-2 control-label">#CTPAT_SVI_NUM#</label>
					<div class="col-sm-10">
						%CTPAT_SVI_NUM%
					</div>
				</div>
			</div>
			<div class="form-group tighter">
				<label for="GROUP_ID" class="col-sm-4 control-label">#GROUP_ID#</label>
				<div class="col-sm-8">
					%GROUP_ID%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="AFFILIATE_ID" class="col-sm-4 control-label">#AFFILIATE_ID#</label>
				<div class="col-sm-8">
					%AFFILIATE_ID%
				</div>
			</div>
		</div>
		<div class="col-sm-4">
			<div class="form-group tighter">
				<label for="EMAIL_NOTIFY" class="col-sm-4 control-label">#EMAIL_NOTIFY#</label>
				<div class="col-sm-8">
					%EMAIL_NOTIFY%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="FAX_NOTIFY" class="col-sm-4 control-label">#FAX_NOTIFY#</label>
				<div class="col-sm-8">
					%FAX_NOTIFY%
				</div>
			</div>
			<!-- EDI_1 -->
			<div class="form-group tighter">
				<label for="EDI_CONNECTION" class="col-sm-4 control-label">#EDI_CONNECTION#</label>
				<div class="col-sm-8">
					%EDI_CONNECTION%
				</div>
			</div>
			<!-- EDI_2 -->
			<div class="form-group tighter">
				<label for="MC_NUM" class="col-sm-4 control-label">#MC_NUM#</label>
				<div class="col-sm-8">
					%MC_NUM%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="CARGO_NUM" class="col-sm-4 control-label">#CARGO_NUM#</label>
				<div class="col-sm-8">
					%CARGO_NUM%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="DOT_NUM" class="col-sm-4 control-label">#DOT_NUM#</label>
				<div class="col-sm-8">
					%DOT_NUM%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="NUM_TRUCKS" class="col-sm-4 control-label">#NUM_TRUCKS#</label>
				<div class="col-sm-8">
					%NUM_TRUCKS%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="CERT_HOLDER" class="col-sm-4 control-label">#CERT_HOLDER#</label>
				<div class="col-sm-8">
					%CERT_HOLDER%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="DEFAULT_PERCENT" class="col-sm-4 control-label">#DEFAULT_PERCENT#</label>
				<div class="col-sm-8">
					%DEFAULT_PERCENT%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="CDN_TAX_EXEMPT" class="col-sm-4 control-label">#CDN_TAX_EXEMPT#</label>
				<div class="col-sm-8">
					%CDN_TAX_EXEMPT%
				</div>
			</div>

			<div class="form-group tighter">
				<label for="FF_NUMBER" class="col-sm-4 control-label">#FF_NUMBER#</label>
				<div class="col-sm-8">
					%FF_NUMBER%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="US_INTRASTATE_NUMBER" class="col-sm-4 control-label">#US_INTRASTATE_NUMBER#</label>
				<div class="col-sm-8">
					%US_INTRASTATE_NUMBER%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="CDN_AUTHORITY_NUMBER" class="col-sm-4 control-label">#CDN_AUTHORITY_NUMBER#</label>
				<div class="col-sm-8">
					%CDN_AUTHORITY_NUMBER%
				</div>
			</div>
		</div>
	</div>
	<div class="form-group tighter">
		<div class="col-sm-6">
			<div class="form-group">
				<label for="CARRIER_NOTES" class="col-sm-2 control-label">#CARRIER_NOTES#</label>
				<div class="col-sm-10">
					%CARRIER_NOTES%
				</div>
			</div>
			<div class="well well-sm tighter">
				<div class="form-group tighter">
					<label for="MASS_EMAIL" class="col-sm-2 control-label">#MASS_EMAIL#</label>
					<div class="col-sm-10">
						%MASS_EMAIL%
					</div>
				</div>
				<div class="form-group tighter">
					<label for="MASS_OPT_OUT" class="col-sm-2 control-label">#MASS_OPT_OUT#</label>
					<div class="col-sm-8">
						%MASS_OPT_OUT%
					</div>
				</div>
				<div class="form-group tighter">
					<label for="MASS_NOTE" class="col-sm-2 control-label">#MASS_NOTE#</label>
					<div class="col-sm-10">
						%MASS_NOTE%
					</div>
				</div>
			</div>
		</div>
		<div class="col-sm-6">
			<div class="panel panel-warning">
				<div class="panel-heading">
					<h3 class="panel-title">Carrier Insurance
					<span style="float: right; position: relative; top: -8px;" class="btn-group">
						%INS_CURRENCY_CODE%
					</span>
					</h3>
				</div>
				<div class="panel-body">
					<div class="form-group tighter">
						<label for="GENERAL_LIAB_INS" class="col-sm-3 control-label">#GENERAL_LIAB_INS#</label>
						<div class="col-sm-4">
							<div class="input-group">
								<span class="input-group-addon">$</span>
								%GENERAL_LIAB_INS%
							</div>
						</div>
						<div class="col-sm-5">
							<div class="input-group">
								<span class="input-group-addon"><span class="glyphicon glyphicon-calendar"></span></span>
								%LIABILITY_DATE%
							</div>
						</div>
					</div>
					<div class="form-group tighter">
						<label for="AUTO_LIAB_INS" class="col-sm-3 control-label">#AUTO_LIAB_INS#</label>
						<div class="col-sm-4">
							<div class="input-group">
								<span class="input-group-addon">$</span>
								%AUTO_LIAB_INS%
							</div>
						</div>
						<div class="col-sm-5">
							<div class="input-group">
								<span class="input-group-addon"><span class="glyphicon glyphicon-calendar"></span></span>
								%AUTO_LIAB_DATE%
							</div>
						</div>
					</div>
					<div class="form-group tighter">
						<label for="CARGO_LIAB_INS" class="col-sm-3 control-label">#CARGO_LIAB_INS#</label>
						<div class="col-sm-4">
							<div class="input-group">
								<span class="input-group-addon">$</span>
								%CARGO_LIAB_INS%
							</div>
						</div>
						<div class="col-sm-5">
							<div class="input-group">
								<span class="input-group-addon"><span class="glyphicon glyphicon-calendar"></span></span>
								%CARGO_LIAB_DATE%
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>
	
	'
);

$sts_form_editcarrier_form = array( //! $sts_form_editcarrier_form
	'title' => '<img src="images/carrier_icon.png" alt="carrier_icon" height="24"> Edit Carrier',
	'action' => 'exp_editcarrier.php',
	//'actionextras' => 'disabled',
	'cancel' => 'exp_listcarrier.php',
	'name' => 'editcarrier',
	'okbutton' => 'Save Changes',
	'saveadd' => 'Add Another',
	'cancelbutton' => 'Back to Carriers',
		'layout' => '
		%CARRIER_CODE%
	<div id="warnings"></div>
	<div class="form-group">
		<div class="col-sm-4">
			<div class="form-group tighter">
				<label for="CARRIER_NAME" class="col-sm-4 control-label">#CARRIER_NAME#</label>
				<div class="col-sm-8">
					%CARRIER_NAME%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="LEGAL_NAME" class="col-sm-4 control-label">#LEGAL_NAME#</label>
				<div class="col-sm-8">
					%LEGAL_NAME%
				</div>
			</div>
			<!-- SAGE50_1 -->
			<div class="form-group tighter">
				<label for="SAGE50_VENDORID" class="col-sm-4 control-label">#SAGE50_VENDORID#</label>
				<div class="col-sm-8">
					%SAGE50_VENDORID%
				</div>
			</div>
			<!-- SAGE50_2 -->
			<div class="form-group tighter">
				<label for="CARRIER_TYPE" class="col-sm-4 control-label">#CARRIER_TYPE#</label>
				<div class="col-sm-8">
					%CARRIER_TYPE%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="DANG_GOODS_ALLOWED" class="col-sm-4 control-label">#DANG_GOODS_ALLOWED#</label>
				<div class="col-sm-8">
					%DANG_GOODS_ALLOWED%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="TEMP_CONTR_ALLOWED" class="col-sm-4 control-label">#TEMP_CONTR_ALLOWED#</label>
				<div class="col-sm-8">
					%TEMP_CONTR_ALLOWED%
				</div>
			</div>

			<div class="form-group tighter">
				<label for="DEFAULT_ZONE" class="col-sm-4 control-label">#DEFAULT_ZONE#</label>
				<div class="col-sm-8">
					%DEFAULT_ZONE%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="TERMINAL_ZONE" class="col-sm-4 control-label">#TERMINAL_ZONE#</label>
				<div class="col-sm-8">
					%TERMINAL_ZONE%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="OPEN_TIME" class="col-sm-4 control-label">#OPEN_TIME#</label>
				<div class="col-sm-8">
					%OPEN_TIME%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="CLOSE_TIME" class="col-sm-4 control-label">#CLOSE_TIME#</label>
				<div class="col-sm-8">
					%CLOSE_TIME%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="DEFAULT_COMMODITY" class="col-sm-4 control-label">#DEFAULT_COMMODITY#</label>
				<div class="col-sm-8">
					%DEFAULT_COMMODITY%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="DEFAULT_TRAILER_TYPE" class="col-sm-4 control-label">#DEFAULT_TRAILER_TYPE#</label>
				<div class="col-sm-8">
					%DEFAULT_TRAILER_TYPE%
				</div>
			</div>
			
		</div>
		<div class="col-sm-4">
			<div class="form-group tighter">
				<label for="CURRENCY_CODE" class="col-sm-4 control-label">#CURRENCY_CODE#</label>
				<div class="col-sm-8">
					%CURRENCY_CODE%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="FED_CODE_NUM" class="col-sm-4 control-label">#FED_CODE_NUM#</label>
				<div class="col-sm-8">
					%FED_CODE_NUM%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="DISCOUNT" class="col-sm-4 control-label">#DISCOUNT#</label>
				<div class="col-sm-8">
					%DISCOUNT%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="TERMS" class="col-sm-4 control-label">#TERMS#</label>
				<div class="col-sm-8">
					%TERMS%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="PMT_HOLD" class="col-sm-4 control-label">#PMT_HOLD#</label>
				<div class="col-sm-8">
					%PMT_HOLD%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="DUNS_CODE" class="col-sm-4 control-label">#DUNS_CODE#</label>
				<div class="col-sm-8">
					%DUNS_CODE%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="SCAC_CODE" class="col-sm-4 control-label">#SCAC_CODE#</label>
				<div class="col-sm-8">
					%SCAC_CODE%
				</div>
			</div>
			<div class="well well-sm tighter">
				<div class="form-group tighter">
					<div class="col-sm-6">
						<a href="https://en.wikipedia.org/wiki/Customs-Trade_Partnership_Against_Terrorism" target="_blank" class="tip" title="Customs-Trade Partnership Against Terrorism"><img src="images/logo-ctpat.jpg" alt="logo-ctpat" width="100%" /></a>
					</div>
					<div class="col-sm-6">
						%CTPAT_CERTIFIED%<br>
						#CTPAT_CERTIFIED#
					</div>
				</div>
				<div class="form-group tighter" id="CTPAT_SVI" hidden>
					<label for="CTPAT_SVI_NUM" class="col-sm-2 control-label">#CTPAT_SVI_NUM#</label>
					<div class="col-sm-10">
						%CTPAT_SVI_NUM%
					</div>
				</div>
			</div>
			<div class="form-group tighter">
				<label for="GROUP_ID" class="col-sm-4 control-label">#GROUP_ID#</label>
				<div class="col-sm-8">
					%GROUP_ID%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="AFFILIATE_ID" class="col-sm-4 control-label">#AFFILIATE_ID#</label>
				<div class="col-sm-8">
					%AFFILIATE_ID%
				</div>
			</div>
		</div>
		<div class="col-sm-4">
			<div class="form-group tighter">
				<label for="EMAIL_NOTIFY" class="col-sm-4 control-label">#EMAIL_NOTIFY#</label>
				<div class="col-sm-8">
					%EMAIL_NOTIFY%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="FAX_NOTIFY" class="col-sm-4 control-label">#FAX_NOTIFY#</label>
				<div class="col-sm-8">
					%FAX_NOTIFY%
				</div>
			</div>
			<!-- EDI_1 -->
			<div class="form-group tighter">
				<label for="EDI_CONNECTION" class="col-sm-4 control-label">#EDI_CONNECTION#</label>
				<div class="col-sm-8">
					%EDI_CONNECTION%
				</div>
			</div>
			<!-- EDI_2 -->
			<div class="form-group tighter">
				<label for="MC_NUM" class="col-sm-4 control-label">#MC_NUM#</label>
				<div class="col-sm-8">
					%MC_NUM%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="CARGO_NUM" class="col-sm-4 control-label">#CARGO_NUM#</label>
				<div class="col-sm-8">
					%CARGO_NUM%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="DOT_NUM" class="col-sm-4 control-label">#DOT_NUM#</label>
				<div class="col-sm-8">
					%DOT_NUM%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="NUM_TRUCKS" class="col-sm-4 control-label">#NUM_TRUCKS#</label>
				<div class="col-sm-8">
					%NUM_TRUCKS%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="CERT_HOLDER" class="col-sm-4 control-label">#CERT_HOLDER#</label>
				<div class="col-sm-8">
					%CERT_HOLDER%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="DEFAULT_PERCENT" class="col-sm-4 control-label">#DEFAULT_PERCENT#</label>
				<div class="col-sm-8">
					%DEFAULT_PERCENT%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="CDN_TAX_EXEMPT" class="col-sm-4 control-label">#CDN_TAX_EXEMPT#</label>
				<div class="col-sm-8">
					%CDN_TAX_EXEMPT%
				</div>
			</div>

			<div class="form-group tighter">
				<label for="FF_NUMBER" class="col-sm-4 control-label">#FF_NUMBER#</label>
				<div class="col-sm-8">
					%FF_NUMBER%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="US_INTRASTATE_NUMBER" class="col-sm-4 control-label">#US_INTRASTATE_NUMBER#</label>
				<div class="col-sm-8">
					%US_INTRASTATE_NUMBER%
				</div>
			</div>
			<div class="form-group tighter">
				<label for="CDN_AUTHORITY_NUMBER" class="col-sm-4 control-label">#CDN_AUTHORITY_NUMBER#</label>
				<div class="col-sm-8">
					%CDN_AUTHORITY_NUMBER%
				</div>
			</div>
		</div>
	</div>
	<div class="form-group">
		<div class="col-sm-6">
			<div class="form-group tighter">
				<label for="CARRIER_NOTES" class="col-sm-2 control-label">#CARRIER_NOTES#</label>
				<div class="col-sm-10">
					%CARRIER_NOTES%
				</div>
			</div>
			<div class="well well-sm tighter">
				<div class="form-group tighter">
					<label for="MASS_EMAIL" class="col-sm-2 control-label">#MASS_EMAIL#</label>
					<div class="col-sm-10">
						%MASS_EMAIL%
					</div>
				</div>
				<div class="form-group tighter">
					<label for="MASS_OPT_OUT" class="col-sm-2 control-label">#MASS_OPT_OUT#</label>
					<div class="col-sm-8">
						%MASS_OPT_OUT%
					</div>
				</div>
				<div class="form-group tighter">
					<label for="MASS_NOTE" class="col-sm-2 control-label">#MASS_NOTE#</label>
					<div class="col-sm-10">
						%MASS_NOTE%
					</div>
				</div>
			</div>

		</div>
		<div class="col-sm-6">
			<div class="panel panel-warning">
				<div class="panel-heading">
					<h3 class="panel-title">Carrier Insurance
					<span style="float: right; position: relative; top: -8px;" class="btn-group">
						%INS_CURRENCY_CODE%
					</span>
					</h3>
				</div>
				<div class="panel-body">
					<div class="form-group tighter">
						<label for="GENERAL_LIAB_INS" class="col-sm-3 control-label">#GENERAL_LIAB_INS#</label>
						<div class="col-sm-4">
							<div class="input-group">
								<span class="input-group-addon">$</span>
								%GENERAL_LIAB_INS%
							</div>
						</div>
						<div class="col-sm-5">
							<div class="input-group">
								<span class="input-group-addon"><span class="glyphicon glyphicon-calendar"></span></span>
								%LIABILITY_DATE%
							</div>
						</div>
					</div>
					<div class="form-group tighter">
						<div class="col-sm-7 col-sm-offset-1">
								%SW_GENERAL_INS_COMP%
						</div>
						<div class="col-sm-4">
								%SW_GENERAL_POLICY%
						</div>
					</div>

					<div class="form-group tighter">
						<label for="AUTO_LIAB_INS" class="col-sm-3 control-label">#AUTO_LIAB_INS#</label>
						<div class="col-sm-4">
							<div class="input-group">
								<span class="input-group-addon">$</span>
								%AUTO_LIAB_INS%
							</div>
						</div>
						<div class="col-sm-5">
							<div class="input-group">
								<span class="input-group-addon"><span class="glyphicon glyphicon-calendar"></span></span>
								%AUTO_LIAB_DATE%
							</div>
						</div>
					</div>
					<div class="form-group tighter">
						<div class="col-sm-7 col-sm-offset-1">
								%SW_AUTO_INS_COMP%
						</div>
						<div class="col-sm-4">
								%SW_AUTO_POLICY%
						</div>
					</div>

					<div class="form-group tighter">
						<label for="CARGO_LIAB_INS" class="col-sm-3 control-label">#CARGO_LIAB_INS#</label>
						<div class="col-sm-4">
							<div class="input-group">
								<span class="input-group-addon">$</span>
								%CARGO_LIAB_INS%
							</div>
						</div>
						<div class="col-sm-5">
							<div class="input-group">
								<span class="input-group-addon"><span class="glyphicon glyphicon-calendar"></span></span>
								%CARGO_LIAB_DATE%
							</div>
						</div>
					</div>
					<div class="form-group tighter">
						<div class="col-sm-7 col-sm-offset-1">
								%SW_CARGO_INS_COMP%
						</div>
						<div class="col-sm-4">
								%SW_CARGO_POLICY%
						</div>
					</div>

				</div>
			</div>
			<!-- CARGO_TYPES -->
		
		</div>
	</div>	
	
	<div class="panel panel-danger">
		<div class="panel-heading">
			<h3 class="panel-title"><img src="images/Saferwatch%20Product%20Logo.png" alt="Saferwatch Product Logo" height="32"> <!-- SW_LINK -->
			</h3>
		</div>
		<div class="panel-body">
			<div class="form-group">
				<div class="col-sm-4">
					<div class="form-group">
						<label for="SW_DOT_NUM_STATUS" class="col-sm-5 control-label">#SW_DOT_NUM_STATUS#</label>
						<div class="col-sm-7">
							%SW_DOT_NUM_STATUS%
						</div>
					</div>
					<div class="form-group tighter">
						<label for="SW_SAFETY_RATING" class="col-sm-5 control-label">#SW_SAFETY_RATING#</label>
						<div class="col-sm-7">
							%SW_SAFETY_RATING%
						</div>
					</div>
					<div class="form-group">
						<label for="SW_SAFETY_RATING_DATE" class="col-sm-5 control-label">#SW_SAFETY_RATING_DATE#</label>
						<div class="col-sm-7">
							%SW_SAFETY_RATING_DATE%
						</div>
					</div>

					<div class="form-group tighter">
						<label for="SW_UNSAFEDRVPCT" class="col-sm-5 control-label">#SW_UNSAFEDRVPCT#</label>
						<div class="col-sm-7">
							<div class="input-group">
								%SW_UNSAFEDRVPCT%
								<span class="input-group-addon" id="basic-addon2">%</span>
							</div>
						</div>
					</div>
					<div class="form-group tighter">
						<label for="SW_HOSPCT" class="col-sm-5 control-label">#SW_HOSPCT#</label>
						<div class="col-sm-7">
							<div class="input-group">
								%SW_HOSPCT%
								<span class="input-group-addon" id="basic-addon2">%</span>
							</div>
						</div>
					</div>
					<div class="form-group tighter">
						<label for="SW_FITPCT" class="col-sm-5 control-label">#SW_FITPCT#</label>
						<div class="col-sm-7">
							<div class="input-group">
								%SW_FITPCT%
								<span class="input-group-addon" id="basic-addon2">%</span>
							</div>
						</div>
					</div>
					<div class="form-group tighter">
						<label for="SW_CONTROLSUBPCT" class="col-sm-5 control-label">#SW_CONTROLSUBPCT#</label>
						<div class="col-sm-7">
							<div class="input-group">
								%SW_CONTROLSUBPCT%
								<span class="input-group-addon" id="basic-addon2">%</span>
							</div>
						</div>
					</div>
					<div class="form-group">
						<label for="SW_MAINTPCT" class="col-sm-5 control-label">#SW_MAINTPCT#</label>
						<div class="col-sm-7">
							<div class="input-group">
								%SW_MAINTPCT%
								<span class="input-group-addon" id="basic-addon2">%</span>
							</div>
						</div>
					</div>
				</div>

				<div class="col-sm-4">
					<div class="form-group tighter">
						<label for="SW_SAFETY_REVIEW_TYPE" class="col-sm-5 control-label">#SW_SAFETY_REVIEW_TYPE#</label>
						<div class="col-sm-7">
							%SW_SAFETY_REVIEW_TYPE%
						</div>
					</div>
					<div class="form-group tighter">
						<label for="SW_SAFETY_REVIEW_DATE" class="col-sm-5 control-label">#SW_SAFETY_REVIEW_DATE#</label>
						<div class="col-sm-7">
							%SW_SAFETY_REVIEW_DATE%
						</div>
					</div>
					<div class="form-group tighter">
						<label for="SW_SAFETY_REVIEW_DOC" class="col-sm-5 control-label">#SW_SAFETY_REVIEW_DOC#</label>
						<div class="col-sm-7">
							%SW_SAFETY_REVIEW_DOC%
						</div>
					</div>
					<div class="form-group">
						<label for="SW_SAFETY_REVIEW_MILES" class="col-sm-5 control-label">#SW_SAFETY_REVIEW_MILES#</label>
						<div class="col-sm-7">
							%SW_SAFETY_REVIEW_MILES%
						</div>
					</div>
				</div>
				<div class="col-sm-4">
					<div class="form-group tighter">
						<label for="SW_RISK_OVERALL" class="col-sm-5 control-label">#SW_RISK_OVERALL#</label>
						<div class="col-sm-7">
							<div class="input-group">
								%SW_RISK_OVERALL%
								<span class="input-group-addon" id="basic-addon2"><img src="images/SW-Blank.png"></span>
							</div>
						</div>
					</div>
					<div class="form-group tighter">
						<label for="SW_RISK_AUTHORITY" class="col-sm-5 control-label">#SW_RISK_AUTHORITY#</label>
						<div class="col-sm-7">
							<div class="input-group">
								%SW_RISK_AUTHORITY%
								<span class="input-group-addon" id="basic-addon2"><img src="images/SW-Blank.png"></span>
							</div>
						</div>
					</div>
					<div class="form-group tighter">
						<label for="SW_RISK_INS" class="col-sm-5 control-label">#SW_RISK_INS#</label>
						<div class="col-sm-7">
							<div class="input-group">
								%SW_RISK_INS%
								<span class="input-group-addon" id="basic-addon2"><img src="images/SW-Blank.png"></span>
							</div>
						</div>
					</div>
					<div class="form-group tighter">
						<label for="SW_RISK_SAFETY" class="col-sm-5 control-label">#SW_RISK_SAFETY#</label>
						<div class="col-sm-7">
							<div class="input-group">
								%SW_RISK_SAFETY%
								<span class="input-group-addon" id="basic-addon2"><img src="images/SW-Blank.png"></span>
							</div>
						</div>
					</div>
					<div class="form-group tighter">
						<label for="SW_RISK_OPER" class="col-sm-5 control-label">#SW_RISK_OPER#</label>
						<div class="col-sm-7">
							<div class="input-group">
								%SW_RISK_OPER%
								<span class="input-group-addon" id="basic-addon2"><img src="images/SW-Blank.png"></span>
							</div>
						</div>
					</div>
					<div class="form-group tighter">
						<label for="SW_RISK_OTHER" class="col-sm-5 control-label">#SW_RISK_OTHER#</label>
						<div class="col-sm-7">
							<div class="input-group">
								%SW_RISK_OTHER%
								<span class="input-group-addon" id="basic-addon2"><img src="images/SW-Blank.png"></span>
							</div>
						</div>
					</div>

				</div>
			</div>
			<div class="form-group">
				<div class="col-sm-8 col-sm-offset-2">
				<table class="display table table-striped table-condensed table-bordered">
				<thead>
				<tr class="exspeedite-bg">
					<th>Crashes</th><th class="text-right">Fatal</th><th class="text-right">Injury</th><th class="text-right">Tow</th><th class="text-right">Total</th>
				</tr>
				</thead>
				<tbody>
				<tr>
					<th>USA</th><td class="text-right">%SW_CRASHFATALUS%</td><td class="text-right">%SW_CRASHINJURYUS%</td><td class="text-right">%SW_CRASHTOWUS%</td><td class="text-right">%SW_CRASHTOTALUS%</td>
				</tr>
				<tr>
					<th>CAN</th><td class="text-right">%SW_CRASHFATALCAN%</td><td class="text-right">%SW_CRASHINJURYCAN%</td><td class="text-right">%SW_CRASHTOWCAN%</td><td class="text-right">%SW_CRASHTOTALCAN%</td>
				</tr>
				</tbody>
				</table>
				</div>
			</div>
		</div>
	</div>
	
	'
);

//! Field Specifications - For use with sts_form

$sts_form_add_carrier_fields = array( //! $sts_form_add_carrier_fields
	'CARRIER_NAME' => array( 'label' => '(Dba) Name', 'format' => 'text', 'extras' => 'required autofocus' ),
	'LEGAL_NAME' => array( 'label' => '(Legal) Name', 'format' => 'text' ),
	'SAGE50_VENDORID' => array( 'label' => 'Sage ID', 'format' => 'text' ),
	'CARRIER_TYPE' => array( 'label' => 'Type', 'format' => 'enum' ),
	'DANG_GOODS_ALLOWED' => array( 'label' => 'Dang Goods', 'format' => 'bool' ),
	'TEMP_CONTR_ALLOWED' => array( 'label' => 'Temp Contr', 'format' => 'bool' ),
	
	'DEFAULT_COMMODITY' => array( 'label' => 'Commodity', 'format' => 'table',
		'table' => COMMODITY_TABLE, 'key' => 'COMMODITY_CODE', 'fields' => 'COMMODITY_NAME' ),
	'DEFAULT_TRAILER_TYPE' => array( 'label' => 'Trailer', 'format' => 'text' ),
	'CURRENCY_CODE' => array( 'label' => 'Currency', 'format' => 'enum' ),
	'FED_CODE_NUM' => array( 'label' => 'Fed#', 'format' => 'text' ),
	//'EDI_ENABLED' => array( 'label' => 'EDI', 'format' => 'bool' ),
	//'EDI_NUMBER' => array( 'label' => 'EDI#', 'format' => 'text' ),
	'EMAIL_NOTIFY' => array( 'label' => 'Email Notify', 'format' => 'email', 'extras' => 'multiple' ),
	'FAX_NOTIFY' => array( 'label' => 'Fax Notify', 'format' => 'text' ),
	
	'DEFAULT_ZONE' => array( 'label' => 'Default Zone', 'format' => 'zip' ),
	'TERMINAL_ZONE' => array( 'label' => 'Terminal Zone', 'format' => 'zip' ),
	'OPEN_TIME' => array( 'label' => 'Open', 'format' => 'text' ),
	'CLOSE_TIME' => array( 'label' => 'Close', 'format' => 'text' ),
	'DISCOUNT' => array( 'label' => 'Discount', 'format' => 'number' ),
	'PMT_HOLD' => array( 'label' => 'Hold', 'format' => 'bool' ),
	'DUNS_CODE' => array( 'label' => 'DUNS', 'format' => 'text' ),
	'SCAC_CODE' => array( 'label' => 'SCAC', 'format' => 'text' ),
	'MC_NUM' => array( 'label' => 'MC#', 'format' => 'text' ),
	'CARGO_NUM' => array( 'label' => 'Contract#', 'format' => 'text' ),
	'DOT_NUM' => array( 'label' => 'DOT#', 'format' => 'text' ),
	'NUM_TRUCKS' => array( 'label' => '# Trucks', 'format' => 'number', 'align' => 'right' ),
	'CARRIER_NOTES' => array( 'label' => 'Notes', 'format' => 'textarea', 'extras' => 'rows="9"' ),

	'GENERAL_LIAB_INS' => array( 'label' => 'General', 'format' => 'numberc', 'align' => 'right' ),
	'AUTO_LIAB_INS' => array( 'label' => 'Auto', 'format' => 'numberc', 'align' => 'right' ),
	'CARGO_LIAB_INS' => array( 'label' => 'Cargo', 'format' => 'numberc', 'align' => 'right' ),
	'LIABILITY_DATE' => array( 'label' => 'Date', 'format' => 'date', 'align' => 'right' ),
	'AUTO_LIAB_DATE' => array( 'label' => 'Date', 'format' => 'date', 'align' => 'right' ),
	'CARGO_LIAB_DATE' => array( 'label' => 'Date', 'format' => 'date', 'align' => 'right' ),
	'CERT_HOLDER' => array( 'label' => 'Cert Holder', 'format' => 'bool' ),
	'DEFAULT_PERCENT' => array( 'label' => 'Default %', 'format' => 'number', 'align' => 'right' ),
	'EDI_CONNECTION' => array( 'label' => 'EDI', 'format' => 'table',
		'table' => FTP_TABLE, 'key' => 'FTP_CODE', 'fields' => 'FTP_REMOTE_ID',
		'order' => 'FTP_REMOTE_ID ASC' ),
	'TERMS' => array( 'label' => 'Default Terms', 'format' => 'table',
		'table' => ITEM_LIST_TABLE, 'key' => 'ITEM_CODE', 'fields' => 'ITEM',
		'condition' => 'ITEM_TYPE = \'Vendor Terms\'' ),
	'CTPAT_CERTIFIED' => array( 'label' => 'C-TPAT Certified', 'format' => 'bool' ),
	'CTPAT_SVI_NUM' => array( 'label' => 'SVI#', 'format' => 'text' ),
	'CDN_TAX_EXEMPT' => array( 'label' => 'Tax Exempt', 'format' => 'bool' ),

	//! - SCR# 863 - new fields
	'GROUP_ID' => array( 'label' => 'Group#', 'format' => 'text' ),
	'AFFILIATE_ID' => array( 'label' => 'Affiliate#', 'format' => 'text' ),
	'FF_NUMBER' => array( 'label' => 'FF#', 'format' => 'text' ),
	'US_INTRASTATE_NUMBER' => array( 'label' => 'US Intrastate#', 'format' => 'text' ),
	'CDN_AUTHORITY_NUMBER' => array( 'label' => 'CDN Authority#', 'format' => 'text' ),

	//! SCR# 887 - New fields
	'MASS_EMAIL' => array( 'label' => 'Mass Email', 'format' => 'text' ),
	'MASS_OPT_OUT' => array( 'label' => 'Opt Out', 'format' => 'bool' ),
	'MASS_NOTE' => array( 'label' => 'Notes', 'format' => 'textarea', 'extras' => 'rows="4"' ),
	'INS_CURRENCY_CODE' => array( 'label' => 'Ins Currency', 'format' => 'enum' ),
);

$sts_form_edit_carrier_fields = array( //! $sts_form_edit_carrier_fields
	'CARRIER_CODE' => array( 'format' => 'hidden' ),	// primary key must be first
	'CARRIER_NAME' => array( 'label' => '(Dba) Name', 'format' => 'text', 'extras' => 'required autofocus' ),
	'LEGAL_NAME' => array( 'label' => '(Legal) Name', 'format' => 'text' ),
	'SAGE50_VENDORID' => array( 'label' => 'Sage ID', 'format' => 'text' ),
	'CARRIER_TYPE' => array( 'label' => 'Type', 'format' => 'enum' ),
	'DANG_GOODS_ALLOWED' => array( 'label' => 'Dang Goods', 'format' => 'bool' ),
	'TEMP_CONTR_ALLOWED' => array( 'label' => 'Temp Contr', 'format' => 'bool' ),
	
	'DEFAULT_COMMODITY' => array( 'label' => 'Commodity', 'format' => 'table',
		'table' => COMMODITY_TABLE, 'key' => 'COMMODITY_CODE', 'fields' => 'COMMODITY_NAME' ),
	'DEFAULT_TRAILER_TYPE' => array( 'label' => 'Trailer', 'format' => 'text' ),
	'CURRENCY_CODE' => array( 'label' => 'Currency', 'format' => 'enum' ),
	'FED_CODE_NUM' => array( 'label' => 'Fed#', 'format' => 'text' ),
	//'EDI_ENABLED' => array( 'label' => 'EDI', 'format' => 'bool' ),
	//'EDI_NUMBER' => array( 'label' => 'EDI#', 'format' => 'text' ),
	'EMAIL_NOTIFY' => array( 'label' => 'Email Notify', 'format' => 'email', 'extras' => 'multiple' ),
	'FAX_NOTIFY' => array( 'label' => 'Fax Notify', 'format' => 'text' ),
	
	'DEFAULT_ZONE' => array( 'label' => 'Default Zone', 'format' => 'zip' ),
	'TERMINAL_ZONE' => array( 'label' => 'Terminal Zone', 'format' => 'zip' ),
	'OPEN_TIME' => array( 'label' => 'Open', 'format' => 'text' ),
	'CLOSE_TIME' => array( 'label' => 'Close', 'format' => 'text' ),
	'DISCOUNT' => array( 'label' => 'Discount', 'format' => 'number' ),
	'PMT_HOLD' => array( 'label' => 'Hold', 'format' => 'bool' ),
	'DUNS_CODE' => array( 'label' => 'DUNS', 'format' => 'text' ),
	'SCAC_CODE' => array( 'label' => 'SCAC', 'format' => 'text' ),
	'MC_NUM' => array( 'label' => 'MC#', 'format' => 'text' ),
	'CARGO_NUM' => array( 'label' => 'Contract#', 'format' => 'text' ),
	'DOT_NUM' => array( 'label' => 'DOT#', 'format' => 'text' ),
	'NUM_TRUCKS' => array( 'label' => '# Trucks', 'format' => 'number', 'align' => 'right' ),
	'CARRIER_NOTES' => array( 'label' => 'Notes', 'format' => 'textarea', 'extras' => 'rows="9"' ),

	'GENERAL_LIAB_INS' => array( 'label' => 'General', 'format' => 'numberc', 'align' => 'right' ),
	'AUTO_LIAB_INS' => array( 'label' => 'Auto', 'format' => 'numberc', 'align' => 'right' ),
	'CARGO_LIAB_INS' => array( 'label' => 'Cargo', 'format' => 'numberc', 'align' => 'right' ),
	'LIABILITY_DATE' => array( 'label' => 'Date', 'label2' => 'General Date', 'format' => 'date', 'align' => 'right' ),
	'AUTO_LIAB_DATE' => array( 'label' => 'Date', 'label2' => 'Auto Date', 'format' => 'date', 'align' => 'right' ),
	'CARGO_LIAB_DATE' => array( 'label' => 'Date', 'labe2l' => 'Cargo Date', 'format' => 'date', 'align' => 'right' ),
	'CERT_HOLDER' => array( 'label' => 'Cert Holder', 'format' => 'bool' ),
	'DEFAULT_PERCENT' => array( 'label' => 'Default %', 'format' => 'number', 'align' => 'right' ),
	'EDI_CONNECTION' => array( 'label' => 'EDI', 'format' => 'table',
		'table' => FTP_TABLE, 'key' => 'FTP_CODE', 'fields' => 'FTP_REMOTE_ID',
		'order' => 'FTP_REMOTE_ID ASC' ),
	'TERMS' => array( 'label' => 'Default Terms', 'format' => 'table',
		'table' => ITEM_LIST_TABLE, 'key' => 'ITEM_CODE', 'fields' => 'ITEM',
		'condition' => 'ITEM_TYPE = \'Vendor Terms\'' ),
	'CTPAT_CERTIFIED' => array( 'label' => 'C-TPAT Certified', 'format' => 'bool' ),
	'CTPAT_SVI_NUM' => array( 'label' => 'SVI#', 'format' => 'text' ),
	'CDN_TAX_EXEMPT' => array( 'label' => 'Tax Exempt', 'format' => 'bool' ),
	
	//! - SCR# 863 - new fields
	'GROUP_ID' => array( 'label' => 'Group#', 'format' => 'text' ),
	'AFFILIATE_ID' => array( 'label' => 'Affiliate#', 'format' => 'text' ),
	'FF_NUMBER' => array( 'label' => 'FF#', 'format' => 'text' ),
	'US_INTRASTATE_NUMBER' => array( 'label' => 'US Intrastate#', 'format' => 'text' ),
	'CDN_AUTHORITY_NUMBER' => array( 'label' => 'CDN Authority#', 'format' => 'text' ),
	
	//! SCR# 887 - New fields
	'MASS_EMAIL' => array( 'label' => 'Mass Email', 'format' => 'text' ),
	'MASS_OPT_OUT' => array( 'label' => 'Opt Out', 'format' => 'bool' ),
	'MASS_NOTE' => array( 'label' => 'Notes', 'format' => 'textarea', 'extras' => 'rows="4"' ),
	'INS_CURRENCY_CODE' => array( 'label' => 'Ins Currency', 'format' => 'enum' ),
	
	//! SCR# 1017 - Truckstop Integration Saferwatch
	'SW_DOT_NUM_STATUS' => array( 'label' => 'DOT# Status', 'format' => 'text' ),
	'SW_SAFETY_RATING' => array( 'label' => 'Safety Rating', 'format' => 'text' ),
	'SW_SAFETY_RATING_DATE' => array( 'label' => 'Rating Date', 'format' => 'date' ),

	'SW_SAFETY_REVIEW_TYPE' => array( 'label' => 'Review Type', 'format' => 'text' ),
	'SW_SAFETY_REVIEW_DATE' => array( 'label' => 'Review Date', 'format' => 'date' ),
	'SW_SAFETY_REVIEW_DOC' => array( 'label' => 'Review Doc#', 'format' => 'text' ),
	'SW_SAFETY_REVIEW_MILES' => array( 'label' => 'Review Miles', 'format' => 'number' ),

	'SW_GENERAL_INS_COMP' => array( 'label' => 'General Insurer', 'format' => 'text' ),
	'SW_AUTO_INS_COMP' => array( 'label' => 'Auto Insurer', 'format' => 'text' ),
	'SW_CARGO_INS_COMP' => array( 'label' => 'Cargo Insurer', 'format' => 'text' ),
	'SW_GENERAL_POLICY' => array( 'label' => 'General Policy', 'format' => 'text' ),
	'SW_AUTO_POLICY' => array( 'label' => 'Auto Policy', 'format' => 'text' ),
	'SW_CARGO_POLICY' => array( 'label' => 'Cargo Policy', 'format' => 'text' ),

	'SW_RISK_OVERALL' => array( 'label' => 'Overall Risk', 'format' => 'text' ),
	'SW_RISK_AUTHORITY' => array( 'label' => 'Authority Risk', 'format' => 'text' ),
	'SW_RISK_INS' => array( 'label' => 'Insurance Risk', 'format' => 'text' ),
	'SW_RISK_SAFETY' => array( 'label' => 'Safety Risk', 'format' => 'text' ),
	'SW_RISK_OPER' => array( 'label' => 'Operation Risk', 'format' => 'text' ),
	'SW_RISK_OTHER' => array( 'label' => 'Other Risk', 'format' => 'text' ),

	'SW_CRASHFATALUS' => array( 'label' => 'Crash Fatal US', 'format' => 'number', 'align' => 'right' ),
	'SW_CRASHINJURYUS' => array( 'label' => 'Crash Injury US', 'format' => 'number', 'align' => 'right' ),
	'SW_CRASHTOWUS' => array( 'label' => 'Crash Tow US', 'format' => 'number', 'align' => 'right' ),
	'SW_CRASHTOTALUS' => array( 'label' => 'Crash Total US', 'format' => 'number', 'align' => 'right' ),
	'SW_CRASHFATALCAN' => array( 'label' => 'Crash Fatal CAN', 'format' => 'number', 'align' => 'right' ),
	'SW_CRASHINJURYCAN' => array( 'label' => 'Crash Injury CAN', 'format' => 'number', 'align' => 'right' ),
	'SW_CRASHTOWCAN' => array( 'label' => 'Crash Tow CAN', 'format' => 'number', 'align' => 'right' ),
	'SW_CRASHTOTALCAN' => array( 'label' => 'Crash Total CAN', 'format' => 'number', 'align' => 'right' ),
	
	'SW_UNSAFEDRVPCT' => array( 'label' => 'Unsafe Driving', 'format' => 'number', 'align' => 'right' ),
	'SW_HOSPCT' => array( 'label' => 'HOS Compliance', 'format' => 'number', 'align' => 'right' ),
	'SW_FITPCT' => array( 'label' => 'Driver Fitness', 'format' => 'number', 'align' => 'right' ),
	'SW_CONTROLSUBPCT' => array( 'label' => 'Drugs/Alcohol', 'format' => 'number', 'align' => 'right' ),
	'SW_MAINTPCT' => array( 'label' => 'Maintenance', 'format' => 'number', 'align' => 'right' ),
	
);

//! Layout Specifications - For use with sts_result

$sts_result_carriers_layout = array( //! $sts_result_carriers_layout
//! SCR# 495 - enable searching on ADDRESS, PHONE1, EMAIL
	'CARRIER_CODE' => array( 'format' => 'hidden' ),
	'EXPIRED' => array( 'format' => 'hidden', 'snippet' => 'CARRIER_EXPIRED(CARRIER_CODE)' ),
	'CARRIER_NAME' => array( 'label' => 'Name', 'format' => 'text' ),
	'CARRIER_TYPE' => array( 'label' => 'Type', 'format' => 'text' ),
	//! SCR# 900 - list carrier screen
	'ISDELETED' => array( 'label' => 'Deleted', 'align' => 'center', 'format' => 'bool' ),
	'ADDRESS' => array( 'label' => 'Address', 'format' => 'text',
		'snippet' => '(SELECT CONCAT_WS(\',\', ADDRESS, CITY, STATE, ZIP_CODE, COUNTRY) FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND ISDELETED = false
			LIMIT 1)', 'searchable' => true ),
	'PHONE1' => array( 'label' => 'Phone', 'format' => 'text',
		'snippet' => '(SELECT CONCAT_WS(\'+\',PHONE_OFFICE, PHONE_EXT) FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND ISDELETED = false
			LIMIT 1)', 'searchable' => true ),
	'EMAIL' => array( 'label' => 'E-mail', 'format' => 'text',
		'snippet' => '(SELECT EMAIL FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND ISDELETED = false
			LIMIT 1)', 'searchable' => true ),
	'DANG_GOODS_ALLOWED' => array( 'label' => 'Dang Goods', 'align' => 'center', 'format' => 'bool' ),
	'TEMP_CONTR_ALLOWED' => array( 'label' => 'Temp Contr', 'align' => 'center', 'format' => 'bool' ),
	'SW_RISK_OVERALL' => array( 'label' => 'Risk', 'format' => 'sw' ),
	
	'DEFAULT_COMMODITY' => array( 'label' => 'Commodity', 'format' => 'table',
		'table' => COMMODITY_TABLE, 'key' => 'COMMODITY_CODE', 'fields' => 'COMMODITY_NAME' ),
	'DEFAULT_TRAILER_TYPE' => array( 'label' => 'Trailer', 'format' => 'text' ),
	'CURRENCY_CODE' => array( 'label' => 'Curr', 'format' => 'text' ),
	'INS_CURRENCY_CODE' => array( 'label' => 'Ins Curr', 'format' => 'text' ),
	'TERMS' => array( 'label' => 'Terms', 'format' => 'table',
		'table' => ITEM_LIST_TABLE, 'key' => 'ITEM_CODE', 'fields' => 'ITEM',
		'condition' => 'ITEM_TYPE = \'Vendor Terms\'' ),
	'FED_CODE_NUM' => array( 'label' => 'Fed#', 'format' => 'text' ),
	//'EDI_ENABLED' => array( 'label' => 'EDI', 'format' => 'bool' ),
	//'EDI_NUMBER' => array( 'label' => 'EDI#', 'format' => 'text' ),
	'MC_NUM' => array( 'label' => 'MC#', 'format' => 'text' ),
	'CARGO_NUM' => array( 'label' => 'Cargo#', 'format' => 'text' ),
	'DOT_NUM' => array( 'label' => 'DOT#', 'format' => 'text' ),
	'NUM_TRUCKS' => array( 'label' => '#Trucks', 'format' => 'number', 'align' => 'right' ),
	'EMAIL_NOTIFY' => array( 'label' => 'Email Notify', 'format' => 'text' ),
	'FAX_NOTIFY' => array( 'label' => 'Fax Notify', 'format' => 'text' ),
	'EDI_CONNECTION' => array( 'label' => 'EDI', 'format' => 'table',
		'table' => FTP_TABLE, 'key' => 'FTP_CODE', 'fields' => 'FTP_REMOTE_ID' ),
	'CTPAT_CERTIFIED' => array( 'label' => 'C-TPAT', 'align' => 'center', 'format' => 'bool' ),
	'CHANGED_DATE' => array( 'label' => 'Changed', 'format' => 'timestamp-s', 'length' => 90 ),
	'CHANGED_BY' => array( 'label' => 'By', 'format' => 'table',
		'table' => USER_TABLE, 'key' => 'USER_CODE', 'fields' => 'USERNAME', 'link' => 'exp_edituser.php?CODE=' )
);

//! SCR# 863 - Carriers List
$sts_result_carriers_office_layout = array( //! $sts_result_carriers_office_layout
	'CARRIER_CODE' => array( 'format' => 'hidden' ),
	'CARRIER_NAME' => array( 'label' => 'Name', 'format' => 'text' ),
	'CARRIER_TYPE' => array( 'label' => 'Type', 'format' => 'text' ),
	'ISDELETED' => array( 'label' => 'Del', 'align' => 'center', 'format' => 'hidden' ),
	'CONTACT_NAME' => array( 'label' => 'E-mail', 'format' => 'text',
		'snippet' => '(SELECT CONTACT_NAME FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND ISDELETED = false
			LIMIT 1)', 'searchable' => true ),
	'ADDRESS' => array( 'label' => 'Address', 'format' => 'text',
		'snippet' => '(SELECT CONCAT_WS(\',\', ADDRESS, CITY, STATE, ZIP_CODE, COUNTRY) FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND ISDELETED = false
			LIMIT 1)', 'searchable' => true ),
	'PHONE1' => array( 'label' => 'Phone', 'format' => 'text',
		'snippet' => '(SELECT CONCAT_WS(\'+\',PHONE_OFFICE, PHONE_EXT) FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND ISDELETED = false
			LIMIT 1)', 'searchable' => true ),
	'EMAIL' => array( 'label' => 'E-mail', 'format' => 'text',
		'snippet' => '(SELECT EMAIL FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND ISDELETED = false
			LIMIT 1)', 'searchable' => true ),
	'MC_NUM' => array( 'label' => 'MC#', 'format' => 'text' ),
	
	'DOT_NUM' => array( 'label' => 'DOT#', 'format' => 'text' ),
	'GROUP_ID' => array( 'label' => 'Group#', 'format' => 'text' ),
	'AFFILIATE_ID' => array( 'label' => 'Affiliate#', 'format' => 'text' ),
	'FF_NUMBER' => array( 'label' => 'FF#', 'format' => 'text' ),
	'US_INTRASTATE_NUMBER' => array( 'label' => 'US Intrastate#', 'format' => 'text' ),
	'CDN_AUTHORITY_NUMBER' => array( 'label' => 'CDN Authority#', 'format' => 'text' ),
);

//! Revised
$sts_result_carriers_office_layout2 = array( //! $sts_result_carriers_office_layout2
	'CARRIER_CODE' => array( 'format' => 'hidden' ),
	'ISDELETED' => array( 'label' => 'Del', 'align' => 'center', 'format' => 'hidden' ),
	'EMAIL' => array( 'label' => 'Email', 'format' => 'text',
		'snippet' => '(SELECT EMAIL FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND ISDELETED = false
			LIMIT 1)', 'searchable' => true ),
	'CONTACT_NAME' => array( 'label' => 'Contact name', 'format' => 'text',
		'snippet' => '(SELECT CONTACT_NAME FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND ISDELETED = false
			LIMIT 1)', 'searchable' => true ),
	'CARRIER_NAME' => array( 'label' => 'Company Name', 'format' => 'text' ),
	'CITY' => array( 'label' => 'City', 'format' => 'text',
		'snippet' => '(SELECT CITY FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND ISDELETED = false
			LIMIT 1)', 'searchable' => true ),
	'STATE' => array( 'label' => 'State or province', 'format' => 'text',
		'snippet' => '(SELECT STATE FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND ISDELETED = false
			LIMIT 1)', 'searchable' => true ),
	'ZIP_CODE' => array( 'label' => 'Postal code', 'format' => 'text',
		'snippet' => '(SELECT ZIP_CODE FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND ISDELETED = false
			LIMIT 1)', 'searchable' => true ),
	'GROUP_ID' => array( 'label' => 'Group', 'format' => 'text' ),
	'PHONE_OFFICE' => array( 'label' => 'Phone number', 'format' => 'text',
		'snippet' => '(SELECT PHONE_OFFICE FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND ISDELETED = false
			LIMIT 1)', 'searchable' => true ),
	'PHONE_EXT' => array( 'label' => 'Ext', 'format' => 'text',
		'snippet' => '(SELECT PHONE_EXT FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND ISDELETED = false
			LIMIT 1)', 'searchable' => true ),
	'AFFILIATE_ID' => array( 'label' => 'Affiliate id', 'format' => 'text' ),
	'MC_NUM' => array( 'label' => 'MC or MX number', 'format' => 'text' ),
	'FF_NUMBER' => array( 'label' => 'FF number', 'format' => 'text' ),
	'DOT_NUM' => array( 'label' => 'DOT number', 'format' => 'text' ),
	'US_INTRASTATE_NUMBER' => array( 'label' => 'US intrastate number', 'format' => 'text' ),
	'CDN_AUTHORITY_NUMBER' => array( 'label' => 'Canadian authority number', 'format' => 'text' ),
);

$sts_result_carriers_pick_layout = array( //! $sts_result_carriers_pick_layout
	'CARRIER_CODE' => array( 'format' => 'hidden' ),
	'EXPIRED' => array( 'format' => 'hidden', 'snippet' => 'CARRIER_EXPIRED(CARRIER_CODE)' ),
	'CARRIER_PICK' => array( 'label' => 'Select', 'format' => 'carrier_pick', 'snippet' => '1' ),
	'CARRIER_NAME' => array( 'label' => 'Name', 'format' => 'text', 'searchable' => true ),
	'CARRIER_TYPE' => array( 'label' => 'Type', 'format' => 'text' ),
	'ADDRESS' => array( 'label' => 'Address', 'format' => 'text',
		'snippet' => '(SELECT CONCAT_WS(\',\', ADDRESS, CITY, STATE, ZIP_CODE, COUNTRY) FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND ISDELETED = false
			LIMIT 1)', 'searchable' => false ),
	'CARRIER_SINCE' => array( 'label' => 'Since', 'format' => 'date' ),	
	'CURRENCY_CODE' => array( 'label' => 'Currency', 'format' => 'text' ),	
	'CTPAT_CERTIFIED' => array( 'label' => 'C-TPAT', 'align' => 'center', 'format' => 'bool' ),
);

$sts_result_top20_carrier_layout = array( //! $sts_result_top20_carrier_layout
	'CARRIER_CODE' => array( 'label' => 'Carrier Name', 'format' => 'table',
		'table' => CARRIER_TABLE, 'key' => 'CARRIER_CODE', 'fields' => 'CARRIER_NAME', 'link' => 'exp_editcarrier.php?CODE=' ),
	'NUM' => array( 'label' => '#Loads', 'format' => 'number', 'align' => 'right' ),
	'REVENUE' => array( 'label' => 'Revenue', 'format' => 'num2', 'align' => 'right' ),
	'EXPENSE' => array( 'label' => 'Expense', 'format' => 'num2', 'align' => 'right' ),
	'AVG_REVENUE' => array( 'label' => 'Avg Revenue', 'format' => 'num2', 'align' => 'right' ),
	'AVG_EXPENSE' => array( 'label' => 'Avg Expense', 'format' => 'num2', 'align' => 'right' ),
	'MARGIN' => array( 'label' => 'Margin', 'format' => 'num2', 'align' => 'right' ),
	'AVG_MARGIN' => array( 'label' => 'Avg Margin', 'format' => 'num2', 'align' => 'right' ),
);

$sts_result_bidding_layout = array( //! $sts_result_bidding_layout
	'CARRIER_CODE' => array( 'label' => 'Carrier Name', 'format' => 'table',
		'table' => CARRIER_TABLE, 'key' => 'CARRIER_CODE', 'fields' => 'CARRIER_NAME', 'link' => 'exp_editcarrier.php?CODE=', 'length' => 120 ),
	'CARRIER_RATE' => array( 'label' => 'Carrier Rate', 'format' => 'number',
		'align' => 'right', 'length' => 50 ),
	'CURRENCY' => array( 'label' => 'Load Currency', 'format' => 'text', 'length' => 50 ),	
	'PICK_UPS' => array( 'label' => 'Pick Up', 'format' => 'text', 'length' => 120 ),
	'DELIVERIES' => array( 'label' => 'Delivery', 'format' => 'text', 'length' => 120 ),
	'PICKUP_DATE' => array( 'label' => 'PU Date', 'format' => 'date', 'length' => 50 ),	
	'DELIVER_DATE' => array( 'label' => 'Del Date', 'format' => 'date', 'length' => 50 ),	
	'COMMODITIES' => array( 'label' => 'Commodities', 'format' => 'text', 'length' => 120 ),
);

//! Edit/Delete Button Specifications - For use with sts_result

$sts_result_bidding_edit = array(
	'title' => '<img src="images/carrier_icon.png" alt="carrier_icon" height="24"> Bidding Report',
	'sort' => 'CARRIER_NAME asc',
	'nodelete' => true
);

$sts_result_carriers_edit = array(
	'title' => '<img src="images/carrier_icon.png" alt="carrier_icon" height="24"> Carriers',
	'sort' => 'CARRIER_NAME asc',
	'cancel' => 'index.php',
	'add' => 'exp_addcarrier.php',
	//'actionextras' => 'disabled',
	'addbutton' => 'Add Carrier',
	'cancelbutton' => 'Back',
	'rowbuttons' => array(
		array( 'url' => 'exp_editcarrier.php?CODE=', 'key' => 'CARRIER_CODE', 'label' => 'CARRIER_NAME', 'tip' => 'Edit carrier ', 'icon' => 'glyphicon glyphicon-edit', 'showif' => 'notdeleted' ),
		array( 'url' => 'exp_deletecarrier.php?TYPE=del&CODE=', 'key' => 'CARRIER_CODE', 'label' => 'CARRIER_NAME', 'tip' => 'Delete carrier ', 'icon' => 'glyphicon glyphicon-trash', 'confirm' => 'yes', 'showif' => 'notdeleted' ),
		array( 'url' => 'exp_deletecarrier.php?TYPE=undel&CODE=', 'key' => 'CARRIER_CODE', 'label' => 'CARRIER_NAME', 'tip' => 'Undelete carrier ', 'icon' => 'glyphicon glyphicon-arrow-left', 'showif' => 'deleted' ),
		array( 'url' => 'exp_deletecarrier.php?TYPE=permdel&CODE=', 'key' => 'CARRIER_CODE', 'label' => 'CARRIER_NAME', 'tip' => 'Permanently Delete carrier ', 'icon' => 'glyphicon glyphicon-remove', 'confirm' => 'yes', 'showif' => 'deleted' ),
		array( 'url' => 'exp_addclientrate.php?TYPE=carrier&CODE=', 'key' => 'CARRIER_CODE', 'label' => 'CARRIER_NAME', 'tip' => 'Add carrier rate ', 'icon' => 'glyphicon glyphicon-usd', 'showif' => 'notdeleted' )

	)
);

$sts_result_carriers_edit2 = array(
	'title' => '<img src="images/carrier_icon.png" alt="carrier_icon" height="24"> Carriers In Last Year',
	'sort' => 'CARRIER_NAME asc',
	'cancel' => 'index.php',
	'cancelbutton' => 'Back',
);

$sts_result_carriers_pick_edit = array(
	//'title' => '<img src="images/carrier_icon.png" alt="carrier_icon" height="24"> Carriers',
	'sort' => 'CARRIER_NAME asc',
);

//! Layout Specifications - For export to Sage 50
$sts_result_carriers_sage50_layout = array(	//! $sts_result_carriers_sage50_layout
	'SAGE50_VENDORID' => array( 'label' => 'Vendor ID', 'format' => 'text' ),
	'CARRIER_NAME' => array( 'label' => 'Vendor Name', 'format' => 'text' ),
	'INACTIVE' => array( 'label' => 'Inactive', 'format' => 'text',
		'snippet' => '\'FALSE\'' ),
	'CONTACT_NAME' => array( 'label' => 'Contact', 'format' => 'text',
		'snippet' => '(SELECT CONTACT_NAME FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND CONTACT_TYPE in (\'company\', \'carrier\')
			AND ISDELETED = false
			LIMIT 1)' ),
	'ADDRESS' => array( 'label' => 'Address-Line One', 'format' => 'text',
		'snippet' => '(SELECT ADDRESS FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND CONTACT_TYPE in (\'company\', \'carrier\')
			AND ISDELETED = false
			LIMIT 1)' ),
	'ADDRESS2' => array( 'label' => 'Address-Line Two', 'format' => 'text',
		'snippet' => '(SELECT ADDRESS2 FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND CONTACT_TYPE in (\'company\', \'carrier\')
			AND ISDELETED = false
			LIMIT 1)' ),
	'CITY' => array( 'label' => 'City', 'format' => 'text',
		'snippet' => '(SELECT CITY FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND CONTACT_TYPE in (\'company\', \'carrier\')
			AND ISDELETED = false
			LIMIT 1)' ),
	'STATE' => array( 'label' => 'State', 'format' => 'text',
		'snippet' => '(SELECT STATE FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND CONTACT_TYPE in (\'company\', \'carrier\')
			AND ISDELETED = false
			LIMIT 1)' ),
	'ZIP_CODE' => array( 'label' => 'Zip', 'format' => 'text',
		'snippet' => '(SELECT ZIP_CODE FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND CONTACT_TYPE in (\'company\', \'carrier\')
			AND ISDELETED = false
			LIMIT 1)' ),
	'COUNTRY' => array( 'label' => 'Country', 'format' => 'text',
		'snippet' => '(SELECT COUNTRY FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND CONTACT_TYPE in (\'company\', \'carrier\')
			AND ISDELETED = false
			LIMIT 1)' ),
	'CONTACT_NAME1' => array( 'label' => 'Remit to 1 Name', 'format' => 'text',
		'snippet' => '(SELECT CONTACT_NAME FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND CONTACT_TYPE = \'Remit to Address 1\'
			AND ISDELETED = false
			LIMIT 1)' ),
	'ADDRESS1' => array( 'label' => 'Remit to 1 Address Line 1', 'format' => 'text',
		'snippet' => '(SELECT ADDRESS FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND CONTACT_TYPE = \'Remit to Address 1\'
			AND ISDELETED = false
			LIMIT 1)' ),
	'ADDRESS21' => array( 'label' => 'Remit to 1 Address Line 2', 'format' => 'text',
		'snippet' => '(SELECT ADDRESS2 FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND CONTACT_TYPE = \'Remit to Address 1\'
			AND ISDELETED = false
			LIMIT 1)' ),
	'CITY1' => array( 'label' => 'Remit to 1 City', 'format' => 'text',
		'snippet' => '(SELECT CITY FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND CONTACT_TYPE = \'Remit to Address 1\'
			AND ISDELETED = false
			LIMIT 1)' ),
	'STATE1' => array( 'label' => 'Remit to 1 State', 'format' => 'text',
		'snippet' => '(SELECT STATE FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND CONTACT_TYPE = \'Remit to Address 1\'
			AND ISDELETED = false
			LIMIT 1)' ),
	'ZIP_CODE1' => array( 'label' => 'Remit to 1 Zip', 'format' => 'text',
		'snippet' => '(SELECT ZIP_CODE FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND CONTACT_TYPE = \'Remit to Address 1\'
			AND ISDELETED = false
			LIMIT 1)' ),
	'COUNTRY1' => array( 'label' => 'Remit to 1 Country', 'format' => 'text',
		'snippet' => '(SELECT COUNTRY FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND CONTACT_TYPE = \'Remit to Address 1\'
			AND ISDELETED = false
			LIMIT 1)' ),
	'VTYPE' => array( 'label' => 'Vendor Type', 'format' => 'text',
		'snippet' => '\'SUPPLY\'' ),
	'VTYPE2' => array( 'label' => '1099 Type', 'format' => 'text',
		'snippet' => '0' ),
	'PHONE1' => array( 'label' => 'Telephone 1', 'format' => 'text',
		'snippet' => '(SELECT PHONE_OFFICE FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND ISDELETED = false
			LIMIT 1)' ),
	'PHONE2' => array( 'label' => 'Telephone 2', 'format' => 'text',
		'snippet' => '(SELECT PHONE_CELL FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND ISDELETED = false
			LIMIT 1)' ),
	'FAX' => array( 'label' => 'Fax Number', 'format' => 'text',
		'snippet' => '(SELECT PHONE_FAX FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND ISDELETED = false
			LIMIT 1)' ),
	'EMAIL' => array( 'label' => 'Vendor E-mail', 'format' => 'text',
		'snippet' => '(SELECT EMAIL FROM EXP_CONTACT_INFO
			WHERE CONTACT_CODE = CARRIER_CODE AND CONTACT_SOURCE = \'carrier\'
			AND ISDELETED = false
			LIMIT 1)' ),
	'VWEB' => array( 'label' => 'Vendor Web Site', 'format' => 'text',
		'snippet' => '\'\'' ),
	'EXPENSE' => array( 'label' => 'Expense Account', 'format' => 'text',
		'snippet' => '\'\'' ),
	'VSHIPVIA' => array( 'label' => 'Ship Via', 'format' => 'text',
		'snippet' => '0' ),
	'TERMS' => array( 'label' => 'Use Standard Terms', 'format' => 'text',
		'snippet' => '\'TRUE\'' ),
	'IDUE' => array( 'label' => 'Due Days', 'format' => 'table',
		'snippet' => '30' ),
);

$sts_result_top20_carrier_view = array(	//! $sts_result_top20_carrier_view
	'title' => '<img src="images/carrier_icon.png" alt="carrier_icon" height="24"> Top 20 Carriers Over The Past 12 Months (Converted)',
	'filters' => false,
	//'cancel' => 'index.php',
	//'add' => 'exp_addshipment.php',
	//'actionextras' => 'disabled',
	//'addbutton' => 'Add Shipment',
	'cancelbutton' => 'Back',
);

//! Use this for parsing CSV files & importing carriers
$sts_csv_carrier_parse = array(
	array( 'table' => CARRIER_TABLE, 'class' => 'sts_carrier',
		'primary' => 'CARRIER_CODE',
		'rows' => array(
			'CARRIER_NAME' => array( 'label' => 'Name', 'format' => 'text',
				'required' => true ),
			'SAGE50_VENDORID' => array( 'label' => 'SageID', 'format' => 'text' ),
			'CARRIER_TYPE' => array( 'label' => 'CarrierType', 'format' => 'enum' ),
			'DANG_GOODS_ALLOWED' => array( 'label' => 'DangGoods', 'format' => 'bool' ),
			'TEMP_CONTR_ALLOWED' => array( 'label' => 'TempContr', 'format' => 'bool' ),
			'DEFAULT_TRAILER_TYPE' => array( 'label' => 'DefaultTrailer', 'format' => 'text' ),
			'CURRENCY_CODE' => array( 'label' => 'Currency', 'format' => 'enum',
				'default' => 'USD' ),
			'FED_CODE_NUM' => array( 'label' => 'FedCodeNum', 'format' => 'text' ),
			'EMAIL_NOTIFY' => array( 'label' => 'EmailNotify', 'format' => 'email' ),
			'FAX_NOTIFY' => array( 'label' => 'FaxNotify', 'format' => 'text' ),
			'DEFAULT_ZONE' => array( 'label' => 'DefaultZone', 'format' => 'text' ),
			'TERMINAL_ZONE' => array( 'label' => 'TerminalZone', 'format' => 'text' ),
			'OPEN_TIME' => array( 'label' => 'OpenTime', 'format' => 'text' ),
			'CLOSE_TIME' => array( 'label' => 'CloseTime', 'format' => 'text' ),
			'DISCOUNT' => array( 'label' => 'Discount', 'format' => 'number' ),
			'PMT_HOLD' => array( 'label' => 'PmtHold', 'format' => 'bool' ),
			'DUNS_CODE' => array( 'label' => 'DUNSCode', 'format' => 'text' ),
			'SCAC_CODE' => array( 'label' => 'SCACCode', 'format' => 'text' ),
			'MC_NUM' => array( 'label' => 'MCNum', 'format' => 'text' ),
			'CARGO_NUM' => array( 'label' => 'CargoNum', 'format' => 'text' ),
			'DOT_NUM' => array( 'label' => 'DOTNum', 'format' => 'text' ),
			'NUM_TRUCKS' => array( 'label' => 'NumTrucks', 'format' => 'number' ),
			'CARRIER_NOTES' => array( 'label' => 'CarrierNotes', 'format' => 'text' ),
			'GENERAL_LIAB_INS' => array( 'label' => 'General', 'format' => 'number' ),
			'AUTO_LIAB_INS' => array( 'label' => 'Auto', 'format' => 'number' ),
			'CARGO_LIAB_INS' => array( 'label' => 'Cargo', 'format' => 'number' ),
			'LIABILITY_DATE' => array( 'label' => 'GeneralDate', 'format' => 'date' ),
			'AUTO_LIAB_DATE' => array( 'label' => 'AutoDate', 'format' => 'date' ),
			'CARGO_LIAB_DATE' => array( 'label' => 'CargoDate', 'format' => 'date' ),
		),
	),
	array( 'table' => CONTACT_INFO_TABLE, 'class' => 'sts_contact_info',
		'primary' => 'CONTACT_INFO_CODE',
		'rows' => array(
			'CONTACT_CODE' => array( 'format' => 'link', 'value' => 'CARRIER_CODE',
				'required' => true ),
			'CONTACT_SOURCE' => array( 'format' => 'text', 'value' => 'carrier' ),
			'CONTACT_TYPE' => array( 'format' => 'enum', 'value' => 'company' ),
	
			'LABEL' => array( 'label' => 'Label', 'format' => 'text' ),
			'CONTACT_NAME' => array( 'label' => 'ContactName', 'format' => 'text' ),
			'JOB_TITLE' => array( 'label' => 'JobTitle', 'format' => 'text' ),
			'ADDRESS' => array( 'label' => 'Address', 'format' => 'text' ),
			'ADDRESS2' => array( 'label' => 'Address2', 'format' => 'text' ),
			'CITY' => array( 'label' => 'City', 'format' => 'text' ),
			'STATE' => array( 'label' => 'State', 'format' => 'state' ),
			'ZIP_CODE' => array( 'label' => 'Zip', 'format' => 'text' ),
			'COUNTRY' => array( 'label' => 'Country', 'format' => 'enum' ),
			'PHONE_OFFICE' => array( 'label' => 'OfficePhone', 'format' => 'text' ),
			'PHONE_EXT' => array( 'label' => 'PhoneExt', 'format' => 'text' ),
			'PHONE_FAX' => array( 'label' => 'PhoneFax', 'format' => 'text' ),
			'PHONE_HOME' => array( 'label' => 'PhoneHome', 'format' => 'text' ),
			'PHONE_CELL' => array( 'label' => 'PhoneCell', 'format' => 'text' ),
			'EMAIL' => array( 'label' => 'Email', 'format' => 'email' )
		),
	),
);

?>
