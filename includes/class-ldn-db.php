<?php
/**
 * PostgreSQL connection helper for read-only ops queries (page_url_registry).
 *
 * Credentials mirror loupe-pricing-wp-plugin DP_Config: wp-config constants
 * DB_*_PG first, then environment variables.
 *
 * @package LoupeDiamondNetwork
 * @since   0.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LDN_Db {

    /**
     * @var resource|null
     */
    private static $connection = null;

    /**
     * @param string $constant wp-config constant name.
     * @param string $env      Environment variable fallback.
     * @param string $default
     * @return string
     */
    private static function config_value($constant, $env, $default = '') {
        if (defined($constant)) {
            return (string) constant($constant);
        }
        $from_env = getenv($env);
        if ($from_env !== false && $from_env !== '') {
            return (string) $from_env;
        }
        return $default;
    }

    /**
     * @return string
     */
    public static function connection_string() {
        $host = self::config_value('DB_HOST_PG', 'DB_HOST', 'localhost');
        $port = self::config_value('DB_PORT_PG', 'DB_PORT', '5432');
        $name = self::config_value('DB_NAME_PG', 'DB_NAME', 'postgres');
        $user = self::config_value('DB_USER_PG', 'DB_USER', 'postgres');
        $pass = self::config_value('DB_PASSWORD_PG', 'DB_PASSWORD', '');

        return sprintf(
            'host=%s port=%s dbname=%s user=%s password=%s sslmode=require',
            $host,
            $port,
            $name,
            $user,
            $pass
        );
    }

    /**
     * @return bool
     */
    public static function is_available() {
        return function_exists('pg_connect');
    }

    /**
     * @return resource|null PostgreSQL connection or null on failure.
     */
    public static function connection() {
        if (!self::is_available()) {
            return null;
        }
        if (self::$connection !== null) {
            $status = @pg_connection_status(self::$connection);
            if ($status === PGSQL_CONNECTION_OK) {
                return self::$connection;
            }
            self::$connection = null;
        }

        $conn = @pg_connect(self::connection_string());
        if ($conn === false) {
            LDN_Plugin::debug_log('Db', 'PostgreSQL connection failed');
            return null;
        }
        self::$connection = $conn;
        return self::$connection;
    }

    /**
     * @return void
     */
    public static function close() {
        if (self::$connection !== null) {
            @pg_close(self::$connection);
            self::$connection = null;
        }
    }
}
