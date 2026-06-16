<?php
/**
 * KZ:GO map tracker - single file.
 *
 * Architecture note (important):
 *   The full map list is sent to the browser ONCE as JSON. All filtering,
 *   sorting, searching and view switching happen client-side from a single
 *   state object that is mirrored into the URL. There is no server-side
 *   pagination/sort/filter anymore, which is what makes view switching and
 *   toggles stay consistent.
 *
 *   Two lightweight JSON endpoints live at the top of this file:
 *     - GET  ?history=<mapId>        -> run history for one map (lazy loaded)
 *     - POST toggle_favorite+map_name -> flip a favorite, returns new state
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);   // visible for local/personal use; set to 0 for a public server

// --- 1. CONFIG (per-user; created by setup.php -> config.json) ---
// All personal settings live in config.json (git-ignored). If it's missing or
// incomplete, send the user to the setup wizard.
$config_file = __DIR__ . '/config.json';
$cfg = is_file($config_file) ? json_decode(file_get_contents($config_file), true) : null;
if (!is_array($cfg) || empty($cfg['csgo_dir'])) {
    header('Location: setup.php');
    exit;
}

$player_steamid32 = (int) ($cfg['steamid32'] ?? 0);          // primary lookup key (Steam account id)
$player_alias     = (string) ($cfg['alias'] ?? '');          // fallback display name if not found by id
$avatar_file      = (string) ($cfg['avatar_file'] ?? '');    // optional local avatar; falls back to a placeholder
$tiers_file       = 'tiers.json';
$favorites_file   = 'favorites.json';
$images_folder    = 'images';                                // optional: local thumbnails override the remote ones

// CS:GO/GOKZ dedicated-server install. The replay store and live DB default to
// their standard locations under it (overridable in config.json).
$csgo_dir    = rtrim(str_replace('\\', '/', (string) $cfg['csgo_dir']), '/');
$replays_dir = trim((string) ($cfg['replays_dir'] ?? '')) !== '' ? (string) $cfg['replays_dir'] : ($csgo_dir . '/addons/sourcemod/data/gokz-replays/_runs');
$live_db     = trim((string) ($cfg['live_db'] ?? '')) !== ''     ? (string) $cfg['live_db']     : ($csgo_dir . '/addons/sourcemod/data/sqlite/gokz-sqlite.sq3');
$replays_dir = rtrim(str_replace('\\', '/', $replays_dir), '/');   // READ-ONLY; streamed via ?rmap/?rfile
$live_db     = str_replace('\\', '/', $live_db);                   // READ-ONLY; copied into cache/ (see below)
// Where the viewer loads map geometry from (SourceUtils export -> mapdata/<map>/index.json).
$map_base_url = (string) ($cfg['map_base_url'] ?? 'mapdata/maps');

// Live GOKZ database (READ-ONLY source). The server keeps it in WAL mode and
// writes to it constantly; opening it directly would touch its -shm file. So we
// copy the DB + its -wal into cache/ and read that copy — the live files are
// never modified. The copy is refreshed only when the live DB changes.
$cache_dir = __DIR__ . '/cache';
$db_file   = $cache_dir . '/gokz-live.sq3';
if (!is_dir($cache_dir)) @mkdir($cache_dir, 0777, true);
if (is_file($live_db)) {
    $src_mtime = (int) filemtime($live_db);
    foreach (['-wal', '-shm'] as $sfx) {
        if (is_file($live_db . $sfx)) $src_mtime = max($src_mtime, (int) filemtime($live_db . $sfx));
    }
    $stamp = $db_file . '.src';
    $have  = is_file($stamp) ? (int) file_get_contents($stamp) : -1;
    if (!is_file($db_file) || $have !== $src_mtime) {
        @copy($live_db, $db_file);                         // main DB (committed pages)
        foreach (['-wal', '-shm'] as $sfx) {               // + WAL holds the newest runs
            if (is_file($live_db . $sfx))      @copy($live_db . $sfx, $db_file . $sfx);
            elseif (is_file($db_file . $sfx))  @unlink($db_file . $sfx);
        }
        @file_put_contents($stamp, (string) $src_mtime);
    }
}

// --- 2. DB CONNECTION (the local copy; reading it merges main + WAL) ---
try {
    $db = new PDO("sqlite:" . $db_file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    http_response_code(500);
    die("<div style='color:white;background:#e74c3c;padding:20px;'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>");
}

// --- 3. PLAYER (look up by SteamID32, fall back to alias) ---
$stmt = $db->prepare("SELECT SteamID32, Alias FROM Players WHERE SteamID32 = :sid LIMIT 1");
$stmt->execute([':sid' => $player_steamid32]);
$player = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$player) {
    $stmt = $db->prepare("SELECT SteamID32, Alias FROM Players WHERE Alias = :alias LIMIT 1");
    $stmt->execute([':alias' => $player_alias]);
    $player = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$player) {
    http_response_code(500);
    die("Player (SteamID32 " . htmlspecialchars((string) $player_steamid32) . " / '" . htmlspecialchars($player_alias) . "') not found in the live DB.");
}
$sid = (int) $player['SteamID32'];
if (!empty($player['Alias'])) $player_alias = $player['Alias'];   // show the live alias

// --- 4. SHARED HELPERS ---
function formatKzTime($rawMs) {
    if ($rawMs === null || $rawMs <= 0) return "--:--.---";
    $totalSeconds = $rawMs / 1000;
    $hours   = (int) floor($totalSeconds / 3600);
    $minutes = (int) floor(($totalSeconds - ($hours * 3600)) / 60);
    $seconds = (int) floor($totalSeconds) % 60;
    $ms      = (int) round(($totalSeconds - floor($totalSeconds)) * 1000);
    if ($ms === 1000) { $ms = 0; $seconds++; } // guard rounding edge
    return $hours > 0
        ? sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $seconds, $ms)
        : sprintf('%02d:%02d.%03d', $minutes, $seconds, $ms);
}

function getRunHistory($db, $sid, $mapid) {
    $stmt = $db->prepare("
        SELECT t.RunTime, t.Teleports, t.Created
        FROM Times t
        JOIN MapCourses mc ON t.MapCourseID = mc.MapCourseID
        WHERE t.SteamID32 = :sid AND mc.MapID = :mapid AND mc.Course = 0
        ORDER BY t.Created DESC
    ");
    $stmt->execute([':sid' => $sid, ':mapid' => $mapid]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Parse + validate a GOKZ .replay header (reads only the first KB). Returns
// ['steamId'=>int|null, 'tickCount'=>int|null] for a REAL replay (magic "gokz"
// and, for format v2, actual recorded ticks), or null if the file is not a
// usable replay (empty/corrupt/placeholder). Used so a map is only flagged as
// "has replay" when a genuine recording exists, not just an empty folder.
function parseReplayHeader($path) {
    $fh = @fopen($path, 'rb');
    if (!$fh) return null;
    $data = fread($fh, 1024);
    fclose($fh);
    if ($data === false || strlen($data) < 24) return null;
    $o = 0;
    $u8  = function () use (&$o, $data) { if ($o + 1 > strlen($data)) return null; $v = ord($data[$o]); $o += 1; return $v; };
    $i32 = function () use (&$o, $data) { if ($o + 4 > strlen($data)) return null; $v = unpack('V', substr($data, $o, 4))[1]; $o += 4; return $v; };
    $str = function () use (&$o, $data, $u8) { $len = $u8(); if ($len === null || $o + $len > strlen($data)) return null; $s = substr($data, $o, $len); $o += $len; return $s; };

    if ($i32() !== 0x676F6B7A) return null;          // magic "gokz"
    $fmt = $u8();
    if ($fmt === null) return null;
    if ($fmt < 2) return ['steamId' => null, 'tickCount' => null];  // older format: accept, player unknown

    $u8();                                            // replayType
    if ($str() === null) return null;                // pluginVersion
    if ($str() === null) return null;                // mapName
    $i32(); $i32(); $i32();                           // mapFileSize, serverIP, timestamp
    if ($str() === null) return null;                // playerAlias
    $steamId = $i32();                               // playerSteamID (account id)
    $u8(); $u8();                                     // mode, style
    $i32(); $i32(); $i32();                           // sensitivity, m_yaw, tickrate
    $tickCount = $i32();                             // tickCount
    if ($steamId === null || $tickCount === null) return null;
    if ($tickCount <= 0) return null;                // no recorded movement -> not a real replay
    return ['steamId' => $steamId, 'tickCount' => $tickCount];
}

// =====================================================================
// AJAX ENDPOINTS - must run before any HTML is emitted.
// =====================================================================

// Lazy run-history for a single map.
if (isset($_GET['history'])) {
    header('Content-Type: application/json');
    $mapid = (int) $_GET['history'];
    $rows  = $mapid > 0 ? getRunHistory($db, $sid, $mapid) : [];
    $out   = array_map(function ($r) {
        $tp = (int) $r['Teleports'];
        return [
            'dateStr'   => (!empty($r['Created']) && $r['Created'] !== 'N/A') ? date('Y-m-d', strtotime($r['Created'])) : '--',
            'timeStr'   => formatKzTime($r['RunTime']),
            'teleports' => $tp,
            'pro'       => $tp === 0,
        ];
    }, $rows);
    echo json_encode($out);
    exit;
}

// Lazy list of replay files available for one map (Replays tab).
// Files live at <replays_dir>/<map>/<course>_<mode>_<style>_<TYPE>.replay (GOKZ's native layout).
if (isset($_GET['replays'])) {
    header('Content-Type: application/json');
    $map = strtolower(basename(trim($_GET['replays'])));   // basename() blocks path traversal
    $out = [];
    $dir = $replays_dir . '/' . $map;
    // SteamID32 -> alias, so each recording shows who ran it.
    $alias_by_id = [];
    foreach ($db->query("SELECT SteamID32, Alias FROM Players") as $p) {
        $alias_by_id[(int) $p['SteamID32']] = $p['Alias'];
    }
    if ($map !== '' && is_dir($dir)) {
        foreach (glob($dir . '/*.replay') as $f) {
            $hdr = parseReplayHeader($f);
            if ($hdr === null) continue;                    // skip empty/corrupt/placeholder files
            $file  = basename($f);
            $stem  = pathinfo($file, PATHINFO_FILENAME);    // e.g. "0_KZT_NRM_NUB"
            $parts = explode('_', $stem);                   // tolerant: unknown names still list
            $out[] = [
                'file'    => $file,
                // streamed through PHP because the store is outside the web root
                'url'     => 'index.php?rmap=' . rawurlencode($map) . '&rfile=' . rawurlencode($file),
                'course'  => (isset($parts[0]) && is_numeric($parts[0])) ? (int) $parts[0] : null,
                'mode'    => $parts[1] ?? null,
                'style'   => $parts[2] ?? null,
                'type'    => $parts[3] ?? null,
                'steamid' => $hdr['steamId'],
                'player'  => ($hdr['steamId'] !== null && isset($alias_by_id[$hdr['steamId']])) ? $alias_by_id[$hdr['steamId']] : null,
            ];
        }
    }
    echo json_encode($out);
    exit;
}

// Stream a single .replay file from the external (read-only) GOKZ store.
// Validated + path-confined so only real *.replay files under $replays_dir are served.
if (isset($_GET['rmap']) && isset($_GET['rfile'])) {
    $map  = basename($_GET['rmap']);
    $file = basename($_GET['rfile']);
    if (!preg_match('/^[A-Za-z0-9_.\-]+$/', $map) || !preg_match('/^[A-Za-z0-9_.\-]+\.replay$/', $file)) {
        http_response_code(400); exit;
    }
    $real = realpath($replays_dir . '/' . $map . '/' . $file);
    $base = realpath($replays_dir);
    $norm = function ($p) { return str_replace('\\', '/', (string) $p); };
    if ($real === false || $base === false
        || strpos($norm($real), rtrim($norm($base), '/') . '/') !== 0
        || !is_file($real)) {
        http_response_code(404); exit;
    }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($real));
    header('Cache-Control: no-cache');
    readfile($real);
    exit;
}

// Toggle a favorite (POST only, returns JSON, no page reload).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_favorite'])) {
    header('Content-Type: application/json');
    $name = trim($_POST['map_name'] ?? '');
    $key  = strtolower($name);

    $favs = [];
    if (file_exists($favorites_file)) {
        $decoded = json_decode(file_get_contents($favorites_file), true);
        if (is_array($decoded)) $favs = $decoded;
    }

    $favorited = false;
    $ok        = true;
    if ($key !== '') {
        if (isset($favs[$key])) {
            unset($favs[$key]);
            $favorited = false;
        } else {
            $favs[$key] = ['name' => $name, 'added' => date('Y-m-d H:i:s')];
            $favorited = true;
        }
        $ok = file_put_contents($favorites_file, json_encode($favs, JSON_PRETTY_PRINT), LOCK_EX) !== false;
    } else {
        $ok = false;
    }

    echo json_encode(['ok' => $ok, 'favorited' => $favorited, 'name' => $name]);
    exit;
}

// =====================================================================
// MAIN PAGE - build the full dataset once.
// =====================================================================

// --- 5. TIERS ---
$tiers_lookup = [];
if (file_exists($tiers_file)) {
    $json_data = json_decode(file_get_contents($tiers_file), true);
    if (is_array($json_data)) {
        foreach ($json_data as $map_info) {
            if (empty($map_info['name'])) continue;
            if (!empty($map_info['disabled'])) continue;   // maps parked at the end of tiers.json
            $tiers_lookup[strtolower($map_info['name'])] = $map_info;
        }
    }
}

// --- 6. FAVORITES ---
$favorites = [];
if (file_exists($favorites_file)) {
    $decoded = json_decode(file_get_contents($favorites_file), true);
    if (is_array($decoded)) $favorites = $decoded;
}

// --- 7. OPTIONAL LOCAL IMAGES (single glob instead of N file_exists calls) ---
$local_images = [];
if (is_dir($images_folder) && is_readable($images_folder)) {
    foreach (glob($images_folder . '/*.{jpg,jpeg,png,JPG,JPEG,PNG}', GLOB_BRACE) as $f) {
        $base = strtolower(pathinfo($f, PATHINFO_FILENAME));
        if (!isset($local_images[$base])) $local_images[$base] = $f;
    }
}

// --- 9. COMPLETIONS (two aggregations: best PRO, best TP) ---
// SQLite returns the Created value from the MIN(RunTime) row (bare-column rule),
// so ProCreated/TpCreated line up with the displayed best time.
$stmt_pro = $db->prepare("
    SELECT m.MapID, m.Name AS MapName, MIN(t.RunTime) AS ProTime, t.Created AS ProCreated
    FROM Maps m
    JOIN MapCourses mc ON m.MapID = mc.MapID
    JOIN Times t       ON mc.MapCourseID = t.MapCourseID
    WHERE t.SteamID32 = :sid AND mc.Course = 0 AND t.Teleports = 0
    GROUP BY m.MapID
");
$stmt_pro->execute([':sid' => $sid]);
$pro_by_name = [];
foreach ($stmt_pro->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $pro_by_name[strtolower($r['MapName'])] = $r;
}

$stmt_tp = $db->prepare("
    SELECT m.MapID, m.Name AS MapName, MIN(t.RunTime) AS TpTime, t.Created AS TpCreated
    FROM Maps m
    JOIN MapCourses mc ON m.MapID = mc.MapID
    JOIN Times t       ON mc.MapCourseID = t.MapCourseID
    WHERE t.SteamID32 = :sid AND mc.Course = 0 AND t.Teleports > 0
    GROUP BY m.MapID
");
$stmt_tp->execute([':sid' => $sid]);
$tp_by_name = [];
foreach ($stmt_tp->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $tp_by_name[strtolower($r['MapName'])] = $r;
}

// --- 9b. AVAILABLE REPLAYS (validated + cached) ---
// A map only counts as "has replay" when a folder holds a REAL recording (valid
// gokz header + actual ticks) — not just an empty/placeholder folder. We also
// record which player(s) own each map's recordings so the Replays tab can filter
// by player. Reading 1000+ headers is cached and rebuilt only when the store changes.
$replay_index = [];   // slug => [steamid32, ...] for maps with >=1 valid replay
if (is_dir($replays_dir)) {
    // Cache strategy: cached loads cost one filemtime + a JSON read. Rebuild only
    // when a new map folder appears (cheap dir-mtime check) or the cache is older
    // than the TTL (catches new recordings dropped into existing folders).
    $cache_idx = $cache_dir . '/replays-index.json';
    $dir_mtime = (int) filemtime($replays_dir);
    $cached    = is_file($cache_idx) ? json_decode(file_get_contents($cache_idx), true) : null;
    $fresh = is_array($cached) && isset($cached['maps'])
        && (int) ($cached['dirmtime'] ?? -1) === $dir_mtime
        && (time() - (int) ($cached['built'] ?? 0)) < 20;          // TTL seconds
    if ($fresh) {
        $replay_index = $cached['maps'];
    } else {
        foreach (glob($replays_dir . '/*', GLOB_ONLYDIR) as $d) {
            $slug = strtolower(basename($d));
            $players = []; $hasValid = false;
            foreach (glob($d . '/*.replay') as $f) {
                $hdr = parseReplayHeader($f);
                if ($hdr === null) continue;               // empty/corrupt/placeholder -> ignore
                $hasValid = true;
                if ($hdr['steamId'] !== null) $players[$hdr['steamId']] = true;
            }
            if ($hasValid) $replay_index[$slug] = array_map('intval', array_keys($players));
        }
        @file_put_contents($cache_idx, json_encode(['dirmtime' => $dir_mtime, 'built' => time(), 'maps' => $replay_index]));
    }
}

// Filter dropdown: every known account, with the ones who actually hold replays
// listed first (so a player with no replays yet is still selectable).
$alias_by_id = [];
foreach ($db->query("SELECT SteamID32, Alias FROM Players") as $p) {
    $alias_by_id[(int) $p['SteamID32']] = $p['Alias'];
}
$seen_ids = [];
foreach ($replay_index as $ids) foreach ($ids as $id) $seen_ids[(int) $id] = true;
$replay_players = [];
foreach (array_keys($seen_ids) as $id) {                       // replay-holders first
    if ($id === 0) continue;
    $replay_players[] = ['id' => (int) $id, 'alias' => $alias_by_id[$id] ?? ('Steam ' . $id)];
}
foreach ($alias_by_id as $id => $alias) {                      // then any other known account
    if (!isset($seen_ids[$id])) $replay_players[] = ['id' => (int) $id, 'alias' => $alias];
}

// --- 10. BUILD UNIFIED MAP LIST + TIER STATS ---
$remote_base = 'https://raw.githubusercontent.com/KZGlobalTeam/map-images/public/mediums/';
$maps        = [];
$tier_stats  = array_fill(1, 7, ['done' => 0, 'total' => 0]);

foreach ($tiers_lookup as $key => $info) {
    $name = $info['name'];
    $tier = (int) ($info['tier'] ?? 1);

    $pro = $pro_by_name[$key] ?? null;
    $tp  = $tp_by_name[$key]  ?? null;

    $proTime = $pro ? (int) $pro['ProTime'] : null;
    $tpTime  = $tp  ? (int) $tp['TpTime']   : null;
    $mapId   = $pro['MapID'] ?? ($tp['MapID'] ?? null);
    $isMissing = ($proTime === null && $tpTime === null);

    // Best = smallest available time.
    $bestTime = null; $bestCreated = null;
    if ($proTime !== null) { $bestTime = $proTime; $bestCreated = $pro['ProCreated']; }
    if ($tpTime !== null && ($bestTime === null || $tpTime < $bestTime)) {
        $bestTime = $tpTime; $bestCreated = $tp['TpCreated'];
    }

    $slug = strtolower(str_replace(' ', '_', $name));
    $img  = $local_images[$slug] ?? ($remote_base . rawurlencode($slug) . '.jpg');

    $maps[] = [
        'name'        => $name,
        'tier'        => $tier,
        'isMissing'   => $isMissing,
        'isFavorite'  => isset($favorites[$key]),
        'mapId'       => $mapId !== null ? (int) $mapId : null,
        // numeric (for sorting)
        'proTime'     => $proTime,
        'tpTime'      => $tpTime,
        'bestTime'    => $bestTime,
        'proDate'     => $pro ? strtotime($pro['ProCreated']) : null,
        'tpDate'      => $tp  ? strtotime($tp['TpCreated'])   : null,
        'bestDate'    => $bestCreated ? strtotime($bestCreated) : null,
        // preformatted (for display, avoids timezone drift + a JS formatter)
        'proStr'      => formatKzTime($proTime),
        'tpStr'       => formatKzTime($tpTime),
        'bestStr'     => formatKzTime($bestTime),
        'bestDateStr' => $bestCreated ? date('Y-m-d', strtotime($bestCreated)) : '--',
        'img'         => $img,
        // replay viewer support: folder slug, whether a VALID replay exists, + who owns them
        'slug'          => $slug,
        'hasReplay'     => isset($replay_index[$slug]),
        'replayPlayers' => $replay_index[$slug] ?? [],
    ];

    if ($tier >= 1 && $tier <= 7) {
        $tier_stats[$tier]['total']++;
        if (!$isMissing) $tier_stats[$tier]['done']++;
    }
}

// --- 11. ASSETS / PLACEHOLDERS ---
$placeholder_avatar = 'data:image/svg+xml,' . rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"><rect width="80" height="80" fill="#222"/><text x="40" y="53" font-size="42" fill="#555" text-anchor="middle" font-family="sans-serif">?</text></svg>'
);
$placeholder_card = 'data:image/svg+xml,' . rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="150"><rect width="300" height="150" fill="#2d2d2d"/><text x="150" y="80" font-size="16" fill="#666" text-anchor="middle" font-family="sans-serif">No Image</text></svg>'
);

$avatar = $placeholder_avatar;
if ($avatar_file && file_exists($avatar_file)) $avatar = $avatar_file;

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>KZ:GO - <?= htmlspecialchars($player_alias) ?></title>
<link href="https://fonts.googleapis.com/css?family=Lato:400,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- GOKZ replay viewer (vendored from github.com/Metapyziks/GOKZReplayViewer) -->
<link type="text/css" rel="stylesheet" href="replayviewer/styles/mapviewer.css">
<link type="text/css" rel="stylesheet" href="replayviewer/styles/replayviewer.css">
<style>
:root{
    --bg:#121212;--darker:#181818;--dark:#303030;--text:#ddd;--primary:#20c997;
    --t1:#049c49;--t2:#007053;--t3:#f39c12;--t4:#fd7e14;--t5:#e74c3c;--t6:#c52412;--t7:#d22ce5;
}
*{box-sizing:border-box;}
body{background:var(--bg);color:var(--text);font-family:'Lato',sans-serif;margin:0;padding:20px;}
.container{max-width:1200px;margin:0 auto;}
.header{display:flex;align-items:center;margin-bottom:30px;border-bottom:1px solid var(--dark);padding-bottom:20px;}
.avatar{width:70px;height:70px;border-radius:50%;border:3px solid var(--primary);margin-right:20px;object-fit:cover;background:#222;}

/* TIER GRID */
.tier-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:10px;margin-bottom:30px;}
.tier-card{background:var(--darker);border:1px solid var(--dark);padding:12px;border-radius:6px;text-align:center;cursor:pointer;text-decoration:none;color:inherit;transition:.15s;}
.tier-card:hover{background:#222;}
/* borders are always tinted to the tier color */
.tier-card[data-tier="1"]{border-color:var(--t1);}
.tier-card[data-tier="2"]{border-color:var(--t2);}
.tier-card[data-tier="3"]{border-color:var(--t3);}
.tier-card[data-tier="4"]{border-color:var(--t4);}
.tier-card[data-tier="5"]{border-color:var(--t5);}
.tier-card[data-tier="6"]{border-color:var(--t6);}
.tier-card[data-tier="7"]{border-color:var(--t7);}
/* hover adds the glow; selected keeps that exact glow lit */
.tier-card[data-tier="1"]:hover,.tier-card.active[data-tier="1"]{box-shadow:0 0 8px var(--t1);}
.tier-card[data-tier="2"]:hover,.tier-card.active[data-tier="2"]{box-shadow:0 0 8px var(--t2);}
.tier-card[data-tier="3"]:hover,.tier-card.active[data-tier="3"]{box-shadow:0 0 8px var(--t3);}
.tier-card[data-tier="4"]:hover,.tier-card.active[data-tier="4"]{box-shadow:0 0 8px var(--t4);}
.tier-card[data-tier="5"]:hover,.tier-card.active[data-tier="5"]{box-shadow:0 0 8px var(--t5);}
.tier-card[data-tier="6"]:hover,.tier-card.active[data-tier="6"]{box-shadow:0 0 8px var(--t6);}
.tier-card[data-tier="7"]:hover,.tier-card.active[data-tier="7"]{box-shadow:0 0 8px var(--t7);}
.tier-count{font-size:16px;font-weight:bold;margin:5px 0;}
.progress-bar{background:#111;height:4px;border-radius:2px;margin-top:10px;overflow:hidden;}
.progress-fill{height:100%;transition:width .8s;}

/* TIER HEADER INFO (filled by JS) */
.tier-header-info{background:var(--darker);padding:15px;border-radius:6px;margin-bottom:20px;border-left:5px solid var(--primary);display:none;justify-content:space-between;align-items:center;}
.th-label{color:#888;text-transform:uppercase;font-size:12px;}
.th-title{margin:0;color:#fff;}
.th-pct{font-size:24px;font-weight:bold;}
.th-sub{font-size:12px;color:#666;}

/* CONTROLS */
.controls{display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;align-items:center;}
.search-input,select.search-input{background:var(--darker);border:1px solid var(--dark);color:#fff;padding:10px 15px;border-radius:4px;font-size:14px;}
#mapSearch{flex:1 1 220px;min-width:180px;}
.btn{background:var(--dark);color:#fff;padding:10px 18px;border-radius:4px;text-decoration:none;font-size:13px;border:1px solid #444;cursor:pointer;transition:background .2s;}
.btn:hover{background:#444;}
.btn.active{background:var(--primary);border-color:var(--primary);}
.order-toggle{min-width:96px;}
.result-count{color:#888;font-size:13px;margin-left:auto;white-space:nowrap;}

/* TABLE VIEW */
.map-list.view-table{display:block;}
.map-table{width:100%;border-collapse:collapse;background:var(--darker);border-radius:8px;overflow:hidden;}
.map-table th{background:#1c1c1c;padding:15px;text-align:left;font-size:11px;color:#888;text-transform:uppercase;}
.map-table td{padding:15px;border-bottom:1px solid #222;}
.map-row{cursor:pointer;}
.map-row:hover td{background:#1e1e1e;}
.tier-pill{padding-left:8px;}
.badge{padding:3px 8px;border-radius:3px;font-size:11px;font-weight:bold;border:1px solid;}
.badge-pro{color:#1e90ff;border-color:#1e90ff;}
.badge-tp{color:orange;border-color:orange;}
.uncompleted-label{color:#444;font-size:11px;}
.drawer{display:none;background:#0c0c0c;}
.drawer-inner{padding:20px;}
.sort-header{cursor:pointer;user-select:none;}
.sort-header:hover{color:#bbb;}
.sort-indicator{margin-left:5px;font-size:10px;}
.fav-link{cursor:pointer;text-decoration:none!important;display:inline-block;}
.fav-link:hover{text-decoration:underline!important;}

/* GRID VIEW */
.map-list.view-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;}
.map-card{background:var(--darker);border-radius:8px;overflow:hidden;border:1px solid var(--dark);transition:transform .2s,box-shadow .2s,border-color .2s;position:relative;cursor:pointer;}
.map-card:hover{transform:translateY(-5px);box-shadow:0 10px 20px rgba(0,0,0,.3);border-color:var(--tier-color);}
.map-card.favorite{border:2px solid var(--tier-color);box-shadow:0 0 10px var(--tier-color);}
.map-card.favorite:hover{border:2px solid var(--tier-color);box-shadow:0 0 15px var(--tier-color),0 10px 20px rgba(0,0,0,.3);}
.map-thumbnail{position:relative;height:150px;overflow:hidden;background:#222;}
.map-thumbnail img{width:100%;height:100%;object-fit:cover;display:block;}
.glow-bar{position:absolute;left:0;width:100%;height:3px;background:var(--tier-color);box-shadow:0 0 8px var(--tier-color);z-index:2;}
.glow-bar.top-bar{top:0;}
.glow-bar.bottom-bar{bottom:0;}
.tier-badge{position:absolute;top:10px;right:10px;background:rgba(0,0,0,.7);color:#fff;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:bold;border:1px solid var(--tier-color);}
.map-info{padding:15px;}
.map-title{margin:0 0 12px 0;font-size:16px;color:#fff;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.map-title a{text-decoration:none!important;}
.map-title a:hover{text-decoration:underline!important;}
.run-times{display:flex;flex-direction:column;gap:8px;}
.run-entry{display:flex;justify-content:space-between;align-items:center;padding:5px 0;}
.run-label{font-size:12px;color:#888;text-transform:uppercase;}
.run-time{font-family:monospace;font-size:14px;font-weight:bold;}
.tp-run .run-time{color:orange;}
.pro-run .run-time{color:#1e90ff;}
.grid-history-drawer{display:none;background:var(--darker);border-top:2px solid var(--tier-color);padding:15px;font-size:13px;}

/* DRAWER / HISTORY CONTENT (shared) */
.hist-title{margin:0 0 12px 0;font-size:12px;color:var(--tier-color,var(--primary));text-transform:uppercase;letter-spacing:1px;}
.hist-loading,.hist-empty{font-size:12px;color:#666;margin:0;}
.hist-scroll{max-height:200px;overflow-y:auto;}
.hist-table{width:100%;font-size:12px;color:#aaa;border-collapse:collapse;}
.hist-table th{background:#1c1c1c;padding:8px;text-align:left;font-size:11px;color:#666;text-transform:uppercase;}
.hist-table td{padding:8px;border-bottom:1px solid #222;}
.hist-table td.hd{color:#999;}
.hist-table td.ht{color:var(--primary);}

/* RESPONSIVE */
@media(max-width:1100px){.map-list.view-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:600px){.map-list.view-grid{grid-template-columns:1fr;}.controls{gap:8px;}.result-count{margin-left:0;}}

/* TABS */
.tab-bar{display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--dark);}
.tab-btn{background:none;border:none;border-bottom:3px solid transparent;color:#888;font-family:inherit;font-size:15px;font-weight:bold;padding:12px 22px;cursor:pointer;transition:color .15s,border-color .15s;display:flex;align-items:center;gap:8px;}
.tab-btn:hover{color:#ccc;}
.tab-btn.active{color:var(--primary);border-bottom-color:var(--primary);}
.tab-panel[hidden]{display:none;}

/* PLAY BUTTON (replays tab, grid view, completed tiles only) */
.play-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;border:none;background:rgba(0,0,0,.45);color:#fff;cursor:pointer;opacity:0;transition:opacity .15s;z-index:4;padding:0;}
.map-card:hover .play-overlay{opacity:1;}
.play-overlay::before{content:"";width:56px;height:56px;border-radius:50%;background:var(--tier-color,var(--primary));box-shadow:0 0 18px var(--tier-color,var(--primary));display:flex;align-items:center;justify-content:center;}
.play-overlay i{position:absolute;font-size:22px;color:#000;margin-left:4px;pointer-events:none;}

/* REPLAY MODAL */
.replay-modal{position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;}
.replay-modal[hidden]{display:none;}
.replay-modal-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.8);}
.replay-modal-box{position:relative;z-index:1;width:min(1280px,94vw);height:min(800px,90vh);background:var(--darker);border:1px solid var(--dark);border-radius:8px;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.6);}
.replay-modal-head{display:flex;align-items:center;gap:14px;padding:12px 16px;background:#1c1c1c;border-bottom:1px solid var(--dark);flex:0 0 auto;}
.replay-modal-title{color:#fff;font-weight:bold;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1 1 auto;}
.replay-picker{display:flex;gap:6px;flex-wrap:wrap;}
.replay-pick{background:var(--dark);color:#ccc;border:1px solid #444;border-radius:4px;padding:5px 12px;font-size:12px;font-weight:bold;cursor:pointer;font-family:inherit;}
.replay-pick:hover{background:#444;color:#fff;}
.replay-pick.active{background:var(--primary);border-color:var(--primary);color:#06231b;}
.replay-modal-close{background:none;border:none;color:#888;font-size:26px;line-height:1;cursor:pointer;padding:0 6px;flex:0 0 auto;}
.replay-modal-close:hover{color:#fff;}
.replay-stage{position:relative;flex:1 1 auto;background:#000;min-height:0;}
.replay-viewer-host{position:absolute;inset:0;}
.replay-modal-msg{position:absolute;inset:0;display:none;align-items:center;justify-content:center;text-align:center;padding:24px;color:#aaa;font-size:15px;line-height:1.6;background:rgba(0,0,0,.55);z-index:5;}
.replay-modal-msg.show{display:flex;}
.replay-modal-msg code{background:#000;color:var(--primary);padding:2px 6px;border-radius:3px;font-size:13px;}

/* ===== mhud (MovementHUD) overlay: centered speed + underscore WASD keys ===== */
#replay-modal .map-viewer .key-display{background:transparent;width:200px;height:108px;left:50%;top:53%;bottom:auto;transform:translateX(-50%);font-family:'Lato',sans-serif;}
#replay-modal .map-viewer .key-display .stat{background:transparent;height:auto;line-height:1;}
/* speed: clean white number just below the crosshair (no label/unit) */
#replay-modal .map-viewer .key-display .speed-outer{left:0;top:0;width:200px;text-align:center;font-size:0;}
#replay-modal .map-viewer .key-display .speed-outer .value{font-size:28px;font-weight:700;color:#fff;letter-spacing:.5px;font-variant-numeric:tabular-nums;text-shadow:0 1px 6px rgba(0,0,0,.75);}
#replay-modal .map-viewer .key-display .sync-outer{display:none;}
/* movement keys: letter above an underscore, white, NO fill. Each key is hidden
   and only flashes into view while it is actually held down. */
#replay-modal .map-viewer .key-display .key{background:transparent!important;border:0;border-bottom:2px solid #fff;border-radius:0;box-shadow:none!important;color:#fff;font-size:13px;font-weight:800;width:30px;height:26px;line-height:20px;text-align:center;text-shadow:0 1px 4px rgba(0,0,0,.7);opacity:0;transition:opacity .04s;}
#replay-modal .map-viewer .key-display .key.pressed{opacity:1;}
#replay-modal .map-viewer .key-display .key-w{left:85px;top:40px;width:30px;height:26px;bottom:auto;}
#replay-modal .map-viewer .key-display .key-a{left:50px;top:70px;width:30px;height:26px;bottom:auto;}
#replay-modal .map-viewer .key-display .key-s{left:85px;top:70px;width:30px;height:26px;bottom:auto;}
#replay-modal .map-viewer .key-display .key-d{left:120px;top:70px;width:30px;height:26px;bottom:auto;}
#replay-modal .map-viewer .key-display .key-walk,#replay-modal .map-viewer .key-display .key-duck,#replay-modal .map-viewer .key-display .key-jump{display:none;}

/* "Download video" button in the modal head */
.replay-dl{background:var(--dark);color:#fff;border:1px solid #444;border-radius:4px;padding:5px 12px;font-size:12px;font-weight:bold;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:6px;white-space:nowrap;}
.replay-dl:hover{background:#444;}
.replay-dl[disabled]{opacity:.55;cursor:default;}
.replay-dl.recording{background:var(--t5);border-color:var(--t5);color:#fff;}
</style>
</head>
<body>
<div class="container">

<div class="header">
    <img src="<?= htmlspecialchars($avatar) ?>" class="avatar" alt="<?= htmlspecialchars($player_alias) ?>" onerror="this.onerror=null;this.src=KZ.placeholderAvatar;">
    <div>
        <h1 style="margin:0;color:#fff;"><?= htmlspecialchars($player_alias) ?>'s Profile</h1>
        <div style="color:var(--primary);font-size:13px;"><i class="fas fa-sync-alt"></i> Live Sync Active (SQLite)</div>
    </div>
</div>

<?php
// The map-browser panel markup is identical for both tabs; capture it once and
// render it twice. All hooks are class-based (js-*) so each tab's JS instance
// scopes its own controls instead of relying on page-unique IDs.
ob_start(); ?>
<div class="tier-grid">
<?php for ($i = 1; $i <= 7; $i++):
    $perc = $tier_stats[$i]['total'] > 0 ? ($tier_stats[$i]['done'] / $tier_stats[$i]['total']) * 100 : 0; ?>
    <a href="#" class="tier-card" data-tier="<?= $i ?>">
        <div style="font-size:10px;color:#888;">TIER <?= $i ?></div>
        <div class="tier-count"><?= $tier_stats[$i]['done'] ?> <span style="color:#444;">/</span> <?= $tier_stats[$i]['total'] ?></div>
        <div class="progress-bar"><div class="progress-fill" style="width:<?= $perc ?>%;background:var(--t<?= $i ?>);"></div></div>
    </a>
<?php endfor; ?>
</div>

<div class="tier-header-info js-tier-header"></div>

<div class="controls">
    <input type="text" class="search-input js-search" placeholder="Search maps...">
    <select class="search-input js-tier" style="max-width:140px;">
        <option value="">All Tiers</option>
        <?php for ($i = 1; $i <= 7; $i++): ?><option value="<?= $i ?>">Tier <?= $i ?></option><?php endfor; ?>
    </select>
    <select class="search-input js-type" style="max-width:150px;">
        <option value="">All Types</option>
        <option value="PRO">PRO Runs</option>
        <option value="TP">TP Runs</option>
        <option value="completed">Completed</option>
        <option value="uncompleted">Uncompleted</option>
        <option value="replay">Has Replay</option>
        <option value="favorites">Favorites</option>
    </select>
    <select class="search-input js-sort" style="max-width:160px;">
        <option value="name">Sort: Name</option>
        <option value="tier">Sort: Difficulty</option>
        <option value="time">Sort: Personal Best</option>
        <option value="type">Sort: Type</option>
        <option value="date">Sort: Date</option>
        <option value="favorite">Sort: Favorite</option>
    </select>
    <button class="btn order-toggle js-order"></button>
    <button class="btn js-view"></button>
    <button class="btn js-reset">Reset</button>
    <span class="result-count js-count"></span>
</div>

<div class="map-list js-list view-table"></div>
<?php $panel_html = ob_get_clean(); ?>

<div class="tab-bar">
    <button class="tab-btn active" data-tab="maps"><i class="fas fa-list"></i> Maps</button>
    <button class="tab-btn" data-tab="replays"><i class="fas fa-film"></i> Replays</button>
</div>

<section id="tab-maps" class="tab-panel"><?= $panel_html ?></section>
<section id="tab-replays" class="tab-panel" hidden><?= $panel_html ?></section>

</div>

<!-- Replay viewer overlay (lazily initialised on first play) -->
<div id="replay-modal" class="replay-modal" hidden>
    <div class="replay-modal-backdrop"></div>
    <div class="replay-modal-box">
        <div class="replay-modal-head">
            <span class="replay-modal-title">Replay</span>
            <div class="replay-picker"></div>
            <button class="replay-dl" title="Record this replay to a video file (.webm)"><i class="fas fa-circle"></i> Record video</button>
            <button class="replay-modal-close" title="Close (Esc)">&times;</button>
        </div>
        <div class="replay-stage">
            <div class="replay-viewer-host" id="replay-canvas"></div>
            <div class="replay-modal-msg"></div>
        </div>
    </div>
</div>

<!-- GOKZ replay viewer engine (vendored). lz-string decompresses exported map content. -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/lz-string/1.4.4/lz-string.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/lz-string/1.4.4/base64-string.min.js"></script>
<script src="replayviewer/js/facepunch.webgame.js"></script>
<script src="replayviewer/js/sourceutils.js"></script>
<script src="replayviewer/js/replayviewer.js"></script>

<script>
window.KZ = {
    maps: <?= json_encode($maps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    tierStats: <?= json_encode($tier_stats, JSON_UNESCAPED_SLASHES) ?>,
    mapBaseUrl: <?= json_encode($map_base_url) ?>,
    players: <?= json_encode($replay_players, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
    placeholderAvatar: <?= json_encode($placeholder_avatar) ?>,
    placeholderCard: <?= json_encode($placeholder_card) ?>
};

(function () {
    "use strict";

    // ---- shared helpers (used by the browser factory and the replay modal) ----
    function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; }
    function debounce(fn, ms) {
        let t;
        return function () { clearTimeout(t); const a = arguments, c = this; t = setTimeout(function () { fn.apply(c, a); }, ms); };
    }
    const SORTS = ['name', 'tier', 'time', 'type', 'date', 'favorite'];
    const TYPES = ['PRO', 'TP', 'completed', 'uncompleted', 'replay', 'favorites'];

    // Builds one self-contained map browser inside `root`. The original "Maps"
    // tab uses { syncUrl:true }; the "Replays" tab uses grid+completed defaults
    // and an onPlay handler that opens the replay viewer for the clicked map.
    function createMapBrowser(root, opts) {
        opts = opts || {};
        const DATA      = KZ.maps;
        const TIERSTATS = KZ.tierStats;

        // controls + regions, scoped to this browser's root section
        const searchEl     = root.querySelector('.js-search');
        const tierEl       = root.querySelector('.js-tier');
        const typeEl       = root.querySelector('.js-type');
        const sortEl       = root.querySelector('.js-sort');
        const orderEl      = root.querySelector('.js-order');
        const viewEl       = root.querySelector('.js-view');
        const resetEl      = root.querySelector('.js-reset');
        const countEl      = root.querySelector('.js-count');
        const listEl       = root.querySelector('.js-list');
        const tierHeaderEl = root.querySelector('.js-tier-header');

        // Replays-only "by player" filter, injected next to the search box.
        let playerEl = null;
        if (opts.players && opts.players.length) {
            playerEl = document.createElement('select');
            playerEl.className = 'search-input js-player';
            playerEl.style.maxWidth = '170px';
            playerEl.innerHTML = '<option value="">All players’ replays</option>'
                + opts.players.map(function (p) { return '<option value="' + p.id + '">' + esc(p.alias) + '’s replays</option>'; }).join('');
            searchEl.insertAdjacentElement('afterend', playerEl);
        }

        const state = { q: '', tier: '', type: opts.defaultType || '', sort: 'name', order: 'asc', view: opts.defaultView || 'table', player: '' };
        let currentList = [];                 // what's rendered right now (index -> map)
        const historyCache = {};              // mapId -> rows

    // ---------- state <-> URL ----------
    function readState() {
        if (!opts.syncUrl) return;            // replays tab keeps its state in memory only
        const p = new URLSearchParams(location.search);
        state.q     = p.get('q') || '';
        state.tier  = /^[1-7]$/.test(p.get('tier') || '') ? p.get('tier') : '';
        const t     = p.get('type') || p.get('typeFilter') || '';   // accept old param name
        state.type  = TYPES.includes(t) ? t : '';
        state.sort  = SORTS.includes(p.get('sort')) ? p.get('sort') : 'name';
        state.order = p.get('order') === 'desc' ? 'desc' : 'asc';
        state.view  = p.get('view') === 'grid' ? 'grid' : 'table';
    }
    function writeState() {
        if (!opts.syncUrl) return;
        const p = new URLSearchParams(location.search);   // preserve foreign params (e.g. tab)
        ['q', 'tier', 'type', 'typeFilter', 'sort', 'order', 'view'].forEach(function (k) { p.delete(k); });
        if (state.q)              p.set('q', state.q);
        if (state.tier)           p.set('tier', state.tier);
        if (state.type)           p.set('type', state.type);
        if (state.sort !== 'name')p.set('sort', state.sort);
        if (state.order !== 'asc')p.set('order', state.order);
        if (state.view !== 'table')p.set('view', state.view);
        const qs = p.toString();
        history.replaceState(null, '', qs ? '?' + qs : location.pathname);
    }

    // ---------- helpers ----------
    function cmpName(a, b) {
        const x = a.name.toLowerCase(), y = b.name.toLowerCase();
        return x < y ? -1 : x > y ? 1 : 0;
    }

    // ---------- filtering ----------
    function getFiltered() {
        const q = state.q.toLowerCase();
        const playerId = state.player ? +state.player : 0;
        return DATA.filter(function (m) {
            if (q && m.name.toLowerCase().indexOf(q) === -1) return false;
            if (state.tier && String(m.tier) !== state.tier) return false;
            if (playerId && (m.replayPlayers || []).indexOf(playerId) === -1) return false;
            switch (state.type) {
                case 'PRO':         return m.proTime != null;
                case 'TP':          return m.tpTime != null;
                case 'completed':   return !m.isMissing;
                case 'uncompleted': return m.isMissing;
                case 'replay':      return m.hasReplay;
                case 'favorites':   return m.isFavorite;
            }
            return true;
        });
    }

    // ---------- sorting (uncompleted always sinks on value sorts) ----------
    function getSorted(list) {
        const dir = state.order === 'asc' ? 1 : -1;
        const arr = list.slice();
        switch (state.sort) {
            case 'name':
                arr.sort(function (a, b) { return dir * cmpName(a, b); });
                break;
            case 'tier':
                arr.sort(function (a, b) { return dir * ((a.tier - b.tier) || cmpName(a, b)); });
                break;
            case 'favorite':
                arr.sort(function (a, b) {
                    const fa = a.isFavorite ? 1 : 0, fb = b.isFavorite ? 1 : 0;
                    if (fa !== fb) return dir * (fb - fa);   // favorites first when asc
                    return cmpName(a, b);
                });
                break;
            case 'time':
            case 'date':
            case 'type':
                arr.sort(valueComparator(state.sort, dir));
                break;
        }
        return arr;
    }
    function valueComparator(key, dir) {
        return function (a, b) {
            if (a.isMissing && b.isMissing) return cmpName(a, b);
            if (a.isMissing) return 1;     // uncompleted last, regardless of direction
            if (b.isMissing) return -1;
            let av, bv;
            if (key === 'time')      { av = a.bestTime; bv = b.bestTime; }
            else if (key === 'date') { av = a.bestDate; bv = b.bestDate; }
            else /* type */          { av = a.proTime != null ? 0 : 1; bv = b.proTime != null ? 0 : 1; } // PRO before TP (asc)
            if (av == null) av = 0;
            if (bv == null) bv = 0;
            const c = av - bv;
            return c !== 0 ? dir * c : cmpName(a, b);
        };
    }

    // ---------- rendering ----------
    function badges(m) {
        let s = '';
        if (m.proTime != null) s += '<span class="badge badge-pro">PRO</span> ';
        if (m.tpTime  != null) s += '<span class="badge badge-tp">TP</span>';
        return s || '<span class="uncompleted-label">&mdash;</span>';
    }
    function headerCell(key, label) {
        let ind = '';
        if (state.sort === key) ind = state.order === 'asc' ? ' &uarr;' : ' &darr;';
        return '<th class="sort-header" data-sort="' + key + '">' + label + '<span class="sort-indicator">' + ind + '</span></th>';
    }
    function renderTable(list) {
        let h = '<table class="map-table"><thead><tr>'
            + headerCell('name', 'Map Name')
            + headerCell('tier', 'Difficulty')
            + headerCell('time', 'Personal Best')
            + headerCell('type', 'Type')
            + headerCell('date', 'Date')
            + headerCell('favorite', 'Favorite')
            + '</tr></thead><tbody>';
        list.forEach(function (m, i) {
            const tierColor = 'var(--t' + m.tier + ')';
            const nameColor = m.isFavorite ? tierColor : (m.isMissing ? '#555' : '#fff');
            h += '<tr class="map-row" data-idx="' + i + '" data-mapid="' + (m.mapId == null ? '' : m.mapId) + '" data-missing="' + m.isMissing + '">'
                + '<td><a class="fav-link" data-name="' + esc(m.name) + '" style="color:' + nameColor + ';font-weight:bold;">' + esc(m.name) + '</a></td>'
                + '<td><span class="tier-pill" style="border-left:4px solid ' + tierColor + ';">Tier ' + m.tier + '</span></td>'
                + '<td style="font-family:monospace;font-size:15px;color:' + (m.isMissing ? '#444' : 'var(--primary)') + ';">' + m.bestStr + '</td>'
                + '<td>' + (m.isMissing ? '<span class="uncompleted-label">UNCOMPLETED</span>' : badges(m)) + '</td>'
                + '<td style="color:#555;">' + m.bestDateStr + '</td>'
                + '<td>' + (m.isFavorite ? '<span style="color:' + tierColor + ';font-size:11px;">&#9733; FAVORITE</span>' : '') + '</td>'
                + '</tr>'
                + '<tr class="drawer" data-drawer="' + i + '"><td colspan="6"><div class="drawer-inner" style="border-left:4px solid ' + tierColor + ';" data-history-host></div></td></tr>';
        });
        return h + '</tbody></table>';
    }
    function renderGrid(list) {
        let h = '';
        list.forEach(function (m, i) {
            const titleColor = m.isFavorite ? 'var(--t' + m.tier + ')' : '#fff';
            h += '<div class="map-card ' + (m.isFavorite ? 'favorite' : '') + '" data-idx="' + i + '" data-mapid="' + (m.mapId == null ? '' : m.mapId) + '" data-missing="' + m.isMissing + '" style="--tier-color:var(--t' + m.tier + ');">'
                + '<div class="map-thumbnail">'
                +   '<img loading="lazy" src="' + esc(m.img) + '" alt="' + esc(m.name) + '" onerror="this.onerror=null;this.src=KZ.placeholderCard;">'
                +   '<div class="glow-bar top-bar"></div><div class="glow-bar bottom-bar"></div>'
                +   '<div class="tier-badge">T' + m.tier + '</div>'
                +   (opts.onPlay && m.hasReplay ? '<button class="play-overlay" title="Watch replay" aria-label="Watch replay"><i class="fas fa-play"></i></button>' : '')
                + '</div>'
                + '<div class="map-info">'
                +   '<div class="map-title"><a class="fav-link" data-name="' + esc(m.name) + '" style="color:' + titleColor + ';">' + esc(m.name) + '</a></div>'
                +   '<div class="run-times">'
                +     '<div class="run-entry tp-run"><span class="run-label">Best TP:</span><span class="run-time">' + m.tpStr + '</span></div>'
                +     '<div class="run-entry pro-run"><span class="run-label">Best PRO:</span><span class="run-time">' + m.proStr + '</span></div>'
                +   '</div>'
                + '</div>'
                + '<div class="grid-history-drawer" data-drawer="' + i + '" style="--tier-color:var(--t' + m.tier + ');"><div class="grid-history-content" data-history-host></div></div>'
                + '</div>';
        });
        return h;
    }
    function render() {
        currentList = getSorted(getFiltered());
        listEl.classList.remove('view-grid', 'view-table');   // keep map-list / js-list hooks intact
        listEl.classList.add(state.view === 'grid' ? 'view-grid' : 'view-table');
        listEl.innerHTML = state.view === 'grid' ? renderGrid(currentList) : renderTable(currentList);
        countEl.textContent = currentList.length + (currentList.length === 1 ? ' map' : ' maps');
        syncControls();
        syncTierUI();
        writeState();
    }

    // ---------- control sync ----------
    function syncControls() {
        if (searchEl.value !== state.q) searchEl.value = state.q;   // avoid cursor jump while typing
        tierEl.value = state.tier;
        typeEl.value = state.type;
        if (playerEl) playerEl.value = state.player;
        sortEl.value = state.sort;
        orderEl.innerHTML = state.order === 'asc' ? '&uarr; Asc' : '&darr; Desc';
        viewEl.innerHTML = state.view === 'table'
            ? '<i class="fas fa-th-large"></i> Grid View'
            : '<i class="fas fa-list"></i> Table View';
    }
    function syncTierUI() {
        root.querySelectorAll('.tier-card').forEach(function (c) {
            c.classList.toggle('active', state.tier !== '' && c.dataset.tier === state.tier);
        });
        const th = tierHeaderEl;
        if (state.tier && TIERSTATS[state.tier]) {
            const st = TIERSTATS[state.tier];
            const pct = st.total ? Math.round((st.done / st.total) * 1000) / 10 : 0;
            th.style.display = 'flex';
            th.style.borderColor = 'var(--t' + state.tier + ')';
            th.innerHTML = '<div><span class="th-label">Viewing</span><h2 class="th-title">Tier ' + state.tier + ' Maps</h2></div>'
                + '<div style="text-align:right;"><div class="th-pct" style="color:var(--t' + state.tier + ');">' + pct + '%</div>'
                + '<div class="th-sub">' + st.done + ' of ' + st.total + ' maps completed</div></div>';
        } else {
            th.style.display = 'none';
            th.innerHTML = '';
        }
    }

    // ---------- drawers ----------
    function historyBlock(rows, missing) {
        let head = '<h4 class="hist-title"><i class="fas fa-history"></i> Run History</h4>';
        if (missing) return head + '<p class="hist-empty">This map has not been completed yet.</p>';
        if (!rows || rows.length === 0) return head + '<p class="hist-empty">No previous runs found for this map.</p>';
        let t = head + '<div class="hist-scroll"><table class="hist-table"><thead><tr><th>Date</th><th>Time</th><th>Teleports</th><th>Type</th></tr></thead><tbody>';
        rows.forEach(function (r) {
            t += '<tr><td class="hd">' + esc(r.dateStr) + '</td><td class="ht">' + esc(r.timeStr) + '</td><td>' + r.teleports
                + '</td><td><span class="badge ' + (r.pro ? 'badge-pro' : 'badge-tp') + '" style="font-size:10px;padding:2px 6px;">'
                + (r.pro ? 'PRO' : 'TP') + '</span></td></tr>';
        });
        return t + '</tbody></table></div>';
    }
    function getDrawer(idx) { return listEl.querySelector('[data-drawer="' + idx + '"]'); }
    function closeAllDrawers() {
        listEl.querySelectorAll('.drawer, .grid-history-drawer').forEach(function (d) { d.style.display = 'none'; });
    }
    async function toggleDrawer(idx) {
        const drawer = getDrawer(idx);
        if (!drawer) return;
        const isTable = drawer.tagName === 'TR';
        const open = isTable ? drawer.style.display === 'table-row' : drawer.style.display === 'block';
        closeAllDrawers();
        if (open) return;
        drawer.style.display = isTable ? 'table-row' : 'block';

        const m = currentList[idx];
        const host = drawer.querySelector('[data-history-host]');
        if (m.isMissing) { host.innerHTML = historyBlock(null, true); return; }
        if (m.mapId == null) { host.innerHTML = historyBlock([], false); return; }
        if (historyCache[m.mapId]) { host.innerHTML = historyBlock(historyCache[m.mapId], false); return; }

        host.innerHTML = '<p class="hist-loading">Loading run history&hellip;</p>';
        try {
            const res = await fetch('?history=' + encodeURIComponent(m.mapId));
            const rows = await res.json();
            historyCache[m.mapId] = rows;
            host.innerHTML = historyBlock(rows, false);
        } catch (e) {
            host.innerHTML = '<p class="hist-empty">Failed to load run history.</p>';
        }
    }

    // ---------- favorites (no reload) ----------
    async function toggleFavorite(name) {
        const key = name.toLowerCase();
        try {
            const body = new URLSearchParams({ toggle_favorite: '1', map_name: name });
            const res = await fetch(location.pathname, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            });
            const j = await res.json();
            if (j && j.ok) {
                const m = DATA.find(function (x) { return x.name.toLowerCase() === key; });
                if (m) m.isFavorite = j.favorited;
                render();
            }
        } catch (e) { /* network failed; leave UI as is */ }
    }

    // ---------- events ----------
    function bind() {
        // list interactions (delegated, survives re-render)
        listEl.addEventListener('click', function (e) {
            const sortH = e.target.closest('.sort-header');
            if (sortH) {
                const key = sortH.dataset.sort;
                if (state.sort === key) state.order = state.order === 'asc' ? 'desc' : 'asc';
                else { state.sort = key; state.order = 'asc'; }
                render();
                return;
            }
            // play button (replays tab only) -> open the viewer, never toggle the drawer
            const playBtn = e.target.closest('.play-overlay');
            if (playBtn) {
                e.preventDefault(); e.stopPropagation();
                const card = playBtn.closest('.map-card');
                if (card && opts.onPlay) opts.onPlay(currentList[+card.dataset.idx], state.player ? +state.player : null);
                return;
            }
            const fav = e.target.closest('.fav-link');
            if (fav) { e.preventDefault(); e.stopPropagation(); toggleFavorite(fav.dataset.name); return; }
            // clicks inside an open drawer should not toggle it shut
            if (e.target.closest('.drawer') || e.target.closest('.grid-history-drawer')) return;
            const item = e.target.closest('.map-row, .map-card');
            if (item) toggleDrawer(item.dataset.idx);
        });

        // click outside this list closes any open grid drawer
        document.addEventListener('click', function (e) {
            if (!listEl.contains(e.target)) closeAllDrawers();
        });

        searchEl.addEventListener('input', debounce(function (e) {
            state.q = e.target.value;
            render();
        }, 120));
        tierEl.addEventListener('change', function (e) { state.tier = e.target.value; render(); });
        typeEl.addEventListener('change', function (e) { state.type = e.target.value; render(); });
        if (playerEl) playerEl.addEventListener('change', function (e) { state.player = e.target.value; render(); });
        sortEl.addEventListener('change', function (e) { state.sort = e.target.value; render(); });
        orderEl.addEventListener('click', function () { state.order = state.order === 'asc' ? 'desc' : 'asc'; render(); });
        viewEl.addEventListener('click', function () { state.view = state.view === 'table' ? 'grid' : 'table'; render(); });
        resetEl.addEventListener('click', function () {
            state.q = ''; state.tier = ''; state.sort = 'name'; state.order = 'asc'; state.player = '';
            state.type = opts.defaultType || '';        // reset honours this tab's default filter
            // keep the current view on reset; it's a display pref, not a filter
            render();
        });

        root.querySelectorAll('.tier-card').forEach(function (card) {
            card.addEventListener('click', function (e) {
                e.preventDefault();
                const t = card.dataset.tier;
                state.tier = (state.tier === t) ? '' : t;   // click active tier to clear
                render();
            });
        });

        if (opts.syncUrl) {
            window.addEventListener('popstate', function () { readState(); render(); });
        }
    }

    readState();
    bind();
    render();
    }   // ===== end createMapBrowser =====

    // =====================================================================
    // Replay viewer modal — self-hosted Gokz.ReplayViewer, created lazily on
    // first play so the heavy WebGL engine never touches the initial load.
    // =====================================================================
    function createReplayModal() {
        const modal    = document.getElementById('replay-modal');
        const host     = document.getElementById('replay-canvas');
        const titleEl  = modal.querySelector('.replay-modal-title');
        const pickerEl = modal.querySelector('.replay-picker');
        const msgEl    = modal.querySelector('.replay-modal-msg');
        const dlBtn    = modal.querySelector('.replay-dl');
        let viewer = null;
        let curMap = null;          // guards against races when opening maps quickly
        let playToken = 0;          // guards against races when switching replays quickly
        let recorder = null, recChunks = [], recording = false, recCancel = false, recPoll = 0;

        function stopRecording(cancel) {
            recCancel = !!cancel;
            if (recPoll) { clearInterval(recPoll); recPoll = 0; }
            if (recorder && recorder.state !== 'inactive') { try { recorder.stop(); } catch (e) {} }
        }
        function resetDlBtn() {
            recording = false;
            if (dlBtn) { dlBtn.classList.remove('recording'); dlBtn.innerHTML = '<i class="fas fa-circle"></i> Record video'; }
        }
        // Record the live WebGL canvas (one full pass) to a .webm via MediaRecorder.
        function recordVideo() {
            if (recording) { stopRecording(false); return; }   // 2nd click = finish early
            if (!viewer || !viewer.replay) return;
            const canvas = host.querySelector('canvas');
            if (!canvas || !canvas.captureStream || typeof MediaRecorder === 'undefined') {
                showMsg('Video recording isn\'t supported in this browser.'); return;
            }
            recChunks = []; recCancel = false;
            let mime = 'video/webm;codecs=vp9';
            if (!MediaRecorder.isTypeSupported(mime)) mime = 'video/webm;codecs=vp8';
            if (!MediaRecorder.isTypeSupported(mime)) mime = 'video/webm';
            const stream = canvas.captureStream(60);
            recorder = new MediaRecorder(stream, { mimeType: mime, videoBitsPerSecond: 12000000 });
            recorder.ondataavailable = function (e) { if (e.data && e.data.size) recChunks.push(e.data); };
            recorder.onstop = function () {
                resetDlBtn();
                if (viewer) viewer.autoRepeat = true;
                if (recCancel || !recChunks.length) return;     // interrupted -> no download
                const blob = new Blob(recChunks, { type: 'video/webm' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = ((curMap && (curMap.slug || curMap.name)) || 'replay') + '.webm';
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(function () { URL.revokeObjectURL(a.href); }, 8000);
            };
            // record exactly one pass from the start
            recording = true;
            dlBtn.classList.add('recording');
            dlBtn.innerHTML = '<i class="fas fa-stop"></i> Stop &amp; save';
            viewer.autoRepeat = false; viewer.tick = 0; viewer.isPlaying = true;
            recorder.start();
            const end = viewer.replay.tickCount, t0 = Date.now();
            recPoll = setInterval(function () {
                if (!recording) { clearInterval(recPoll); recPoll = 0; return; }
                if (!viewer || !viewer.replay || viewer.tick >= end - 2 || (Date.now() - t0) > 20 * 60 * 1000) {
                    stopRecording(false);
                }
            }, 200);
        }
        if (dlBtn) dlBtn.addEventListener('click', recordVideo);

        function destroyViewer() {
            if (recording) stopRecording(true);                 // discard a half-done recording on switch/close
            if (!viewer) return;
            // The engine has no teardown: its rAF loop and a window 'resize' listener
            // both call instance methods dynamically (this.animate / this.onResize /
            // this.onRenderFrame). Replacing them with no-ops halts the loop after the
            // current frame and neutralises the (un-removable) resize listener.
            try {
                viewer.animate = function () {};
                viewer.onResize = function () {};
                viewer.onRenderFrame = function () {};
            } catch (e) {}
            try {
                var cv = host.querySelector('canvas');
                var gl = cv && (cv.getContext('webgl2') || cv.getContext('webgl'));
                var ext = gl && gl.getExtension('WEBGL_lose_context');
                if (ext) ext.loseContext();                // release the GL context (no leak)
            } catch (e) {}
            host.innerHTML = '';                            // remove old canvas + HUD
            viewer = null;
        }
        function createViewer() {
            viewer = new Gokz.ReplayViewer(host);
            viewer.mapBaseUrl = KZ.mapBaseUrl; // SourceUtils export -> <base>/<map>/index.json
            viewer.saveTickInHash = false;     // don't write the tick into the page URL hash
            viewer.isPlaying = true;
            viewer.replayLoaded.addListener(function (replay) {
                const mins = Math.floor(replay.time / 60);
                const secs = (replay.time - mins * 60).toFixed(3);
                const mode = (Gokz.GlobalMode[replay.mode] || '').toString().toUpperCase();
                titleEl.textContent = replay.playerName + '  •  ' + replay.mapName
                    + '  •  ' + mins + ':' + (secs.indexOf('.') === 1 ? '0' : '') + secs
                    + (mode ? '  [' + mode + ']' : '')
                    + '  [' + (replay.teleportsUsed === 0 ? 'PRO' : 'NUB') + ']';
            });
            viewer.animate();
            // The canvas sizes to its container on the engine's first frame; the modal
            // just became visible, so nudge a resize once layout has settled.
            requestAnimationFrame(function () { try { window.dispatchEvent(new Event('resize')); } catch (e) {} });
            // The key display (mhud) is created inside the auto-hiding playback bar; move
            // it out to the viewer host so it stays visible and centres on the viewport.
            const mv = setInterval(function () {
                const kd = host.querySelector('.key-display');
                if (kd && kd.parentElement !== host) { host.appendChild(kd); clearInterval(mv); }
            }, 80);
            return viewer;
        }

        function showMsg(html) { msgEl.innerHTML = html; msgEl.classList.add('show'); }
        function hideMsg()     { msgEl.innerHTML = ''; msgEl.classList.remove('show'); }

        function labelFor(r) {
            const parts = [];
            if (r.mode) parts.push(r.mode);
            if (r.type) parts.push(String(r.type).toUpperCase());
            if (r.course != null && r.course > 0) parts.push('C' + r.course);
            let s = parts.join(' ') || r.file;
            if (r.player) s += ' · ' + r.player;            // who ran it
            return s;
        }

        async function open(map, preferId) {
            curMap = map;
            modal.hidden = false;
            document.body.style.overflow = 'hidden';
            titleEl.textContent = map.name;
            pickerEl.innerHTML = '';
            hideMsg();
            let list = [];
            try {
                const res = await fetch('?replays=' + encodeURIComponent(map.slug || map.name));
                list = await res.json();
            } catch (e) { list = []; }
            if (curMap !== map) return;                       // superseded by a later open()
            if (!Array.isArray(list) || list.length === 0) {
                showMsg('No valid replay found for <b>' + esc(map.name) + '</b>.');
                return;
            }
            // If a player filter is active, play that player's recording first.
            if (preferId) {
                list.sort(function (a, b) { return (a.steamid === preferId ? 0 : 1) - (b.steamid === preferId ? 0 : 1); });
            }
            if (list.length > 1) {
                pickerEl.innerHTML = list.map(function (r, i) {
                    return '<button class="replay-pick' + (i === 0 ? ' active' : '') + '" data-i="' + i + '">' + esc(labelFor(r)) + '</button>';
                }).join('');
                pickerEl.querySelectorAll('.replay-pick').forEach(function (b) {
                    b.addEventListener('click', function () {
                        pickerEl.querySelectorAll('.replay-pick').forEach(function (x) { x.classList.remove('active'); });
                        b.classList.add('active');
                        play(list[+b.dataset.i], map);
                    });
                });
            }
            play(list[0], map);
        }

        async function play(r, map) {
            const tok = ++playToken;
            hideMsg();
            const slug = map.slug || map.name;
            // Is this map's geometry exported? Same-origin, so a real 404 is reliable —
            // this turns an otherwise-blank canvas into a clear, actionable message.
            let ok = false;
            try { const h = await fetch(KZ.mapBaseUrl + '/' + encodeURIComponent(slug) + '/index.json', { method: 'HEAD' }); ok = h.ok; }
            catch (e) { ok = false; }
            if (tok !== playToken) return;                    // superseded by a newer play()
            // Fresh viewer per play: guarantees the new replay/map fully replaces the
            // previous one (no stale frame) and the canvas sizes to the visible modal.
            destroyViewer();
            if (!ok) {
                showMsg('Map <b>' + esc(slug) + '</b> isn\'t exported yet.<br>'
                    + 'Run <code>tools\\export-maps.bat</code> (with this map in the MAPS list) to view it in 3D.');
                return;
            }
            createViewer();
            viewer.loadReplay(r.url);                          // replay streams from our same-origin PHP endpoint
        }

        function close() {
            modal.hidden = true;
            document.body.style.overflow = '';
            curMap = null;
            destroyViewer();                                  // tear down so reopen is always fresh
        }

        modal.querySelector('.replay-modal-close').addEventListener('click', close);
        modal.querySelector('.replay-modal-backdrop').addEventListener('click', close);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });

        return { open: open };
    }

    // =====================================================================
    // Tab switching (Maps | Replays).
    // =====================================================================
    function createTabs() {
        const btns = Array.prototype.slice.call(document.querySelectorAll('.tab-btn'));
        const panels = { maps: document.getElementById('tab-maps'), replays: document.getElementById('tab-replays') };
        function show(tab) {
            if (!panels[tab]) tab = 'maps';
            btns.forEach(function (b) { b.classList.toggle('active', b.dataset.tab === tab); });
            panels.maps.hidden    = tab !== 'maps';
            panels.replays.hidden = tab !== 'replays';
            const p = new URLSearchParams(location.search);
            if (tab === 'replays') p.set('tab', 'replays'); else p.delete('tab');
            const qs = p.toString();
            history.replaceState(null, '', qs ? '?' + qs : location.pathname);
        }
        btns.forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.tab); }); });
        if (new URLSearchParams(location.search).get('tab') === 'replays') show('replays');
    }

    // ---- wire everything up ----
    const replayModal = createReplayModal();
    createMapBrowser(document.getElementById('tab-maps'),    { syncUrl: true });
    createMapBrowser(document.getElementById('tab-replays'), { syncUrl: false, defaultView: 'grid', defaultType: 'replay', players: KZ.players, onPlay: replayModal.open });
    createTabs();
})();
</script>
</body>
</html>
<?php
ob_end_flush();
