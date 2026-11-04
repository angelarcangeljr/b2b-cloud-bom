<?php
// Router for PHP's built-in web server (used by start-mac.sh):
//   php -S localhost:8000 router.php
// The built-in server ignores .htaccess, so this blocks the same private files.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');

if (preg_match('~(^|/)\.|^/(inc|tools|standalone)(/|$)|^/(config(\.sample)?\.php|schema\.sql|README\.md|router\.php|start-mac\.sh)$~i', $path)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    exit('Not found');
}
if ($path === '/') {
    require __DIR__ . '/index.php';
    return true;
}
return false; // let the built-in server serve the PHP page or static file
