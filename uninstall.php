<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
$gz_ai_settings = get_option( 'gz_ai_settings', array() );
if ( ! empty( $gz_ai_settings['delete_data'] ) ) { delete_option( 'gz_ai_settings' ); }
wp_clear_scheduled_hook( 'gz_ai_cleanup_rates' );
delete_option( 'gz_ai_rewrite_version' );
global $wpdb;
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'gz_ai_rate_' ) . '%' ) );
