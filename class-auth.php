<?php
/**
 * Setup JWT-Auth.
 *
 * @package jwt-auth
 */

namespace JWTAuth;

use Exception;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use Firebase\JWT\JWT;

/**
 * The public-facing functionality of the plugin.
 */
class Auth {
	/**
	 * The namespace to add to the api calls.
	 *
	 * @var string The namespace to add to the api call
	 */
	private $namespace;

	/**
	 * Store errors to display if the JWT is wrong
	 *
	 * @var WP_Error
	 */
	private $jwt_error = null;

	/**
	 * Setup action & filter hooks.
	 */
	public function __construct() {
		$this->namespace = 'jwt-auth/v1';
	}

	/**
	 * Add the endpoints to the API
	 */
	public function register_rest_routes() {
		register_rest_route(
			$this->namespace,
			'token',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'get_token' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'token/validate',
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'validate_token' ),
			)
		);
	}

	/**
	 * Add CORs suppot to the request.
	 */
	public function add_cors_support() {
		$enable_cors = defined( 'JWT_AUTH_CORS_ENABLE' ) ? JWT_AUTH_CORS_ENABLE : false;

		if ( $enable_cors ) {
			$headers = apply_filters( 'jwt_auth_cors_allow_headers', 'Access-Control-Allow-Headers, Content-Type, Authorization' );

			header( sprintf( 'Access-Control-Allow-Headers: %s', $headers ) );
		}
	}

	/**
	 * Authenticate user either via wp_authenticate or custom auth (e.g: OTP).
	 *
	 * @param string $username The username.
	 * @param string $password The password.
	 * @param mixed  $custom_auth The custom auth data (if any).
	 *
	 * @return WP_User|WP_Error $user Returns WP_User object if success, or WP_Error if failed.
	 */
	public function authenticate_user( $username, $password, $custom_auth = '' ) {
		// If using custom authentication.
		if ( $custom_auth ) {
			$custom_auth_error = new WP_Error( 'jwt_auth_custom_auth_failed', __( 'Custom authentication failed.', 'jwt-auth' ) );

			/**
			 * Do your own custom authentication and return the result through this filter.
			 * It should return either WP_User or WP_Error.
			 */
			$user = apply_filters( 'jwt_auth_do_custom_auth', $custom_auth_error, $username, $password, $custom_auth );
		} else {
			$user = wp_authenticate( $username, $password );
		}

		return $user;
	}

	/**
	 * Get token by sending POST request to jwt-auth/v1/token.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response The response.
	 */
	public function get_token( WP_REST_Request $request ) {
		$secret_key = defined( 'JWT_AUTH_SECRET_KEY' ) ? JWT_AUTH_SECRET_KEY : false;

		$username    = $request->get_param( 'username' );
		$password    = $request->get_param( 'password' );
		$custom_auth = $request->get_param( 'custom_auth' );

		// First thing, check the secret key if not exist return a error.
		if ( ! $secret_key ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'statusCode' => 403,
					'code'       => 'jwt_auth_bad_config',
					'message'    => __( 'JWT is not configurated properly.', 'jwt-auth' ),
					'data'       => array(),
				)
			);
		}

		$user = $this->authenticate_user( $username, $password, $custom_auth );

		// If the authentication is failed return error response.
		if ( is_wp_error( $user ) ) {
			$error_code = $user->get_error_code();

			return new WP_REST_Response(
				array(
					'success'    => false,
					'statusCode' => 403,
					'code'       => $error_code,
					'message'    => $user->get_error_message( $error_code ),
					'data'       => array(),
				)
			);
		}

		// Valid credentials, the user exists, let's generate the token.
		return $this->generate_token( $user, false );
	}

	/**
	 * Generate token
	 *
	 * @param WP_User $user The WP_User object.
	 * @param bool    $return_raw Whether or not to return as raw token string.
	 *
	 * @return WP_REST_Response|string Return as raw token string or as a formatted WP_REST_Response.
	 */
	public function generate_token( $user, $return_raw = true ) {
		$issued_at  = time();
		$not_before = $issued_at;
		$not_before = apply_filters( 'jwt_auth_not_before', $not_before, $issued_at );
		$expire     = $issued_at + ( DAY_IN_SECONDS * 7 );
		$expire     = apply_filters( 'jwt_auth_expire', $expire, $issued_at );

		$payload = array(
			'iss'  => get_bloginfo( 'url' ),
			'iat'  => $issued_at,
			'nbf'  => $not_before,
			'exp'  => $expire,
			'data' => array(
				'user' => array(
					'id' => $user->ID,
				),
			),
		);

		// Let the user modify the token data before the sign.
		$token = JWT::encode( apply_filters( 'jwt_auth_token_payload', $payload, $user ), $secret_key );

		// If return as raw token string.
		if ( $return_raw ) {
			return $token;
		}

		// The token is signed, now create object with basic info of the user.
		$response = array(
			'success'    => true,
			'statusCode' => 200,
			'code'       => 'jwt_auth_valid_credential',
			'message'    => __( 'Credential is valid', 'jwt-auth' ),
			'data'       => array(
				'token'       => $token,
				'id'          => $user->ID,
				'email'       => $user->user_email,
				'nicename'    => $user->user_nicename,
				'firstName'   => $user->first_name,
				'lastName'    => $user->last_name,
				'displayName' => $user->display_name,
			),
		);

		// Let the user modify the data before send it back.
		return apply_filters( 'jwt_auth_token_response', $response, $user );
	}

	/**
	 * Determine if given response is an error response.
	 *
	 * @param mixed $response The response.
	 * @return boolean
	 */
	public function is_error_response( $response ) {
		if ( is_array( $response->data ) ) {
			if ( ! isset( $response->data['success'] ) || ! $response->data['success'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Main validation function, this function try to get the Autentication
	 * headers and decoded.
	 *
	 * @param bool $output Whether to only return the payload or not.
	 *
	 * @return WP_REST_Response | Array Returns WP_REST_Response or token's $payload.
	 */
	public function validate_token( $output = true ) {
		/**
		 * Looking for the HTTP_AUTHORIZATION header, if not present just
		 * return the user.
		 */
		$auth = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : false;

		// Double check for different auth header string (server dependent).
		if ( ! $auth ) {
			$auth = isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] : false;
		}

		if ( ! $auth ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'statusCode' => 403,
					'code'       => 'jwt_auth_no_auth_header',
					'message'    => __( 'Authorization header not found.', 'jwt-auth' ),
					'data'       => array(),
				)
			);
		}

		/**
		 * The HTTP_AUTHORIZATION is present, verify the format.
		 * If the format is wrong return the user.
		 */
		list($token) = sscanf( $auth, 'Bearer %s' );

		if ( ! $token ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'statusCode' => 403,
					'code'       => 'jwt_auth_bad_auth_header',
					'message'    => __( 'Authorization header malformed.', 'jwt-auth' ),
					'data'       => array(),
				)
			);
		}

		// Get the Secret Key.
		$secret_key = defined( 'JWT_AUTH_SECRET_KEY' ) ? JWT_AUTH_SECRET_KEY : false;

		if ( ! $secret_key ) {
			return new WP_REST_Response(
				array(
					'success'    => false,
					'statusCode' => 403,
					'code'       => 'jwt_auth_bad_config',
					'message'    => __( 'JWT is not configurated properly.', 'jwt-auth' ),
					'data'       => array(),
				)
			);
		}

		// Try to decode the token.
		try {
			$payload = JWT::decode( $token, $secret_key, array( 'HS256' ) );

			// The Token is decoded now validate the iss.
			if ( $payload->iss !== get_bloginfo( 'url' ) ) {
				// The iss do not match, return error.
				return new WP_REST_Response(
					array(
						'success'    => false,
						'statusCode' => 403,
						'code'       => 'jwt_auth_bad_iss',
						'message'    => __( 'The iss do not match with this server.', 'jwt-auth' ),
						'data'       => array(),
					)
				);
			}

			// So far so good, validate the user id in the token.
			if ( ! isset( $payload->data->user->id ) ) {
				// No user id in the token, abort!!
				return new WP_REST_Response(
					array(
						'success'    => false,
						'statusCode' => 403,
						'code'       => 'jwt_auth_bad_request',
						'message'    => __( 'User ID not found in the token.', 'jwt-auth' ),
						'data'       => array(),
					)
				);
			}

			// Everything looks good return the token if $output is set to false.
			if ( ! $output ) {
				return $payload;
			}

			// Otherwise, then return success response.
			return new WP_REST_Response(
				array(
					'success'    => true,
					'statusCode' => 200,
					'code'       => 'jwt_auth_valid_token',
					'message'    => __( 'Token is valid', 'jwt-auth' ),
					'data'       => array(),
				)
			);
		} catch ( Exception $e ) {
			// Something is wrong when trying to decode the token, return error response.
			return new WP_REST_Response(
				array(
					'success'    => false,
					'statusCode' => 403,
					'code'       => 'jwt_auth_invalid_token',
					'message'    => $e->getMessage(),
					'data'       => array(),
				)
			);
		}
	}

	/**
	 * This is our Middleware to try to authenticate the user according to the token sent.
	 *
	 * @param int|bool $user_id User ID if one has been determined, false otherwise.
	 * @return int|bool User ID if one has been determined, false otherwise.
	 */
	public function determine_current_user( $user_id ) {
		/**
		 * This hook only should run on the REST API requests to determine
		 * if the user in the Token (if any) is valid, for any other
		 * normal call ex. wp-admin/.* return the user.
		 *
		 * @since 1.2.3
		 */
		$rest_api_slug = rest_get_url_prefix();

		$valid_api_uri = strpos( $_SERVER['REQUEST_URI'], $rest_api_slug );

		if ( ! $valid_api_uri ) {
			return $user_id;
		}

		/**
		 * If the request URI is for validate the token don't do anything,
		 * this avoid double calls to the validate_token function.
		 */
		$validate_uri = strpos( $_SERVER['REQUEST_URI'], 'token/validate' );

		if ( $validate_uri > 0 ) {
			return $user_id;
		}

		$payload = $this->validate_token( false );

		// If $payload is an error response, then return the default $user_id.
		if ( $this->is_error_response( $payload ) ) {
			if ( 'jwt_auth_no_auth_header' === $payload->data['code'] ||
				'jwt_auth_bad_auth_header' === $payload->data['code']
			) {
				if (
					'/wp-json/jwt-auth/v1/token' !== $_SERVER['REQUEST_URI']
				) {
					$this->jwt_error = $payload;
				}
			} else {
				$this->jwt_error = $payload;
			}

			return $user_id;
		}

		// Everything is ok here, return the user ID stored in the token.
		return $payload->data->user->id;
	}

	/**
	 * Filter to hook the rest_pre_dispatch, if there is an error in the request
	 * send it, if there is no error just continue with the current request.
	 *
	 * @param mixed           $result Can be anything a normal endpoint can return, or null to not hijack the request.
	 * @param WP_REST_Server  $server Server instance.
	 * @param WP_REST_Request $request The request.
	 *
	 * @return mixed $result
	 */
	public function rest_pre_dispatch( $result, WP_REST_Server $server, WP_REST_Request $request ) {
		if ( $this->is_error_response( $this->jwt_error ) ) {
			return $this->jwt_error;
		}

		if ( empty( $result ) ) {
			return $result;
		}

		return $result;
	}
}
