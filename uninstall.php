<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit();
}

/**
 * Delete plugin options.
 */
$delete_option = is_multisite() ? 'delete_site_option' : 'delete_option';
$delete_option( 'plugin-columns-plugins' );
$delete_option( 'plugin-columns-categories' );
$delete_option( 'plugin-columns-imported-plugins' );
$delete_option( 'plugin-columns-options' );
$delete_option( 'plugin-columns-trash' );
$delete_option( 'plugin-columns-pinned-categories');
$delete_option( 'plugin-columns-hidden-categories' );
$delete_option( 'plugin-columns-warning-categories' );
$delete_option( 'plugin-columns-noupdate-categories' );
$delete_option( 'plugin-columns-noupdate-plugins' );
