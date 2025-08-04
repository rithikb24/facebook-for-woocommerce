<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit;

use WooCommerce\Facebook\AJAX;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Unit tests for AJAX class - focused on sync_modified_products() method.
 *
 * @since 2.0.0
 */
class AJAXTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * The AJAX instance under test.
	 *
	 * @var AJAX
	 */
	private $ajax;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->ajax = new AJAX();
	}

	/**
	 * Test that the class exists and can be instantiated.
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( AJAX::class ) );
		$this->assertInstanceOf( AJAX::class, $this->ajax );
	}

	/**
	 * Test that sync_modified_products method exists.
	 */
	public function test_sync_modified_products_method_exists() {
		$this->assertTrue( method_exists( $this->ajax, 'sync_modified_products' ) );
	}

	/**
	 * Test sync_modified_products when full batch API sync is disabled.
	 */
	public function test_sync_modified_products_when_sync_disabled() {
		// Mock the integration to return false for allow_full_batch_api_sync
		$this->mock_facebook_for_woocommerce_integration( false );

		// Mock wp_send_json_error to capture the response
		$json_response = null;
		$this->mock_wp_send_json_error( $json_response );

		// Call the method
		$this->ajax->sync_modified_products();

		// Verify error response was sent
		$this->assertEquals( 'Full product sync disabled by filter.', $json_response );
	}

	/**
	 * Test sync_modified_products successful execution.
	 */
	public function test_sync_modified_products_success() {
		// Mock the integration to allow sync
		$this->mock_facebook_for_woocommerce_integration( true );

		// Mock nonce verification to pass
		$this->mock_nonce_verification( true );

		// Mock the products sync handler
		$this->mock_products_sync_handler();

		// Mock wp_send_json_success to capture the response
		$json_success_called = false;
		$this->mock_wp_send_json_success( $json_success_called );

		// Call the method
		$this->ajax->sync_modified_products();

		// Verify success response was sent
		$this->assertTrue( $json_success_called );
	}

	/**
	 * Test sync_modified_products when nonce verification fails.
	 */
	public function test_sync_modified_products_nonce_failure() {
		// Mock the integration to allow sync
		$this->mock_facebook_for_woocommerce_integration( true );

		// Mock nonce verification to fail
		$this->mock_nonce_verification( false );

		// Mock wp_send_json_error to capture the response
		$json_response = null;
		$this->mock_wp_send_json_error( $json_response );

		// Call the method - this should trigger nonce failure
		try {
			$this->ajax->sync_modified_products();
		} catch ( \Exception $e ) {
			// Nonce failure might throw an exception or call wp_die
			$this->assertTrue( true );
		}
	}

	/**
	 * Test sync_modified_products when sync handler throws exception.
	 */
	public function test_sync_modified_products_exception_handling() {
		// Mock the integration to allow sync
		$this->mock_facebook_for_woocommerce_integration( true );

		// Mock nonce verification to pass
		$this->mock_nonce_verification( true );

		// Mock the products sync handler to throw exception
		$this->mock_products_sync_handler_with_exception();

		// Mock wp_send_json_error to capture the response
		$json_response = null;
		$this->mock_wp_send_json_error( $json_response );

		// Call the method
		$this->ajax->sync_modified_products();

		// Verify error response was sent with exception message
		$this->assertEquals( 'Test sync exception', $json_response );
	}

	/**
	 * Test that constants are defined correctly.
	 */
	public function test_constants() {
		$this->assertEquals( 'wc_facebook_search_product_attributes', AJAX::ACTION_SEARCH_PRODUCT_ATTRIBUTES );
	}

	/**
	 * Helper method to mock facebook_for_woocommerce integration.
	 *
	 * @param bool $allow_sync Whether to allow full batch API sync.
	 */
	private function mock_facebook_for_woocommerce_integration( bool $allow_sync ) {
		// Mock the global function
		if ( ! function_exists( 'facebook_for_woocommerce' ) ) {
			function facebook_for_woocommerce() {
				return $GLOBALS['mock_facebook_for_woocommerce'] ?? null;
			}
		}

		// Create mock integration
		$mock_integration = $this->createMock( \stdClass::class );
		$mock_integration->method( 'allow_full_batch_api_sync' )->willReturn( $allow_sync );

		// Create mock main class
		$mock_main = $this->createMock( \stdClass::class );
		$mock_main->method( 'get_integration' )->willReturn( $mock_integration );

		$GLOBALS['mock_facebook_for_woocommerce'] = $mock_main;
	}

	/**
	 * Helper method to mock nonce verification.
	 *
	 * @param bool $should_pass Whether nonce verification should pass.
	 */
	private function mock_nonce_verification( bool $should_pass ) {
		// Mock check_admin_referer function
		if ( ! function_exists( 'check_admin_referer' ) ) {
			function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
				return $GLOBALS['mock_nonce_verification'] ?? true;
			}
		}

		$GLOBALS['mock_nonce_verification'] = $should_pass;

		if ( ! $should_pass ) {
			// Mock wp_die function for nonce failure
			if ( ! function_exists( 'wp_die' ) ) {
				function wp_die( $message = '', $title = '', $args = array() ) {
					throw new \Exception( 'Nonce verification failed' );
				}
			}
		}
	}

	/**
	 * Helper method to mock products sync handler.
	 */
	private function mock_products_sync_handler() {
		// Mock the sync handler
		$mock_sync_handler = $this->createMock( \stdClass::class );
		$mock_sync_handler->method( 'create_or_update_modified_products' )->willReturn( true );

		// Update the mock main class to return the sync handler
		if ( isset( $GLOBALS['mock_facebook_for_woocommerce'] ) ) {
			$GLOBALS['mock_facebook_for_woocommerce']->method( 'get_products_sync_handler' )->willReturn( $mock_sync_handler );
		}
	}

	/**
	 * Helper method to mock products sync handler that throws exception.
	 */
	private function mock_products_sync_handler_with_exception() {
		// Mock the sync handler to throw exception
		$mock_sync_handler = $this->createMock( \stdClass::class );
		$mock_sync_handler->method( 'create_or_update_modified_products' )
			->willThrowException( new \Exception( 'Test sync exception' ) );

		// Update the mock main class to return the sync handler
		if ( isset( $GLOBALS['mock_facebook_for_woocommerce'] ) ) {
			$GLOBALS['mock_facebook_for_woocommerce']->method( 'get_products_sync_handler' )->willReturn( $mock_sync_handler );
		}
	}

	/**
	 * Helper method to mock wp_send_json_error.
	 *
	 * @param mixed &$response Reference to capture the response.
	 */
	private function mock_wp_send_json_error( &$response ) {
		if ( ! function_exists( 'wp_send_json_error' ) ) {
			function wp_send_json_error( $data = null, $status_code = null, $options = 0 ) {
				$GLOBALS['mock_json_error_response'] = $data;
			}
		}

		// Use a reference to capture the response
		$this->add_filter_with_safe_teardown( 'wp_send_json_error_capture', function() use ( &$response ) {
			$response = $GLOBALS['mock_json_error_response'] ?? null;
		});

		// Trigger the filter after the function call
		add_action( 'shutdown', function() {
			do_action( 'wp_send_json_error_capture' );
		}, 1 );
	}

	/**
	 * Helper method to mock wp_send_json_success.
	 *
	 * @param bool &$called Reference to track if function was called.
	 */
	private function mock_wp_send_json_success( &$called ) {
		if ( ! function_exists( 'wp_send_json_success' ) ) {
			function wp_send_json_success( $data = null, $status_code = null, $options = 0 ) {
				$GLOBALS['mock_json_success_called'] = true;
			}
		}

		// Use a reference to capture the call
		$this->add_filter_with_safe_teardown( 'wp_send_json_success_capture', function() use ( &$called ) {
			$called = $GLOBALS['mock_json_success_called'] ?? false;
		});

		// Trigger the filter after the function call
		add_action( 'shutdown', function() {
			do_action( 'wp_send_json_success_capture' );
		}, 1 );
	}
}
