<?php
/**
 * Plugin Name:     ChatScribe
 * Plugin URI:      
 * Description:     An integrated AI chatbot for WordPress.
 * Author:          Claudette Raynor
 * Author URI:      https://claudetteraynor.info
 * Text Domain:     chatscribe
 * Domain Path:     /languages
 * Version:         0.1.0
 * Update URI:      false
 *
 * @package         ChatScribe
 */


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CHATSCRIBE_FILE', __FILE__ );

require_once __DIR__ . '/vendor/autoload.php';

new ChatScribe\Plugin();