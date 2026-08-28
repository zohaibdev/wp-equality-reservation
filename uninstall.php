<?php
/**
 * Runs when the plugin is deleted from the WordPress admin.
 * Removes the stored settings option.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'erf_settings' );
