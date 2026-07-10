<?php

namespace Drw\App\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Basic, transient-backed rate limiter for REST endpoints, used as defense
 * in depth on top of (never instead of) a real permission_callback / capability
 * check. Suitable for cheap, per-user throttling of admin-UI "helper" endpoints
 * (live availability checks, pre-flight validators) that would otherwise let an
 * already-authorized-but-compromised session or a buggy client hammer the
 * database with repeated queries.
 *
 * Backed by get_transient()/set_transient(): on a persistent object cache
 * (Redis/Memcached) the counter is shared across all web workers; on the
 * default DB-backed transient fallback it still works, just slower. Either
 * way this is NOT a substitute for edge/WAF-level rate limiting against a
 * determined attacker -- see the class docblock note below on its known
 * limitations.
 *
 * Not atomic: check() performs a plain get_transient() then set_transient()
 * (read-modify-write), so a handful of concurrent requests hitting the exact
 * same bucket at the same instant could each read the same pre-increment
 * count and all be admitted. That race is an accepted trade-off for a
 * best-effort, defense-in-depth guard -- the real access gate remains the
 * endpoint's own permission_callback.
 */
class RateLimiter {

	/**
	 * Transient key prefix. The bucket identifier is hashed with md5() so any
	 * caller-supplied string (which may be arbitrarily long or contain
	 * characters unsafe for an option name, e.g. "check-code:123") maps to a
	 * short, fixed-length, storage-safe key.
	 */
	const KEY_PREFIX = 'drw_rl_';

	/**
	 * Whether another attempt is allowed for the given bucket within the
	 * rolling window, recording the attempt if so.
	 *
	 * The window is renewed on every accepted attempt (set_transient() is
	 * called with the full $window_seconds each time), so this behaves as a
	 * sliding window rather than a fixed one: a bucket that keeps receiving
	 * accepted requests never fully resets until it goes quiet for
	 * $window_seconds. That is intentional for a basic abuse guard -- it
	 * caps steady-state request rate rather than only bursts -- but it means
	 * callers should not assume the counter clears at a predictable wall-clock
	 * boundary.
	 *
	 * @param string $bucket         Identifier for what is being limited, e.g. 'check-code:42'.
	 * @param int    $max_attempts   Maximum attempts allowed within the window.
	 * @param int    $window_seconds Length of the rolling window, in seconds.
	 * @return bool True (and increments the counter) if under the limit;
	 *              false (and leaves the counter untouched) once the limit
	 *              has been reached.
	 */
	public static function check( $bucket, $max_attempts, $window_seconds ) {
		$key          = self::KEY_PREFIX . md5( (string) $bucket );
		$max_attempts = (int) $max_attempts;

		$stored = get_transient( $key );
		$count  = is_numeric( $stored ) ? (int) $stored : 0;

		if ( $count >= $max_attempts ) {
			return false;
		}

		set_transient( $key, $count + 1, (int) $window_seconds );

		return true;
	}
}
