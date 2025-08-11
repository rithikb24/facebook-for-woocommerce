<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Products\Sync;

use WooCommerce\Facebook\Products\Sync;
use WooCommerce\Facebook\Products\Sync\Background;
use WooCommerce\Facebook\Framework\Logger;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Unit tests for Background sync timestamp functionality.
 *
 * These tests verify that the timestamp functionality correctly updates product sync timestamps
 * after successful API calls, handles errors gracefully, and maintains data integrity.
 *
 * @since 3.5.5
 */
class BackgroundTimestampTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * The testable Background instance under test.
	 *
	 * @var TestableBackground
	 */
	private $background;

	/**
	 * Mock job object for testing.
	 *
	 * @var \stdClass
	 */
	private $mock_job;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Mock WordPress functions
		$this->mock_wordpress_functions();

		// Create testable background instance
		$this->background = new TestableBackground();

		// Create mock job
		$this->mock_job = new \stdClass();
		$this->mock_job->id = 'test-job-123';
		$this->mock_job->status = 'processing';
		$this->mock_job->progress = 0;
		$this->mock_job->total = 0;
		$this->mock_job->handles = array();
	}

	/**
	 * Test that timestamps are updated after successful API call for UPDATE requests.
	 *
	 * This test verifies the core functionality: when a product sync UPDATE request
	 * is successfully sent to Facebook, the product's last sync timestamp is updated.
	 */
	public function test_timestamps_updated_after_successful_update_request() {
		// Arrange: Set up test data with UPDATE requests
		$test_data = array(
			'p-123' => Sync::ACTION_UPDATE,
			'p-456' => Sync::ACTION_UPDATE,
		);

		$mock_requests = array(
			array(
				'method' => Sync::ACTION_UPDATE,
				'data' => array( 'id' => 'wc_post_id_123' ),
			),
			array(
				'method' => Sync::ACTION_UPDATE,
				'data' => array( 'id' => 'wc_post_id_456' ),
			),
		);

		// Configure background to return successful API response
		$this->background->set_api_response_handles( array( 'handle1', 'handle2' ) );
		$this->background->set_mock_requests( $mock_requests );

		// Act: Process the items using the REAL process_items method
		$this->background->process_items( $this->mock_job, $test_data );

		// Assert: Verify timestamps were updated for both products
		$updated_timestamps = $this->background->get_updated_timestamps();
		$this->assertCount( 2, $updated_timestamps );
		$this->assertArrayHasKey( 123, $updated_timestamps );
		$this->assertArrayHasKey( 456, $updated_timestamps );
		$this->assertIsInt( $updated_timestamps[123] );
		$this->assertIsInt( $updated_timestamps[456] );
		$this->assertGreaterThan( 0, $updated_timestamps[123] );
		$this->assertGreaterThan( 0, $updated_timestamps[456] );
	}

	/**
	 * Test that timestamps are NOT updated for DELETE requests.
	 *
	 * This test ensures that only UPDATE requests trigger timestamp updates,
	 * as DELETE requests don't need to track sync times for removed products.
	 */
	public function test_timestamps_not_updated_for_delete_requests() {
		// Arrange: Set up test data with DELETE requests
		$test_data = array(
			'p-123' => Sync::ACTION_DELETE,
			'p-456' => Sync::ACTION_DELETE,
		);

		$mock_requests = array(
			array(
				'method' => Sync::ACTION_DELETE,
				'data' => array( 'id' => 'wc_post_id_123' ),
			),
			array(
				'method' => Sync::ACTION_DELETE,
				'data' => array( 'id' => 'wc_post_id_456' ),
			),
		);

		// Configure background to return successful API response
		$this->background->set_api_response_handles( array( 'handle1', 'handle2' ) );
		$this->background->set_mock_requests( $mock_requests );

		// Act: Process the items using the REAL process_items method
		$this->background->process_items( $this->mock_job, $test_data );

		// Assert: Verify no timestamps were updated
		$updated_timestamps = $this->background->get_updated_timestamps();
		$this->assertEmpty( $updated_timestamps );
	}

	/**
	 * Test that timestamp update errors are handled gracefully without interrupting sync.
	 *
	 * This test verifies that if updating timestamps fails (e.g., database error),
	 * the error is logged but doesn't prevent the sync process from completing.
	 */
	public function test_timestamp_update_errors_handled_gracefully() {
		// Arrange: Set up test data
		$test_data = array(
			'p-123' => Sync::ACTION_UPDATE,
		);

		$mock_requests = array(
			array(
				'method' => Sync::ACTION_UPDATE,
				'data' => array( 'id' => 'wc_post_id_123' ),
			),
		);

		// Configure background to simulate timestamp update failure
		$this->background->set_api_response_handles( array( 'handle1' ) );
		$this->background->set_mock_requests( $mock_requests );
		$this->background->set_timestamp_update_should_fail( true );

		// Act: Process the items using the REAL process_items method (should not throw exception)
		$this->background->process_items( $this->mock_job, $test_data );

		// Assert: Verify error was logged but process continued
		$logged_errors = $this->background->get_logged_errors();
		$this->assertCount( 1, $logged_errors );
		$this->assertStringContains( 'Error updating product sync timestamps', $logged_errors[0]['message'] );
		$this->assertEquals( 'product_sync_timestamp_update_error', $logged_errors[0]['context']['event'] );

		// Verify job was still updated with handles (sync process continued)
		$this->assertEquals( array( 'handle1' ), $this->mock_job->handles );
	}

	/**
	 * Test that timestamps are updated only for valid product IDs extracted from retailer_id.
	 *
	 * This test focuses specifically on the regex pattern matching and product ID validation
	 * in the timestamp update logic.
	 */
	public function test_timestamp_update_with_valid_product_id_extraction() {
		// Arrange: Set up test data with various retailer_id formats
		$test_data = array(
			'p-123' => Sync::ACTION_UPDATE,
		);

		$mock_requests = array(
			array(
				'method' => Sync::ACTION_UPDATE,
				'data' => array( 'id' => 'wc_post_id_789' ), // Valid format
			),
		);

		// Configure background to return successful API response
		$this->background->set_api_response_handles( array( 'handle1' ) );
		$this->background->set_mock_requests( $mock_requests );

		// Act: Process the items
		$this->background->process_items( $this->mock_job, $test_data );

		// Assert: Verify timestamp was updated for the extracted product ID (789)
		$updated_timestamps = $this->background->get_updated_timestamps();
		$this->assertCount( 1, $updated_timestamps );
		$this->assertArrayHasKey( 789, $updated_timestamps );
		$this->assertIsInt( $updated_timestamps[789] );
		$this->assertGreaterThan( 0, $updated_timestamps[789] );
	}

	/**
	 * Test that timestamps are NOT updated for invalid retailer_id formats.
	 *
	 * This test verifies that the regex pattern '/wc_post_id_(\d+)/' correctly
	 * rejects invalid formats and prevents timestamp updates.
	 */
	public function test_timestamp_not_updated_for_invalid_retailer_id_formats() {
		// Arrange: Set up test data with invalid retailer_id formats
		$test_data = array(
			'p-123' => Sync::ACTION_UPDATE,
			'p-456' => Sync::ACTION_UPDATE,
			'p-789' => Sync::ACTION_UPDATE,
		);

		$mock_requests = array(
			array(
				'method' => Sync::ACTION_UPDATE,
				'data' => array( 'id' => 'invalid_format_123' ), // Invalid format
			),
			array(
				'method' => Sync::ACTION_UPDATE,
				'data' => array( 'id' => 'wc_post_id_abc' ), // Non-numeric ID
			),
			array(
				'method' => Sync::ACTION_UPDATE,
				'data' => array( 'id' => 'wc_post_id_' ), // Missing ID
			),
		);

		// Configure background to return successful API response
		$this->background->set_api_response_handles( array( 'handle1', 'handle2', 'handle3' ) );
		$this->background->set_mock_requests( $mock_requests );

		// Act: Process the items
		$this->background->process_items( $this->mock_job, $test_data );

		// Assert: Verify no timestamps were updated due to invalid formats
		$updated_timestamps = $this->background->get_updated_timestamps();
		$this->assertEmpty( $updated_timestamps );
	}

	/**
	 * Test that timestamps are NOT updated for zero or negative product IDs.
	 *
	 * This test verifies the product ID validation logic that checks if product_id > 0.
	 */
	public function test_timestamp_not_updated_for_zero_or_negative_product_ids() {
		// Arrange: Set up test data with zero and negative product IDs
		$test_data = array(
			'p-123' => Sync::ACTION_UPDATE,
			'p-456' => Sync::ACTION_UPDATE,
		);

		$mock_requests = array(
			array(
				'method' => Sync::ACTION_UPDATE,
				'data' => array( 'id' => 'wc_post_id_0' ), // Zero ID
			),
			array(
				'method' => Sync::ACTION_UPDATE,
				'data' => array( 'id' => 'wc_post_id_-5' ), // Negative ID (though regex won't match)
			),
		);

		// Configure background to return successful API response
		$this->background->set_api_response_handles( array( 'handle1', 'handle2' ) );
		$this->background->set_mock_requests( $mock_requests );

		// Act: Process the items
		$this->background->process_items( $this->mock_job, $test_data );

		// Assert: Verify no timestamps were updated
		$updated_timestamps = $this->background->get_updated_timestamps();
		$this->assertEmpty( $updated_timestamps );
	}

	/**
	 * Test that timestamps are NOT updated when request data is missing 'id' field.
	 *
	 * This test verifies the isset() and !empty() checks for request['data']['id'].
	 */
	public function test_timestamp_not_updated_when_id_field_missing() {
		// Arrange: Set up test data with missing or empty ID fields
		$test_data = array(
			'p-123' => Sync::ACTION_UPDATE,
			'p-456' => Sync::ACTION_UPDATE,
			'p-789' => Sync::ACTION_UPDATE,
		);

		$mock_requests = array(
			array(
				'method' => Sync::ACTION_UPDATE,
				'data' => array(), // Missing 'id' field
			),
			array(
				'method' => Sync::ACTION_UPDATE,
				'data' => array( 'id' => '' ), // Empty 'id' field
			),
			array(
				'method' => Sync::ACTION_UPDATE,
				'data' => array( 'id' => null ), // Null 'id' field
			),
		);

		// Configure background to return successful API response
		$this->background->set_api_response_handles( array( 'handle1', 'handle2', 'handle3' ) );
		$this->background->set_mock_requests( $mock_requests );

		// Act: Process the items
		$this->background->process_items( $this->mock_job, $test_data );

		// Assert: Verify no timestamps were updated
		$updated_timestamps = $this->background->get_updated_timestamps();
		$this->assertEmpty( $updated_timestamps );
	}

	/**
	 * Test that timestamps are updated for mixed valid and invalid requests.
	 *
	 * This test verifies that the logic correctly processes each request independently
	 * and only updates timestamps for valid UPDATE requests.
	 */
	public function test_timestamp_update_mixed_valid_invalid_requests() {
		// Arrange: Set up test data with mixed request types and formats
		$test_data = array(
			'p-123' => Sync::ACTION_UPDATE,
			'p-456' => Sync::ACTION_DELETE,
			'p-789' => Sync::ACTION_UPDATE,
			'p-101' => Sync::ACTION_UPDATE,
		);

		$mock_requests = array(
			array(
				'method' => Sync::ACTION_UPDATE,
				'data' => array( 'id' => 'wc_post_id_123' ), // Valid UPDATE
			),
			array(
				'method' => Sync::ACTION_DELETE,
				'data' => array( 'id' => 'wc_post_id_456' ), // DELETE - should not update timestamp
			),
			array(
				'method' => Sync::ACTION_UPDATE,
				'data' => array( 'id' => 'invalid_format_789' ), // Invalid format
			),
			array(
				'method' => Sync::ACTION_UPDATE,
				'data' => array( 'id' => 'wc_post_id_101' ), // Valid UPDATE
			),
		);

		// Configure background to return successful API response
		$this->background->set_api_response_handles( array( 'handle1', 'handle2', 'handle3', 'handle4' ) );
		$this->background->set_mock_requests( $mock_requests );

		// Act: Process the items
		$this->background->process_items( $this->mock_job, $test_data );

		// Assert: Verify timestamps were updated only for valid UPDATE requests (123 and 101)
		$updated_timestamps = $this->background->get_updated_timestamps();
		$this->assertCount( 2, $updated_timestamps );
		$this->assertArrayHasKey( 123, $updated_timestamps );
		$this->assertArrayHasKey( 101, $updated_timestamps );
		$this->assertArrayNotHasKey( 456, $updated_timestamps ); // DELETE request
		$this->assertArrayNotHasKey( 789, $updated_timestamps ); // Invalid format
	}

	/**
	 * Test the regex pattern matching edge cases.
	 *
	 * This test specifically focuses on the regex pattern '/wc_post_id_(\d+)/'
	 * and various edge cases it should handle.
	 */
	public function test_regex_pattern_edge_cases() {
		// Arrange: Set up test data with various edge case retailer_id formats
		$test_data = array(
			'p-123' => Sync::ACTION_UPDATE,
			'p-456' => Sync::ACTION_UPDATE,
			'p-789' => Sync::ACTION_UPDATE,
			'p-101' => Sync::ACTION_UPDATE,
		);

		$mock_requests = array(
			array(
				'method' => Sync::ACTION_UPDATE,
				'data' => array( 'id' => 'wc_post_id_123456789' ), // Large number - should work
			),
			array(
				'method' => Sync::ACTION_UPDATE,
				'data' => array( 'id' => 'wc_post_id_1' ), // Single digit - should work
			),
			array(
				'method' => Sync::ACTION_UPDATE,
				'data' => array( 'id' => 'WC_POST_ID_789' ), // Wrong case - should not match
			),
			array(
				'method' => Sync::ACTION_UPDATE,
				'data' => array( 'id' => 'wc_post_id_101_extra' ), // Extra text after - should still match 101
			),
		);

		// Configure background to return successful API response
		$this->background->set_api_response_handles( array( 'handle1', 'handle2', 'handle3', 'handle4' ) );
		$this->background->set_mock_requests( $mock_requests );

		// Act: Process the items
		$this->background->process_items( $this->mock_job, $test_data );

		// Assert: Verify timestamps were updated for valid patterns only
		$updated_timestamps = $this->background->get_updated_timestamps();
		$this->assertCount( 3, $updated_timestamps );
		$this->assertArrayHasKey( 123456789, $updated_timestamps ); // Large number
		$this->assertArrayHasKey( 1, $updated_timestamps ); // Single digit
		$this->assertArrayHasKey( 101, $updated_timestamps ); // With extra text
		$this->assertArrayNotHasKey( 789, $updated_timestamps ); // Wrong case
	}

	/**
	 * Helper method to mock WordPress functions.
	 */
	private function mock_wordpress_functions() {
		// Mock update_post_meta function to capture calls
		if ( ! function_exists( 'update_post_meta' ) ) {
			function update_post_meta( $post_id, $meta_key, $meta_value ) {
				global $test_updated_post_meta, $test_timestamp_update_should_fail;

				// Simulate failure if configured
				if ( isset( $test_timestamp_update_should_fail ) && $test_timestamp_update_should_fail ) {
					throw new \Exception( 'Simulated update_post_meta failure' );
				}

				if ( ! isset( $test_updated_post_meta ) ) {
					$test_updated_post_meta = array();
				}
				$test_updated_post_meta[ $post_id ][ $meta_key ] = $meta_value;
				return true;
			}
		}

		// Mock time function for consistent testing
		if ( ! function_exists( 'time' ) ) {
			function time() {
				return 1640995200; // Fixed timestamp for testing
			}
		}

		// Mock Logger::log to capture logged errors
		if ( ! class_exists( '\WooCommerce\Facebook\Framework\Logger' ) ) {
			eval( '
				namespace WooCommerce\Facebook\Framework;
				class Logger {
					public static function log( $message, $context = array(), $options = array(), $exception = null ) {
						global $test_logged_errors;
						if ( ! isset( $test_logged_errors ) ) {
							$test_logged_errors = array();
						}
						$test_logged_errors[] = array(
							"message" => $message,
							"context" => $context,
							"options" => $options,
							"exception" => $exception,
						);
					}
				}
			' );
		}

		// Mock facebook_for_woocommerce function to return mock plugin instance
		if ( ! function_exists( 'facebook_for_woocommerce' ) ) {
			function facebook_for_woocommerce() {
				global $test_api_response_handles;

				$mock_response = new \stdClass();
				$mock_response->handles = isset( $test_api_response_handles ) ? $test_api_response_handles : array();

				$mock_api = new class( $mock_response ) {
					private $response;
					public function __construct( $response ) {
						$this->response = $response;
					}
					public function send_item_updates( $catalog_id, $requests ) {
						return $this->response;
					}
				};

				$mock_integration = new class {
					public function get_product_catalog_id() {
						return 'test-catalog-123';
					}
				};

				$mock_plugin = new class( $mock_api, $mock_integration ) {
					private $api;
					private $integration;
					public function __construct( $api, $integration ) {
						$this->api = $api;
						$this->integration = $integration;
					}
					public function get_api() {
						return $this->api;
					}
					public function get_integration() {
						return $this->integration;
					}
					public function log( $message ) {
						// Mock logging - do nothing
					}
				};

				return $mock_plugin;
			}
		}
	}

	/**
	 * Clean up globals after each test.
	 */
	public function tearDown(): void {
		unset( $GLOBALS['test_updated_post_meta'] );
		unset( $GLOBALS['test_timestamp_update_should_fail'] );
		unset( $GLOBALS['test_logged_errors'] );
		parent::tearDown();
	}
}

/**
 * Testable version of Background class that uses the REAL process_items method.
 * We only override process_item to control the requests and update_job to prevent DB writes.
 * The API calls are mocked at the WordPress function level.
 */
class TestableBackground extends Background {

	/**
	 * Mock API response handles.
	 *
	 * @var array
	 */
	private $api_response_handles = array();

	/**
	 * Mock requests to return from process_item.
	 *
	 * @var array
	 */
	private $mock_requests = array();

	/**
	 * Set mock API response handles.
	 */
	public function set_api_response_handles( array $handles ) {
		$this->api_response_handles = $handles;
		// Also set the global variable that the mock API will use
		global $test_api_response_handles;
		$test_api_response_handles = $handles;
	}

	/**
	 * Set mock requests.
	 */
	public function set_mock_requests( array $requests ) {
		$this->mock_requests = $requests;
	}

	/**
	 * Set whether timestamp updates should fail.
	 */
	public function set_timestamp_update_should_fail( bool $should_fail ) {
		global $test_timestamp_update_should_fail;
		$test_timestamp_update_should_fail = $should_fail;
	}

	/**
	 * Get updated timestamps from global.
	 */
	public function get_updated_timestamps(): array {
		global $test_updated_post_meta;
		$timestamps = array();

		if ( isset( $test_updated_post_meta ) ) {
			foreach ( $test_updated_post_meta as $post_id => $meta ) {
				if ( isset( $meta['_fb_sync_last_time'] ) ) {
					$timestamps[ $post_id ] = $meta['_fb_sync_last_time'];
				}
			}
		}

		return $timestamps;
	}

	/**
	 * Get logged errors from global.
	 */
	public function get_logged_errors(): array {
		global $test_logged_errors;
		return isset( $test_logged_errors ) ? $test_logged_errors : array();
	}

	/**
	 * Override process_item to return mock requests.
	 * This allows us to control what requests are generated.
	 */
	public function process_item( $item, $job ) {
		static $request_index = 0;

		if ( isset( $this->mock_requests[ $request_index ] ) ) {
			return $this->mock_requests[ $request_index++ ];
		}

		return null;
	}

	/**
	 * Override update_job to prevent actual job updates.
	 * This prevents database writes during testing.
	 */
	public function update_job( $job ) {
		return $job;
	}
}
