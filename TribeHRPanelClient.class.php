<?php

/**
 * This class represents a basic client for the TribeHR panel, and is used
 * on behalf of the panel specified by the Integration ID and secret key found in config.php.
 * Here you will find any methods related to making API Lookup calls or decoding and validating
 * any panels-related requests that come in from TribeHR.
 *
 * While many of the concepts found in this class would more properly be classes in their own right,
 * here we've done as much inline as possible to make the example easy to follow and completely transparent.
 */
class TribeHRPanelClient {

	const REQUEST_LOG = '../logs/request.log';
	const INCOMING_NONCE = 'incoming';
	const GENERATED_NONCE = 'outgoing';

	private $tokenError = '';
	private $db = null;

	/**
	 * Instantiating our app.
	 * Note that in this simple example, while a DB engine is injected, all calls are still
	 * made using basic SQL. We did not want to introduce an abstraction layer - we wanted it
	 * to be completely clear what was happening in each step.
	 *
	 * @param PDO $db
	 * @return void
	 */
	function __construct($db) {
		$this->db = $db;
	}

	/**
	 * A basic logger that can be enabled/disabled through the configuration file.
	 * When active, will write a timestamped entry to a flat logfile.
	 *
	 * @param string $content
	 * @param string $logfile  (default: self::REQUEST_LOG) By default, writes to /logs/request.log. Provide a valid file path to override.
	 * @return bool            True if the operation did not fail
	 */
	public function log($content, $logfile = null)
	{
		// This define exists in config.php; you can enable/disable logging there
		if (!REQUEST_LOGGING_ENABLED) {
			return true;
		}

		if (empty($logfile)) {
			$logfile = self::REQUEST_LOG;
		}

		return file_put_contents($logfile, sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $content), FILE_APPEND);
	}

	/**
	 * Retrieve the last error generated while working with JWTs (JSON Web Tokens)
	 *
	 * @return string
	 */
	public function tokenError()
	{
		return $this->tokenError;
	}

	/**
	 * Use the TribeHR Lookup API to get identifying information about an account.
	 * Simple helper wrapper around request()
	 *
	 * @param string $accountIdentifier  Globally-unique identifier for a TribeHR account; usually found in the 'account' claim of a JWT sent by TribeHR
	 * @return mixed                     The response as returned from request()
	 */
	public function accountLookup($accountIdentifier) {
		$url = TRIBEHR_LOOKUP_API_ENDPOINT . "account/" . $accountIdentifier . ".json";
		return $this->request($url);
	}

	/**
	 * Use the TribeHR Lookup API to get identifying information about an user in a specific account.
	 * Simple helper wrapper around request()
	 *
	 * @param string $accountIdentifier  Globally-unique identifier for a TribeHR account; usually found in the 'account' claim of a JWT sent by TribeHR
	 * @param string $userIdentifier     Globally-unique identifier for a TribeHR user; usually found in the 'aud' or 'sub' claim of a content request JWT sent by TribeHR
	 * @return mixed                     The response as returned from request()
	 */
	public function userLookup($accountIdentifier, $userIdentifier) {
		$url = TRIBEHR_LOOKUP_API_ENDPOINT . "account/" . $accountIdentifier . "/users/" . $userIdentifier . ".json";
		return $this->request($url);
	}

	/**
	 * Use the TribeHR Lookup API to get identifying information about a all users in a specific account.
	 * Simple helper wrapper around request()
	 *
	 * @param string $accountIdentifier  Globally-unique identifier for a TribeHR account; usually found in the 'account' claim of a JWT sent by TribeHR
	 * @return mixed                     The response as returned from request()
	 */
	public function bulkUserLookup($accountIdentifier) {
		$url = TRIBEHR_LOOKUP_API_ENDPOINT . "account/" . $accountIdentifier . "/users.json";
		return $this->request($url);
	}

	/**
	 * Make a request against TribeHR's Lookup API, and decode the response.
	 * With this method, the caller must build the URL itself; using the appropriate
	 * helper wrapper is suggested.
	 * See: accountLookup(), userLookup(), bulkUserLookup()
	 *
	 * @param string $url  Full URL to issue the request against
	 * @return mixed       False if the request failed or was invalid; array representing Lookup API results if successful
	 */
	public function request($url)
	{
		$this->log('Issuing TribeHR Lookup API reqeust to URL: '. $url);

		// Generate a nonce that we haven't sent to TribeHR within the last 12 hours
		$validNonce = false;
		$nonce = '';
		while (!$validNonce) {
			$nonce = bin2hex(openssl_random_pseudo_bytes(16));
			$validNonce = $this->validNonce($nonce, self::GENERATED_NONCE);
		}

		$claims = array(
			'iss' => INTEGRATION_ID,
			'iat' => time(),
			'jti' => $nonce,
			'exp' => strtotime('+5 minutes')
		);

		$headers = array('Authorization: Bearer '. JWT::encode($claims, SECRET_SHARED_KEY));

		$this->log('Request headers: '. print_r($headers, true));

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 0);

		$response = curl_exec($ch);
		$info = curl_getinfo($ch);
		curl_close($ch);

		if ($info['http_code'] != 200) {
			$this->log('Request failed with code: '. $info['http_code'] .'. Response body: '. $response);
			return false;
		}

		$jsonResponse = json_decode($response, true);

		$this->log('Request decoded JSON response: '. print_r($jsonResponse, true));
		return $jsonResponse;
	}

	/**
	 * Decode and validate a JWT sent by TribeHR.
	 * If any errors are encountered, will set a message into $this->tokenError
	 *
	 * @param string $rawJWT  string representing a signed JWT
	 * @return mixed          false if the JWT was incomplete or validation failed; array of claim => value pairs otherwise
	 */
	public function decodeAndValidateToken($rawJWT)
	{
		if (empty($rawJWT)) {
			$this->tokenError = "Missing JWT";
			return false;
		}

		try {
			$decodedJWT = JWT::decode($rawJWT, SECRET_SHARED_KEY, $verifySignature = true);
		} catch (UnexpectedValueException $e) {
			$this->tokenError = "JWT Invalid: ". $e->getMessage();
			return false;
		}

		// TribeHR says that their 'iss' claim will *always* be "http://www.tribehr.com"
		if ($decodedJWT->iss != 'http://www.tribehr.com') {
			$this->tokenError = "Invalid 'iss' claim: ". $decodedJWT->iss;
			return false;
		}

		// 'exp' must be a UNIX timestamp that is in the future from 'now'
		if (!is_numeric($decodedJWT->exp) || $decodedJWT->exp < time()) {
			$this->tokenError = "Expired JWT";
			return false;
		}

		// 'iat' must be a UNIX timestamp, reperesenting the time at which the claim was issued
		// This must be verified to be a a valid UNIX timestamp and before the 'exp' time
		if (!is_numeric($decodedJWT->iat) || $decodedJWT->iat >= $decodedJWT->exp) {
			$this->tokenError = "Missing or invalid 'iat' claim";
			return false;
		}

		// 'jti' is a nonce that won't be reused by TribeHR more than once every 12 hours.
		// If a jti is re-used, this could be a malicious replay attack, or a client that is caching inappropriately.
		if (!$this->validNonce($decodedJWT->jti, self::INCOMING_NONCE)) {
			$this->tokenError = "Missing, duplicated or invalid 'jti' claim";
			return false;
		}

		return $decodedJWT;
	}

	/**
	 * Determine if a given nonce is currently valid or not.
	 * A nonce is valid for TribeHR panels if it has not been seen in the last 12 hours.
	 *
	 * @return boolean  true if the nonce is valid, false otherwise.
	 */
	private function validNonce($nonce, $nonceType) {
		$nonceTable = 'nonces_'. $nonceType;

		// If you need to test by copy/pasting repeated requests, you can turn off nonce validation in config.php
		if (!ENFORCE_NONCE) {
			return true;
		}

		if (empty($nonce)) {
			return false;
		}

		// Delete any nonces that are over 12h old; they're all valid again
		$query = $this->db->prepare('DELETE FROM '. $nonceTable .' WHERE time < ?;');
		$query->execute(array(strtotime('-12 hours')));

		// Check if the nonce value exists; everything left is within 12 hours
		$query = $this->db->prepare('SELECT nonce FROM '. $nonceTable .' WHERE nonce = ?');
		$query->execute(array($nonce));
		$result = $query->fetch(PDO::FETCH_ASSOC);

		if (!empty($result)) {
			return false;
		}

		// The nonce is valid, but now that we've seen it we need to record the fact
		$query = $this->db->prepare('INSERT INTO '. $nonceTable .' (nonce, time) VALUES (?, ?)');
		$query->execute(array($nonce, time()));

		return true;
	}

	/**
	 * Helper to build an appropriate failure response to an activation request.
	 * Echoes the response directly to the buffer.
	 *
	 * Ensures that the header is set to "401 Unauthorized", and that the message is formatted as expected.
	 * Reminder: Message returned is seen by the TribeHR administrator, and should be useful to them.
	 *
	 * @param string $message  Message to relay to the user when refusing the activation
	 * @return void
	 */
	public function sendActivationErrorResponse($message)
	{
		header($_SERVER['SERVER_PROTOCOL'] .' 401 Unauthorized');

		echo json_encode(array(
			'error' => array(
				'message' => $message
			)
		));
	}

	/**
	 * Helper to build an appropriate failure response to a content request.
	 * Echoes the response directly to the buffer.
	 *
	 * Builds some (very) basic display HTML around the given error message.
	 * Your application will probably want this to look nicer and/or more consistent with regular content.
	 * Since this application is just an example, we wanted the error state to be very starkly visible for you.
	 *
	 * @param string $message  Message to relay to the user when something has gone wrong with a content request
	 * @return void
	 */
	public function sendHtmlErrorResponse($message)
	{
		echo '
		<div style="border:1px solid brown; padding:20px; overflow:hidden;">
			<p>' . $message . '</p>
		</div>
		';
	}
}

