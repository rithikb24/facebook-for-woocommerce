<?php
/**
 * Unit tests for the Sync class
 */

namespace WooCommerce\Facebook\Tests\Unit\Products;

use WooCommerce\Facebook\Products\Sync;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

/**
 * Class SyncTest
 *
 * @package WooCommerce\Facebook\Tests\Unit\Products
 */
class SyncTest extends AbstractWPUnitTestWithOptionIsolationAndSafeFiltering {

    /**
     * @var Sync
     */
    private $sync;

    /**
     * @var array
     */
    private $test_product_ids;

    /**
     * @var array
     */
    private $mock_products;

    /**
     * @var array
     */
    private $post_meta_values;

    /**
     * Set up the test environment
     */
    public function setUp(): void {
        parent::setUp();

        // Create a mock for the profiling logger
        $profiling_logger = $this->createMock(\WooCommerce\Facebook\Logger\ProfilingLogger::class);
        $profiling_logger->method('start')->willReturn(null);
        $profiling_logger->method('stop')->willReturn(null);

        // Mock the facebook_for_woocommerce() function to return a mock plugin instance
        $plugin = $this->createMock(\WC_Facebookcommerce::class);
        $plugin->method('get_profiling_logger')->willReturn($profiling_logger);

        // Define our test product IDs
        $this->test_product_ids = [1, 2, 3, 4, 5];

        // Set up mock products with different modification times
        $this->mock_products = [];
        $this->post_meta_values = [];

        // Product 1: Never synced (no _fb_sync_last_time)
        $product1 = $this->createMock(\WC_Product::class);
        $product1->method('get_date_modified')->willReturn(new \WC_DateTime('@' . strtotime('2023-01-01')));
        $this->mock_products[1] = $product1;
        $this->post_meta_values[1] = ['_fb_sync_last_time' => false];

        // Product 2: Modified after last sync
        $product2 = $this->createMock(\WC_Product::class);
        $product2->method('get_date_modified')->willReturn(new \WC_DateTime('@' . strtotime('2023-01-15')));
        $this->mock_products[2] = $product2;
        $this->post_meta_values[2] = ['_fb_sync_last_time' => strtotime('2023-01-01')];

        // Product 3: Not modified since last sync
        $product3 = $this->createMock(\WC_Product::class);
        $product3->method('get_date_modified')->willReturn(new \WC_DateTime('@' . strtotime('2023-01-01')));
        $this->mock_products[3] = $product3;
        $this->post_meta_values[3] = ['_fb_sync_last_time' => strtotime('2023-01-15')];

        // Product 4: Modified at exactly the same time as last sync
        $product4 = $this->createMock(\WC_Product::class);
        $product4->method('get_date_modified')->willReturn(new \WC_DateTime('@' . strtotime('2023-01-15')));
        $this->mock_products[4] = $product4;
        $this->post_meta_values[4] = ['_fb_sync_last_time' => strtotime('2023-01-15')];

        // Product 5: Invalid product (null)
        $this->mock_products[5] = null;
        $this->post_meta_values[5] = [];

        // Mock the global functions
        $this->mock_global_functions();

        // Instantiate the Sync class
        $this->sync = new Sync();

        // Replace the requests property with a public property for testing
        $reflection = new \ReflectionClass($this->sync);
        $property = $reflection->getProperty('requests');
        $property->setAccessible(true);
        $property->setValue($this->sync, []);
    }

    /**
     * Mock global functions used in the Sync class
     */
    private function mock_global_functions() {
        // Mock WC_Facebookcommerce_Utils::get_all_product_ids_for_sync()
        $this->add_filter_with_safe_teardown('wc_facebook_get_all_product_ids_for_sync', function() {
            return $this->test_product_ids;
        });

        // Mock wc_get_product()
        $this->add_filter_with_safe_teardown('woocommerce_product_get_product', function($product, $product_id) {
            if (isset($this->mock_products[$product_id])) {
                return $this->mock_products[$product_id];
            }
            return $product;
        }, 10, 2);

        // Mock get_post_meta()
        $this->add_filter_with_safe_teardown('get_post_metadata', function($value, $object_id, $meta_key, $single) {
            if ($meta_key === '_fb_sync_last_time' && isset($this->post_meta_values[$object_id][$meta_key])) {
                return $this->post_meta_values[$object_id][$meta_key];
            }
            return $value;
        }, 10, 4);

        // Mock facebook_for_woocommerce()
        global $facebook_for_woocommerce;
        $facebook_for_woocommerce = $this->createMock(\WC_Facebookcommerce::class);
        $profiling_logger = $this->createMock(\WooCommerce\Facebook\Logger\ProfilingLogger::class);
        $profiling_logger->method('start')->willReturn(null);
        $profiling_logger->method('stop')->willReturn(null);
        $facebook_for_woocommerce->method('get_profiling_logger')->willReturn($profiling_logger);
    }

    /**
     * Test that create_or_update_modified_products correctly identifies and queues products
     * that have never been synced or have been modified since last sync
     */
    public function test_create_or_update_modified_products() {
        // Call the method under test
        $this->sync->create_or_update_modified_products();

        // Get the requests property to check which products were queued
        $reflection = new \ReflectionClass($this->sync);
        $property = $reflection->getProperty('requests');
        $property->setAccessible(true);
        $requests = $property->getValue($this->sync);

        // Expected products to be queued:
        // - Product 1 (never synced)
        // - Product 2 (modified after last sync)
        // Products 3, 4, and 5 should not be queued

        // Check that Product 1 is queued (never synced)
        $this->assertArrayHasKey(Sync::PRODUCT_INDEX_PREFIX . '1', $requests);
        $this->assertEquals(Sync::ACTION_UPDATE, $requests[Sync::PRODUCT_INDEX_PREFIX . '1']);

        // Check that Product 2 is queued (modified after last sync)
        $this->assertArrayHasKey(Sync::PRODUCT_INDEX_PREFIX . '2', $requests);
        $this->assertEquals(Sync::ACTION_UPDATE, $requests[Sync::PRODUCT_INDEX_PREFIX . '2']);

        // Check that Product 3 is NOT queued (not modified since last sync)
        $this->assertArrayNotHasKey(Sync::PRODUCT_INDEX_PREFIX . '3', $requests);

        // Check that Product 4 is NOT queued (modified at exactly the same time as last sync)
        $this->assertArrayNotHasKey(Sync::PRODUCT_INDEX_PREFIX . '4', $requests);

        // Check that Product 5 is NOT queued (invalid product)
        $this->assertArrayNotHasKey(Sync::PRODUCT_INDEX_PREFIX . '5', $requests);

        // Check that only 2 products were queued in total
        $this->assertCount(2, $requests);
    }

    /**
     * Test that create_or_update_modified_products handles empty product list correctly
     */
    public function test_create_or_update_modified_products_with_empty_product_list() {
        // Override the test product IDs to be empty
        $this->test_product_ids = [];

        // Call the method under test
        $this->sync->create_or_update_modified_products();

        // Get the requests property to check which products were queued
        $reflection = new \ReflectionClass($this->sync);
        $property = $reflection->getProperty('requests');
        $property->setAccessible(true);
        $requests = $property->getValue($this->sync);

        // Check that no products were queued
        $this->assertEmpty($requests);
    }

    /**
     * Test that create_or_update_modified_products handles products with null modified date
     */
    public function test_create_or_update_modified_products_with_null_modified_date() {
        // Create a product with null modified date
        $product = $this->createMock(\WC_Product::class);
        $product->method('get_date_modified')->willReturn(null);
        $this->mock_products[1] = $product;
        $this->post_meta_values[1] = ['_fb_sync_last_time' => strtotime('2023-01-01')];

        // Override the test product IDs to only include this product
        $this->test_product_ids = [1];

        // Call the method under test
        $this->sync->create_or_update_modified_products();

        // Get the requests property to check which products were queued
        $reflection = new \ReflectionClass($this->sync);
        $property = $reflection->getProperty('requests');
        $property->setAccessible(true);
        $requests = $property->getValue($this->sync);

        // Check that the product was NOT queued (null modified date should be treated as 0)
        $this->assertEmpty($requests);
    }

    /**
     * Test that create_or_update_modified_products handles products with zero last sync time
     */
    public function test_create_or_update_modified_products_with_zero_last_sync_time() {
        // Create a product with a modified date and zero last sync time
        $product = $this->createMock(\WC_Product::class);
        $product->method('get_date_modified')->willReturn(new \WC_DateTime('@' . strtotime('2023-01-01')));
        $this->mock_products[1] = $product;
        $this->post_meta_values[1] = ['_fb_sync_last_time' => 0];

        // Override the test product IDs to only include this product
        $this->test_product_ids = [1];

        // Call the method under test
        $this->sync->create_or_update_modified_products();

        // Get the requests property to check which products were queued
        $reflection = new \ReflectionClass($this->sync);
        $property = $reflection->getProperty('requests');
        $property->setAccessible(true);
        $requests = $property->getValue($this->sync);

        // Check that the product was queued (zero last sync time should be treated as never synced)
        $this->assertArrayHasKey(Sync::PRODUCT_INDEX_PREFIX . '1', $requests);
        $this->assertEquals(Sync::ACTION_UPDATE, $requests[Sync::PRODUCT_INDEX_PREFIX . '1']);
    }

    /**
     * Test that create_or_update_modified_products handles products with future last sync time
     */
    public function test_create_or_update_modified_products_with_future_last_sync_time() {
        // Create a product with a modified date and future last sync time
        $product = $this->createMock(\WC_Product::class);
        $product->method('get_date_modified')->willReturn(new \WC_DateTime('@' . strtotime('2023-01-01')));
        $this->mock_products[1] = $product;
        $this->post_meta_values[1] = ['_fb_sync_last_time' => strtotime('2025-01-01')]; // Future date

        // Override the test product IDs to only include this product
        $this->test_product_ids = [1];

        // Call the method under test
        $this->sync->create_or_update_modified_products();

        // Get the requests property to check which products were queued
        $reflection = new \ReflectionClass($this->sync);
        $property = $reflection->getProperty('requests');
        $property->setAccessible(true);
        $requests = $property->getValue($this->sync);

        // Check that the product was NOT queued (future last sync time means product hasn't been modified since)
        $this->assertEmpty($requests);
    }

    /**
     * Test that create_or_update_modified_products correctly interacts with create_or_update_products
     */
    public function test_create_or_update_modified_products_interaction_with_create_or_update_products() {
        // Create a spy on the create_or_update_products method
        $sync_mock = $this->getMockBuilder(Sync::class)
                          ->setMethods(['create_or_update_products'])
                          ->getMock();

        // Set up expectations for the create_or_update_products method
        $sync_mock->expects($this->once())
                 ->method('create_or_update_products')
                 ->with($this->callback(function($products) {
                     // We expect only products 1 and 2 to be passed to create_or_update_products
                     return count($products) === 2 && in_array(1, $products) && in_array(2, $products);
                 }));

        // Set up the same test products as in the main test
        $reflection = new \ReflectionClass($sync_mock);
        $property = $reflection->getProperty('requests');
        $property->setAccessible(true);
        $property->setValue($sync_mock, []);

        // Call the method under test
        $sync_mock->create_or_update_modified_products();
    }

    /**
     * Test that create_or_update_modified_products handles products with string timestamps
     */
    public function test_create_or_update_modified_products_with_string_timestamps() {
        // Create a product with a string timestamp for last sync time
        $product = $this->createMock(\WC_Product::class);
        $product->method('get_date_modified')->willReturn(new \WC_DateTime('@' . strtotime('2023-01-15')));
        $this->mock_products[1] = $product;
        $this->post_meta_values[1] = ['_fb_sync_last_time' => '1672531200']; // String timestamp for 2023-01-01

        // Override the test product IDs to only include this product
        $this->test_product_ids = [1];

        // Call the method under test
        $this->sync->create_or_update_modified_products();

        // Get the requests property to check which products were queued
        $reflection = new \ReflectionClass($this->sync);
        $property = $reflection->getProperty('requests');
        $property->setAccessible(true);
        $requests = $property->getValue($this->sync);

        // Check that the product was queued (modified after last sync)
        $this->assertArrayHasKey(Sync::PRODUCT_INDEX_PREFIX . '1', $requests);
        $this->assertEquals(Sync::ACTION_UPDATE, $requests[Sync::PRODUCT_INDEX_PREFIX . '1']);
    }
}
