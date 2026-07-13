<?php
/**
 * Router for PHP's built-in web server so it can serve WordPress.
 *
 * Serves existing static files directly; routes everything else through
 * WordPress (index.php) so permalinks and wp-admin work as expected.
 */
$root = getcwd();
$uri  = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$path = realpath($root . $uri);

// Serve real files (css/js/images/php in wp-admin, etc.) directly.
if ($path && strpos($path, $root) === 0 && is_file($path)) {
    if (preg_match('/\.php$/', $path)) {
        require $path;
        return true;
    }
    return false; // let the built-in server serve the static asset
}

// Fall back to WordPress front controller.
require $root . '/index.php';
