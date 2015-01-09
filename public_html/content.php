<?php
require_once('../init.php');

ini_set("display_errors", true);
error_reporting(E_ALL);

// $databaseConnection is initialized in init.php
$client = new TribeHRPanelClient($databaseConnection);
$app = new app($client, $databaseConnection);

// Step 1: Receive and verify TribeHR's conent request
// With a content request, the JWT is in the querystring, in a "jwt" parameter
$client->log('Beginning content request workflow');

$rawJWT = null;
if (!empty($_GET['jwt'])) {
	$rawJWT = $_GET['jwt'];
}

// Use our client library to validate and decode the JWT authorization token
$decodedJWT = $client->decodeAndValidateToken($rawJWT);

if ($decodedJWT == false) {
	// Write the technical problem to our logs
	$client->log('Could not validate content request. JWT error: '. $client->tokenError());

	// The validation failed - could be a hacking attempt or a communication error.
	// Send back an activation failure, with a user-facing message.
	$client->sendHtmlErrorResponse('We were unable to verify your request for AddressMapper information. Please try again, and if it fails contact our support team at [your phone number here].');
	die();
}

$client->log('Decoded JWT: '. print_r($decodedJWT, true));

// Step 2: Check if your application already recognizes the account and users
// The JWT contains globally-unique identifer strings for the account, the user who owns the information to
// display, and the user requesting the information. We're storing these keys in database columns called 'external_id'
// in their respective tables, if we've seen a request for these entities before.
$account = $app->getAccountByExternalId($decodedJWT->account, $preventApiLookup = true);

// If we don't recognize the account requesting the panel, we absolutely should not render any content!
// Each account is expected to have gone through the activation process, and we should have a mapping already
// to one of our local accounts.
if (empty($account)) {
	$client->sendHtmlErrorResponse('Your account is not yet activated with TribeHR panels. In TribeHR, activate the AddressMapper panel to get access to your AddressMapper information from within TribeHR.');
	die();
}

// If these users are unrecognized, getUserByExternalId will go ahead
//  and do Step 3: Request identifying information from TribeHR automatically,
// and try to make a local mapping.
$subjectUser = $app->getUserByExternalId($decodedJWT->sub, $account);
$requestingUser = $app->getUserByExternalId($decodedJWT->aud, $account);

// For this sample application, if we don't recognize the $subjectUser then we don't have a lot
// of useful information to show. Our application logic requires that we have a user to report on -
// your application might not. So, if we don't recognize $subjectUser, give the user an error message.
// However, for our very simple application, we can still proceed if we do not recognize $requestingUser.
// For your application, you might or might not require this User be recognized in your system as well.
//
// Reminder: by default, this application will try to create new accounts for users it hasn't seen before, so
// not recoginizing $subjectUser is unlikely. You can disable this in config.php by setting CREATE_USER_IF_NOT_EXISTS
// to false
if (empty($subjectUser)) {
	$client->sendHtmlErrorResponse("Your co-worker hasn't registered with AddressMapper!<br />You should suggest that they go to our website and register with their email address in your company's account.");
	die();
}

if (empty($requestingUser)) {
	$requestingUser = $app->guestUser();
}

// Step 4: Build HTML content and respond to TribeHR
// Now that we know all we need to about the requesting account and users, we can use our application's normal
// logic to generate page content as appropriate. Notice we're using only information from our own DB, not the
// information in the format given by TribeHR API requests. We're using our own data.
// We're keeping this example app pretty simple, so there are no generic templates or layouts to re-use, and
// the calls to our "app" class are minimal.
// We do suggest taking a look at the panels style guide so that your content looks good within TribeHR
?>
<html>
<head>
	<!-- Automatically enable use of TribeHR-consistent styling by including this css file! -->
	<link rel="stylesheet" href="https://media.tribehr.com/partners/panels/tribehr_panel_styles.css"></link>
	<style>
	html, body, #map-canvas { 
		height: 400px; 
		width: 700px;
		margin: 0; 
		padding: 0;
		z-index:1;
	}
	</style>
	<script type="text/javascript" src="https://maps.googleapis.com/maps/api/js"></script>
</head>
<body>
	<div class="greybox">
		<h3 class="plaintext">Hello <?php echo htmlspecialchars($requestingUser['first_name']); ?> <?php echo htmlspecialchars($requestingUser['last_name']); ?> from <?php echo htmlspecialchars($account['account_name']); ?></h3>
	</div>

	<br />

	<table class="listingTable">
		<tr>
			<th>Your Co-ordinates</th><th><?php echo htmlspecialchars($subjectUser['first_name']); ?>'s Co-ordinates</th>
		</tr>
		<tr>
			<td><?php echo htmlspecialchars($requestingUser['lat']); ?>, <?php echo htmlspecialchars($requestingUser['lng']); ?></td>
			<td><?php echo htmlspecialchars($subjectUser['lat']); ?>, <?php echo htmlspecialchars($subjectUser['lng']); ?></td>
		</tr>
	</table>

	<div id="map-canvas"></div>

	<script>
		var map = null;
		var requesterAddressMarker = null;
		var subjectAddressMarker = null;

		function createMarker(latlng, name, html) {
			var contentString = html;
			var marker = new google.maps.Marker({
				position: latlng,
				map: map,
				zIndex: Math.round(latlng.lat()*-100000)<<5
			});
			google.maps.event.trigger(marker, 'click');
			return marker;
		}

		function initialize() {
			var mapOptions = {
				center:{
					lat: <?php echo htmlspecialchars($subjectUser['lat']); ?>,
					lng: <?php echo htmlspecialchars($subjectUser['lng']); ?>
				},
				zoom: 8,
				streetViewControl: false,
				rotateControl: false,
				panControl: false,
				mapTypeControl: false
			};
			map = new google.maps.Map(document.getElementById('map-canvas'), mapOptions);

			requesterAddressMarker = createMarker(new google.maps.LatLng(<?php echo htmlspecialchars($requestingUser['lat']); ?>, <?php echo htmlspecialchars($requestingUser['lng']); ?>), "aud", "<b>YOU</b>");
			subjectAddressMarker = createMarker(new google.maps.LatLng(<?php echo htmlspecialchars($subjectUser['lat']); ?>, <?php echo htmlspecialchars($subjectUser['lng']); ?>), "sub", "<b>THEM</b>");

			requesterAddressMarker.setMap(map);
			subjectAddressMarker.setMap(map);

			var flightPlanCoordinates = [
				new google.maps.LatLng(<?php echo htmlspecialchars($requestingUser['lat']); ?>, <?php echo htmlspecialchars($requestingUser['lng']); ?>),
				new google.maps.LatLng(<?php echo htmlspecialchars($subjectUser['lat']); ?>, <?php echo htmlspecialchars($subjectUser['lng']); ?>),
				];
			var flightPath = new google.maps.Polyline({
				path: flightPlanCoordinates,
				geodesic: true,
				strokeColor: '#FF0000',
				strokeOpacity: 1.0,
				strokeWeight: 2
			});

			flightPath.setMap(map);
		}

		google.maps.event.addDomListener(window, 'load', initialize);
	</script>
</body>
</html>
