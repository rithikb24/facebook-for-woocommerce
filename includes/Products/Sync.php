<?php
/**
 * Copyright (c) Facebook, Inc. and its affiliates. All Rights Reserved
 *
 * This source code is licensed under the license found in the
 * LICENSE file in the root directory of this source tree.
 *
 * @package FacebookCommerce
 */

namespace WooCommerce\Facebook\Products;

defined( 'ABSPATH' ) || exit;

/**
 * The product sync handler.
 *
 * @since 2.0.0
 */
class Sync {


	/** @var string the prefix used in the array indexes */
	const PRODUCT_INDEX_PREFIX = 'p-';

	/** @var string the update action */
	const ACTION_UPDATE = 'UPDATE';

	/** @var string the delete action */
	const ACTION_DELETE = 'DELETE';

	/** @var array the array of requests to schedule for sync */
	protected $requests = array();


	/**
	 * Sync constructor.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {

		$this->add_hooks();
	}


	/**
	 * Adds needed hooks to support product sync.
	 *
	 * @since 2.0.0
	 */
	public function add_hooks() {

		add_action( 'shutdown', array( $this, 'schedule_sync' ) );

		// stock update actions
		add_action( 'woocommerce_product_set_stock', array( $this, 'handle_stock_update' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'handle_stock_update' ) );
	}


	/**
	 * Adds all eligible product IDs to the requests array to be created or updated.
	 *
	 * Uses cursor-based pagination to efficiently process large catalogs without
	 * loading all product IDs into memory at once.
	 *
	 * @see \WC_Facebook_Product_Feed::get_product_ids()
	 * @see \WC_Facebook_Product_Feed::write_product_feed_file()
	 *
	 * @since 2.0.0
	 */
	public function create_or_update_all_products() {
		$profiling_logger = facebook_for_woocommerce()->get_profiling_logger();
		$profiling_logger->start( 'create_or_update_all_products' );

		// Set batch size
		$batch_size = 500;

		// Start with cursor at 0 (beginning)
		$last_id = 0;

		// Track total processed for logging
		$total_processed = 0;

		// Process products in batches using cursor-based pagination
		do {
			// Get next batch of products using cursor-based pagination
			$product_ids = $this->get_next_product_batch($last_id, $batch_size);

			// If no more products, we're done
			if (empty($product_ids)) {
				break;
			}

			// Update the cursor to the highest ID in this batch
			$last_id = max($product_ids);

			// Queue up these IDs for sync
			$this->create_or_update_products($product_ids);

			// Schedule the sync for this batch
			$this->schedule_sync();

			// Clear the requests array for the next batch
			$this->requests = array();

			// Update total processed count
			$total_processed += count($product_ids);

			// Log progress
			facebook_for_woocommerce()->log(
				sprintf('Processed batch of %d products. Total processed: %d', count($product_ids), $total_processed)
			);

		} while (!empty($product_ids));

		$profiling_logger->stop( 'create_or_update_all_products' );
	}

	/**
	 * Gets the next batch of product IDs using cursor-based pagination.
	 *
	 * @since 2.0.0
	 *
	 * @param int $last_id The last product ID processed (cursor)
	 * @param int $batch_size The number of products to retrieve
	 * @return array Array of product IDs
	 */
	protected function get_next_product_batch($last_id, $batch_size) {
		global $wpdb;

		// Get published products with ID greater than last_id
		$query = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_type IN ('product', 'product_variation')
			AND post_status = 'publish'
			AND ID > %d
			ORDER BY ID ASC
			LIMIT %d",
			$last_id,
			$batch_size
		);

		$results = $wpdb->get_col($query);

		if (empty($results)) {
			return array();
		}

		// Filter to only include products that should be synced
		$product_ids = array();
		foreach ($results as $product_id) {
			$product = wc_get_product($product_id);

			if ($product) {
				// Skip parent variable products as they can't be synced directly
				if ($product->is_type('variable')) {
					continue;
				}

				// Only include products that should be synced
				if (\WooCommerce\Facebook\Products::product_should_be_synced($product)) {
					$product_ids[] = (int) $product_id;
				}
			}
		}

		return $product_ids;
	}

	/**
	 * Adds all eligible product IDs to the requests array to be created or updated.
	 *
	 * Uses cursor-based pagination to efficiently process modified products without
	 * loading all product IDs into memory at once.
	 *
	 * @see \WC_Facebook_Product_Feed::get_product_ids()
	 * @see \WC_Facebook_Product_Feed::write_product_feed_file()
	 *
	 * @since 2.0.0
	 */
	public function create_or_update_modified_products() {
		$profiling_logger = facebook_for_woocommerce()->get_profiling_logger();
		$profiling_logger->start( 'create_or_update_modified_products' );

		// Set batch size
		$batch_size = 200;

		// Start with cursor at 0 (beginning)
		$last_id = 0;

		// Track total processed for logging
		$total_processed = 0;

		// Process products in batches using cursor-based pagination
		do {
			// Get next batch of modified products using cursor-based pagination
			$product_ids = $this->get_next_modified_product_batch($last_id, $batch_size);

			// If no more products, we're done
			if (empty($product_ids)) {
				break;
			}

			// Update the cursor to the highest ID in this batch
			$last_id = max($product_ids);

			// Queue up these IDs for sync
			$this->create_or_update_products($product_ids);

			// Schedule the sync for this batch
			$this->schedule_sync();

			// Clear the requests array for the next batch
			$this->requests = array();

			// Update total processed count
			$total_processed += count($product_ids);

			// Log progress
			facebook_for_woocommerce()->log(
				sprintf('Processed batch of %d modified products. Total processed: %d', count($product_ids), $total_processed)
			);

		} while (!empty($product_ids));

		$profiling_logger->stop( 'create_or_update_modified_products' );
	}

	/**
	 * Gets the next batch of modified product IDs using cursor-based pagination.
	 *
	 * @since 2.0.0
	 *
	 * @param int $last_id The last product ID processed (cursor)
	 * @param int $batch_size The number of products to retrieve
	 * @return array Array of product IDs
	 */
	protected function get_next_modified_product_batch($last_id, $batch_size) {
		global $wpdb;

		// Get published products with ID greater than last_id
		$query = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_type IN ('product', 'product_variation')
			AND post_status = 'publish'
			AND ID > %d
			ORDER BY ID ASC
			LIMIT %d",
			$last_id,
			$batch_size
		);

		$results = $wpdb->get_col($query);

		if (empty($results)) {
			return array();
		}

		// Filter to only include modified products that should be synced
		$product_ids = array();
		foreach ($results as $product_id) {
			$product = wc_get_product($product_id);

			if ($product) {
				// Skip parent variable products as they can't be synced directly
				if ($product->is_type('variable')) {
					continue;
				}

				$last_sync_time = get_post_meta($product_id, '_fb_sync_last_time', true);
				$modified_time = $product->get_date_modified() ? $product->get_date_modified()->getTimestamp() : 0;

				// If never synced or modified since last sync, add to sync queue
				if ((!$last_sync_time || $modified_time > $last_sync_time) &&
					\WooCommerce\Facebook\Products::product_should_be_synced($product)) {
					$product_ids[] = (int) $product_id;
				}
			}
		}

		return $product_ids;
	}

	/**
	 * Adds all eligible product IDs to the requests array to be created or updated.
	 * which are coming form bulk edit
	 *
	 * @see \WC_Facebook_Product_Feed::get_product_ids()
	 * @see \WC_Facebook_Product_Feed::write_product_feed_file()
	 *
	 * @since 3.5.3
	 *
	 * @param array $product_ids for the bulk edit
	 */
	public function create_or_update_all_products_for_bulk_edit( array $product_ids ) {
		$profiling_logger = facebook_for_woocommerce()->get_profiling_logger();
		$profiling_logger->start( 'create_or_update_all_products_for_bulk_edit' );

		$parent_products    = [];
		$variation_products = [];

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product->is_type( 'variable' ) ) {
				$parent_products[] = $product_id;
				foreach ( $product->get_children() as $child_id ) {
					$variation_products[] = $child_id;
				}
			}
		}

		$final_product_ids = array_diff( $product_ids, $parent_products );
		$final_product_ids = array_merge( $final_product_ids, $variation_products );

		// Queue up these IDs for sync. they will only be included in the final requests if they should be synced.
		$this->create_or_update_products( $final_product_ids );

		$profiling_logger->stop( 'create_or_update_all_products_for_bulk_edit' );
	}


	/**
	 * Adds the given product IDs to the requests array to be updated.
	 *
	 * @since 2.0.0
	 *
	 * @param int[] $product_ids
	 */
	public function create_or_update_products( array $product_ids ) {
		foreach ( $product_ids as $product_id ) {
			$this->requests[ $this->get_product_index( $product_id ) ] = self::ACTION_UPDATE;
		}
	}


	/**
	 * Adds the given retailer IDs to the requests array to be deleted.
	 *
	 * @since 2.0.0
	 *
	 * @param int[] $retailer_ids retailer IDs to delete
	 */
	public function delete_products( array $retailer_ids ) {

		foreach ( $retailer_ids as $retailer_id ) {
			$this->requests[ $this->get_product_index( $retailer_id ) ] = self::ACTION_DELETE;
		}
	}


	/**
	 * Adds the products with stock changes to the requests array to be updated.
	 *
	 * @since 2.1.0
	 *
	 * @param \WC_Product $product product object
	 */
	public function handle_stock_update( \WC_Product $product ) {

		// bail if not connected
		if ( ! facebook_for_woocommerce()->get_connection_handler()->is_connected() ) {
			return;
		}

		// bail if admin and not AJAX
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		// add the product to the list of products to be updated
		$this->create_or_update_products( array( $product->get_id() ) );
	}


	/**
	 * Creates a background job to sync the products in the requests array.
	 *
	 * @since 2.0.0
	 *
	 * @return \stdClass|object|null
	 */
	public function schedule_sync() {

		if ( ! empty( $this->requests ) ) {

			$job_handler = facebook_for_woocommerce()->get_products_sync_background_handler();
			$job         = $job_handler->create_job( array( 'requests' => $this->requests ) );

			$job_handler->dispatch();

			return $job;
		}
	}


	/**
	 * Gets the prefixed product ID used as the array index.
	 *
	 * @since 2.0.0
	 *
	 * @param int|string $product_id product ID
	 * @return string prefixed product index
	 */
	private function get_product_index( $product_id ) {

		return self::PRODUCT_INDEX_PREFIX . $product_id;
	}


	/**
	 * Determines whether a sync is currently in progress.
	 *
	 * @since 2.0.0
	 *
	 * @return bool
	 */
	public static function is_sync_in_progress() {

		$jobs = facebook_for_woocommerce()->get_products_sync_background_handler()->get_jobs(
			array(
				'status' => 'processing',
			)
		);

		return ! empty( $jobs );
	}
}
