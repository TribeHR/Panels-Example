<?php

date_default_timezone_set('UTC');

// Basic panel configuration, given by TribeHR
define('INTEGRATION_ID', '[put your integration ID here]');
define('SECRET_SHARED_KEY', '[put your secret shared key here]');

// Endpoint URL. Re-point locally for testing if desired.
define('TRIBEHR_LOOKUP_API_ENDPOINT', 'https://app.tribehr.com/lookup/');

// Values that can be useful for debugging/testing an integration
define('REQUEST_LOGGING_ENABLED', false);
define('ENFORCE_NONCE', true);
define('CREATE_ACCOUNT_IF_NOT_EXISTS', true);
define('CREATE_USER_IF_NOT_EXISTS', true);
