<?php

// $Id: sts_card_imperial_class.php 5494 2025-03-19 19:34:00Z dev $
// Imperial class - everything to do with decoding and importing

// no direct access
defined('_STS_INCLUDE') or die('Restricted access');
require_once( "sts_card_class.php" );

class sts_card_imperial extends sts_card {
	private $tokens;
	private $conv_gallon = 0.264172;
	private $country;
	private $trans_date;


	public $product_codes = array(
		'0' => 'Additives',
		'1' => 'Tire Repair',
		'2' => 'Emergency Repair',
		'3' => 'Lubricants',
		'4' => 'Tire Purchase',
		'5' => 'Driver Expense',
		'6' => 'Truck Repair',
		'7' => 'Parts',
		'8' => 'Trailer Expense',
		'9' => 'Miscellaneous',
		'A' => 'Truck Wash',
		'B' => 'Scales',
		'C' => 'Parking Service',
		'D' => 'Hotel',
		'E' => 'Regulatory',
		'F' => 'Labor',
		'G' => 'Groceries',
		'H' => 'Shower',
		'I' => 'Trip Scan',
		'J' => 'Tolls',
		'K' => 'Aviation',
		'L' => 'Vehicle Maintenance',
		'M' => 'Aviation Maintenance',
		'N' => 'Set-up Fee',
		'O' => 'Diesel Exhaust Fluid',
		'P' => 'Wiper Fluid',
		'Q' => 'TS Trans Fee',
		'R' => 'Training',
		'S' => 'Trao;er Parking',
		'T' => 'Retail Lubricants',
		'U' => 'Solvent',
		'V' => 'Merch Surcharge',
		'W' => 'Anti-Frz / Coolant',
	);	
	
	// Use parent constructor
	
	// Allow re-use of objects - singleton function
	public static function getInstance( $database, $debug = false, $logger = null ) {
		static $instance = null;
		$myclass = get_class ();
		if( $debug ) echo "<p>Get instance of $myclass</p>";
		if (null === $instance) {
			$instance = new $myclass( $database, $debug, $logger );
		}
		return $instance;
    }
    
    //! Convert from CAD to USD
    private function to_usd( $date, $amount ) {
	    $result = false;
	    $check = $this->database->get_one_row("
	    	SELECT $amount * CONVERT_RATE('".$date."', 'CAD', 'USD') AS USD");
	    if( is_array($check) && count($check) == 1 && isset($check['USD'])) {
		    $result = $check['USD'];
	    }
	    
	    return $result;
    }
    

	//! Function to extract fixed-length fields based on record type
	private function parse_record($line) {
	    $recordType = substr($line, 0, 2); // First 2 chars define type
		
	    // Common fields
	    $data['TRANS_NUM'] = $data['CD_TRANS'] = substr($line, 14, 10);
	
	    // Parse fields based on Record Type
	    switch ($recordType) {
	        case 'TA': // Transaction Summary Record
	            $data['CD_CARD_NUM'] = $data['CARD_NUM'] = trim(substr($line, 24, 25));
	            $data['Location ID'] = substr($line, 61, 10);
				
				$trans_date = substr($line, 71, 12);
	            $this->trans_date = $data['TRANS_DATE'] = $data['IFTA_DATE'] = date("Y-m-d", strtotime(
	            	substr($trans_date, 4, 2).'/'.
				    substr($trans_date, 6, 2).'/'.
				    substr($trans_date, 0, 4) ));

				$data['CURRENCY_CODE'] = substr($line, 219, 3);
				
				$country = substr($line, 244, 1);
				$this->country = $data['COUNTRY'] = ($country == 'C' ? 'Canada' : 'USA');
	            break;
	
	        case 'TL': // Transaction Line Record
	        	$category = trim(substr($line, 26, 4));
	        	
	        	if( $category == 'ULSD') {			// ULTRA LOW SULFUR DIESEL
					$quantity = substr($line, 30, 7);
		            $data['FUEL_PURCHASED'] = (float) (substr($quantity, 0, 5).'.'.substr($quantity, 5, 2));
					$ppu = substr($line, 71, 7);
					$data['CD_TRACTOR_PPG'] = (float) (substr($ppu, 0, 4).'.'.substr($ppu, 4, 3));
		            if( isset($this->country) && $this->country == 'Canada' ) {
			        	$data['FUEL_PURCHASED'] =  $data['FUEL_PURCHASED'] * $this->conv_gallon;
			        	$data['CD_TRACTOR_PPG'] =  $data['CD_TRACTOR_PPG'] / $this->conv_gallon;
			        	$data['CD_TRACTOR_PPG'] = $this->to_usd( $this->trans_date, $data['CD_TRACTOR_PPG'] );
		            }
					
					$amount = substr($line, 37, 11);
					$data['ULSD_Amount'] = (float) (substr($amount, 0, 9).'.'.substr($amount, 9, 2));
		        	
	        	} else if( $category == 'RFR') {	// MARKED UNLEADED REGULAR REEFER
					$quantity = substr($line, 30, 7);
		            $data['CD_REEFER_GAL'] = (float) (substr($quantity, 0, 5).'.'.substr($quantity, 5, 2));
					$ppu = substr($line, 71, 7);
					$data['CD_REEFER_PPG'] = (float) (substr($ppu, 0, 4).'.'.substr($ppu, 4, 3));

		            if( isset($this->country) && $this->country == 'Canada' ) {
			        	$data['CD_REEFER_GAL'] =  $data['CD_REEFER_GAL'] * $this->conv_gallon;
			        	$data['CD_REEFER_PPG'] =  $data['CD_REEFER_PPG'] / $this->conv_gallon;
			        	$data['CD_REEFER_PPG'] = $this->to_usd( $this->trans_date, $data['CD_REEFER_PPG'] );
		            }
					
					$amount = substr($line, 37, 11);
					$data['RFR_Amount'] = (float) (substr($amount, 0, 9).'.'.substr($amount, 9, 2));
		        	
		        } else if( $category == 'CADV') {	// CASH ADVANCE
					$amount = substr($line, 37, 11);
					$data['CASH_ADV'] = (float) (substr($amount, 0, 9).'.'.substr($amount, 9, 2));
		    //        if( isset($this->country) && $this->country == 'Canada' ) {
			//        	$data['CASH_ADV'] = $this->to_usd( $this->trans_date, $data['CASH_ADV'] );
			//        }
			        
		        }
		        // Don't track anything else
	            break;
	
	        case 'TX': // Transaction Tax Record
	            break;
	
	        case 'LL': // Location Record
	            $data['CARD_STOP'] = $data['CD_STOP_NAME'] = trim(substr($line, 28, 25));
	            $data['CITY'] = $data['CD_STOP_CITY'] = trim(substr($line, 53, 25));
	            $data['IFTA_JURISDICTION'] = $data['STATE'] = $data['CD_STOP_STATE'] = substr($line, 78, 2);
	            break;
	
	        case 'TI': // Transaction Information Record (Extra Data)
        		$code = trim(substr($line, 24, 4));
        		
        		if( $code == 'NAME') {
	        		$data['CD_DRIVER'] = trim(substr($line, 28, 25));
        		} else if( $code == 'DRID') {
	        		$data['CD_EMPLOYEE_NUM'] = trim(substr($line, 28, 25));
        		} else if( $code == 'ODRD') {
	        		$data['ODOMETER_IN'] = trim(substr($line, 28, 25));
	        	} else if( $code == 'UNIT') {
	        		$data['CD_UNIT'] = trim(substr($line, 28, 25));
	        	} else if( $code == 'TRLR') {
	        		$data['CD_TRAILER'] = trim(substr($line, 28, 25));
	        	}
	            break;
	
	        default:
	            // Ignore other types for now
	            break;
	    }
	
	    return $data;
	}

    public function parse( $file ) {
	    if( $this->debug ) echo "<p>".__METHOD__.": ".(isset($file) ? strlen($file)." characters" : "").".</p>";
		if( $this->logger )
			$this->logger->log_event( __METHOD__.": entry, ".(isset($file) ? strlen($file)." characters" : ""), EXT_ERROR_DEBUG);
		$lines = explode("\n", $file);
		$this->tokens = [];
		$line_number = 1;
		$num_lines = count($lines);
		
		foreach( $lines as $line ) {
			$line = trim($line);
			$length = strlen($line);
			
			if( $length > 0 ) {
				$type = substr($line, 0, 2);
				if( $type == 'HH' ) continue;	// Skip over header
				
				if( $type == 'TA' ) {
					$token = [
						'line' => $line_number,
						'length' => $length,
					//	'raw' => [ $line ],
						'fields' => []
					];
				} 
							
				if( $type == 'TX' ) continue;	// Skip over tax
				
				$parse = $this->parse_record($line);
				
				$token['fields'] = array_merge($token['fields'], $parse);
				
				if( $type == 'TT' ) {
					$this->tokens[] = $token;
				}

				$line_number++;
			}
		}
		if( $this->debug ) {
			echo "<pre>PARSEXX\n";
			var_dump($this->tokens);
			echo "</pre>";
		}
		if( $this->logger )
			$this->logger->log_event( __METHOD__.": exit, ".(isset($this->tokens) ? count($this->tokens)." tokens" : ""), EXT_ERROR_DEBUG);
		
    }

    private function get_fields_for_import( $token, $origin ) {
	    $needed = array_fill_keys([
		    'IFTA_DATE', 'CD_TRANS','CD_UNIT', 'IFTA_TRACTOR',
	    	'CD_STOP_NAME', 'CD_STOP_CITY', 'CD_ORIGIN',
	    	'IFTA_JURISDICTION', 'FUEL_PURCHASED',
	    	'CD_TRACTOR_PPG', 'CD_REEFER_GAL',
	    	'CD_REEFER_PPG', 'CD_DRIVER',
	    	'CD_EMPLOYEE_NUM', 'CD_CARD_NUM',
	    	'CD_HUB', 'CD_PREV_HUB', 'CD_TRAILER'
		    ], "");
	    
	    $result = array_intersect_key($token['fields'], $needed);
	    $result['CD_ORIGIN'] = $origin;
	    $result['IFTA_TRACTOR'] = 0;

	    if( ! empty($origin) && ! empty($result['CD_UNIT']) ) {
		    // Look up card number
		    $check1 = $this->card_advance->database->get_one_row("
		    	SELECT t.TRACTOR_CODE
				FROM EXP_TRACTOR t, EXP_TRACTOR_CARD c
				WHERE c.CARD_SOURCE = '".$origin."' AND c.UNIT_NUMBER = '".$result['CD_UNIT']."'
				AND t.TRACTOR_CODE = c.TRACTOR_CODE
				AND t.LOG_IFTA = true
		    	LIMIT 1");
		    	
		    if( is_array($check1) && ! empty($check1["TRACTOR_CODE"])) {
			    if( $this->logger )
					$this->logger->log_event( __METHOD__.
						": entry, auto-resolve origin $origin unit# ".$result['CD_UNIT']." to tractor_code ".
						$check1["TRACTOR_CODE"], EXT_ERROR_DEBUG);
			    $result['IFTA_TRACTOR'] = $check1["TRACTOR_CODE"];
			}
	    }
	    
		if( $this->debug ) {
	    	echo "<pre>IMPORTXX";
	    	var_dump($token['fields']);
	    //	var_dump($needed);
	    	var_dump($result);
	    	echo "</pre>";
    	}
	    
	    return $result;
    }
    
    //! Get the fields needed for a cash advance
    private function get_fields_for_advance( $token, $origin ) {
	    $result = false;
	    $needed = array_fill_keys([
			'CARD_SOURCE', 'TRANS_DATE', 'TRANS_NUM',
		    'CARD_STOP', 'CITY', 'STATE',
		    'CASH_ADV', 'CASH_ADV_FEE',
		    'DRIVER_NAME', 'TRIP_NUM', 'CURRENCY_CODE',
		    'FUEL_CARD_ID', 'CARD_NUM'		    
		    ], "");
	    $result = array_intersect_key($token['fields'], $needed);
	    $result['CARD_SOURCE'] = $origin;
	    $result['DRIVER_CODE'] = 0;
	    
	    if( isset($result['CASH_ADV']) && $result['CASH_ADV'] > 0 ) {
		    // Look up card number
		    if( ! empty($result['CARD_NUM']) ) {
			    $check1 = $this->card_advance->database->get_one_row("
			    	SELECT DRIVER_CODE FROM EXP_DRIVER_CARD
			    	WHERE CARD_SOURCE = '".$origin."' AND CARD_NUM = '".$result['CARD_NUM']."'
			    	LIMIT 1");
		    }
		    if( is_array($check1) && ! empty($check1["DRIVER_CODE"])) {
			    $result['DRIVER_CODE'] = $check1["DRIVER_CODE"];
		    } else if( ! empty($result['DRIVER_NAME']) ) {
		    	if( strpos($result['DRIVER_NAME'], ',') !== false ) {
				    // Lookup driver code
				    list($last, $first) = explode(',', $result['DRIVER_NAME']);
				    $first = trim($first);
				} else if( strpos($result['DRIVER_NAME'], ' ') !== false ) {
				    // Lookup driver code
				    list($first, $last) = explode(' ', $result['DRIVER_NAME']);
				    $first = trim($first);
				}

			    $check2 = $this->card_advance->database->get_multiple_rows("
			    	SELECT DRIVER_CODE FROM EXP_DRIVER
			    	WHERE (LAST_NAME = '".$last."' AND FIRST_NAME = '".$first."')
			    	OR (LAST_NAME = '".$last."' AND FIRST_NAME LIKE '".$first."%')
			    ");
			    if( is_array($check2) && count($check2) == 1 &&
			    	isset($check2[0]['DRIVER_CODE'])) {
			    	$result['DRIVER_CODE'] = intval($check2[0]['DRIVER_CODE']);
			    	if( $this->logger )
						$this->logger->log_event( __METHOD__.": map $card_num to driver ".
							$result['DRIVER_CODE'], EXT_ERROR_DEBUG);
			    	
			    	$this->card_advance->database->get_one_row("
				    	INSERT INTO EXP_DRIVER_CARD (CARD_SOURCE, CARD_NUM, DRIVER_CODE)
				    	VALUES ( '".$origin."', '".$result['CARD_NUM']."', ".$result['DRIVER_CODE'].") ");
			    }
		    }
		} else {
			$result = false;
		}
	    
		if( $this->debug ) {
	    	echo "<pre>IMPORTYY";
	    	var_dump($token['fields']);
	    	var_dump($needed);
	    	var_dump($result);
	    	echo "</pre>";
    	}
	    
	    return $result;
    }
    
   public function dump( $origin, $back ) {
	    $result = '';
	    if( $this->debug ) echo "<p>".__METHOD__.": entry.</p>";
	    if( is_array($this->tokens) && count($this->tokens) > 0 ) {
		    $result .= '<div class="well well-sm">
			<h3>View Fuel Card Relevant Data <a class="btn btn-sm btn-default" href="'.$back.'"><span class="glyphicon glyphicon-remove"></span> Back</a></h3>
			<div class="table-responsive">
			<table class="display table table-striped table-condensed table-bordered table-hover" id="FUEL_CARDS">
			<thead><tr class="exspeedite-bg">
			<th>Line</th><th>Date<br>Trans#</th><th>Tractor<br>Trailer</th>
			<th>Address</th><th>Tractor Gal<br>Tractor PPG</th>
			<th>Reefer Gal<br>Reefer PPG</th><th>Hub<br>Prev Hub</th>
			<th>Driver<br>Emp num</th><th>Card<br>Advance</th>
			</tr>
			</thead>
			<tbody>';

		    foreach($this->tokens as $token) {
			//    if( $token['type'] == 'detail' ) {
				    
				    if( $this->debug ) {
				    	echo "<pre>".__METHOD__.": token, origin\n";
				    	var_dump($token);
				    	var_dump($origin);
				    	echo "</pre>";
				    }
				    $fields = $this->get_fields_for_import( $token, $origin );
				    $fields2 = $this->get_fields_for_advance( $token, $origin );
				    if( $this->debug ) {
				    	echo "<pre>".__METHOD__.": fields, fields2\n";
				    	var_dump($fields);
				    	var_dump($fields2);
				    	echo "</pre>";
				    }

				    $result .= '<tr>
						<td class="text-right">'.$token['line'].'</td>
						<td><strong>'.date("m/d/Y", strtotime($fields['IFTA_DATE'])).'<br>'.$fields['CD_TRANS'].'</strong></td>
						<td><strong>'.(isset($fields['CD_UNIT']) ? $fields['CD_UNIT'] : '').'</strong><br>'.(isset($fields['CD_TRAILER']) ? $fields['CD_TRAILER'] : '').'</td>
						<td>'.$fields['CD_STOP_NAME'].'<br>'.$fields['CD_STOP_CITY'].' <strong>'.$fields['IFTA_JURISDICTION'].'</strong></td>
						<td class="text-right"><strong>'.
						(isset($fields['FUEL_PURCHASED']) ? number_format($fields['FUEL_PURCHASED'], 2).' GAL' : '').
						'</strong><br>'.(isset($fields['CD_TRACTOR_PPG']) ? number_format($fields['CD_TRACTOR_PPG'], 3).' $/GAL' : '').'</td>
						<td class="text-right">'.(isset($fields['CD_REEFER_GAL']) ? number_format($fields['CD_REEFER_GAL'], 2).' GAL' : '').'<br>'.(isset($fields['CD_REEFER_PPG']) ? number_format($fields['CD_REEFER_PPG'], 3).' $/GAL' : '').'</td>
						<td class="text-right">'.(isset($fields['CD_HUB']) ? $fields['CD_HUB'] : '').'<br>'.(isset($fields['CD_PREV_HUB']) ? $fields['CD_PREV_HUB'] : '').'</td>
						<td>'.$fields['CD_DRIVER'].'<br>'.(isset($fields['CD_EMPLOYEE_NUM']) ?$fields['CD_EMPLOYEE_NUM'] : '').'</td>
						<td>'.$fields['CD_CARD_NUM'].
							(is_array($fields2) && isset($fields2['CASH_ADV']) ? '<br>'.number_format($fields2['CASH_ADV'],2) : '').'</td>
					</tr>';
			//    }
		    }
	   		$result .= '</tbody>
			</table>
			</div>
			</div>';

	    }
	    return $result;
	}

  public function import( $origin ) {
	    $result = true;
	    //! SCR# 554 - Add more visibility to import process
	    $this->count_ifta_import			= 0;
	    $this->count_ifta_import_error		= 0;
		$this->count_ifta_duplicate			= 0;
		$this->count_advance_import			= 0;
		$this->count_advance_import_error	= 0;
		$this->count_advance_duplicate		= 0;

	    if( $this->debug ) echo "<p>".__METHOD__.": entry, origin $origin ".
		    (is_array($this->tokens) ? count($this->tokens)." tokens" : "no tokens")."</p>";
		if( $this->logger )
			$this->logger->log_event( __METHOD__.": entry, origin $origin ".
		    (is_array($this->tokens) ? count($this->tokens)." tokens" : "no tokens"), EXT_ERROR_DEBUG);
	    if( is_array($this->tokens) && count($this->tokens) > 0 ) {
 		    foreach($this->tokens as $token) {
			    if( $this->logger )
					$this->logger->log_event( __METHOD__.": line=".(isset($token['line']) ? $token['line'] : 'unknown')." type=".(isset($token['type']) ? $token['type'] : 'unknown'), EXT_ERROR_DEBUG);
				$result = $result2 = true;	// initialize to true, no problems
			//    if( $token['type'] == 'detail' ) {
				    $fields = $this->get_fields_for_import( $token, $origin );
				    if( is_array($fields) ) {
						// Check for duplicates
						$check = $this->ifta_log->fetch_rows( "IFTA_DATE = '".$fields['IFTA_DATE']."'
					    	AND CD_TRANS = '".$fields['CD_TRANS']."'
					    	AND CD_UNIT = '".$fields['CD_UNIT']."'
					    	AND CD_CARD_NUM = '".$fields['CD_CARD_NUM']."'", "IFTA_LOG_CODE");
					    
					    if( ! is_array($check) || count($check) == 0 ) {

						    // Add the log entry
						    if( $this->logger )
								$this->logger->log_event( __METHOD__.": add IFTA ".print_r($fields, true), EXT_ERROR_DEBUG);
						    $result = $this->ifta_log->add( $fields );
						    if( $result ) {
								$this->ifta_log->audit->log_import( $result, $fields['IFTA_TRACTOR'], $fields );
						    	$this->count_ifta_import++;
						    } else {
						    	$this->count_ifta_import_error++;
						    }
						    	
						    if( $this->logger && ! $result )
								$this->logger->log_event( __METHOD__.": Add IFTA_LOG error: ".$this->ifta_log->error(), EXT_ERROR_ERROR);
						} else {
						    $this->count_ifta_duplicate++;
						    if( $this->logger )
								$this->logger->log_event( __METHOD__.": DUPLICATE IFTA ".print_r($fields, true), EXT_ERROR_ERROR);
						}
					} else {
					    if( $this->logger )
							$this->logger->log_event( __METHOD__.": No fields found!", EXT_ERROR_ERROR);
					}

				    //!  Import cash advances
					if( $this->debug ) echo "<p>".__METHOD__.": Import cash advances.</p>";
				    if( $this->import_cash_adv ) {
					    $fields = $this->get_fields_for_advance( $token, $origin );
					    if( $this->debug ) {
					    	echo "<pre>after get_fields_for_advance\n";
					    	var_dump($fields);
					    	echo "</pre>";
					    }
					    if( is_array($fields) ) {
							// Check for duplicates
							$check = $this->card_advance->fetch_rows( "TRANS_DATE = '".$fields['TRANS_DATE']."'
						    	AND TRANS_NUM = '".$fields['TRANS_NUM']."'
						    	AND CARD_NUM = '".$fields['CARD_NUM']."'", "ADVANCE_CODE");
						    
						    if( ! is_array($check) || count($check) == 0 ) {
							    if( $this->logger )
									$this->logger->log_event( __METHOD__.": add advance ".print_r($fields, true), EXT_ERROR_DEBUG);
	
							    $result2 = $this->card_advance->add( $fields );
							    if( $result )
							    	$this->count_advance_import++;
							    else
							    	$this->count_advance_import_error++;
						    	
							    if( $this->logger && ! $result2 )
									$this->logger->log_event( __METHOD__.": Add CARD_ADVANCE error: ".$this->card_advance->error(), EXT_ERROR_ERROR);
							} else {
								$this->count_advance_duplicate++;
							    if( $this->logger )
									$this->logger->log_event( __METHOD__.": DUPLICATE advance ".print_r($fields, true), EXT_ERROR_ERROR);
									
							}
						}
					}
			//	}
				// If a DB operation failed...
				if( ! $result || ! $result2 ) {
					$result = false;
					break;
				}
			}
		}
	    if( $this->logger )
			$this->logger->log_event( __METHOD__.": exit, return ".($result ? "true" : "false").
				"\n\tIFTA import/error/duplicate = ".
				$this->count_ifta_import."/".
				$this->count_ifta_import_error."/".
				$this->count_ifta_duplicate." ADVANCE import/error/duplicate = ".
				$this->count_advance_import."/".
				$this->count_advance_import_error."/".
				$this->count_advance_duplicate, EXT_ERROR_ERROR);
		$this->log_import( $origin, $result );

		return $result;
	}

}

