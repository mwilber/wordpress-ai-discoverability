<?php
namespace GZ_AI;
defined( 'ABSPATH' ) || exit;

/** Database-backed rolling 60-second budgets, shared across PHP workers and MCP URLs. */
final class Rate_Limiter {
	const PREFIX = 'gz_ai_rate_log_';

	public static function consume( string $bucket, int $limit ): array {
		global $wpdb;
		$now = time();
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		$key = self::PREFIX . hash_hmac( 'sha256', $bucket . '|' . $ip, wp_salt( 'nonce' ) );
		$denied = array( 'limited' => true, 'remaining' => 0, 'reset' => $now + 60 );
		if ( ! wp_next_scheduled( 'gz_ai_cleanup_rates' ) ) { wp_schedule_single_event( $now + 120, 'gz_ai_cleanup_rates' ); }

		// Compare-and-swap prevents concurrent workers from spending the same allowance.
		// Direct SQL avoids dependence on a persistent object cache or cached option reads.
		for ( $attempt = 0; $attempt < 10; ++$attempt ) {
			$now = time();
			$denied['reset'] = $now + 60;
			$old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
			if ( $wpdb->last_error ) { return $denied; }
			$times = null === $old ? array() : json_decode( substr( $old, 13 ), true );
			if ( ! is_array( $times ) || ! array_is_list( $times ) ) { return $denied; }
			$active = array();
			foreach ( $times as $timestamp ) {
				if ( ! is_int( $timestamp ) ) { return $denied; }
				if ( $timestamp > $now - 60 ) { $active[] = $timestamp; }
			}
			if ( count( $active ) >= $limit ) {
				$denied['reset'] = $active[ count( $active ) - $limit ] + 60;
				return $denied;
			}
			$active[] = $now;
			sort( $active, SORT_NUMERIC );
			// The last accepted request prefixes the value so cron can remove idle rows.
			$value = sprintf( '%012d:', max( $active ) ) . wp_json_encode( $active );
			if ( null === $old ) {
				$stored = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", $key, $value ) );
			} else {
				$stored = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, $key, $old ) );
			}
			if ( false === $stored ) { return $denied; }
			if ( 1 === $stored ) {
				return array( 'limited' => false, 'remaining' => $limit - count( $active ), 'reset' => $active[0] + 60 );
			}
			// Another request changed or removed the row; read it again before retrying.
		}
		// Storage errors or sustained contention fail closed without executing a tool.
		return $denied;
	}

	public static function cleanup(): void {
		global $wpdb;
		$pattern = $wpdb->esc_like( self::PREFIX ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING(option_value, 1, 12) AS UNSIGNED) <= %d", $pattern, time() - 60 ) );
		// Remove counters left by versions that used clock-minute buckets.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND CAST(SUBSTRING(option_name, 12, 12) AS UNSIGNED) < %d", $wpdb->esc_like( 'gz_ai_rate_' ) . '%', $pattern, (int) floor( time() / 60 ) - 1 ) );
	}
}

add_action( 'gz_ai_cleanup_rates', array( Rate_Limiter::class, 'cleanup' ) );
