<?php
/**
 * Plugin Name: Mishtalk replace custom CLI
 * Description: Custom WP-CLI command to search for 'moneymaven.io' instances in the database.
 * Version: 1.0
 * Author: Your Name
 */

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once __DIR__ . '/class-search-replace-urls-command.php';
}
?>
