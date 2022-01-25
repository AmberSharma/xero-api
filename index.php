<?php
/**
 * Created by PhpStorm.
 * User: amber
 * Date: 4/10/20
 * Time: 12:02 AM
 */

use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;

const TABLE_NAME = "contacts_test";
const SERVER_NAME = "localhost";
const DATABASE = "xero";
const USER = "root";
const PWD = "root";
const XERO_TOKEN = [
    "ORG" => "org",
    "ACCESS_TOKEN" => "access_token",
    "REFRESH_TOKEN" => "refresh_token",
    "EXPIRES" => "expires",
    "TENANT_ID" => "tenant_id"
];

require __DIR__ . '/vendor/autoload.php';
session_start();

$authorizeUrl = 'https://login.xero.com/identity/connect/authorize';
$tokenUrl = 'https://identity.xero.com/connect/token';
$orgUrl = 'https://api.xero.com/api.xro/2.0/Organization';
$connectionUrl = 'https://api.xero.com/Connections';

//$clientId = "5C55CCAA07B04831AD7F831C8212A84C";
//$clientSecret = "-0pN6bekmdgTHG493gQH-WlXDpJVmWWyiJOK6COdb0jnB2Vm";

$clientId = "CB2DD9BBF8C64D218EB0CD3602A80FF2";
$clientSecret = "j3Y9tMChtYds5BFVWgfnBx5kVef4c9XYFp1wi7YTXnlZK-aB";
$redirectUri = 'http://localhost/xero-api/index.php';
//$redirectUri = 'https://sga.swifttrack.co.uk/xero-redirect';

$provider = new \League\OAuth2\Client\Provider\GenericProvider([
    'clientId' => $clientId,
    'clientSecret' => $clientSecret,
    'redirectUri' => $redirectUri,
    'urlAuthorize' => $authorizeUrl,
    'urlAccessToken' => $tokenUrl,
    'urlResourceOwnerDetails' => $orgUrl
]);

/** @var AccessTokenInterface $token */
$token = "";

$conn = dbConnect();
$getTokenIfExists = getTokenIfExists();

if(empty($getTokenIfExists)) {
    // If we don't have an authorization code then get one
    if (!isset($_GET['code'])) {
        unset($_SESSION["token"]);
        $authorizationUrl = authorizeClient($provider);

        // Redirect the user to the authorization URL.
        header('Location: ' . $authorizationUrl);
        exit();
    } elseif (empty($_GET['state']) || ($_GET['state'] !== $_SESSION['oauth2state'])) {
        unset($_SESSION['oauth2state']);
        exit('Invalid state');
    } else {
        try {

            $accessToken = getToken($provider);
            $xeroTenantIdArray = checkConnection($provider, $accessToken, $connectionUrl);
            setXeroToken(array(
                XERO_TOKEN["ORG"] => $xeroTenantIdArray[0]['tenantName'],
                XERO_TOKEN["ACCESS_TOKEN"] => $accessToken->getToken(),
                XERO_TOKEN["REFRESH_TOKEN"] => $accessToken->getRefreshToken(),
                XERO_TOKEN["EXPIRES"] => $accessToken->getExpires(),
                XERO_TOKEN["TENANT_ID"] => $xeroTenantIdArray[0]['tenantId']

            ));
            $sql = getSqls($provider, $accessToken, $xeroTenantIdArray[0]['tenantId']);
            $conn = dbConnect();
            foreach ($sql as $key => $value) {
                if (mysqli_query($conn, $value)) {
                    echo "Record Updated";
                } else {
                    echo "Error";
                }
            }
        } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
            exit($e->getMessage());
        }
    }
} else {
    try {
        $accessToken = new AccessToken(array(
            "access_token" => $getTokenIfExists[XERO_TOKEN['ACCESS_TOKEN']],
            "refresh_token" => $getTokenIfExists[XERO_TOKEN["REFRESH_TOKEN"]],
            "expires" => $getTokenIfExists[XERO_TOKEN["EXPIRES"]],
        ));

        $accessToken = getToken($provider, $accessToken);
        updateXeroToken(array(
            XERO_TOKEN["ACCESS_TOKEN"] => $accessToken->getToken(),
            XERO_TOKEN["REFRESH_TOKEN"] => $accessToken->getRefreshToken(),
            XERO_TOKEN["EXPIRES"] => $accessToken->getExpires()
        ),
            array(
                XERO_TOKEN["TENANT_ID"] => $getTokenIfExists[XERO_TOKEN['TENANT_ID']]
            )
        );
        $sql = getSqls($provider, $accessToken, $getTokenIfExists[XERO_TOKEN['TENANT_ID']]);
        $conn = dbConnect();
        foreach ($sql as $key => $value) {
            if (mysqli_query($conn, $value)) {
                echo "Record Updated";
            } else {
                echo "Error";
            }
        }
    } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
        exit($e->getMessage());
    }
}

/**
 * @param GenericProvider $provider
 * @return mixed
 */
function authorizeClient($provider) {
    $options = [
        'scope' => ['openid email profile offline_access accounting.settings accounting.transactions accounting.contacts accounting.journals.read accounting.reports.read accounting.attachments']
    ];

    // Fetch the authorization URL from the provider; this returns the
    // urlAuthorize option and generates and applies any necessary parameters (e.g. state).
    $authorizationUrl = $provider->getAuthorizationUrl($options);


    // Get the state generated for you and store it to the session.
    $_SESSION['oauth2state'] = $provider->getState();

    return $authorizationUrl;
}

/**
 * @param GenericProvider $provider
 * @param string|AccessTokenInterface $accessToken
 * @throws IdentityProviderException
 * @return AccessTokenInterface
 */
function getToken($provider, $accessToken = "") {
    if($accessToken instanceof AccessTokenInterface) {
        if($accessToken->hasExpired()) {
            $accessToken = $provider->getAccessToken('refresh_token', [
                'refresh_token' =>$accessToken->getRefreshToken(),
            ]);

        }
    } else {
        // Try to get an access token using the authorization code grant.
        $accessToken = $provider->getAccessToken('authorization_code', [
            'code' => $_GET['code']
        ]);
    }

    return $accessToken;
}

/**
 * @param GenericProvider $provider
 * @param AccessTokenInterface $accessToken
 * @param $connectionUrl
 * @throws IdentityProviderException
 * @return mixed
 */
function checkConnection($provider, $accessToken, $connectionUrl) {
    $options['headers']['Accept'] = 'application/json';

    $connectionsResponse = $provider->getAuthenticatedRequest(
        'GET',
        $connectionUrl,
        $accessToken->getToken(),
        $options
    );

    return $provider->getParsedResponse($connectionsResponse);
}

/**
 * @param GenericProvider $provider
 * @param AccessTokenInterface $accessToken
 * @param $options
 * @throws IdentityProviderException
 * @return mixed
 */
function getContacts($provider, $accessToken, $options) {
    $request = $provider->getAuthenticatedRequest(
        'GET',
        'https://api.xero.com/api.xro/2.0/Contacts',
        $accessToken,
        $options
    );
    return $provider->getParsedResponse($request);
}

/**
 * @param GenericProvider $provider
 * @param AccessTokenInterface $accessToken
 * @param $options
 * @param $where
 * @param $orderBy
 * @throws IdentityProviderException
 * @return mixed
 */
function getInvoice($provider, $accessToken, $options, $where = [], $orderBy = "") {
    $url = "https://api.xero.com/api.xro/2.0/Invoices?".implode(" and ", $where).$orderBy;
    $request = $provider->getAuthenticatedRequest(
        'GET',
        $url,
        $accessToken,
        $options
    );
    return $provider->getParsedResponse($request);
}

/**
 * @param $provider
 * @param $accessToken
 * @param $tenantId
 * @return array
 * @throws IdentityProviderException
 */
function getSqls($provider, $accessToken, $tenantId) {
    $options['headers']['xero-tenant-id'] = $tenantId;
    $options['headers']['Accept'] = 'application/json';
    $contacts = getContacts($provider, $accessToken, $options);
    $queryData = [];
    $contactData = [];
    $sql = [];
    $count = 0;
    foreach ( $contacts["Contacts"] as $key1 => $value1) {
        if(isset($value1["AccountNumber"])) {
            $count ++;
            $contactIdArr[] = $value1["ContactID"];
            $contactData[$value1["ContactID"]] = $value1["AccountNumber"];
            if($count == 20 || $key1 == (count($contacts["Contacts"]) - 1)) {
                $count = 0;
                $contactIDs = implode(",", $contactIdArr);
                $where = array(
                    "ContactIDs={$contactIDs}",
                );
                $orderBy = "&order=DueDateString DESC";
                $invoice = getInvoice($provider, $accessToken, $options, $where, $orderBy);
                if (!empty($invoice["Invoices"])) {
                    foreach ($invoice["Invoices"] as $key2 => $value2) {
                        if (!isset($queryData[$contactData[$value2["Contact"]["ContactID"]]])) {
                            $queryData[$contactData[$value2["Contact"]["ContactID"]]] = 1;
                            $sql[] = "UPDATE ".TABLE_NAME." SET last_inv_amount='".$value2["Total"]
                                ."', last_inv_date='".$value2["DateString"]
                                ."', last_inv_no='".$value2["InvoiceNumber"]
                                ."', last_inv_owed='".$value2["AmountDue"]."' WHERE account_no ='".$contactData[$value2["Contact"]["ContactID"]]."'";
                        }
                    }
                }
                unset($contactIdArr);
                unset($contactData);
            }
        }

    }

    return $sql;
}


function dbConnect() {
    $conn = mysqli_connect(SERVER_NAME, USER,PWD, DATABASE);
    if($conn->connect_error) {
        die("Connection Failed");
    }

    return $conn;
}

/**
 * @param string $org
 * @return array|null
 */
function getTokenIfExists($org = "") {
    $conn = dbConnect();
    $sql = "SELECT * from xero_token";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return null;

}

/**
 * @param array $args
 */
function setXeroToken($args = []) {
    $conn = dbConnect();
    $sql = "INSERT INTO xero_token(`".implode("`,`", array_keys($args))."`) values ('".implode("','", array_values($args))."')";
    $conn->query($sql);
}

function updateXeroToken($args = [], $where) {
    $conn = dbConnect();
    $sql = "UPDATE xero_token SET ";
    foreach($args as $key=>$value) {
        $sql .= $key . " = '" . $value . "', ";
    }
    $sql = trim($sql, ' ');
    $sql = trim($sql, ',');
    $sql .= " WHERE ". XERO_TOKEN["TENANT_ID"] . " = '".$where[XERO_TOKEN["TENANT_ID"]]."'";
    $conn->query($sql);
}