<?php

// no direct access
defined('_STS_INCLUDE') or die('Restricted access');

// This defines the formatting of the %DETAILS_GO_HERE% section.
// If not found, the default version in the stop class will be used.

// You will need to edit the header and layout sections
// Be sure to use HTML that works in emails, not all HTML does...

// %NAME% is replaced with the field of that name
// #NAME#text# - this is conditional, will display text if the field NAME exists and has a value
// Within text, use @ if you want to show a #

//
// CNAME			-	Commodity name
// CDESCRIPTION		-	Description
// PALLETS			-	Pallets
// PIECES			-	Pieces / Items
// UNAME			-	Item Units
// WEIGHT			-	Weight
// CNOTES			-	detail notes


$sts_invoice_detail = [	//! $sts_invoice_detail
	'header' => '
	<table class="noborder">
		<thead>
			<tr>
				<th class="w25">
					COMMODITY
				</th>
				<th class="w25">
					DESCRIPTION
				</th>
				<th class="w15 text-right">
					PALLETS
				</th>
				<th class="w15 text-right">
					ITEMS
				</th>
				<th class="w15 text-right">
					WEIGHT
				</th>
			</tr>
		</thead>
		<tbody>',
	'layout' => '
			<tr>
				<td class="w25">
					%CNAME%
				</td>
				<td class="w25">
					%CDESCRIPTION%<br>
					%CNOTES%
				</td>
				<td class="w15 text-right">
					%PALLETS%
				</td>
				<td class="w15 text-right">
					%PIECES% %UNAME%
				</td>
				<td class="w15 text-right">
					%WEIGHT%
				</td>
			</tr>
	',
	'footer' => '</tbody>
	</table>
		' ];

?>