<?php
require_once('../init.php');

// $databaseConnection is initialized in init.php
$client = new TribeHRPanelClient($databaseConnection);
$app = new app($client, $databaseConnection);

// Step 1: Receive and verify TribeHR's activation request
// With an activation request, the JWT should be located in the "Authorization" header with a "Bearer" leading token
$client->log('Beginning activation workflow');
$requestApacheHeaders = getallheaders();
$client->log('Activation request headers: '. print_r($requestApacheHeaders, true));

$rawJWT = null;
if (isset($requestApacheHeaders['Authorization']) && strpos($requestApacheHeaders['Authorization'], 'Bearer') !== false) {
	// The JWT is the part after you strip out the "Bearer " token
	$rawJWT = str_replace('Bearer ', '', $requestApacheHeaders['Authorization']);
}

// Use our client library to validate and decode the JWT authorization token
$decodedJWT = $client->decodeAndValidateToken($rawJWT);

if ($decodedJWT == false) {
	// Write the technical problem to our logs
	$client->log('Could not validate activation request. JWT error: '. $client->tokenError());

	// The validation failed - could be a hacking attempt or a communication error.
	// Send back an activation failure, with a user-facing message.
	$client->sendActivationErrorResponse('The request to activate our panel for your account was missing some information. Please try again, and if it fails contact our support team at [your phone number here].');
	die();
}

$client->log('Decoded JWT: '. print_r($decodedJWT, true));

// Step 2: Check if the application already recognizes the account
// The account in question is represented by a globally-unique identifier string in the JWT
// In our database of customer accounts, we store these in a column called 'external_id' if we've seen it from TribeHR before
// This call will also do Step 3: Request account information from TribeHR if we do not recognize the identifier given.
$account = $app->getAccountByExternalId($decodedJWT->account);

// Step 4: Respond to TribeHR
// Next, decide if the activation should be allowed to proceed or not, and send the appropriate
// response back to TribeHR. For this sample application, let's decide to approve the activation if
// we were able to get a local account result out of getAccountByExternalId().
// This indicates that we were able to recognize which of our local accounts TribeHR is attempting to activate.
$allowActivation = !empty($account);

if (!$allowActivation) {
	$client->log('Not allowing activation for account: '. $decodedJWT->account);
	$client->sendActivationErrorResponse('You do not have an account with the Address Mapper tool, so we would not be able to show you any maps. Please visit our website, create an account with your administrator email, and try again.');
	die();
}

// We're going to allow the activation
// In a normal application, we would suggest at this point responding with the "200 OK", and then using
// a job queue or other means of triggering the bulk user lookup on a different thread, so the user doesn't wait.
// For example purposes, here we'll do the most straightforward approach and assume we don't have
// access to those tools.
// Reminder: if TribeHR does not receive a response within 15s, it will assume the activation is disallowed.

// Step 5: Create user mappings pre-emptively
$allTribeUsers = $client->bulkUserLookup($decodedJWT->account);
if (!empty($allTribeUsers) && is_array($allTribeUsers)) {
	foreach ($allTribeUsers as $currentTribeUser) {
		$app->mapExternalUserToInternalUser($currentTribeUser, $account);
	}
}

// Tell TribeHR that everything is good to go, and expect content requests from
// this account to start coming in soon!
header($_SERVER['SERVER_PROTOCOL'] .' 200 OK');
die();
