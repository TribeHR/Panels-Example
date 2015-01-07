# "AddressMapper" Sample TribeHR Panel
This application is an example of how to integrate TribeHR's panels into your app, written in PHP.
It is a simple little tool that shows a map with two connected location pins: one for the person you're looking up, and one for you. It only works through TribeHR panels.

## Specification and Credentials Not Included
In order to build a panel for TribeHR, you will need to contact our partner representatives. They will be able to provide you with our specification documents, your credentials required to connect to our system, and an account on TribeHR configured to work with in-development panels.

## Example Use Only
This sample app was written to be an example for you - as a result, it is constructed in as straightforward a way as possible. Classes are kept to a minimum, preferring inline execution so that you can easily follow it. There are many comments, aiming to explain exactly what is happening and why.

The activation and content request workflows have been built exactly as suggested in the "Example Workflows" section of your specification document. You should be able to follow along with the document and the comments in the code; they use the same headings for your convenience.

We do not suggest that this code is robust or bug free. We've included right in this repo everything you need to run the example (including a useful JWT library), but if you're using it as the foundation for your own application you'll probably want to be very careful.

## Requirements for Running the AddressMapper Code
 - Apache, PHP 5.3+, SQLite (included in PHP 5+)
 - cURL enabled in PHP
 - The 'logs' folder, and files within, must be writable by the Apache user
 - The 'db' folder, and files within, must be readable, writable, and executable by the Apache user

## The Application
### The Configuration File
The file "config.php" is found right in the root directory. Here you'll set up everything you need to actually try running the sample app locally, if you wish.
 - ###### INTEGRATION_ID
 Given to you by TribeHR through our partner process. Enter it as a string.

 - ###### SECRET_SHARED_KEY
 Given to you by TribeHR along with your Integration ID. Enter it as a string.

 - ###### TRIBEHR_LOOKUP_API_ENDPOINT
 The base URL used for Lookup API calls. By default, this points to TribeHR's production environment. For testing, you might want to create a simple web app to accept your queries so that you can look at what the sample app is sending out, or control the different possible requests and responses that TribeHR can generate. If you do, you can change the endpoint here to map to your mock system instead.

 - ###### REQUEST_LOGGING_ENABLED
 If you set this to _true_ instead of the default _false_, the sample app will begin verbosely logging debug information to the file *logs/request.log*. This will help you follow along with each step, but will pile up very quickly.

 - ###### ENFORCE_NONCE
 If set to _false_, the application will still accept requests as valid even when the 'jti' nonce claim in the JWT has been used in the last 12 hours. This is useful when you want to replay a request without having to re-generate a valid JWT each time.

 - ###### CREATE_ACCOUNT_IF_NOT_EXISTS
 By default, if an activation workflow request is made to this sample app from a TribeHR account that doesn't exist in the app, a new local account record will be created. Set this value to _false_ if you would rather the app rejects activation requests by accounts it does not recognize. Setting this to _false_ is useful if your real application will require a valid mapping between accounts.

 - ###### CREATE_USER_IF_NOT_EXISTS
 By default, if the application is given a TribeHR user that it cannot find an equivalent to in the database, a new local user record will be created. Set this value to _false_ if you would rather the app simply treats these as unrecognized users. Setting this to _false_ is useful if your real application will require a valid mapping between users.

### The Database
The database found in the repository is a SQLite 3 database with the following schema, which is assumed by the application:

**accounts**

`id (int, PK, autoincr.), external_id (text), admin_email (text), account_name (text)`

**users**

`id (int, PK, autoincr.), account_id (int), external_id (text), username (text), email (text), first_name (text), last_name (text), lat (real), lng (real)`

**nonces_incoming**

`nonce (text, PK), time (int)`

**nonces_outgoing**

`nonce (text, PK), time (int)`

### The Code
The actual sample application only consists of four code files:
 - ###### app.class.php
 The class representing the "application logic", or our main app.

 - ###### TribeHRPanelClient.class.php
 The class representing an API client for communicating with TribeHR.

 - ###### activation.php
 Represents the specific endpoint used to receive and respond to activation requests.

 - ###### content.php
 Represents the specific endpoint used to receive and respond to content requests.

### A Live Example
If you would like to see the sample application actually up and running in your live TribeHR account, just reach out to your parner help contact and we'll see if we can get you set up on a preview build. You'll get the needed instructions at that time.

## Feedback Welcome
Since this is intended as a useful example for you, our partners, please feel free to pass along any feedback that you have about this application to your help contact. We want the process of building a panel for TribeHR to go as smoothly for you as possible, and if there are more examples that you need to see we'll do our best to accommodate your needs if we can.
