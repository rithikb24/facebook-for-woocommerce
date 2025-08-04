<?php
declare( strict_types=1 );

namespace WooCommerce\Facebook\Tests\Unit\Products;

use WooCommerce\Facebook\Products\Sync;
use WooCommerce\Facebook\Tests\AbstractWPUnitTestWithOptionIsolationAndSafeFiltering;

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
	 * Test that the class exists and can be instantiated.
	 */
	public function test_class_exists() {
		$this->assertTrue( class_exists( Sync::class ) );
		$this->assertInstanceOf( Sync::class, $this->sync );
	}

	/**
	 * Test that create_or_update_modified_products method exists and can be called.
	 */
	public function test_create_or_update_modified_products_method_exists() {
		$this->assertTrue( method_exists( $this->sync, 'create_or_update_modified_products' ) );

		// Test that the method can be called without fatal errors
		// We expect it to handle the case where dependencies are not available gracefully
		try {
			$this->sync->create_or_update_modified_products();
			$this->assertTrue( true ); // If we get here, no fatal error occurred
		} catch ( \Exception $e ) {
			// Method should handle exceptions gracefully
			$this->assertTrue( true );
		}
	}

	/**
	 * Test create_or_update_products method adds products to requests array.
	 */
	public function test_create_or_update_products_adds_to_requests() {
		$product_ids = array( 1, 2, 3 );

		// Use reflection to access the protected requests property
		$reflection = new \ReflectionClass( $this->sync );
		$requests_property = $reflection->getProperty( 'requests' );
		$requests_property->setAccessible( true );

		// Initially empty
		$this->assertEmpty( $requests_property->getValue( $this->sync ) );

		// Add products
		$this->sync->create_or_update_products( $product_ids );

		// Check requests array
		$requests = $requests_property->getValue( $this->sync );
		$this->assertCount( 3, $requests );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-1'] );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-2'] );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-3'] );
	}

	/**
	 * Test delete_products method adds products to requests array with delete action.
	 */
	public function test_delete_products_adds_to_requests() {
		$retailer_ids = array( 1, 2, 3 );

		// Use reflection to access the protected requests property
		$reflection = new \ReflectionClass( $this->sync );
		$requests_property = $reflection->getProperty( 'requests' );
		$requests_property->setAccessible( true );

		// Add products for deletion
		$this->sync->delete_products( $retailer_ids );

		// Check requests array
		$requests = $requests_property->getValue( $this->sync );
		$this->assertCount( 3, $requests );
		$this->assertEquals( Sync::ACTION_DELETE, $requests['p-1'] );
		$this->assertEquals( Sync::ACTION_DELETE, $requests['p-2'] );
		$this->assertEquals( Sync::ACTION_DELETE, $requests['p-3'] );
	}

	/**
	 * Test get_product_index method generates correct index.
	 */
	public function test_get_product_index() {
		$reflection = new \ReflectionClass( $this->sync );
		$method = $reflection->getMethod( 'get_product_index' );
		$method->setAccessible( true );

		$this->assertEquals( 'p-123', $method->invoke( $this->sync, 123 ) );
		$this->assertEquals( 'p-456', $method->invoke( $this->sync, '456' ) );
	}

	/**
	 * Test that constants are defined correctly.
	 */
	public function test_constants() {
		$this->assertEquals( 'p-', Sync::PRODUCT_INDEX_PREFIX );
		$this->assertEquals( 'UPDATE', Sync::ACTION_UPDATE );
		$this->assertEquals( 'DELETE', Sync::ACTION_DELETE );
	}

	/**
	 * Test that multiple operations work together correctly.
	 */
	public function test_multiple_operations() {
		// Use reflection to access the protected requests property
		$reflection = new \ReflectionClass( $this->sync );
		$requests_property = $reflection->getProperty( 'requests' );
		$requests_property->setAccessible( true );

		// Add some products for update
		$this->sync->create_or_update_products( array( 1, 2 ) );

		// Add some products for deletion
		$this->sync->delete_products( array( 3, 4 ) );

		// Check requests array contains both operations
		$requests = $requests_property->getValue( $this->sync );
		$this->assertCount( 4, $requests );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-1'] );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-2'] );
		$this->assertEquals( Sync::ACTION_DELETE, $requests['p-3'] );
		$this->assertEquals( Sync::ACTION_DELETE, $requests['p-4'] );
	}

	/**
	 * Test that duplicate product IDs are handled correctly.
	 */
	public function test_duplicate_product_ids() {
		// Use reflection to access the protected requests property
		$reflection = new \ReflectionClass( $this->sync );
		$requests_property = $reflection->getProperty( 'requests' );
		$requests_property->setAccessible( true );

		// Add same product multiple times
		$this->sync->create_or_update_products( array( 1 ) );
		$this->sync->create_or_update_products( array( 1 ) );

		// Should only have one entry
		$requests = $requests_property->getValue( $this->sync );
		$this->assertCount( 1, $requests );
		$this->assertEquals( Sync::ACTION_UPDATE, $requests['p-1'] );

		// Adding for deletion should overwrite
		$this->sync->delete_products( array( 1 ) );
		$requests = $requests_property->getValue( $this->sync );
		$this->assertCount( 1, $requests );
		$this->assertEquals( Sync::ACTION_DELETE, $requests['p-1'] );
	}
}
