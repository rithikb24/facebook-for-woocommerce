<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Products;

use WooCommerce\Facebook\Products\Sync;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;
use WC_Product;
use WC_DateTime;

/**
 * Unit tests for Products\Sync class - focused on create_or_update_modified_products() method.
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
	 * Test create_or_update_modified_products with products that need syncing.
	 */
	public function test_create_or_update_modified_products_syncs_modified_products() {
		$product_ids = array( 1, 2 );
		$log_calls = array();

		// Mock Logger::log to capture log calls
		$this->add_filter_with_safe_teardown( 'woocommerce_facebook_log', function( $message, $context = array() ) use ( &$log_calls ) {
			$log_calls[] = array( 'message' => $message, 'context' => $context );
			return false;
		});

		// Mock the utility function to return our test product IDs
		$this->mock_utils_get_all_product_ids_for_sync( $product_ids );

		$current_time = time();
		$last_sync_time = $current_time - 7200; // Synced 2 hours ago
		$modified_time = $current_time - 3600;  // Modified 1 hour ago

		// Mock products that were modified after last sync
		$this->mock_product( 1, $modified_time, $last_sync_time );
		$this->mock_product( 2, $modified_time, false ); // Never synced

		$this->sync->create_or_update_modified_products();

		// Verify logging occurred and both products were queued
		$this->assertCount( 2, $log_calls );
		$this->assertEquals( 'Starting sync of modified products', $log_calls[0]['message'] );
		$this->assertEquals( 'Completed sync of modified products', $log_calls[1]['message'] );
		$this->assertEquals( 2, $log_calls[1]['context']['product_count'] );
	}

	/**
	 * Test create_or_update_modified_products with products that don't need syncing.
	 */
	public function test_create_or_update_modified_products_skips_unmodified_products() {
		$product_ids = array( 1, 2 );
		$log_calls = array();

		// Mock Logger::log
		$this->add_filter_with_safe_teardown( 'woocommerce_facebook_log', function( $message, $context = array() ) use ( &$log_calls ) {
			$log_calls[] = array( 'message' => $message, 'context' => $context );
			return false;
		});

		// Mock the utility function
		$this->mock_utils_get_all_product_ids_for_sync( $product_ids );

		$current_time = time();
		$last_sync_time = $current_time - 3600; // Synced 1 hour ago
		$modified_time = $current_time - 7200;  // Modified 2 hours ago (before last sync)

		// Mock products that were NOT modified after last sync
		$this->mock_product( 1, $modified_time, $last_sync_time );
		$this->mock_product( 2, $modified_time, $last_sync_time );

		$this->sync->create_or_update_modified_products();

		// Verify no products were queued for sync
		$this->assertCount( 2, $log_calls );
		$this->assertEquals( 'Completed sync of modified products', $log_calls[1]['message'] );
		$this->assertEquals( 0, $log_calls[1]['context']['product_count'] );
	}

	/**
	 * Test create_or_update_modified_products with exception handling.
	 */
	public function test_create_or_update_modified_products_handles_exceptions() {
		$log_calls = array();

		// Mock Logger::log
		$this->add_filter_with_safe_teardown( 'woocommerce_facebook_log', function( $message, $context = array() ) use ( &$log_calls ) {
			$log_calls[] = array( 'message' => $message, 'context' => $context );
			return false;
		});

		// Mock the utility function to throw an exception
		$this->add_filter_with_safe_teardown( 'woocommerce_facebook_utils_get_all_product_ids_for_sync', function() {
			throw new \Exception( 'Test exception' );
		});

		$this->sync->create_or_update_modified_products();

		// Verify error logging occurred
		$this->assertCount( 2, $log_calls );
		$this->assertEquals( 'Starting sync of modified products', $log_calls[0]['message'] );
		$this->assertEquals( 'Error syncing modified products', $log_calls[1]['message'] );
		$this->assertEquals( 'Test exception', $log_calls[1]['context']['error_message'] );
	}

	/**
	 * Helper method to mock the utility function for getting product IDs.
	 *
	 * @param array $product_ids The product IDs to return.
	 */
	private function mock_utils_get_all_product_ids_for_sync( array $product_ids ) {
		$this->add_filter_with_safe_teardown( 'woocommerce_facebook_utils_get_all_product_ids_for_sync', function() use ( $product_ids ) {
			return $product_ids;
		});
	}

	/**
	 * Helper method to mock a product.
	 *
	 * @param int        $product_id     The product ID.
	 * @param int        $modified_time  The modification timestamp.
	 * @param int|false  $last_sync_time The last sync timestamp or false if never synced.
	 */
	private function mock_product( int $product_id, int $modified_time, $last_sync_time ) {
		// Mock wc_get_product
		$this->add_filter_with_safe_teardown( "woocommerce_facebook_wc_get_product_{$product_id}", function() use ( $modified_time ) {
			$product = $this->createMock( WC_Product::class );

			if ( $modified_time > 0 ) {
				$date_modified = $this->createMock( WC_DateTime::class );
				$date_modified->method( 'getTimestamp' )->willReturn( $modified_time );
				$product->method( 'get_date_modified' )->willReturn( $date_modified );
			} else {
				$product->method( 'get_date_modified' )->willReturn( null );
			}

			return $product;
		});

		// Mock get_post_meta for last sync time
		$this->add_filter_with_safe_teardown( "get_post_metadata", function( $value, $object_id, $meta_key, $single ) use ( $product_id, $last_sync_time ) {
			if ( $object_id === $product_id && $meta_key === '_fb_sync_last_time' ) {
				return $last_sync_time;
			}
			return $value;
		}, 10, 4 );
	}
}
