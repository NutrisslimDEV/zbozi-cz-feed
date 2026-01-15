<?php
/**
 * Plugin Name: Zboží.cz Feed (CZ)
 * Description: Generates a Zboží.cz-compatible XML feed (sklik_cz-datasource.xml) from WooCommerce products.
 * Version: 1.0.0
 * Author: Nutrisslim DEV team
 * License: GPLv2 or later
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'ZBOZI_CZ_FEED_VERSION', '1.0.0' );
define( 'ZBOZI_CZ_FEED_DIR', plugin_dir_path( __FILE__ ) );
define( 'ZBOZI_CZ_FEED_URL', plugin_dir_url( __FILE__ ) );

// includes (no composer)
require_once ZBOZI_CZ_FEED_DIR . 'src/Utils/XmlHelper.php';
require_once ZBOZI_CZ_FEED_DIR . 'src/Repositories/ProductRepository.php';
require_once ZBOZI_CZ_FEED_DIR . 'src/Services/FeedBuilder.php';
require_once ZBOZI_CZ_FEED_DIR . 'src/Controllers/AdminController.php';

add_action( 'plugins_loaded', function () {
    if ( ! class_exists( 'WooCommerce' ) ) { return; }
    ( new \ZboziCZ\Controllers\AdminController() )->hooks();
} );
