<?php
require_once('TribeHRPanelClient.class.php');

/**
 * This class represents the main "application" logic for our sample app.
 * Here you will find everything that has to do with our app, it's users, and what we do with them.
 * The concept of mapping a TribeHR "external" user to one of our app's users (and accounts) fits
 * into that as well.
 *
 * While many of the concepts found in this class would more properly be classes in their own right,
 * here we've done as much inline as possible to make the example easy to follow and completely transparent.
 */
class app
{
	const DEFAULT_LAT = 43.4;
	const DEFAULT_LNG = -80.5;

	private $client = null;
	private $db = null;

	/**
	 * Instantiating our app.
	 * To clearly illustrate what is happening in each step, all calls are made with basic SQL,
	 * and no database abstraction layer is used.
	 *
	 * @param TribeHRPanelClient $client
	 * @param PDO $db
	 * @return void
	 */
	public function __construct($client, $db)
	{
		$this->client = $client;
		$this->db = $db;
	}

	/**
	 * Given an "external_id" value that maps to that column in our accounts database table,
	 * return the row that matches. If one cannot be found, use the TribeHR Lookup API
	 * to see if we can find out which of our application's accounts best matches.
	 *
	 * @param string $externalId
	 * @param bool $preventApiLookup  (default: false) If true, will not use the TribeHR Lookup API if account not found
	 * @return array                  Returns a database row representing one of our application's accounts, or an empty array if one does not exist
	 */
	public function getAccountByExternalId($externalId, $preventApiLookup = false)
	{
		if (empty($externalId)) {
			return array();
		}

		$query = $this->db->prepare('SELECT * FROM accounts WHERE external_id = ? LIMIT 1;');
		$query->execute(array($externalId));

		$account = $query->fetch(PDO::FETCH_ASSOC);

		// We don't have a local account that matches this external ID.
		// By default, unless specified otherwise, we should check with TribeHR to learn about
		// the account and save the mapping for future requests
		if (empty($account) && !$preventApiLookup) {
			$account = $this->mapExternalIdToAccount($externalId);
		}

		return $account;
	}

	/**
	 * Given an "external_id" value that maps to that column in our users database table,
	 * return the row that matches. If one cannot be found, use the TribeHR Lookup API
	 * to see if we can find out which of our application's users best matches.
	 *
	 * @param string $externalId
	 * @param bool $preventApiLookup  (default: false) If true, will not use the TribeHR Lookup API if user not found
	 * @return array                  Returns a database row representing one of our application's users, or an empty array if one does not exist
	 */
	public function getUserByExternalId($externalId, $account, $preventApiLookup = false)
	{
		if (empty($externalId)) {
			return array();
		}

		$query = $this->db->prepare('SELECT * FROM users WHERE account_id = ? AND external_id = ? LIMIT 1;');
		$query->execute(array($account['id'], $externalId));

		$user = $query->fetch(PDO::FETCH_ASSOC);

		// We don't have a local user in this account that matches this external ID.
		// By default, unless specified otherwise, we should check with TribeHR to learn about
		// the user and save the mapping for future requests
		if (empty($user) && !$preventApiLookup) {
			$user = $this->mapExternalIdToUser($externalId, $account);
		}

		return $user;
	}

	/**
	 * Given an "external_id" value, request identifying information from TribeHR and then
	 * best match the result to one of our application's accounts.
	 * See: mapExternalAccountToInternalAccount()
	 *
	 * @param string $externalId
	 * @return array              Returns a database row representing one of our application's accounts, or an empty array if one does not exist
	 */
	public function mapExternalIdToAccount($externalId)
	{
		// Request identifying information about the account from TribeHR
		// Currently all we know about the account is the provided identifier
		$tribeAccount = $this->client->accountLookup($externalId);

		if (empty($tribeAccount)) {
			$this->client->log('Failed to look up identifying information for account: '. $externalId);
			return false;
		}

		return $this->mapExternalAccountToInternalAccount($tribeAccount);
	}

	/**
	 * Given an array that represents a partner's concept of an account, attempt to match it to one
	 * of our application's own accounts. If a match can be found, then save the mapping to the database
	 * into the "external_id" column to prevent the need to do the mapping again.
	 *
	 * If no mapping can be found, create a new account in our application with the information
	 * from the external account (this behaviour can be disabled in the config file, see below).
	 *
	 * @param array $externalAccount  Array representing a TribeHR Lookup API account result. Use TribeHRPanelClient::accountLookup() to generate
	 * @return array                  Returns a database row representing one of our application's accounts, or an empty array if one does not exist
	 */
	public function mapExternalAccountToInternalAccount($externalAccount)
	{
		// Try to match the identifying information given by TribeHR to one of our app's accounts
		// Looking at the spec and our app, we've determined that there's a match if one of the following is true:
		//  - our account's "admin_email" is the same as TribeHR's "admin_email"
		//  - our account's "account_name" is the same as TribeHR's "name"
		// Note that this *could* match more than one local account. For simplicity, we're treating the first
		// random one that matches as the correct one. Your app may have more exacting criteria.
		$query = $this->db->prepare('SELECT * FROM accounts WHERE admin_email = ? OR account_name = ? LIMIT 1');
		$query->execute(array($externalAccount['config']['admin_email'], $externalAccount['config']['name']));
		$localAccount = $query->fetch(PDO::FETCH_ASSOC);

		if (!empty($localAccount)) {
			// If we already have this mapping, no need to re-write it
			if ($localAccount['external_id'] == $externalAccount['identifier']) {
				return $localAccount;
			}

			// We have a match between a TribeHR identifier and one of our internal accounts!
			// Record the TribeHR identifer into our "external_id" column, so that next time we
			// don't have to do the API request while users wait
			$query = $this->db->prepare('UPDATE accounts SET external_id = ? WHERE id = ?');
			$query->execute(array($externalAccount['identifier'], $localAccount['id']));

			$this->client->log('Mapped account '. $localAccount['account_name'] .' to identifier '. $externalAccount['identifier']);
			$localAccount['external_id'] = $externalAccount['identifier'];
		}

		// In this sample application, we're being very lenient - if we can't find an existing account,
		// we'll create one on the spot. Your application might not follow this pattern.
		// For debugging or experimentation purposes, you can disable this feature by setting the
		// CREATE_ACCOUNT_IF_NOT_EXISTS to false in config.php
		if (empty($localAccount) && CREATE_ACCOUNT_IF_NOT_EXISTS) {
			$query = $this->db->prepare('INSERT INTO accounts (external_id, admin_email, account_name) VALUES (?, ?, ?)');
			$query->execute(array($externalAccount['identifier'], $externalAccount['config']['admin_email'], $externalAccount['config']['name']));

			// Return expects database query format. Get our new entry from the DB, if we can.
			// Do not allow it to do a lookup, or we can get into infinite recursion.
			$localAccount = $this->getAccountByExternalId($externalAccount['identifier'], $preventApiLookup = true);
			$this->client->log('Created a new account '. $localAccount['account_name'] .' for identifier '. $externalAccount['identifier']);
		}

		return $localAccount;
	}

	/**
	 * Given an "external_id" value, request identifying information from TribeHR and then
	 * best match the result to one of our application's users.
	 * See: mapExternalUserToInternalUser()
	 *
	 * @param string $externalId
	 * @return array              Returns a database row representing one of our application's users, or an empty array if one does not exist
	 */
	public function mapExternalIdToUser($externalId, $account)
	{
		// Request identifying information about the user from TribeHR
		// Currently all we know about the user is the provided identifier
		$tribeUser = $this->client->userLookup($account['external_id'], $externalId);

		if (empty($tribeUser)) {
			$this->client->log('Failed to look up identifying information for user: '. $externalId);
			return false;
		}

		return $this->mapExternalUserToInternalUser($tribeUser, $account);
	}

	/**
	 * Given an array that represents a partner's concept of a user, attempt to match it to one
	 * of our application's own users in the given account. If a match can be found, then save the
	 * mapping to the database into the "external_id" column to prevent the need to do the mapping again.
	 *
	 * If no mapping can be found, create a new user in our application with the information
	 * from the external user (this behaviour can be disabled in the config file, see below).
	 *
	 * @param array $externalUser  Array representing a TribeHR Lookup API user result. Use TribeHRPanelClient::userLookup() or TribeHRPanelClient::bulkUserLookup() to generate
	 * @return array                  Returns a database row representing one of our application's users, or an empty array if one does not exist
	 */
	public function mapExternalUserToInternalUser($externalUser, $account)
	{
		// Try to match the identifying information given by TribeHR to one of our app's users in a given account
		// Looking at the spec and our app, we've determined that there's a match if one of the following is true:
		//  - our account's "username" is the same as TribeHR's "username"
		//  - our account's "email" is the same as TribeHR's "email"
		//  - our account's "first_name" and "last_name" are the same as TribeHR's "first_name" and "last_name"
		$query = $this->db->prepare('
			SELECT * FROM users
			WHERE
				account_id = ? AND
				(
					username = ? OR
					email = ? OR
					(first_name = ? AND last_name = ?)
				)
			LIMIT 1;
			');
		$query->execute(array(
			$account['id'],
			$externalUser['username'],
			$externalUser['email'],
			$externalUser['employee_record']['first_name'],
			$externalUser['employee_record']['last_name']
		));
		$localUser = $query->fetch(PDO::FETCH_ASSOC);

		if (!empty($localUser)) {
			// If we already have this mapping, no need to re-write it
			if ($localUser['external_id'] == $externalUser['identifier']) {
				return $localUser;
			}

			// We have a match between a TribeHR identifier and one of our internal users!
			// Record the TribeHR identifer into our "external_id" column, so that next time we
			// don't have to do the API request while users wait
			$query = $this->db->prepare('UPDATE users SET external_id = ? WHERE id = ?');
			$query->execute(array($externalUser['identifier'], $localUser['id']));

			$this->client->log('Mapped user '. $localUser['username'] .' to identifier '. $externalUser['identifier']);
			$localUser['external_id'] = $externalUser['identifier'];
		}

		// In this sample application, we're being very lenient - if we can't find an existing user,
		// we'll create one on the spot. Your application might not follow this pattern.
		// For debugging or experimentation purposes, you can disable this feature by setting the
		// CREATE_USER_IF_NOT_EXISTS to false in config.php
		if (empty($localUser) && CREATE_USER_IF_NOT_EXISTS) {
			$userCoordinates = $this->generateUserAddress();

			$query = $this->db->prepare('
				INSERT INTO users
					(account_id, external_id, username, email, first_name, last_name, lat, lng)
				VALUES
					(?, ?, ?, ?, ?, ?, ?, ?)
				');
			$query->execute(array(
				$account['id'],
				$externalUser['identifier'],
				$externalUser['username'],
				$externalUser['email'],
				$externalUser['employee_record']['first_name'],
				$externalUser['employee_record']['last_name'],
				$userCoordinates['lat'],
				$userCoordinates['lng']
			));

			// Return expects database query format. Get our new entry from the DB, if we can.
			// Do not allow it to do a lookup, or we can get into infinite recursion.
			$localUser = $this->getUserByExternalId($externalUser['identifier'], $account, $preventApiLookup = true);
			$this->client->log('Created a new user '. $localUser['email'] .' for identifier '. $externalUser['identifier']);
		}

		return $localUser;
	}

	/**
	 * For our simple sample application, since this part most closely resembles actual "application" logic
	 * and doesn't touch the integration at all, it is uninteresting.
	 * So we'll just generate pseudorandom lat and lng values.
	 *
	 * @return array  Array with keys 'lat' and 'lng', representing valid latitude and longitude co-ordinates
	 */
	private function generateUserAddress()
	{
		return array(
			'lat' => (rand(200, 650) / 10),
			'lng' => (rand(-1250, -800) / 10)
			);
	}

	/**
	 * Our sample application supports the concept of a "guest" user for some contexts.
	 * This method is to be used to build an array that looks the same as a database row representing
	 * an actual user, with the values we want for our guest.
	 *
	 * @return array  Array resembling the arrays that represent a user from our application's database
	 */
	public function guestUser()
	{
		return array(
			'id' => 0,
			'external_id' => '',
			'username' => '',
			'email' => '',
			'first_name' => 'guest',
			'last_name' => '',
			'lat' => self::DEFAULT_LAT,
			'lng' => self::DEFAULT_LNG
			);
	}
}
