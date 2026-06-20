<?php
/**
 * Environment resolver.
 *
 * Answers "is this install production or staging?" — the second axis of a
 * dynamic route (the first being site identity, see LDN_Site_Resolver).
 *
 * Resolution order (first match wins):
 *   1. `LDN_ENVIRONMENT` constant — explicit per-install override.
 *   2. `wp_get_environment_type()` — WordPress core (WP 5.5+). Kinsta sets the
 *      backing `WP_ENVIRONMENT_TYPE` automatically per environment (Live →
 *      production, Staging → staging, Premium Staging → development) and strips
 *      the staging markers when a site is pushed to live, so this is correct on
 *      both sides without any per-URL bookkeeping.
 *   3. Default `production` (safe default when nothing is set).
 *
 * The rollout contract has exactly two buckets — `production` and `staging` —
 * so any non-production type (`staging`, `development`, `local`) normalises to
 * `staging`. This deliberately treats Premium Staging (`development`) as
 * staging, rather than silently falling through to production.
 *
 * NOTE: do not hard-code `WP_ENVIRONMENT_TYPE` in a committed wp-config.php —
 * it is cloned to staging and would then mislabel the clone. Let Kinsta own it;
 * use `LDN_ENVIRONMENT` only for non-Kinsta edge cases.
 *
 * @package LoupeDiamondNetwork
 * @since   0.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Environment {

    /**
     * Canonical environment buckets (match the rollout contract).
     */
    const PRODUCTION = 'production';
    const STAGING = 'staging';

    /**
     * Resolve the current environment bucket.
     *
     * @return string LDN_Environment::PRODUCTION | LDN_Environment::STAGING
     */
    public static function current() {
        if (defined('LDN_ENVIRONMENT') && LDN_ENVIRONMENT) {
            return self::normalize((string) LDN_ENVIRONMENT);
        }
        if (function_exists('wp_get_environment_type')) {
            return self::normalize((string) wp_get_environment_type());
        }
        return self::PRODUCTION;
    }

    /**
     * Normalise an arbitrary environment type to one of the two buckets.
     * Only the exact value "production" is production; everything else
     * (staging, development, local, …) is treated as staging.
     *
     * @param string $type
     * @return string
     */
    public static function normalize($type) {
        return strtolower(trim((string) $type)) === self::PRODUCTION
            ? self::PRODUCTION
            : self::STAGING;
    }

    /**
     * @return bool
     */
    public static function is_production() {
        return self::current() === self::PRODUCTION;
    }

    /**
     * @return bool
     */
    public static function is_staging() {
        return self::current() === self::STAGING;
    }
}
