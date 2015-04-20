<?php

date_default_timezone_set('UTC');

// Basic panel configuration, given by TribeHR
define('INTEGRATION_ID', '[put your integration ID here]');
define('SECRET_SHARED_KEY', '[put your secret shared key here]');

// Lookup API endpoint to connect to.
// Ensure that you're pointing to either the production or sandbox environment as appropriate and matches your
//  integration ID and shared key.
// You can also mock out the TribeHR system and point to your own solution for testing
define('TRIBEHR_LOOKUP_API_ENDPOINT', 'https://app.sandbox.tribehr.com/lookup/');		// development sandbox
// define('TRIBEHR_LOOKUP_API_ENDPOINT', 'https://app.tribehr.com/lookup/');			// production environment

// Values that can be useful for debugging/testing an integration
define('REQUEST_LOGGING_ENABLED', false);
define('ENFORCE_NONCE', true);
define('CREATE_ACCOUNT_IF_NOT_EXISTS', true);
define('CREATE_USER_IF_NOT_EXISTS', true);
