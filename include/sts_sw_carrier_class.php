<?php

// $Id: sts_sw_carrier_class.php 5593 2025-11-11 06:48:37Z dev $
// SCR# 1017 - Truckstop Integration Saferwatch API.

// no direct access
defined('_STS_INCLUDE') or die('Restricted access');

// Per email of 2025-02-18 use version 32
define('API_URL', 'https://www.saferwatch.com/webservices/CarrierService32.php');

require_once( "sts_table_class.php" );

require_once( "sts_setting_class.php" );
require_once( "sts_carrier_class.php" );
require_once( "sts_contact_info_class.php" );
require_once( "sts_item_list_class.php" );

class sts_cargo_type extends sts_table {
	private $setting_table;
	private $item_list_table;
	private $service_key;
	private $customer_key;
	private $have_credentials;
	private $editable;

	// Constructor does not need the table name
	public function __construct( $database, $debug = false ) {

		$this->debug = $debug;
		$this->database = $database;
		$this->primary_key = "CARGO_CODE";
		if( $this->debug ) echo "<p>Create sts_cargo</p>";
		$this->setting_table = sts_setting::getInstance( $this->database, $this->debug );
		$this->item_list_table = sts_item_list::getInstance($this->database, $this->debug);

		$this->service_key = $this->setting_table->get( 'api', 'SW_SERVICE_KEY' );
		$this->customer_key = $this->setting_table->get( 'api', 'SW_CUSTOMER_KEY' );
		$this->have_credentials = ( ! empty($this->service_key) && ! empty($this->customer_key) );
		$this->editable = $this->setting_table->get( 'option', 'SW_CARRIER_EDITABLE' ) == 'true';
		
		parent::__construct( $database, CARGO_TABLE, $debug);
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
    
 	public function enabled() {
		return $this->have_credentials;
	}

	public function editable() {
		return $this->editable;
	}
	
   	//! Create checkboxes for companies
	public function cargo_checkboxes( $form, $source_code = false, $source_type = 'carrier', $all = false ) {
		if( $this->debug ) echo "<p>".__METHOD__.": souce_code = $source_code</p>";
		
		if( $this->enabled() ) {
			$cargo_types = $this->item_list_table->get_items( 'Cargo type' );			
		
			if( is_array($cargo_types) && count( $cargo_types ) > 0 ) {
				$cargo_str = '<div id="CARGO_TYPES" class="panel panel-danger">
				  <div class="panel-heading">
				    <h3 class="panel-title">Cargo Types'.
				    ($all ? '&nbsp;&nbsp;&nbsp;<a class="btn btn-success btn-sm" id="ALL_CARGO"><span class="text-white"><span class="glyphicon glyphicon-ok"></span> ALL</span></a>' : '')
				    .'</h3>
				  </div>
				  <div class="panel-body">
				  <div class="form-group">
					
				';
				$column = 1;
				foreach( $cargo_types as $code => $name ) {
					$check = $source_code ?
						$this->fetch_rows("SOURCE_TYPE = '".$source_type."'
						AND SOURCE_CODE = ".$source_code." AND ITEM_CODE = ".$code) : false;
					if( $this->debug ) {
						echo "<pre>";
						var_dump($check);
						echo "</pre>";
					}
					$exists = is_array($check) && count($check) > 0;
					if( $this->debug ) echo "<p>".__METHOD__.": exists = ".($exists ? 'true' : 'false')."</p>";
					
					$cargo_str .= '<div class="col-sm-4">
					<div class="checkbox">
					    <label>
					      <input type="checkbox" class="cargo" name="CARGO_'.$code.'" id="CARGO_'.$code.'" value="'.$code.'"'.
					      ($exists ? ' checked' : '').
					      ($this->editable ? '' : ' disabled').'> '.$name.'
					    </label>
					    </div>
				     </div>
					    ';
				}
				$cargo_str .= '</div>
				</div>
				</div>
				<div id="CARGO_HELP" hidden><span class="help-block"><span class="glyphicon glyphicon-warning-sign"></span> Select at least one cargo type.</span></div>
				';		
			
				$form = str_replace('<!-- CARGO_TYPES -->', $cargo_str, $form);
			}
		}

		return $form;
	}
	
	//! Process checkboxes for cargo types
	public function process_cargo_checkboxes( $source_code = false, $source_type = 'carrier' ) {
		if( $this->debug ) echo "<p>".__METHOD__.": souce_code = $source_code</p>";

		$cargo_types = $this->item_list_table->get_items( 'Cargo type' );			
		
		if( is_array($cargo_types) && count( $cargo_types ) > 0 ) {
			foreach( $cargo_types as $code => $name ) {
				$check = $source_code ?
					$this->fetch_rows("SOURCE_TYPE = '".$source_type."'
					AND SOURCE_CODE = ".$source_code." AND ITEM_CODE = ".$code) : false;
				
				$exists = is_array($check) && count($check) > 0;
				if( $this->debug ) echo "<p>".__METHOD__.": exists = ".($exists ? 'true' : 'false')."</p>";
				
				if( is_array($_POST) &&
					isset($_POST['CARGO_'.$code])) {
					
					if( ! $exists )
						$this->add([
							'SOURCE_TYPE' => $source_type,
							'SOURCE_CODE' => $source_code,
							'ITEM_CODE' => $code
						]);
				} else {
					if( $exists )
						$this->delete_row("SOURCE_TYPE = '".$source_type."'
							AND SOURCE_CODE = ".$source_code." AND ITEM_CODE = ".$code);
				}
			}
		}
	}

}


// https://www.saferwatch.com/helpdocs/WebServices/Integration.php?version=32
// https://truckstop.readme.io/reference/truckstop-logos#/

class sts_sw_carrier {

	private $debug = false;
	private $database;
	private $setting_table;
	private $carrier_table;
	private $contact_info_table;
	private $item_list_table;
	private $cargo_table;
	
	private $service_key;
	private $customer_key;
	private $have_credentials;
	private $editable;
	private $error_msg;
	private $parsedResponse;
	private $updateDB  = false;
	private $addDB  = false;
	private $original = false;

	private $fmcsa_key;
	private $fmcsa_url = 'https://mobile.fmcsa.dot.gov/qc/services/carriers/name/';
	
	private $log_file;
	private $debug_diag_level; //! SCR 531 - debug log level
	private $diag_level;
	const NEVER_UPDATE = ['CURRENCY_CODE', 'EMAIL_NOTIFY'];
	
	public function __construct( $database, $debug = false ) {
		global $sts_crm_dir, $sts_error_level_label;
		$this->debug = $debug;
		$this->database = $database;
		$this->setting_table = sts_setting::getInstance( $this->database, $this->debug );
		$this->carrier_table = sts_carrier::getInstance( $this->database, $this->debug );
		$this->contact_info_table = sts_contact_info::getInstance($this->database, $this->debug);
		$this->item_list_table = sts_item_list::getInstance($this->database, $this->debug);
		$this->cargo_table = sts_cargo_type::getInstance($this->database, $this->debug);
		
		$this->log_file = $this->setting_table->get( 'option', 'SW_LOG_FILE' );
		if( ctype_alpha($this->log_file[0]) && ctype_alpha($this->log_file[1]) )
			$this->log_file = $sts_crm_dir.DIRECTORY_SEPARATOR.$this->log_file;
		
		$this->debug_diag_level = $this->setting_table->get( 'option', 'SW_DIAG_LEVEL' );
		$this->diag_level =  array_search(strtolower($this->debug_diag_level), $sts_error_level_label);
		if( $this->diag_level === false ) $this->diag_level = EXT_ERROR_ALL;

		$this->service_key = $this->setting_table->get( 'api', 'SW_SERVICE_KEY' );
		$this->customer_key = $this->setting_table->get( 'api', 'SW_CUSTOMER_KEY' );
		$this->fmcsa_key = $this->setting_table->get( 'api', 'FMCSA_API_KEY' );
		$this->have_credentials = ( ! empty($this->service_key) && ! empty($this->customer_key) );

		$this->editable = $this->setting_table->get( 'option', 'SW_CARRIER_EDITABLE' ) == 'true';
	}

	// Allow re-use of objects - singleton function
	public static function getInstance( $database, $debug = false ) {
		static $instance = null;
		$myclass = get_class ();
		if( $debug ) echo "<h2>Get instance of $myclass</h2>";
		if (null === $instance) {
			$instance = new $myclass( $database, $debug );
		}
		return $instance;
    }
    
	public function log_event( $event, $level = EXT_ERROR_ERROR ) {		
		if( $this->diag_level >= $level ) {
			if( isset($this->log_file) && $this->log_file <> '' &&
				 ! is_dir($this->log_file) ) {
				file_put_contents($this->log_file, date('m/d/Y h:i:s A')." ".$event.PHP_EOL,
					(file_exists($this->log_file) ? FILE_APPEND : 0) );
			}
		}
	}
	
	public function enabled() {
		return $this->have_credentials;
	}

	public function editable() {
		return $this->editable;
	}
	
	public function addDB() {
		$this->addDB = true;
	}
	
	private function space_encode( $string ) {
		return str_replace(' ', '%20', $string);
	}
	
	//! Call FMCSA API to look up carrier name
	// https://mobile.fmcsa.dot.gov/QCDevsite/home
	public function fmcsa_lookup_carrier( $string ) {
		$result = false;
		
		$this->log_event( __METHOD__.": entry $string", EXT_ERROR_DEBUG );
		$url = $this->fmcsa_url.$this->space_encode($string).'?webKey='.$this->fmcsa_key;
		if( $this->debug ) {
			echo "<pre>".__METHOD__.": URL\n";
			var_dump($url);
			echo "</pre>";
		}

		$response = file_get_contents($url);
		if( $this->debug ) {
			echo "<pre>".__METHOD__.": response\n";
			var_dump($response);
			echo "</pre>";
		}

		if( !empty($response) ) {
			$info = json_decode($response, true);
			
			if( is_array($info["content"]) && count($info["content"]) > 0 ) {
				$result = [];
				foreach($info["content"] as $row) {
					if( is_array($row) && is_array($row["carrier"]) ) {
						$carrier = $row["carrier"];
						
						$result[] = [
							"legalName" => $carrier["legalName"],
							"dbaName" => $carrier["dbaName"],
							"dotNumber" => $carrier["dotNumber"],
							"phyCity" => $carrier["phyCity"],
							"phyState" => $carrier["phyState"]
						];
					}
				}
			}
		}
		
		if( $this->debug ) {
			echo "<pre>".__METHOD__.": RESULT\n";
			var_dump($result);
			echo "</pre>";
		}

		return $result;
	}
	
	private function extractField($xmlField) {
	    return isset($xmlField) && !empty((string) $xmlField) ? (string) $xmlField : '';
	}
	
	// Given a carrier code, return a name.
	private function carrier_name( $code ) {
		$name = false;
		if( $code > 0 ) {
			$check = $this->carrier_table->fetch_rows( "CARRIER_CODE = $code
				AND ISDELETED = false",
				"CARRIER_NAME" );
			
			if( is_array($check) && count($check) == 1 &&
				!empty($check[0]['CARRIER_NAME']) ) {
				$name = $check[0]['CARRIER_NAME'];
			}
		}
		
		return $name;
	}

	public function lookup_carrier( $dot_num, $mc_num ) {
	    if( $this->debug ) echo "<p>".__METHOD__.": entry: $dot_num, $mc_num</p>";
		$this->log_event( __METHOD__.": entry $dot_num, $mc_num", EXT_ERROR_DEBUG );
		
		$code = $name = $legal = false;
		if( !empty($dot_num) || !empty($mc_num) ) {
			if( !empty($dot_num) && !empty($mc_num) )
				$search = "(DOT_NUM = '".$dot_num."'
					OR MC_NUM = '".$mc_num."' 
					OR CONCAT('MC', MC_NUM) = '".$mc_num."')";
			else if( !empty($dot_num) && empty($mc_num) )
				$search = "DOT_NUM = '".$dot_num."'";
			else if( empty($dot_num) && !empty($mc_num) )
				$search = "(MC_NUM = '".$mc_num."'
					OR CONCAT('MC', MC_NUM) = '".$mc_num."')";
			
			$check = $this->carrier_table->fetch_rows( "$search
				AND ISDELETED = false",
				"CARRIER_CODE, DOT_NUM, MC_NUM, CARRIER_NAME, LEGAL_NAME" );
				
			if( is_array($check) and count($check) > 0 ) {
				if( count($check) > 1 ) {
					$this->log_event( __METHOD__.": ERROR: matched multiple carriers!", EXT_ERROR_ERROR );
					foreach( $check as $row ) {
						$this->log_event( __METHOD__.":   code = ".$row['CARRIER_CODE'].
							", DOT_NUM = ".$row['DOT_NUM'].
							", MC_NUM = ".$row['MC_NUM'].
							", CARRIER_NAME = ".$row['CARRIER_NAME'].
							", LEGAL_NAME = ".$row['LEGAL_NAME'] );
					}
					$name = $check;

				} else {
					if( !empty($check[0]['CARRIER_CODE']) )
						$code = $check[0]['CARRIER_CODE'];
					if( !empty($check[0]['CARRIER_NAME']) )
						$name = $check[0]['CARRIER_NAME'];
					if( !empty($check[0]['LEGAL_NAME']) )
						$legal = $check[0]['LEGAL_NAME'];
				}
			}
		}
		
		$result_str = ($code == false ? 'false' : $code).', '.
			($name == false ? 'false' : (is_array($name) ? '[array]' : $name)).', '.
			($legal == false ? 'false' : $legal);
	    if( $this->debug ) echo "<p>".__METHOD__.": return: $result_str</p>";
		$this->log_event( __METHOD__.": return $result_str", EXT_ERROR_DEBUG );
		return [$code, $name, $legal];
	}	
		
	//! create a carrier with all the CarrierDetails
	// May need to update this for future revisions of the API
	private function importCarrier( $details ) {
		global $validDate2;
		
		$dot_num = $this->extractField($details->dotNumber);
		$dot_num_status = $this->extractField($details->dotNumber['status']);
		$mc_num = $this->extractField($details->docketNumber);
		
		$carrier_fields = [
			'CARRIER_TYPE' => 'carrier',
		];
		
		$contact_info = [
			'CONTACT_SOURCE' => 'carrier',
			'CONTACT_TYPE' => 'company',
		];
		
		if( ! empty($dot_num) ) $carrier_fields['DOT_NUM'] = $dot_num;
		if( ! empty($dot_num_status) ) $carrier_fields['SW_DOT_NUM_STATUS'] = $dot_num_status;
		if( ! empty($mc_num) ) $carrier_fields['MC_NUM'] = $mc_num;
		
		if( is_object($details->DocketData)) {
			$id = $details->Identity;
			$dbaName = $this->extractField($id->dbaName);
			if( ! empty($dbaName) )
				$carrier_fields['CARRIER_NAME'] = $contact_info['LABEL'] = $dbaName;
			$legalName = $this->extractField($id->legalName);
			if( ! empty($legalName) ) {
				if( empty($dbaName) )
					$carrier_fields['CARRIER_NAME'] = $contact_info['LABEL'] = $legalName;
				else
					$carrier_fields['LEGAL_NAME'] = $legalName;
			}
			
			$street = $this->extractField($id->businessStreet);
			if( ! empty($street) ) $contact_info['ADDRESS'] = $street;
			$city = $this->extractField($id->businessCity);
			if( ! empty($city) ) $contact_info['CITY'] = $city;
			$state = $this->extractField($id->businessState);
			if( ! empty($state) ) $contact_info['STATE'] = $state;
			$zip = $this->extractField($id->businessZipCode);
			if( ! empty($zip) ) $contact_info['ZIP_CODE'] = $zip;
			$country = $this->extractField($id->businessCountry);
			if( ! empty($country) ) $contact_info['COUNTRY'] = ($country == 'US' ? 'USA' : ($country == 'CA' ? 'Canada' : $country));
			if( in_array($country, ['US', 'USA']) ) {
				$carrier_fields['CURRENCY_CODE'] = $carrier_fields['INS_CURRENCY_CODE'] = 'USD';
			} else {
				$carrier_fields['CURRENCY_CODE'] = $carrier_fields['INS_CURRENCY_CODE'] = 'CAD';
			}
			
			$phone = $this->extractField($id->businessPhone);
			if( ! empty($phone) ) $contact_info['PHONE_OFFICE'] = $phone;
			$fax = $this->extractField($id->businessFax);
			if( ! empty($fax) ) $contact_info['PHONE_FAX'] = $fax;

			$email = $this->extractField($id->emailAddress);
			if( ! empty($email) ) $contact_info['EMAIL'] = $carrier_fields['EMAIL_NOTIFY'] = $email;

			$duns = $this->extractField($id->dunBradstreetNum);
			if( ! empty($duns) ) $carrier_fields['DUNS_CODE'] = $duns;
		}
					
		if( is_object($details->Safety) ) {
			$safety = $details->Safety;
			
			$rating = $this->extractField($safety->rating);
			if( ! empty($rating) ) $carrier_fields['SW_SAFETY_RATING'] = $rating;
			$ratingDate = $this->extractField($safety->ratingDate);
			if( ! empty($ratingDate) && $validDate2($ratingDate) ) $carrier_fields['SW_SAFETY_RATING_DATE'] = date("Y-m-d", strtotime($ratingDate));

			$unsafeDrvPCT = $this->extractField($safety->unsafeDrvPCT);
			if( ! empty($unsafeDrvPCT) )
				$carrier_fields['SW_UNSAFEDRVPCT'] = $unsafeDrvPCT;
			$hosPCT = $this->extractField($safety->hosPCT);
			if( ! empty($hosPCT) )
				$carrier_fields['SW_HOSPCT'] = $hosPCT;
			$drvFitPCT = $this->extractField($safety->drvFitPCT);
			if( ! empty($drvFitPCT) )
				$carrier_fields['SW_FITPCT'] = $drvFitPCT;
			$controlSubPCT = $this->extractField($safety->controlSubPCT);
			if( ! empty($controlSubPCT) )
				$carrier_fields['SW_CONTROLSUBPCT'] = $controlSubPCT;
			$vehMaintPCT = $this->extractField($safety->vehMaintPCT);
			if( ! empty($vehMaintPCT) )
				$carrier_fields['SW_MAINTPCT'] = $vehMaintPCT;

		}
		
		if( is_object($details->Review) ) {
			$review = $details->Review;

			$reviewType = $this->extractField($review->reviewType);
			if( ! empty($reviewType) ) $carrier_fields['SW_SAFETY_REVIEW_TYPE'] = $reviewType;
			$reviewDate = $this->extractField($review->reviewDate);
			if( ! empty($reviewDate) && $validDate2($reviewDate) ) $carrier_fields['SW_SAFETY_REVIEW_DATE'] = date("Y-m-d", strtotime($reviewDate));
			$reviewDocNum = $this->extractField($review->reviewDocNum);
			if( ! empty($reviewDocNum) ) $carrier_fields['SW_SAFETY_REVIEW_DOC'] = $reviewDocNum;
			$reviewMiles = $this->extractField($review->reviewMiles);
			if( ! empty($reviewMiles) ) $carrier_fields['SW_SAFETY_REVIEW_MILES'] = $reviewMiles;
		}
			
		if( is_object($details->Crash) ) {
			$crash = $details->Crash;
		
			$crashFatalUS = $this->extractField($crash->crashFatalUS);
			if( ! empty($crashFatalUS) )
				$carrier_fields['SW_CRASHFATALUS'] = $crashFatalUS;
			$crashInjuryUS = $this->extractField($crash->crashInjuryUS);
			if( ! empty($crashInjuryUS) )
				$carrier_fields['SW_CRASHINJURYUS'] = $crashInjuryUS;
			$crashTowUS = $this->extractField($crash->crashTowUS);
			if( ! empty($crashTowUS) )
				$carrier_fields['SW_CRASHTOWUS'] = $crashTowUS;
			$crashTotalUS = $this->extractField($crash->crashTotalUS);
			if( ! empty($crashTotalUS) )
				$carrier_fields['SW_CRASHTOTALUS'] = $crashTotalUS;
			
			$crashFatalCAN = $this->extractField($crash->crashFatalCAN);
			if( ! empty($crashFatalCAN) )
				$carrier_fields['SW_CRASHFATALCAN'] = $crashFatalCAN;
			$crashInjuryCAN = $this->extractField($crash->crashInjuryCAN);
			if( ! empty($crashInjuryCAN) )
				$carrier_fields['SW_CRASHINJURYCAN'] = $crashInjuryCAN;
			$crashTowCAN = $this->extractField($crash->crashTowCAN);
			if( ! empty($crashTowCAN) )
				$carrier_fields['SW_CRASHTOWCAN'] = $crashTowCAN;
			$crashTotalCAN = $this->extractField($crash->crashTotalCAN);
			if( ! empty($crashTotalCAN) )
				$carrier_fields['SW_CRASHTOTALCAN'] = $crashTotalCAN;
		}

		if( is_object($details->Equipment) ) {
			$eq = $details->Equipment;

			$powerUnitsTotal = $this->extractField($eq->powerUnitsTotal);
			if( ! empty($powerUnitsTotal) ) $carrier_fields['NUM_TRUCKS'] = $powerUnitsTotal;
		}
		
		if( is_object($details->Cargo) ) {
			$cargo = $details->Cargo;
		//	echo "<pre>XXCARGO\n";
		//	var_dump($details->Cargo);
		//	echo "</pre>";
			
			$hazmatIndicator = $this->extractField($cargo->hazmatIndicator);
			if( ! empty($hazmatIndicator) ) $carrier_fields['DANG_GOODS_ALLOWED'] = (strtoupper($powerUnitsTotal) == 'YES' ? 1 : 0);

			$cargo_types = [];
			foreach((array) $cargo as $key => $value ) {
				if( substr($key, 0, 5) == 'cargo' && strtoupper($value) == 'YES') {
					$cargo_types[] = substr($key, 5);
				}
			}
		}

		//! Insurance details
		if( is_object($details->CertData)) {
			$certData = $details->CertData;
		
			if( is_object($certData->Certificate)) {
				$certificate = $certData->Certificate;
			
				if( is_object($certificate->Coverage) && count((array) $certData->Certificate->Coverage) > 0 ) {
					$size = count($certificate->Coverage);
					for( $c=0; $c < $size; $c++ ) {
						$coverage = $certificate->Coverage[$c];
						$type = $this->extractField($coverage->type);
						$name = $this->extractField($coverage->insurerName);
						$policy = $this->extractField($coverage->policyNumber);
						$expires = date("Y-m-d", strtotime($this->extractField($coverage->expirationDate)));
						$limit = str_replace(',', '', $this->extractField($coverage->coverageLimit));
						
						switch( strtoupper($type) ) {
							case 'GENERAL':
								if( ! empty($name) )
									$carrier_fields['SW_GENERAL_INS_COMP'] = $name;
								if( ! empty($policy) )
									$carrier_fields['SW_GENERAL_POLICY'] = $policy;
								if( ! empty($expires) )
									$carrier_fields['LIABILITY_DATE'] = $expires;
								if( ! empty($limit) )
									$carrier_fields['GENERAL_LIAB_INS'] = $limit;
								break;

							case 'AUTO':
								if( ! empty($name) )
									$carrier_fields['SW_AUTO_INS_COMP'] = $name;
								if( ! empty($policy) )
									$carrier_fields['SW_AUTO_POLICY'] = $policy;
								if( ! empty($expires) )
									$carrier_fields['AUTO_LIAB_DATE'] = $expires;
								if( ! empty($limit) )
									$carrier_fields['AUTO_LIAB_INS'] = $limit;
								break;

							case 'CARGO':
								if( ! empty($name) )
									$carrier_fields['SW_CARGO_INS_COMP'] = $name;
								if( ! empty($policy) )
									$carrier_fields['SW_CARGO_POLICY'] = $policy;
								if( ! empty($expires) )
									$carrier_fields['CARGO_LIAB_DATE'] = $expires;
								if( ! empty($limit) )
									$carrier_fields['CARGO_LIAB_INS'] = $limit;
								break;
							default:
								break;
						}
					
					}
				}
			}
		}
		
		//! RiskAssessment
		if( is_object($details->RiskAssessment) ) {
			$risk = $details->RiskAssessment;
			
			$overall = $this->extractField($risk->Overall);
			if( ! empty($overall) ) $carrier_fields['SW_RISK_OVERALL'] = $overall;
			$auth = $this->extractField($risk->Authority);
			if( ! empty($auth) ) $carrier_fields['SW_RISK_AUTHORITY'] = $auth;
			$ins = $this->extractField($risk->Insurance);
			if( ! empty($ins) ) $carrier_fields['SW_RISK_INS'] = $ins;
			$safety = $this->extractField($risk->Safety);
			if( ! empty($safety) ) $carrier_fields['SW_RISK_SAFETY'] = $safety;
			$operation = $this->extractField($risk->Operation);
			if( ! empty($operation) ) $carrier_fields['SW_RISK_OPER'] = $operation;
			$other = $this->extractField($risk->Other);
			if( ! empty($other) ) $carrier_fields['SW_RISK_OTHER'] = $other;
		}

					
		if( $this->debug ) {
			echo "<pre>".__METHOD__.": RESULT\n";
			var_dump($carrier_fields);
			var_dump($contact_info, $cargo_types);
			echo "</pre>";
		}

		return [$carrier_fields, $contact_info, $cargo_types];
	}
	
	private function update_cargo_types( $carrier, $cargo ) {
		if( $this->debug ) {
			echo "<pre>".__METHOD__."\n";
			var_dump($carrier, $cargo);
			echo "</pre>";
		}
		
		// Update list of cargo types
		$codes = [];
		foreach( $cargo as $cargo_type ) {
			$existing = $this->item_list_table->get_item_code( 'Cargo type', $cargo_type );
			
			if( $existing == false ) {
			    if( $this->debug ) echo "<h3>".__METHOD__.": ADD Cargo type = $cargo_type</h3>";
				$code = $this->item_list_table->add([
					'ITEM_TYPE' => 'Cargo type',
					'ITEM' => $cargo_type
				]);
				
				$codes[] = $code;
			} else {
				$codes[] = $existing;
			}
		}
		
		// Remove all cargo types for this carrier
		$this->cargo_table->delete_row("SOURCE_TYPE = 'carrier' AND SOURCE_CODE = $carrier" );
		
		foreach( $codes as $cargo_code ) {
			$this->cargo_table->add([
				'SOURCE_TYPE' => 'carrier',
				'SOURCE_CODE' => $carrier,
				'ITEM_CODE' => $cargo_code
			]);
		}
		
	}
	
	private function add_carrier( $details ) {
		list($carrier_fields, $contact_info, $cargo_types) = $this->importCarrier( $details );
		
		$code = $this->carrier_table->add($carrier_fields);
		
		if( $code > 0 ) {
			if( $this->debug ) echo "<h3>".__METHOD__.": ADD CARRIER $code</h3>";
			
			$contact_info['CONTACT_CODE'] = $code;
			$this->contact_info_table->add($contact_info);
			
			$this->update_cargo_types( $code, $cargo_types );
		} else {
		    if( $this->debug ) echo "<h3>".__METHOD__.":ADD CARRIER FAILED</h3>";
			$this->log_event( __METHOD__.": ADD CARRIER FAILED", EXT_ERROR_DEBUG );
		}
		echo '<h3>Added carrier <a href="exp_editcarrier.php?CODE='.$code.'">'.$carrier_fields['CARRIER_NAME'].'</a></h3>';
		
		return $code;		
	}

	private function update_carrier( $code, $details ) {
	    if( $this->debug ) echo "<h3>".__METHOD__.": entry, $code</h3>";
		$this->log_event( __METHOD__.": entry, $code", EXT_ERROR_DEBUG );

		list($carrier_fields, $contact_info, $cargo_types) = $this->importCarrier( $details );
		
		$db_fields = $this->carrier_table->fetch_rows( "CARRIER_CODE = ".$code );
		
		$new_fields = [];
		foreach( $carrier_fields as $field => $value ) {
		//	echo "<pre>";
		//	var_dump($field, $value, $db_fields[0][$field]);
		//	var_dump(isset($db_fields[0][$field]), isset($value));
		//	echo "</pre>";
			
			if( is_array($db_fields) && is_array($db_fields[0]) &&
				(! isset($db_fields[0][$field]) && isset($value) ||
				isset($db_fields[0][$field]) && $db_fields[0][$field] != $value) &&
				! in_array($field, self::NEVER_UPDATE) ) {
				$new_fields[$field] = $value;
			}
		}
		
	    if( $this->debug ) {
			echo "<pre>".__METHOD__.": ZZZ_NEW\n";
			var_dump($new_fields);
			echo "</pre>";
		}
		$this->log_event( __METHOD__.": updated fields\n".print_r($new_fields, true), EXT_ERROR_DEBUG );
		
		if( count($new_fields) > 0 ) {
			$this->carrier_table->update( $code, $new_fields );
		} else {
			$this->log_event( __METHOD__.": ".$carrier_fields['CARRIER_NAME']." is already up to date.", EXT_ERROR_DEBUG );
			echo '<h3>'.$carrier_fields['CARRIER_NAME'].' is already up to date.</h3>';
		}
		
		if( $this->debug ) echo "<h3>".__METHOD__.": UPDATE CARRIER $code</h3>";
		
		$this->log_event( __METHOD__.": contact info fields\n".print_r($contact_info, true), EXT_ERROR_DEBUG );

		// Search for contact info, and delete existing before re-adding
		$contact_info['CONTACT_CODE'] = $code;
		$check = $this->contact_info_table->fetch_rows( "CONTACT_CODE = $code
			AND CONTACT_SOURCE = 'carrier'
			AND CONTACT_TYPE = 'company'
			AND LABEL = '".$contact_info['LABEL']."'" );
		
		if( is_array($check) && count($check) > 0 ) {
			$this->contact_info_table->delete_row( "CONTACT_CODE = $code
			AND CONTACT_SOURCE = 'carrier'
			AND CONTACT_TYPE = 'company'
			AND LABEL = '".$contact_info['LABEL']."'" );
		}
		
		$this->contact_info_table->add($contact_info);
		
		$this->update_cargo_types( $code, $cargo_types );
		
		echo '<h3>Updated carrier <a href="exp_editcarrier.php?CODE='.$code.'">'.$carrier_fields['CARRIER_NAME'].'</a></h3>';
	}
	
	//! Given a valid XML in $this->parsedResponse do something with it.
	private function processValidResponse($original = false) {
		$result = false;
		
		if( is_object($this->parsedResponse->CarrierDetails) ) {
			$details = $this->parsedResponse->CarrierDetails;
			
			$mc_num = $dot_num = false;
			if( is_object($details->docketNumber) )
				$mc_num = $this->extractField($details->docketNumber);

			if( is_object($details->dotNumber) )
				$dot_num = $this->extractField($details->dotNumber);
				
			if( ! empty($dot_num) || ! empty($dot_num) ) {
				$legalName = $dbaName = '';
				if( is_object($details->Identity) ) {
					$id = $details->Identity;
					$legalName = $this->extractField($id->legalName);
					$dbaName = $this->extractField($id->dbaName);
				}
				
				list($code, $name, $legal_name) = $this->lookup_carrier( $dot_num, $mc_num );
				
				$orig_name = $this->carrier_name($original);
								
				// Remove the dot after INC for matching purposes
				if( !empty($legalName) &&
					substr(strtoupper($legalName), -4) == 'INC.' &&
					!empty($name) &&
					substr(strtoupper($name), -3) == 'INC')
					$legalName = substr($legalName, 0, -1);
				
				if( $code > 0 && 
					(strcasecmp( $legalName, $name) == 0 ||
					strcasecmp( $legalName, $legal_name) == 0 ||
					strcasecmp( $dbaName, $name) == 0 ||
					strcasecmp( $dbaName, $legal_name) == 0 ) ) {
						
					if( $original && $original != $code ) {
					    if( $this->debug ) echo "<h3>".__METHOD__.": found (DIFFERENT) matching carrier $code</h3>";
						$this->log_event( __METHOD__.": found (DIFFERENT) matching carrier $code", EXT_ERROR_DEBUG );
						echo '<h3>Matched (DIFFERENT) carrier '.$code.' in Exspeedite.</h3>';
						
					} else {
					    if( $this->debug ) echo "<h3>".__METHOD__.": found matching carrier $code</h3>";
						$this->log_event( __METHOD__.": found matching carrier $code", EXT_ERROR_DEBUG );
						echo '<h3>Matched carrier '.$code.'</h3>';
						
						//! I can use CarrierDetails to update carrier record here.
						if( $this->updateDB )
							$this->update_carrier( $code, $details );
					}
				} else if( $code > 0 ) {
				    if( $this->debug ) echo "<h3>".__METHOD__.": found matching carrier $code but name mismatch ($name != $legalName)</h3>";
					$this->log_event( __METHOD__.": found matching carrier $code but name mismatch ($name != $legalName)", EXT_ERROR_DEBUG );
					echo '<h3>Matched carrier '.$code.', but the names don\'t match</h3>
					<h3>'.$name.' != '.$legalName.'</h3>
					<h3>Please check the name and DOT# and try again.</h3>';
					
				} else {
				    if( $this->debug ) echo "<h3>".__METHOD__.": NOT found matching carrier for DOT# $dot_num</h3>";
					$this->log_event( __METHOD__.": SaferWatch returned carrier info for DOT# $dot_num", EXT_ERROR_DEBUG );
					
					if( is_array($name) && count($name) > 1 ) {
						echo '<h3>Error: Multiple Matches in Exspeedite</h3>
						<h5 class="text-info"><span class="glyphicon glyphicon-warning-sign"></span> You have multiple carriers with the same DOT# or MC#. This should not happen. Please use the links below to correct the issue and try again</h5>';
						foreach( $name as $row ) {
							echo '<h4>'.($row['CARRIER_CODE'] == $this->original ? '* ' : '').
							'<a href="exp_editcarrier.php?CODE='.$row['CARRIER_CODE'].'" target="_blank">'.$row['CARRIER_NAME'].'</a> (DOT# '.$row['DOT_NUM'].
							' MC# '.$row['MC_NUM'].
							')</h4>
							';
						}
					} else if( $this->addDB )
						$result = $this->add_carrier( $details );
					else
						echo '<h3>SaferWatch returned carrier info for DOT# '.$dot_num.'</h3>
						<h3>DOT# matches carrier '.$legalName.
						(!empty($dbaName) ? " (Dba: $dbaName)" : '').
						' which isn\'t in Exspeedite.</h3>
						
						<h3>Please check the name and DOT# and try again.</h3>';

					//! I can use CarrierDetails to build a carrier record here.
				//	if( $this->updateDB )
				//		$this->add_carrier( $details );
				}
				
			} else {
				if( $this->debug ) echo "<h2>".__METHOD__.": ERROR: missing DOT# and MC#</h2>";
				$this->log_event( __METHOD__.": ERROR: missing DOT# and MC#", EXT_ERROR_ERROR );
				echo '<h3>missing DOT# and MC# '.$dot_num.'</h3>
				<h3>Please check the name and DOT# and MC# and try again.</h3>';
			}
		} else {
			if( $this->debug ) echo "<h2>".__METHOD__.": ERROR: missing CarrierDetails</h2>";
			$this->log_event( __METHOD__.": ERROR: missing CarrierDetails", EXT_ERROR_ERROR );
		}
		
		return $result;
	}

	//! Need at least one of $docketNumber or $dotNumber
	public function saferwatchCarrierLookup($docketNumber, $dotNumber,
		$updateDB = true, $original = false) {

	    if( $this->debug ) echo "<p>".__METHOD__.": entry: $docketNumber, $dotNumber</p>";
		$this->log_event( __METHOD__.": entry $docketNumber, $dotNumber", EXT_ERROR_DEBUG );

	    $this->parsedResponse = false;
	    $this->updateDB = $updateDB;
		
		if( $this->enabled() ) {
		    $params = [
		        "Action"        => "CarrierLookup",
		        "ServiceKey"    => $this->service_key,
		        "CustomerKey"   => $this->customer_key,
		        "certdata"      => "YES",
		        "concerns"      => "YES"
		    ];
		    
		    if( ! empty($docketNumber) ) $params["docketNumber"] = $docketNumber;
		    if( ! empty($dotNumber) ) $params["dotNumber"] = $dotNumber;
		    
		    echo '<h3>Match'.
		    	(! empty($dotNumber) ? ' DOT# = '.$dotNumber.(! empty($docketNumber) ? ' AND' : '') : '').
		    	
		    	(! empty($docketNumber) ? ' MC# = '.$docketNumber : '').'</h3>';
		
		    $response = $this->fetchApiResponse($params);
		    
		    if( $response )
			    $this->parsedResponse = $this->parseXMLResponse($response);
		    
		    if( $this->debug ) {
				echo "<p>".__METHOD__.": parsedResponse</p><pre>";
				var_dump($this->parsedResponse);
				echo "</pre>";
		    }
		    if( is_object($this->parsedResponse) && is_object($this->parsedResponse->ResponseDO) ) {
		    	$status = $this->extractField($this->parsedResponse->ResponseDO->status);
		    	$action = $this->extractField($this->parsedResponse->ResponseDO->action);
		    	$code = $this->extractField($this->parsedResponse->ResponseDO->code);
		    	$msg = $this->extractField($this->parsedResponse->ResponseDO->displayMsg);
		    	
		    	if( $action == 'FAILED' ) {
				    if( $this->debug ) echo "<h2>".__METHOD__.": ERROR: $action code $code msg $msg</h2>";
					$this->log_event( __METHOD__.": ERROR: $action code $code msg $msg", EXT_ERROR_ERROR );
					echo '<h3>Failed: '.$msg.'</h3>
					<h3>Please check your DOT# and/or MC#</h3>';
		    	} else {
				    if( $this->debug ) echo "<h2>".__METHOD__.": OK: $status $action code $code</h2>";
					$this->log_event( __METHOD__.": OK: $status $action code $code", EXT_ERROR_DEBUG );
					return $this->processValidResponse($original);
		    	}
		    } else {
				if( $this->debug ) echo "<h2>".__METHOD__.": ERROR: unable to parse response</h2>";
				$this->log_event( __METHOD__.": ERROR: unable to parse response", EXT_ERROR_ERROR );
				echo '<h3>ERROR: unable to parse response from SaferWatch</h3>
				<h3>Please check your DOT# and/or MC#</h3>';
		    }
		    
	    } else {
			if( $this->debug ) echo "<h2>".__METHOD__.": ERROR: not enabled, missing key(s)</h2>";
				$this->log_event( __METHOD__.": ERROR: not enabled, missing key(s)", EXT_ERROR_ERROR );
	    }

	    return $this->parsedResponse;
	}
	
	private function fetchApiResponse($params) {
	    if( $this->debug ) {
			echo "<p>".__METHOD__.": params</p><pre>";
			var_dump($params);
			echo "</pre>";
	    }
		$this->log_event( __METHOD__.": params".
			print_r($params, true), EXT_ERROR_DEBUG );

	    $this->error_msg = '';
	    
	    $url = API_URL . '?' . http_build_query($params);
	    
	    $ch = curl_init();
	    curl_setopt($ch, CURLOPT_URL, $url);
	    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
	    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");
	    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
	    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
	    
	    $response = curl_exec($ch);
		if ($errno = curl_errno($ch)) {
			$this->error_msg = curl_error($ch);
			if( $this->debug ) echo "<p>".__METHOD__.": error_msg = ".$this->error_msg."</p>";
			$this->log_event( __METHOD__.": error $errno", EXT_ERROR_DEBUG );
		}
	    curl_close($ch);
	    
	    if( $this->debug ) {
			echo "<p>".__METHOD__.": response</p><pre>";
			var_dump($response);
			echo "</pre>";
	    }
	    
	    return $response;
	}
	
	private function parseXMLResponse($xmlString) {
	    if( $this->debug ) echo "<p>".__METHOD__.": entry</p>";
	    
	    libxml_use_internal_errors(true);
	    $xml = simplexml_load_string($xmlString, "SimpleXMLElement", LIBXML_NOCDATA);

	    if( $this->debug ) {
			echo "<p>".__METHOD__.": xml</p><pre>";
			var_dump($xml);
			echo "</pre>";
	    }
		$this->log_event( __METHOD__.": XML".
			print_r($xml, true), EXT_ERROR_DEBUG );
	    
	    return $xml;
	}
	
	public function sw_update_carrier( $code ) {
	    if( $this->debug ) echo "<p>".__METHOD__.": entry: $code</p>";
		$this->log_event( __METHOD__.": entry $code", EXT_ERROR_DEBUG );
		
		$this->original = $code;
	    
		if( $this->enabled() ) {
			$check = $this->carrier_table->fetch_rows( "CARRIER_CODE = $code AND ISDELETED = false
				AND (DOT_NUM IS NOT NULL OR MC_NUM IS NOT NULL)",
				"DOT_NUM, MC_NUM" );
			
			$dotNumber = $docketNumber = false;
			if( is_array($check) && count($check) == 1 ) {
				if( ! empty($check[0]['DOT_NUM']) )
					$dotNumber = $check[0]['DOT_NUM'];
				
				if( ! empty($check[0]['MC_NUM']) ) {
					$docketNumber = $check[0]['MC_NUM'];
					
					if( strncmp($docketNumber, 'MC', 2) != 0 )
						$docketNumber = 'MC'.$docketNumber;
				}
				
				$data = $this->saferwatchCarrierLookup($docketNumber, $dotNumber);
				
			} else {
				echo '<h3>You need either a DOT# or an MC# to use this.</h3>';
			}
	    } else {
			if( $this->debug ) echo "<h2>".__METHOD__.": ERROR - not enabled, missing key(s)</h2>";
	    }
	}
}

?>