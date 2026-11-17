<?php
/**
 * Plugin Name:     ChatScribe
 * Plugin URI:      https://github.com/Good-Work-Marketing/chatscribe
 * Description:     A simple AI assistant for WordPress.
 * Author:          Good Work Marketing, Claudette Raynor
 * Author URI:      https://goodworkmarketing.com
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
