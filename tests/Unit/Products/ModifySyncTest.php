<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Products;

use WooCommerce\Facebook\Products\Sync;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Unit tests for Products\Sync class - focused on create_or_update_modified_products() core logic.
 *
 * @since 3.5.5
 */
class ModifySyncTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * The testable Sync instance under test.
	 *
	 * @var TestableSync
	 */
	private $sync;

	/**
	 * Mock product data.
	 *
	 * @var array
	 */
	private $mock_products = array();

	/**
	 * Mock post meta data.
	 *
	 * @var array
	 */
	private $mock_post_meta = array();

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Mock the Logger class
		$this->mock_logger();

		// Mock WordPress functions
		$this->mock_wordpress_functions();

		$this->sync = new TestableSync();
		$this->mock_products = array();
		$this->mock_post_meta = array();
	}

	/**
	 * Test that products never synced before are added to sync queue.
	 */
	public function test_create_or_update_modified_products_syncs_never_synced_products() {
		// Set up mock data
		$product_ids = array( 1, 2, 3 );

		// Mock products that have never been synced (no _fb_sync_last_time meta)
		$this->setup_mock_products( array(
			1 => array( 'modified_time' => 1640995200 ), // 2022-01-01
			2 => array( 'modified_time' => 1640995200 ),
			3 => array( 'modified_time' => 1640995200 ),
		) );

		$this->setup_mock_post_meta( array(
			1 => array( '_fb_sync_last_time' => false ), // Never synced
			2 => array( '_fb_sync_last_time' => '' ),    // Never synced
			3 => array( '_fb_sync_last_time' => false ), // Never synced
		) );

		// Configure mock to return these product IDs
		$this->sync->set_mock_product_ids( $product_ids );

		// Execute the method
		$this->sync->create_or_update_modified_products();

		// Verify all products were added to sync queue
		$requests = $this->sync->get_requests();
		$this->assertCount( 3, $requests );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-1'] );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-2'] );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-3'] );
	}

	/**
	 * Test that products modified since last sync are added to sync queue.
	 */
	public function test_create_or_update_modified_products_syncs_modified_products() {
		// Set up mock data
		$product_ids = array( 1, 2, 3 );

		$this->setup_mock_products( array(
			1 => array( 'modified_time' => 1641081600 ), // Modified after sync
			2 => array( 'modified_time' => 1641168000 ), // Modified after sync
			3 => array( 'modified_time' => 1640908800 ), // Modified before sync
		) );

		$this->setup_mock_post_meta( array(
			1 => array( '_fb_sync_last_time' => 1640995200 ),
			2 => array( '_fb_sync_last_time' => 1640995200 ),
			3 => array( '_fb_sync_last_time' => 1640995200 ),
		) );

		$this->sync->set_mock_product_ids( $product_ids );

		$this->sync->create_or_update_modified_products();

		// Only products 1 and 2 should be synced (modified after last sync)
		$requests = $this->sync->get_requests();
		$this->assertCount( 2, $requests );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-1'] );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-2'] );
		$this->assertArrayNotHasKey( 'p-3', $requests );
	}

	/**
	 * Test that products not modified since last sync are not added to sync queue.
	 */
	public function test_create_or_update_modified_products_skips_unmodified_products() {
		// Set up mock data
		$product_ids = array( 1, 2 );

		$this->setup_mock_products( array(
			1 => array( 'modified_time' => 1640995200 ), // Modified before sync
			2 => array( 'modified_time' => 1641081600 ), // Same time as sync
		) );

		$this->setup_mock_post_meta( array(
			1 => array( '_fb_sync_last_time' => 1641081600 ),
			2 => array( '_fb_sync_last_time' => 1641081600 ),
		) );

		$this->sync->set_mock_product_ids( $product_ids );

		$this->sync->create_or_update_modified_products();

		// No products should be synced (product 2 has same time, but we use >= so it should sync)
		$requests = $this->sync->get_requests();
		$this->assertCount( 1, $requests ); // Product 2 should sync due to >= comparison
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-2'] );
	}

	/**
	 * Test handling of products with no modification date.
	 */
	public function test_create_or_update_modified_products_handles_no_modification_date() {
		// Set up mock data
		$product_ids = array( 1, 2 );

		$this->setup_mock_products( array(
			1 => array( 'modified_time' => 0 ), // No modification date
			2 => array( 'modified_time' => 0 ), // No modification date
		) );

		$this->setup_mock_post_meta( array(
			1 => array( '_fb_sync_last_time' => false ), // Never synced
			2 => array( '_fb_sync_last_time' => 1640995200 ), // Previously synced
		) );

		$this->sync->set_mock_product_ids( $product_ids );

		$this->sync->create_or_update_modified_products();

		// Only product 1 should be synced (never synced before)
		$requests = $this->sync->get_requests();
		$this->assertCount( 1, $requests );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-1'] );
		$this->assertArrayNotHasKey( 'p-2', $requests );
	}

	/**
	 * Test handling of invalid products.
	 */
	public function test_create_or_update_modified_products_skips_invalid_products() {
		// Set up mock data
		$product_ids = array( 1, 999, 2 );

		$this->setup_mock_products( array(
			1 => array( 'modified_time' => 1640995200 ),
			// 999 is intentionally missing (invalid product)
			2 => array( 'modified_time' => 1640995200 ),
		) );

		$this->setup_mock_post_meta( array(
			1 => array( '_fb_sync_last_time' => false ),
			2 => array( '_fb_sync_last_time' => false ),
		) );

		$this->sync->set_mock_product_ids( $product_ids );

		$this->sync->create_or_update_modified_products();

		// Only valid products should be synced
		$requests = $this->sync->get_requests();
		$this->assertCount( 2, $requests );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-1'] );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-2'] );
		$this->assertArrayNotHasKey( 'p-999', $requests );
	}

	/**
	 * Test edge case with zero timestamps.
	 */
	public function test_create_or_update_modified_products_handles_zero_timestamps() {
		// Set up mock data
		$product_ids = array( 1, 2 );

		$this->setup_mock_products( array(
			1 => array( 'modified_time' => 0 ),
			2 => array( 'modified_time' => 1640995200 ),
		) );

		$this->setup_mock_post_meta( array(
			1 => array( '_fb_sync_last_time' => 0 ),
			2 => array( '_fb_sync_last_time' => 0 ),
		) );

		$this->sync->set_mock_product_ids( $product_ids );

		$this->sync->create_or_update_modified_products();

		// Both products should be synced (! $last_sync_time is true when last_sync_time = 0)
		$requests = $this->sync->get_requests();
		$this->assertCount( 2, $requests );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-1'] );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-2'] );
	}

	/**
	 * Set up mock products data.
	 */
	private function setup_mock_products( array $products_data ) {
		$this->mock_products = $products_data;
	}

	/**
	 * Set up mock post meta data.
	 */
	private function setup_mock_post_meta( array $meta_data ) {
		$this->mock_post_meta = $meta_data;
	}

	/**
	 * Helper method to mock WordPress functions.
	 */
	private function mock_wordpress_functions() {
		// Mock wc_get_product function
		if ( ! function_exists( 'wc_get_product' ) ) {
			function wc_get_product( $product_id ) {
				$test_instance = $GLOBALS['test_instance'] ?? null;
				if ( $test_instance && isset( $test_instance->mock_products[ $product_id ] ) ) {
					return new MockWCProduct( $test_instance->mock_products[ $product_id ] );
				}
				return false;
			}
		}

		// Mock get_post_meta function
		if ( ! function_exists( 'get_post_meta' ) ) {
			function get_post_meta( $post_id, $key = '', $single = false ) {
				$test_instance = $GLOBALS['test_instance'] ?? null;
				if ( $test_instance && isset( $test_instance->mock_post_meta[ $post_id ][ $key ] ) ) {
					return $test_instance->mock_post_meta[ $post_id ][ $key ];
				}
				return $single ? false : array();
			}
		}

		// Store test instance globally for access in mocked functions
		$GLOBALS['test_instance'] = $this;
	}

	/**
	 * Helper method to mock the Logger class.
	 */
	private function mock_logger() {
		if ( ! class_exists( '\WooCommerce\Facebook\Framework\Logger' ) ) {
			eval( '
				namespace WooCommerce\Facebook\Framework;
				class Logger {
					public static function log( $message, $context = array(), $options = array(), $exception = null ) {
						// Do nothing in tests
					}
				}
			' );
		}
	}

	/**
	 * Clean up globals after each test.
	 */
	public function tearDown(): void {
		unset( $GLOBALS['test_instance'] );
		parent::tearDown();
	}
}

/**
 * Mock WC_Product class for testing.
 */
class MockWCProduct {
	private $data;

	public function __construct( $data ) {
		$this->data = $data;
	}

	public function get_date_modified() {
		if ( isset( $this->data['modified_time'] ) && $this->data['modified_time'] > 0 ) {
			return new MockDateTime( $this->data['modified_time'] );
		}
		return null;
	}
}

/**
 * Mock DateTime class for testing.
 */
class MockDateTime {
	private $timestamp;

	public function __construct( $timestamp ) {
		$this->timestamp = $timestamp;
	}

	public function getTimestamp() {
		return $this->timestamp;
	}
}

/**
 * Testable version of Sync class that allows dependency injection.
 */
class TestableSync extends Sync {

	/**
	 * Mock product IDs to return.
	 *
	 * @var array
	 */
	private $mock_product_ids = array();

	/**
	 * Set mock product IDs.
	 */
	public function set_mock_product_ids( array $product_ids ) {
		$this->mock_product_ids = $product_ids;

		// Mock the WC_Facebookcommerce_Utils::get_all_product_ids_for_sync method
		if ( ! class_exists( '\WC_Facebookcommerce_Utils' ) ) {
			eval( '
				class WC_Facebookcommerce_Utils {
					public static function get_all_product_ids_for_sync() {
						$test_instance = $GLOBALS["test_instance"] ?? null;
						if ( $test_instance && $test_instance->sync ) {
							return $test_instance->sync->mock_product_ids;
						}
						return array();
					}
				}
			' );
		}
	}

	/**
	 * Get the requests array for testing.
	 */
	public function get_requests(): array {
		return $this->requests;
	}
}
