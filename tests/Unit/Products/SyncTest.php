<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Products;

use WooCommerce\Facebook\Products\Sync;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Unit tests for Products\Sync class - focused on create_or_update_modified_products() core logic.
 *
 * @since 2.0.0
 */
class SyncTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

	/**
	 * The Sync instance under test.
	 *
	 * @var Sync
	 */
	private $sync;

	/**
	 * Set up before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sync = new Sync();
	}

	/**
	 * Test that products never synced before are added to sync queue.
	 */
	public function test_create_or_update_modified_products_syncs_never_synced_products() {
		// Mock product IDs
		$product_ids = array( 1, 2, 3 );

		// Mock WC_Facebookcommerce_Utils::get_all_product_ids_for_sync()
		$this->mock_get_all_product_ids_for_sync( $product_ids );

		// Mock products with no last sync time (never synced)
		$this->mock_products_with_sync_data( array(
			1 => array( 'last_sync_time' => false, 'modified_time' => 1640995200 ), // 2022-01-01
			2 => array( 'last_sync_time' => '', 'modified_time' => 1640995200 ),
			3 => array( 'last_sync_time' => null, 'modified_time' => 1640995200 ),
		) );

		// Execute the method
		$this->sync->create_or_update_modified_products();

		// Verify all products were added to sync queue
		$requests = $this->get_sync_requests();
		$this->assertCount( 3, $requests );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-1'] );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-2'] );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-3'] );
	}

	/**
	 * Test that products modified since last sync are added to sync queue.
	 */
	public function test_create_or_update_modified_products_syncs_modified_products() {
		$product_ids = array( 1, 2, 3 );

		$this->mock_get_all_product_ids_for_sync( $product_ids );

		// Mock products where modified time > last sync time
		$this->mock_products_with_sync_data( array(
			1 => array( 'last_sync_time' => 1640995200, 'modified_time' => 1641081600 ), // Modified after sync
			2 => array( 'last_sync_time' => 1640995200, 'modified_time' => 1641168000 ), // Modified after sync
			3 => array( 'last_sync_time' => 1640995200, 'modified_time' => 1640908800 ), // Modified before sync
		) );

		$this->sync->create_or_update_modified_products();

		// Only products 1 and 2 should be synced (modified after last sync)
		$requests = $this->get_sync_requests();
		$this->assertCount( 2, $requests );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-1'] );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-2'] );
		$this->assertArrayNotHasKey( 'p-3', $requests );
	}

	/**
	 * Test that products not modified since last sync are not added to sync queue.
	 */
	public function test_create_or_update_modified_products_skips_unmodified_products() {
		$product_ids = array( 1, 2 );

		$this->mock_get_all_product_ids_for_sync( $product_ids );

		// Mock products where modified time <= last sync time
		$this->mock_products_with_sync_data( array(
			1 => array( 'last_sync_time' => 1641081600, 'modified_time' => 1640995200 ), // Modified before sync
			2 => array( 'last_sync_time' => 1641081600, 'modified_time' => 1641081600 ), // Same time as sync
		) );

		$this->sync->create_or_update_modified_products();

		// No products should be synced
		$requests = $this->get_sync_requests();
		$this->assertEmpty( $requests );
	}

	/**
	 * Test handling of products with no modification date.
	 */
	public function test_create_or_update_modified_products_handles_no_modification_date() {
		$product_ids = array( 1, 2 );

		$this->mock_get_all_product_ids_for_sync( $product_ids );

		// Mock products with no modification date (should default to 0)
		$this->mock_products_with_sync_data( array(
			1 => array( 'last_sync_time' => false, 'modified_time' => null ), // Never synced, no mod date
			2 => array( 'last_sync_time' => 1640995200, 'modified_time' => null ), // Synced before, no mod date
		) );

		$this->sync->create_or_update_modified_products();

		// Only product 1 should be synced (never synced before)
		$requests = $this->get_sync_requests();
		$this->assertCount( 1, $requests );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-1'] );
		$this->assertArrayNotHasKey( 'p-2', $requests );
	}

	/**
	 * Test handling of invalid products.
	 */
	public function test_create_or_update_modified_products_skips_invalid_products() {
		$product_ids = array( 1, 999, 2 );

		$this->mock_get_all_product_ids_for_sync( $product_ids );

		// Mock valid products and one invalid
		$this->mock_products_with_sync_data( array(
			1 => array( 'last_sync_time' => false, 'modified_time' => 1640995200 ),
			999 => null, // Invalid product
			2 => array( 'last_sync_time' => false, 'modified_time' => 1640995200 ),
		) );

		$this->sync->create_or_update_modified_products();

		// Only valid products should be synced
		$requests = $this->get_sync_requests();
		$this->assertCount( 2, $requests );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-1'] );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-2'] );
		$this->assertArrayNotHasKey( 'p-999', $requests );
	}

	/**
	 * Test that exceptions during sync are handled gracefully.
	 */
	public function test_create_or_update_modified_products_handles_exceptions() {
		// Mock get_all_product_ids_for_sync to throw exception
		$this->mock_get_all_product_ids_for_sync_with_exception();

		// Method should not throw exception
		$this->sync->create_or_update_modified_products();

		// No products should be synced due to exception
		$requests = $this->get_sync_requests();
		$this->assertEmpty( $requests );
	}

	/**
	 * Test edge case with zero timestamps.
	 */
	public function test_create_or_update_modified_products_handles_zero_timestamps() {
		$product_ids = array( 1, 2 );

		$this->mock_get_all_product_ids_for_sync( $product_ids );

		$this->mock_products_with_sync_data( array(
			1 => array( 'last_sync_time' => 0, 'modified_time' => 0 ),
			2 => array( 'last_sync_time' => 0, 'modified_time' => 1640995200 ),
		) );

		$this->sync->create_or_update_modified_products();

		// Product 2 should be synced (modified time > 0)
		$requests = $this->get_sync_requests();
		$this->assertCount( 1, $requests );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-2'] );
	}

	/**
	 * Helper method to get sync requests using reflection.
	 */
	private function get_sync_requests(): array {
		$reflection = new \ReflectionClass( $this->sync );
		$requests_property = $reflection->getProperty( 'requests' );
		$requests_property->setAccessible( true );
		return $requests_property->getValue( $this->sync );
	}

	/**
	 * Helper method to mock WC_Facebookcommerce_Utils::get_all_product_ids_for_sync().
	 */
	private function mock_get_all_product_ids_for_sync( array $product_ids ) {
		// Mock the static method call
		$GLOBALS['mock_get_all_product_ids_for_sync'] = $product_ids;

		if ( ! class_exists( '\WC_Facebookcommerce_Utils' ) ) {
			eval( '
				class WC_Facebookcommerce_Utils {
					public static function get_all_product_ids_for_sync() {
						return $GLOBALS["mock_get_all_product_ids_for_sync"] ?? array();
					}
				}
			' );
		}
	}

	/**
	 * Helper method to mock WC_Facebookcommerce_Utils::get_all_product_ids_for_sync() with exception.
	 */
	private function mock_get_all_product_ids_for_sync_with_exception() {
		if ( ! class_exists( '\WC_Facebookcommerce_Utils' ) ) {
			eval( '
				class WC_Facebookcommerce_Utils {
					public static function get_all_product_ids_for_sync() {
						throw new \Exception("Test exception");
					}
				}
			' );
		}
	}

	/**
	 * Helper method to mock products and their sync data.
	 */
	private function mock_products_with_sync_data( array $products_data ) {
		// Mock wc_get_product function
		if ( ! function_exists( 'wc_get_product' ) ) {
			function wc_get_product( $product_id ) {
				$products_data = $GLOBALS['mock_products_data'] ?? array();

				if ( ! isset( $products_data[ $product_id ] ) || $products_data[ $product_id ] === null ) {
					return false;
				}

				$data = $products_data[ $product_id ];
				$mock_product = new \stdClass();

				// Mock get_date_modified method
				$mock_product->get_date_modified = function() use ( $data ) {
					if ( $data['modified_time'] === null ) {
						return null;
					}

					$mock_date = new \stdClass();
					$mock_date->getTimestamp = function() use ( $data ) {
						return $data['modified_time'];
					};
					return $mock_date;
				};

				return $mock_product;
			}
		}

		// Mock get_post_meta function
		if ( ! function_exists( 'get_post_meta' ) ) {
			function get_post_meta( $post_id, $key = '', $single = false ) {
				$products_data = $GLOBALS['mock_products_data'] ?? array();

				if ( $key === '_fb_sync_last_time' && isset( $products_data[ $post_id ] ) ) {
					return $products_data[ $post_id ]['last_sync_time'];
				}

				return '';
			}
		}

		$GLOBALS['mock_products_data'] = $products_data;
	}
}
