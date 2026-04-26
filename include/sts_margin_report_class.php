<?php

// $Id: sts_margin_report_class.php 5614 2026-01-11 04:32:14Z dev $
// Margin Report functions

// no direct access
defined('_STS_INCLUDE') or die('Restricted access');

require_once( "sts_table_class.php" );
require_once( "sts_setting_class.php" );
require_once( "sts_shipment_class.php" );
require_once( "sts_email_class.php" );

class sts_margin_report extends sts_table {
	private		$setting_table;
	private		$export_sage50;
	private		$ass_backwards;
	public		$database;

	// Constructor does not need the table name
	public function __construct( $database, $debug = false ) {

		$this->debug = $debug;
		$this->database = $database;
		$this->primary_key = "LOAD_CODE";
		
		$this->setting_table = sts_setting::getInstance( $this->database, $this->debug );
		$this->export_sage50 = $this->setting_table->get( 'api', 'EXPORT_SAGE50_CSV' ) == 'true';
		$this->client_id = ($this->setting_table->get("option", "CLIENT_ID") == 'true');
		$this->ass_backwards = ($this->setting_table->get("option", "MARGIN_LUMPER_NOTAX") == 'true');

		if( $this->debug ) echo "<p>Create sts_margin_report</p>";
		parent::__construct( $database, LOAD_TABLE, $debug);

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
    
    public function ass_backwards() {
	    return $this->ass_backwards;
    }
    
    public function user_menu( $selected = false, $id = 'USER_CODE', $match = '', $onchange = true ) {
		$select = false;

		$choices = $this->database->get_multiple_rows("
			select USER_CODE, USERNAME, FULLNAME 
			from exp_user
			where USER_GROUPS like '%sales%'
			AND ISACTIVE = 'Active'
			UNION
			select -1 as USER_CODE, '' as USERNAME, '-- ALL --' as FULLNAME
			UNION
			select -2 as USER_CODE, '' as USERNAME, '-- No Sales Person --' as FULLNAME 
			order by FULLNAME");

		if( is_array($choices) && count($choices) > 0) {
			
			$select = '<select class="form-control input-lg" name="'.$id.'" id="'.$id.'"'.($onchange ? ' onchange="form.submit();"' : '').'>
			';
			$select .= '<option value="None">Select Sales Person</option>
			';
			foreach( $choices as $row ) {
				$select .= '<option value="'.$row["USER_CODE"].'"';
				if( $selected && $selected == $row["USER_CODE"] )
					$select .= ' selected';
				$select .= '>'.$row["FULLNAME"].($row["USER_CODE"] > 0 ?
					' ('.$row["USERNAME"].')' : '').'</option>
				';
			}
			$select .= '</select>';
		}
			
		return $select;
	}

    public function user_name( $user ) {
	    $result = "No Sales Person";

		$check = $this->database->get_one_row("
			select USER_CODE, FULLNAME
			from (select USER_CODE, FULLNAME 
			from exp_user
			where USER_GROUPS like '%sales%'
			AND ISACTIVE = 'Active'
			UNION
			select -1 as USER_CODE, '-- ALL --' as FULLNAME
			UNION
			select -2 as USER_CODE, '-- No Sales Person --' as FULLNAME 
			order by FULLNAME) x
			where USER_CODE = $user
			LIMIT 1");
			
		if( is_array($check) && isset($check['FULLNAME']) )
			$result = $check['FULLNAME'];
		
		return $result;
	}
	
     public function has_subs( $user, $id = 'HAS_SUBS' ) {
	    $result = "";

		$check = $this->database->get_one_row("
			SELECT count(*) > 0 HAS_SUBS
			FROM exp_user
			where manager = $user");
			
		if( is_array($check) && isset($check['HAS_SUBS']) && $check['HAS_SUBS'] == 1 ) {
			$result = '	<div class="form-group h3">
			<label class="col-sm-3" for="HAS_SUBS">Include Directs: </label>
			<div class="col-sm-2">
				<input type="checkbox" class="my-switch" name="'.$id.'">
			</div>
			<label class="col-sm-3" for="USER_CODE">OR Select Direct: </label>
			<div class="col-sm-3">
				'.$this->subs_menu( $user).'
			</div>
		</div>';
		}
		
		return $result;
	}
	
    public function subs_menu( $user, $selected = false, $id = 'SUBS_MENU' ) {
	    $result = "";

		$choices = $this->database->get_multiple_rows("
			SELECT USER_CODE, USERNAME, FULLNAME
			FROM EXP_USER
			WHERE MANAGER = $user");	
			
		if( is_array($choices) && count($choices) > 0) {
			
			$select = '<select class="form-control input-lg" name="'.$id.'" id="'.$id.'">
			';
			$select .= '<option value="None">NONE</option>
			';
			foreach( $choices as $row ) {
				$select .= '<option value="'.$row["USER_CODE"].'"';
				if( $selected && $selected == $row["USER_CODE"] )
					$select .= ' selected';
				$select .= '>'.$row["FULLNAME"].($row["USER_CODE"] > 0 ?
					' ('.$row["USERNAME"].')' : '').'</option>
				';
			}
			$select .= '</select>';
		} else {
			$select = '';
		}
			
		return $select;
	}
	
	public function office_menu( $selected = false, $user, $id = 'OFFICE_CODE' ) {
		$select = false;

		$choices = $this->database->get_multiple_rows("
			SELECT distinct o.OFFICE_CODE, o.OFFICE_NAME, o.INVOICE_PREFIX, u.USER_GROUPS like '%admin%' IS_ADMIN
			FROM EXP_OFFICE o, EXP_USER u, EXP_USER_OFFICE uo
			WHERE $user < 0
			OR (u.USER_CODE = $user
			AND(u.USER_GROUPS like '%admin%'
				OR (u.USER_CODE = uo.USER_CODE
					AND o.OFFICE_CODE = uo.OFFICE_CODE)))
			GROUP BY o.OFFICE_CODE
			ORDER BY 2 ASC");

		if( is_array($choices) && count($choices) > 0) {
			
			$select = '<select class="form-control input-lg" name="'.$id.'" id="'.$id.'">
			';
			
			// All is only for admin
			if( $user < 0 || (isset($choices[0]["IS_ADMIN"]) && $choices[0]["IS_ADMIN"]) ) {
				$select .= '<option value="-1"';
				if( $selected && $selected == -1 )
					$select .= ' selected';
				$select .= '>-- ALL --</option>
				';
			}

			foreach( $choices as $row ) {
				$select .= '<option value="'.$row["OFFICE_CODE"].'"';
				if( $selected && $selected == $row["OFFICE_CODE"] )
					$select .= ' selected';
				$select .= '>'.$row["OFFICE_NAME"].' ('.$row["INVOICE_PREFIX"].')</option>
				';
			}
			$select .= '</select>';
		}
			
		return $select;
	}

    public function currency_menu( $id = 'CURRENCY' ) {
	    $home = $this->setting_table->get("option", "HOME_CURRENCY");
	    
	    $choices = ['USD', 'CAD'];
	    
		$select = '<select class="form-control input-lg" name="'.$id.'" id="'.$id.'">
		';
			
		foreach( $choices as $row ) {
			$select .= '<option value="'.$row.'"';
			if( $home && $home == $row )
				$select .= ' selected';
			$select .= '>'.$row.'</option>
			';
		}
		$select .= '</select>';
		
		return $select;
    }
    
    public function business_code_menu( $selected = false, $id = 'BUSINESS_CODE' ) {
		$select = false;

		$choices = $this->database->get_multiple_rows("
			select exp_business_code.BUSINESS_CODE, exp_business_code.BC_NAME
			from exp_business_code
			where APPLIES_TO = 'shipment'
			UNION
			select -1 as BUSINESS_CODE, '-- ALL --' as BC_NAME 
			order by BC_NAME");

		if( is_array($choices) && count($choices) > 0) {
			
			$select = '<select class="form-control input-lg" name="'.$id.'" id="'.$id.'">
			';
			foreach( $choices as $row ) {
				$select .= '<option value="'.$row["BUSINESS_CODE"].'"';
				if( $selected && $selected == $row["BUSINESS_CODE"] )
					$select .= ' selected';
				$select .= '>'.$row["BC_NAME"].'</option>
				';
			}
			$select .= '</select>';
		}
			
		return $select;
	}

    public function client_field( $selected = false, $id = 'CLIENT_CODE' ) {
		$ctype = 'bill_to';
		$hb = '<p><strong>{{CLIENT_NAME}}/{{LABEL}}</strong><br>{{CONTACT_TYPE}}, {{ADDRESS}}, {{CITY}}, {{STATE}}, {{ZIP_CODE}}'.($this->client_id ? '<br>Client ID: {{CLIENT_ID}}' : '').($this->export_sage50 ? '<br>Sage50: {{SAGE50_CLIENTID}}' : '').'</p>';
		
		$select = '<input type="hidden" name="'.$id.'" id="'.$id.'" value="-1">
			<div class="form-group h3">
				<label class="col-sm-3" for="CLIENT_NAME">Client: </label>
				<div class="col-sm-4">
					<input class="form-control input-lg" name="CLIENT_NAME" id="CLIENT_NAME" type="text"  autocomplete="off" placeholder="Leave blank for all">
				</div>
			</div>
		
			<script language="JavaScript" type="text/javascript"><!--
		
			var '.$id.'_clients = new Bloodhound({
			  name: \''.$id.'\',
			  remote : {
				  url: \'exp_suggest_client.php?code=Vinegar&type='.$ctype.'&query=%QUERY\',
				  wildcard: \'%QUERY\'
			  },
			  datumTokenizer: Bloodhound.tokenizers.obj.whitespace(\'LABEL\'),
			  queryTokenizer: Bloodhound.tokenizers.whitespace
			});
						
			'.$id.'_clients.initialize();

			$(\'#CLIENT_NAME:not([readonly])\').typeahead(null, {
			  name: \''.$id.'\',
			  minLength: 2,
			  limit: 20,
			  highlight: true,
			  display: \'CLIENT_NAME\',
			  source: '.$id.'_clients,
			  templates: {
			  	suggestion: Handlebars.compile(
			      \''.$hb.'\')
			  }
			});
			
			$(\'#CLIENT_NAME\').on(\'typeahead:selected\', function(obj, datum, name) {
				$(\'input#CLIENT_CODE\').val(datum.CLIENT_CODE);
			});
			
	//--></script>
				';
			
		return $select;
	}

	/*
	public function margin_report( $user, $has_subs, $office, $business, $client, $from, $to, $currency ) {
		
		$shipment_table = sts_shipment::getInstance($this->database, $this->debug);

		// Note: LOAD_REVENUE fails if NUM_SHIPMENTS > 1 and each shipment has charges
		// should not be a problem for SS
		$report = $this->database->multi_query("
			DROP TEMPORARY TABLE IF EXISTS SHIPMENTS;
			
			CREATE TEMPORARY TABLE SHIPMENTS
			select distinct shipment_code, LOAD_CODE
			from (select l.LOAD_CODE, sh.shipment_code
			from exp_shipment sh
            left join exp_load l
			
			on L.LOAD_CODE = SH.LOAD_CODE
			and sh.CURRENT_STATUS != ".$shipment_table->behavior_state["cancel"]."
			where sh.PICKUP_DATE between '".date("Y-m-d", strtotime($from))."' and '".date("Y-m-d", strtotime($to))."') x
			
			union ALL
			(select l.LOAD_CODE, sh.shipment_code
			from exp_shipment sh, exp_load l, EXP_SHIPMENT_LOAD SHL
			
			where L.LOAD_CODE = SHL.LOAD_CODE
			and sh.LOAD_CODE = SHL.LOAD_CODE
			and sh.CURRENT_STATUS != ".$shipment_table->behavior_state["cancel"]."
			and sh.PICKUP_DATE between '".date("Y-m-d", strtotime($from))."' and '".date("Y-m-d", strtotime($to))."');

			select a.*,
			COALESCE(case when ".($this->ass_backwards ? "1" : "0")." AND CLIENT_CURRENCY = '".$currency."' then
				TOTAL_CHARGES
			when LOAD_CODE is NULL then
				TOTAL_CHARGES * exchange_rate1
			else
				LOAD_REVENUE_ORIG * exchange_rate2 end, 0) AS LOAD_REVENUE,
			COALESCE(case when CARRIER_CURRENCY = '".$currency."' then
				CARRIER_TOTAL
			else
				LOAD_EXPENSE_ORIG * exchange_rate2 end, 0) AS LOAD_EXPENSE
			".($this->ass_backwards ? ", COALESCE(LUMPER_BASE * exchange_rate3, 0) AS LUMPER_CHANGE" : "")."
			from(
			select distinct sh.SHIPMENT_CODE,
				sh.PICKUP_DATE,
		        bc.BC_NAME,
		        sh.ss_number,
				l.LOAD_CODE,
				sh.OFFICE_CODE,
				sh.sales_person,
				u.USER_CODE, COALESCE(u.FULLNAME, 'NO SALESPERSON') FULLNAME,
				o.OFFICE_name as Office_Name,
		        concat(o.OFFICE_name,' - ',sh.office_code) as office,
		        sh.BILLTO_NAME, 
		        sh.TOTAL_CHARGES,
		        COALESCE(CASE WHEN L.LOAD_REVENUE IS NULL THEN
					LOAD_REVENUE_CUR(L.LOAD_CODE, l.currency)
				ELSE L.LOAD_REVENUE END, 0) AS LOAD_REVENUE_ORIG,
				COALESCE(".($this->ass_backwards ? "L.CARRIER_TOTAL" :
				"CASE WHEN L.LOAD_EXPENSE IS NULL THEN
					LOAD_EXPENSE_CUR(L.LOAD_CODE, l.currency)
				ELSE L.LOAD_EXPENSE END").", 0) AS LOAD_EXPENSE_ORIG,
		        l.currency as CARRIER_CURRENCY,
		        cb.currency as CLIENT_CURRENCY,
		        L.LUMPER_CURRENCY,
		        CASE WHEN L.LUMPER > 0 THEN L.CARRIER_HANDLING ELSE NULL END AS LUMPER_BASE, 
		        CASE WHEN L.LUMPER > 0 THEN L.LUMPER_TAX ELSE NULL END AS LUMPER_TAX, 
		        CASE WHEN L.LUMPER > 0 THEN L.LUMPER_TOTAL ELSE NULL END AS LUMPER_TOTAL, 
		        L.CARRIER_TOTAL,
		        CONVERT_RATE(sh.PICKUP_DATE, 'USD', 'CAD') exchange_rate,
		        CONVERT_RATE(sh.PICKUP_DATE, cb.currency, '".$currency."') exchange_rate1,  
		        COALESCE(CONVERT_RATE(sh.PICKUP_DATE, l.currency, '".$currency."'), 0) exchange_rate2,
		        ".($this->ass_backwards ? "COALESCE(CONVERT_RATE(sh.PICKUP_DATE, L.LUMPER_CURRENCY, '".$currency."'), 0) exchange_rate3,
" : "")."
		        (select COMPANY_NAME from exp_company
                where exp_company.company_code = o.company_code) as companyName
                , CASE WHEN TOTAL_CHARGES_HOME IS NULL THEN
		        	CONVERT_TO_HOME(SHIPMENT_ID, TOTAL_CHARGES)
		        ELSE
		        	TOTAL_CHARGES_HOME END AS CNVRTCharges
		 		, bc.BC_NAME BUSINESS
		 		, concat(sc.STATUS_CODES_CODE,'-',sc.STATUS_STATE) STATUS_STATE
				from shipments
				left join exp_shipment sh on sh.shipment_code = shipments.shipment_code
				LEFT JOIN EXP_LOAD L ON L.LOAD_CODE = shipments.load_code

		        LEFT JOIN EXP_OFFICE O ON SH.OFFICE_CODE = O.OFFICE_CODE
		        LEFT JOIN EXP_BUSINESS_CODE BC ON SH.BUSINESS_CODE = BC.BUSINESS_CODE
		        LEFT JOIN EXP_CLIENT_BILLING CB ON CB.SHIPMENT_ID = SH.SHIPMENT_CODE
		        LEFT JOIN EXP_USER U ON SH.SALES_PERSON = U.USER_CODE
		        LEFT JOIN EXP_STATUS_CODES SC ON SH.CURRENT_STATUS = SC.STATUS_CODES_CODE
		where sh.PICKUP_DATE between '".date("Y-m-d", strtotime($from))."' and '".date("Y-m-d", strtotime($to))."'
		 AND (sh.TOTAL_CHARGES > 0 -- remove zero revenue shipments
		 	OR COALESCE(CASE WHEN L.LOAD_REVENUE IS NULL THEN
					LOAD_REVENUE_CUR(L.LOAD_CODE, l.currency)
				ELSE L.LOAD_REVENUE END, 0) = 0)
		".($user == -2 ? "AND IFNULL(u.USER_CODE,0) = 0" :
		($user > 0 ? ($has_subs ? "AND IFNULL(u.USER_CODE,0) IN
			((SELECT USER_CODE FROM EXP_USER WHERE MANAGER = $user OR USER_CODE = $user))" :
		"AND IFNULL(u.USER_CODE,0) = ".$user) : ""))."
		-- AND INSTR(sh.ss_number,'-')=0 -- extra stops
		-- and sh.BILLING_STATUS in (".$shipment_table->billing_behavior_state["approved"].", ".
		-- 	$shipment_table->billing_behavior_state["billed"].")
		and sh.CURRENT_STATUS != ".$shipment_table->behavior_state["cancel"]."
		
		".($office > 0 ? "and sh.OFFICE_CODE = ".$office : "")."
		".($business > 0 ? "and sh.BUSINESS_CODE = ".$business : "")."
		".($client > 0 ? "and sh.BILLTO_CLIENT_CODE = ".$client : "")."
		) a
		ORDER BY a.USER_CODE ASC, a.OFFICE_CODE ASC, a.SS_NUMBER ASC, a.LOAD_CODE ASC
		" );

		//	CONVERT_RATE(sh.PICKUP_DATE, cb.currency, '".$currency."') exchange_rate1,   
		//	(select count(*) from exp_shipment s where s.load_code = l.load_code) as NUM_SHIPMENTS,

		return $report;
	}
//		and sh.BILLING_STATUS = ".$shipment_table->billing_behavior_state["billed"]."

*/
	public function margin_report( $user, $has_subs, $office, $business, $client, $from, $to, $currency ) {
		
		$shipment_table = sts_shipment::getInstance($this->database, $this->debug);

		// Note: LOAD_REVENUE fails if NUM_SHIPMENTS > 1 and each shipment has charges
		// should not be a problem for SS
		$report = $this->database->multi_query("
			-- remove cancelled shipments
			DELETE FROM exp_margin_report_data
			WHERE PICKUP_DATE between '".date("Y-m-d", strtotime($from))."' and '".date("Y-m-d", strtotime($to))."'
			AND EXISTS (SELECT SHIPMENT_CODE FROM EXP_SHIPMENT WHERE
EXP_SHIPMENT.SHIPMENT_CODE = EXP_MARGIN_REPORT_DATA.SHIPMENT_CODE
AND CURRENT_STATUS = ".$shipment_table->behavior_state["cancel"].");

			select a.*,
			COALESCE(case when ".($this->ass_backwards ? "1" : "0")." AND CLIENT_CURRENCY = '".$currency."' then
				TOTAL_CHARGES
			when LOAD_CODE is NULL then
				CONVERT_NEW(exchange_rate, TOTAL_CHARGES, CLIENT_CURRENCY, '".$currency."')
			else
				CONVERT_NEW(exchange_rate, TOTAL_CHARGES, CARRIER_CURRENCY, '".$currency."')
			end, 0) AS LOAD_REVENUE,
			
			COALESCE(case when CARRIER_CURRENCY = '".$currency."' then
				CARRIER_TOTAL
			else
				CONVERT_NEW(exchange_rate, LOAD_EXPENSE_ORIG, CARRIER_CURRENCY, '".$currency."')
			end, 0) AS LOAD_EXPENSE
				
			".($this->ass_backwards ? ", COALESCE(
			CONVERT_NEW(exchange_rate, LUMPER_BASE, LUMPER_CURRENCY, '".$currency."'), 0) AS LUMPER_CHANGE" : "")."

			from(
			select distinct SHIPMENT_CODE,
				PICKUP_DATE,
		        BC_NAME,
				SS_NUMBER AS ss_number,
				LOAD_CODE,
				OFFICE_CODE,
				SALES_PERSON AS sales_person,
				SALES_PERSON AS USER_CODE,
				FULLNAME,
				OFFICE_NAME as Office_Name,
		        concat(OFFICE_NAME,' - ',OFFICE_CODE) as office,
		        BILLTO_NAME, 
		        TOTAL_CHARGES,
		        LOAD_REVENUE_ORIG,
				LOAD_EXPENSE_ORIG,
		        CARRIER_CURRENCY,
		        CLIENT_CURRENCY,
		        LUMPER_CURRENCY,
		        LUMPER_BASE, 
		        LUMPER_TAX, 
		        LUMPER_TOTAL, 
		        CARRIER_TOTAL,
		        EXCHANGE_RATE AS exchange_rate,
		        
		--        CONVERT_RATE(sh.PICKUP_DATE, cb.currency, '".$currency."') exchange_rate1,  
		--        COALESCE(CONVERT_RATE(sh.PICKUP_DATE, l.currency, '".$currency."'), 0) exchange_rate2,
		--        ".($this->ass_backwards ? "COALESCE(CONVERT_RATE(sh.PICKUP_DATE, L.LUMPER_CURRENCY, '".$currency."'), 0) exchange_rate3,
" : "")."
		    	COMPANY_NAME as companyName,
                CNVRT_CHARGES AS CNVRTCharges,
		 		BC_NAME AS BUSINESS,
		 		CURRENT_STATUS,
		 		STATUS_STATE
		
		FROM exp_margin_report_data

		WHERE PICKUP_DATE between '".date("Y-m-d", strtotime($from))."' and '".date("Y-m-d", strtotime($to))."'
		
		 AND (TOTAL_CHARGES > 0 -- remove zero revenue shipments
		 	OR ( LOAD_OFFICE_NUM(LOAD_CODE) = SS_NUMBER AND
		-- 	OR ( LOAD_NUM_SHIPMENTS(LOAD_CODE) = 1 AND
		 	COALESCE(LOAD_REVENUE_ORIG,0) - COALESCE(LOAD_EXPENSE_ORIG,0) != 0))  -- New approach
		 
		".($user == -2 ? "AND IFNULL(SALES_PERSON,0) = 0" :
		($user > 0 ? ($has_subs ? "AND IFNULL(SALES_PERSON,0) IN
			((SELECT USER_CODE FROM EXP_USER WHERE MANAGER = $user OR USER_CODE = $user))" :
		"AND IFNULL(SALES_PERSON,0) = ".$user) : ""))."
		-- AND INSTR(SS_NUMBER,'-')=0 -- extra stops
		
		-- and BILLING_STATUS in (".$shipment_table->billing_behavior_state["approved"].", ".
		-- 	$shipment_table->billing_behavior_state["billed"].")
		and CURRENT_STATUS != ".$shipment_table->behavior_state["cancel"]."
		
		".($office > 0 ? "and OFFICE_CODE = ".$office : "")."
		".($business > 0 ? "and BUSINESS_CODE = ".$business : "")."
		".($client > 0 ? "and BILLTO_CLIENT_CODE = ".$client : "")."
		) a
		ORDER BY a.USER_CODE ASC, a.OFFICE_CODE ASC, a.SS_NUMBER ASC, a.LOAD_CODE ASC
		" );

		//	CONVERT_RATE(sh.PICKUP_DATE, cb.currency, '".$currency."') exchange_rate1,   
		//	(select count(*) from exp_shipment s where s.load_code = l.load_code) as NUM_SHIPMENTS,

		return $report;
	}


	// Given a shipment, update the margin report data
	public function add_margin_report_data( $shipment_code ) {
		
		$shipment_table = sts_shipment::getInstance($this->database, $this->debug);

	/*	$check = $shipment_table->fetch_rows("SHIPMENT_CODE = ".$shipment_code.
			" AND (TOTAL_CHARGES IS NULL OR CURRENT_STATUS != 20)", "SHIPMENT_CODE, TOTAL_CHARGES, CURRENT_STATUS" );
		if( is_array($check) && count($check) == 1 ) {
			echo "<h1>Send email</h1>";
			
			$email = sts_email::getInstance($this->database, $this->debug);
			$email->send_alert(__METHOD__.": Anomaly, shipment = ".$shipment_code.
			" contents = <pre>\n". print_r($check, true)."\n</pre>" );
		}
	*/

		// Note: LOAD_REVENUE fails if NUM_SHIPMENTS > 1 and each shipment has charges
		// should not be a problem for SS
		$report = $this->database->multi_query("
			DELETE FROM exp_margin_report_data
			WHERE SHIPMENT_CODE = $shipment_code;
			
			DROP TEMPORARY TABLE IF EXISTS SHIPMENTS;
			
			CREATE TEMPORARY TABLE SHIPMENTS
			select distinct LOAD_CODE, SHIPMENT_CODE
			from (select l.LOAD_CODE, sh.shipment_code
			from exp_shipment sh
            left join exp_load l
			
			on L.LOAD_CODE = SH.LOAD_CODE
			and sh.CURRENT_STATUS != ".$shipment_table->behavior_state["cancel"]."
			where sh.shipment_code = $shipment_code) x
			
			union
			(select LOAD_CODE, shipment_code
			from EXP_SHIPMENT_LOAD			
			where shipment_code = $shipment_code);
			
			INSERT INTO exp_margin_report_data (
				SHIPMENT_CODE, PICKUP_DATE, BUSINESS_CODE, BC_NAME, SS_NUMBER, LOAD_CODE, 
				OFFICE_CODE, OFFICE_NAME, SALES_PERSON, FULLNAME,
				
				BILLTO_CLIENT_CODE, BILLTO_NAME,
				TOTAL_CHARGES, LOAD_REVENUE_ORIG, LOAD_EXPENSE_ORIG,
				
				CARRIER_CURRENCY, CLIENT_CURRENCY, LUMPER_CURRENCY,
				
				LUMPER_BASE, LUMPER_TAX, LUMPER_TOTAL, CARRIER_TOTAL,
				
				EXCHANGE_RATE, COMPANY_NAME, CNVRT_CHARGES,
				
				BILLING_STATUS, CURRENT_STATUS, STATUS_STATE, CREATED_DATE, CREATED_BY )
							
			select distinct sh.SHIPMENT_CODE,
				sh.PICKUP_DATE, SH.BUSINESS_CODE, bc.BC_NAME, sh.ss_number, l.LOAD_CODE,
				
				sh.OFFICE_CODE, o.OFFICE_name as Office_Name,
				sh.sales_person,
				COALESCE(u.FULLNAME, 'NO SALESPERSON') FULLNAME,
				
		        sh.BILLTO_CLIENT_CODE, sh.BILLTO_NAME, sh.TOTAL_CHARGES,
		        COALESCE(CASE WHEN L.LOAD_REVENUE IS NULL THEN
					LOAD_REVENUE_CUR(L.LOAD_CODE, l.currency)
				ELSE L.LOAD_REVENUE END, 0) AS LOAD_REVENUE_ORIG,
				COALESCE(".($this->ass_backwards ? "L.CARRIER_TOTAL" :
				"CASE WHEN L.LOAD_EXPENSE IS NULL THEN
					LOAD_EXPENSE_CUR(L.LOAD_CODE, l.currency)
				ELSE L.LOAD_EXPENSE END").", 0) AS LOAD_EXPENSE_ORIG,
				
		        l.currency as CARRIER_CURRENCY,
		        cb.currency as CLIENT_CURRENCY,
		        L.LUMPER_CURRENCY,
		        
		        CASE WHEN L.LUMPER > 0 THEN L.CARRIER_HANDLING ELSE NULL END AS LUMPER_BASE, 
		        CASE WHEN L.LUMPER > 0 THEN L.LUMPER_TAX ELSE NULL END AS LUMPER_TAX, 
		        CASE WHEN L.LUMPER > 0 THEN L.LUMPER_TOTAL ELSE NULL END AS LUMPER_TOTAL, 
		        L.CARRIER_TOTAL,
		        
		        CONVERT_RATE(sh.PICKUP_DATE, 'USD', 'CAD') AS EXCHANGE_RATE,
		        (select COMPANY_NAME from exp_company
                where exp_company.company_code = o.company_code) as COMPANY_NAME,
                CASE WHEN TOTAL_CHARGES_HOME IS NULL THEN
		        	CONVERT_TO_HOME(SHIPMENT_ID, TOTAL_CHARGES)
		        ELSE
		        	TOTAL_CHARGES_HOME END AS CNVRT_CHARGES,
		 		
		 		sh.BILLING_STATUS AS BILLING_STATUS,
		 		SH.CURRENT_STATUS AS CURRENT_STATUS,
		 		concat(sc.STATUS_CODES_CODE,'-',sc.STATUS_STATE) AS STATUS_STATE,
		 		CURRENT_DATE() AS CREATED_DATE,
		 		".(isset($_SESSION['EXT_USER_CODE']) ? $_SESSION['EXT_USER_CODE'] : 0)." AS CREATED_BY
		 		
				from shipments
				left join exp_shipment sh on sh.shipment_code = shipments.shipment_code
				LEFT JOIN EXP_LOAD L ON L.LOAD_CODE = shipments.load_code

		        LEFT JOIN EXP_OFFICE O ON SH.OFFICE_CODE = O.OFFICE_CODE
		        LEFT JOIN EXP_BUSINESS_CODE BC ON SH.BUSINESS_CODE = BC.BUSINESS_CODE
		        LEFT JOIN EXP_CLIENT_BILLING CB ON CB.SHIPMENT_ID = SH.SHIPMENT_CODE
		        LEFT JOIN EXP_USER U ON SH.SALES_PERSON = U.USER_CODE
		        LEFT JOIN EXP_STATUS_CODES SC ON SH.CURRENT_STATUS = SC.STATUS_CODES_CODE
		where (sh.TOTAL_CHARGES > 0 -- remove zero revenue shipments
			OR L.LOAD_REVENUE > 0
			OR L.LOAD_EXPENSE > 0)
		-- AND INSTR(sh.ss_number,'-')=0 -- extra stops
		-- and sh.BILLING_STATUS in (".$shipment_table->billing_behavior_state["approved"].", ".
		-- 	$shipment_table->billing_behavior_state["billed"].")
		and sh.CURRENT_STATUS != ".$shipment_table->behavior_state["cancel"]."
		
		" );

		//	CONVERT_RATE(sh.PICKUP_DATE, cb.currency, '".$currency."') exchange_rate1,   
		//	(select count(*) from exp_shipment s where s.load_code = l.load_code) as NUM_SHIPMENTS,

		return true;
	}

	// Given a load, update the margin report data
	public function add_margin_report_data_load( $load_code ) {
		$check = $this->database->get_multiple_rows("
			SELECT SHIPMENT_CODE FROM EXP_SHIPMENT
			WHERE LOAD_CODE = $load_code
		");
		
		if( is_array($check) && count($check) > 0 ) {
			foreach( $check as $row ) {
				$this->add_margin_report_data( $row['SHIPMENT_CODE'] );
			}
		}
	}

	//! This will update magin data for 1000 shipments
	public function update_all( $year = false, $month = false ) {
	//	echo __METHOD__.': entry<br>';
	//	ob_flush(); flush();
		$shipment_table = sts_shipment::getInstance($this->database, $this->debug);

		$filter = "AND NOT EXISTS (SELECT SHIPMENT_CODE FROM EXP_MARGIN_REPORT_DATA d
			WHERE sh.SHIPMENT_CODE = d.SHIPMENT_CODE)";
		
		if( $year != false ) {
			$filter = "AND YEAR(PICKUP_DATE) = ".$year;
		} else if( $month != false ) {
			$filter = "AND YEAR(PICKUP_DATE) = YEAR(NOW())
			AND MONTH(PICKUP_DATE) = ".$month;
		}

		$count = 0;
		$check = $this->database->get_multiple_rows("
			SELECT sh.SHIPMENT_CODE
			FROM EXP_SHIPMENT sh
			LEFT JOIN EXP_LOAD l
			ON l.LOAD_CODE = sh.load_code
			WHERE sh.CURRENT_STATUS != ".$shipment_table->behavior_state["cancel"]."
			and (sh.TOTAL_CHARGES > 0 -- remove zero revenue shipments
			OR L.LOAD_REVENUE > 0
			OR L.LOAD_EXPENSE > 0)
			AND PICKUP_DATE > DATE_SUB(NOW(), INTERVAL 5 YEAR)
			".$filter."
			LIMIT 50000
		");
		
		if( is_array($check) && count($check) > 0 ) {
			foreach( $check as $row ) {
				if( ! empty($row["SHIPMENT_CODE"]) ) {
				//	echo $row["SHIPMENT_CODE"].' ';
					$result = $this->add_margin_report_data( $row["SHIPMENT_CODE"] );
					if( $result ) $count++;
					echo ($count % 10 == 0 ? '+' : '.').($count % 100 == 0 ? ' '.$count.'<br>' : '').str_pad('',4096);
				//	sleep(1);
				} else {
					echo 'X '.str_pad('',4096);
				}
				ob_flush(); flush();
			}
		}
		
		return $count;
	}
	
	
}

?>