<?php
/**
 * Router for PHP's built-in dev server (`php -S localhost:8000 router.php`).
 *
 * Why this exists: without a router, `php -S` falls back to serving index.php
 * (HTTP 200) for ANY path that doesn't resolve to a real file. That breaks two
 * things for the replay viewer:
 *   1. The "is this map exported?" check (HEAD maps/<map>/index.json) — a missing
 *      export would look like 200 OK instead of 404.
 *   2. The GOKZ engine itself, which expects a real 404 when map files are absent.
 *
 * This router serves real files as-is and returns a genuine 404 for anything
 * that isn't a real file, while still routing the app (and its ?history /
 * ?replays / favorite endpoints) through index.php.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$full = __DIR__ . urldecode($path);

// Real, existing file (css, js, png, the exported maps, replays, etc.) -> let
// the built-in server handle it. For *.php this executes the script.
if ($path !== '/' && is_file($full)) {
    return false;
}

// App entrypoint.
if ($path === '/' || $path === '/index.php') {
    require __DIR__ . '/index.php';
    return true;
}

// Anything else that isn't a real file is a genuine 404 (don't leak index.php).
http_response_code(404);
header('Content-Type: text/plain');
echo 'Not Found';
return true;
