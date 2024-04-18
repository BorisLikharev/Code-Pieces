<?php
/*
 * Plugin Name: License Manager Extension - Activation Verification
 * Description: Enhances the License Manager for WooCommerce by adding customizable and robust activation verification for software license activations.
 * Version: 1.0
 * Author: Boris Likharev
 * Author URI: mailto:boris@boris.la
 *
 * This code enhances the License Manager for WooCommerce by adding customizable and robust activationverification for software license activations. 
 * It introduces a dedicated endpoint in the WordPress REST API, allowing for comprehensive validation of license serial numbers and associated tokens.
 * 
 * This version has been significantly simplified by omitting sensitive elements related to my client's software that were present in the original code.
 *
 * The process involves:
 * 1. Sanitizing and validating serial numbers and tokens received via GET requests.
 * 2. Verifying if the provided serial number is registered in the database, and checking associated customer, product, and order details.
 * 3. Making a secured REST API call to License Manager to validate the token associated with the serial number.
 * 4. Returning a structured response through the REST API that includes the verification result and relevant messages.
 *
 * The REST API Endpoint:
 * - URL: /wp-json/licenses/v1/activation_verify
 * - Method: GET
 * - Parameters: 'serial' (the serial number of the license), 'token' (the activation token to verify)
 * - Permissions: Public access (for demonstration purposes only!)
 *
 * Functions Included:
 * - LicenseXtra_VerifyActivationByToken(): Main function that orchestrates the verification process.
 * - LicenseXtra_TokenVerification(): Handles the communication with the external API to verify the token.
 * - LicenseXtra_RESTCallback_ActivationVerify(): REST API endpoint callback that processes the request and formats the response.
 *
 * Usage:
 * The endpoint can be accessed via a GET request providing 'serial' and 'token' parameters. It responds with the verification status and a message indicating the outcome.
 *
 * Note: This implementation includes hardcoded API keys for demonstration purposes and should be secured in production environments.
 *       Also, take a look at RegExp in LicenseXtra_RESTCallback_ActivationVerify(), you might need to modify it for your serial number format.
 * 
 * @author Boris Likharev
 * @version 1.0
 */

// REST API credentials for the License Manager (these need to be hardcoded here for illustrative purposes)
define('API_KEY', '[--INSERT_HERE--]');
define('API_SEC', '[--INSERT_HERE--]');

function LicenseXtra_VerifyActivation($serial_number, $token) {
    // Sanitize input parameters
    $sanitized_serial_number = preg_replace('/[^a-zA-Z0-9\-_]/', '', $serial_number);
    $sanitized_token = preg_replace('/[^a-zA-Z0-9\-_]/', '', $token);
    
    // Retrieve the license information
    $license = lmfwc_get_license($sanitized_serial_number);
    if (!$license) {
        return ['result' => false, 'message' => 'Not valid! Serial number is not in the database: ' . $sanitized_serial_number];
    }

    // Check if the serial number is assigned to a customer, a product, and an order
    if ($license->getUserId() === null) {
        return ['result' => false, 'message' => 'Not valid! Serial number is not assigned to any customer!'];
    }
    if ($license->getProductId() === null) {
        return ['result' => false, 'message' => 'Not valid! Serial number is not assigned to any product!'];
    }
    if ($license->getOrderId() === null) {
        return ['result' => false, 'message' => 'Not valid! Serial number is not assigned to any order!'];
    }

    // Perform token validation via REST API and return result
    return LicenseXtra_TokenVerification($sanitized_serial_number, $sanitized_token);
}

function LicenseXtra_TokenVerification($sanitized_serial_number, $sanitized_token) {

    // Prepare the request URL and headers
    $url = rest_url('lmfwc/v2/licenses/validate/' . $sanitized_serial_number);
    $args = array(
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode(API_KEY . ':' . API_SEC)
        ),
        'method'  => 'GET'
    );

    // Perform the REST request
    // Sticking with http call for safety, however there is a hackish way to do internal one by changing users, which is cheaper, but less secure :-(
    $response = wp_remote_request($url, $args);

    // Check if there was an error in the HTTP request
    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        return ['result' => false, 'message' => "HTTP Request Error: " . $error_message];
    }

    // Decode the response body and check the data
    $data = json_decode(wp_remote_retrieve_body($response), true);

    // Check if the response data contains activation data and it's an array
    if (!isset($data['data']['activationData']) || !is_array($data['data']['activationData'])) {
        return ['result' => false, 'message' => 'No activation data available!'];
    }

    // Loop through each activation to find a match for the provided token
    foreach ($data['data']['activationData'] as $activation) {
        if (strcasecmp($activation['token'], $sanitized_token) === 0) {
            return ['result' => true, 'message' => (string) $activation['token']];
        }
    }

    return ['result' => false, 'message' => 'No activation data found for the provided token!'];
}

function LicenseXtra_RESTCallback_ActivationVerify($request) {
    $serial = $request['serial'];

    // Check serial number format 
    // (You might need to adjust regexp here, currently it's set for alphanumerical XXXXX-XXXXX-XXXXX-XXXXX-XXXXX-XXXXX without 'I' and 'O')
    if (preg_match('/^([A-HJ-NP-Z0-9]{5}-){5}[A-HJ-NP-Z0-9]{5}$/i', $serial) === 0) {
        return new WP_REST_Response(array(
            'message' => 'Incorrect serial format: ' . $serial,
            'response' => 'invalid'
        ), 200);
    }

    $token = $request['token'];

    // Check token format (regexp set for License Manager's tokens)
    if (preg_match('/^[a-f0-9]{40}$/i', $token) === 0) {
        return new WP_REST_Response(array(
            'message' => 'Incorrect token format: ' . $token,
            'response' => 'invalid'
        ), 200);
    }

    // Validating the serial and token
    $validated_license = LicenseXtra_VerifyActivation($serial, $token);
    if ($validated_license['result'] === false) {
        return new WP_REST_Response(array(
            'message' => $validated_license['message'],
            'response' => 'invalid'
        ), 200);
    }

    // If both serial and token are valid
    return new WP_REST_Response(array(
        'message' => 'Both serial and token are still valid and registered! All good!',
        'response' => 'Activated',
    ), 200);
}

add_action( 'rest_api_init', function () {
    // Registering the REST API route
    register_rest_route( 'licenses/v1', '/activation_verify', array(
        'methods' => 'GET', // The request method
        'callback' => 'LicenseXtra_RESTCallback_ActivationVerify',
        'permission_callback' => function () {
			// No permission checks needed for this demonstration, allow access to anyone :-)
            return true;
        }
    ));
});
