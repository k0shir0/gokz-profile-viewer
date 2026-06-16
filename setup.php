<?php
/**
 * First-run setup wizard. Checks dependencies and writes config.json
 * (SteamID, alias, avatar, CS:GO paths). Git-ignored output: config.json.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$config_file = __DIR__ . '/config.json';
$existing = is_file($config_file) ? (json_decode(file_get_contents($config_file), true) ?: []) : [];

function which_ok($cmd) {
    // best-effort: run "<cmd> -version" / "--version" and see if it works
    foreach (['-version', '--version'] as $flag) {
        $out = @shell_exec(escapeshellarg($cmd) . ' ' . $flag . ' 2>&1');
        if ($out !== null && $out !== '') return trim(strtok($out, "\r\n"));
    }
    return false;
}

// SteamID (STEAM_0:1:X | 7656... | 32-bit) -> SteamID32 (account id)
function to_steamid32($in) {
    $in = trim($in);
    if (preg_match('/^STEAM_[0-5]:([01]):(\d+)$/i', $in, $m)) return (int) $m[2] * 2 + (int) $m[1];
    if (preg_match('/^\d{17}$/', $in)) return (int) bcsub($in, '76561197960265728');
    if (preg_match('/^\[U:1:(\d+)\]$/', $in, $m)) return (int) $m[1];
    if (preg_match('/^\d{1,10}$/', $in)) return (int) $in;   // already a 32-bit account id
    return 0;
}

$errors = [];
$saved  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sid32 = to_steamid32($_POST['steamid'] ?? '');
    if ($sid32 <= 0) $errors[] = 'Could not parse that SteamID. Use STEAM_0:1:XXXX, a 17-digit SteamID64, or a 32-bit account id.';

    $csgo = rtrim(str_replace('\\', '/', trim($_POST['csgo_dir'] ?? '')), '/');
    if ($csgo === '') $errors[] = 'CS:GO "csgo" folder is required.';
    elseif (!is_dir($csgo)) $errors[] = 'That csgo folder does not exist: ' . htmlspecialchars($csgo);

    $replays = trim($_POST['replays_dir'] ?? '');
    $livedb  = trim($_POST['live_db'] ?? '');
    $replays_eff = $replays !== '' ? $replays : ($csgo . '/addons/sourcemod/data/gokz-replays/_runs');
    $livedb_eff  = $livedb  !== '' ? $livedb  : ($csgo . '/addons/sourcemod/data/sqlite/gokz-sqlite.sq3');
    if (!$errors && !is_dir($replays_eff)) $errors[] = 'Replays folder not found: ' . htmlspecialchars($replays_eff);
    if (!$errors && !is_file($livedb_eff)) $errors[] = 'GOKZ SQLite DB not found: ' . htmlspecialchars($livedb_eff);

    // optional avatar upload
    $avatar_file = $existing['avatar_file'] ?? '';
    if (!empty($_FILES['avatar']['tmp_name']) && is_uploaded_file($_FILES['avatar']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $dest = 'avatar.' . $ext;
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], __DIR__ . '/' . $dest)) $avatar_file = $dest;
            else $errors[] = 'Could not save the uploaded avatar.';
        } else $errors[] = 'Avatar must be an image (jpg/png/gif/webp).';
    }

    if (!$errors) {
        $cfg = [
            'steamid32'    => $sid32,
            'alias'        => trim($_POST['alias'] ?? ''),
            'avatar_file'  => $avatar_file,
            'csgo_dir'     => $csgo,
            'replays_dir'  => $replays,            // blank = derive from csgo_dir
            'live_db'      => $livedb,             // blank = derive from csgo_dir
            'map_base_url' => trim($_POST['map_base_url'] ?? '') ?: 'mapdata/maps',
        ];
        if (file_put_contents($config_file, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false) {
            $saved = true;
            // also write renderer config mirror is unnecessary: renderer reads config.json
        } else {
            $errors[] = 'Could not write config.json (check folder permissions).';
        }
    }
}

// dependency probes
$php_ok   = version_compare(PHP_VERSION, '7.4.0', '>=');
$sqlite_ok = extension_loaded('pdo_sqlite');
$ffmpeg   = which_ok('ffmpeg');
$node     = which_ok('node');

$val = function ($k, $d = '') use ($existing) { return htmlspecialchars($existing[$k] ?? $d); };
?>
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup — GOKZ Profile Viewer</title>
<style>
:root{--bg:#121212;--card:#1c1c1c;--line:#303030;--text:#ddd;--primary:#20c997;--err:#e74c3c;--ok:#2ecc71;}
*{box-sizing:border-box}body{background:var(--bg);color:var(--text);font-family:'Lato',system-ui,sans-serif;margin:0;padding:30px}
.wrap{max-width:720px;margin:0 auto}
h1{color:#fff;margin:0 0 4px}.sub{color:#888;margin:0 0 24px}
.card{background:var(--card);border:1px solid var(--line);border-radius:8px;padding:20px;margin-bottom:18px}
.card h2{margin:0 0 14px;font-size:15px;color:var(--primary);text-transform:uppercase;letter-spacing:1px}
label{display:block;margin:12px 0 5px;font-size:13px;color:#bbb}
input[type=text]{width:100%;background:#0e0e0e;border:1px solid var(--line);color:#fff;padding:10px 12px;border-radius:5px;font-size:14px}
.hint{color:#777;font-size:12px;margin-top:4px}
.dep{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #262626;font-size:14px}
.dep:last-child{border:0}.pill{font-weight:bold}.pill.ok{color:var(--ok)}.pill.no{color:var(--err)}.pill.opt{color:#f39c12}
.btn{background:var(--primary);color:#06231b;border:0;padding:12px 26px;border-radius:5px;font-weight:bold;font-size:15px;cursor:pointer}
.err{background:rgba(231,76,60,.12);border:1px solid var(--err);color:#ffb4ab;padding:10px 14px;border-radius:6px;margin-bottom:14px;font-size:14px}
.done{background:rgba(46,204,113,.12);border:1px solid var(--ok);color:#a8f0c6;padding:14px;border-radius:6px;font-size:15px}
.done a{color:var(--primary);font-weight:bold}
code{background:#000;color:var(--primary);padding:1px 6px;border-radius:3px}
</style></head><body><div class="wrap">
<h1>GOKZ Profile Viewer — Setup</h1>
<p class="sub">Configure your profile. Nothing here is committed — your settings go in <code>config.json</code> (git-ignored).</p>

<?php if ($saved): ?>
  <div class="done">✓ Saved <code>config.json</code>. <a href="index.php">Open your profile →</a></div>
<?php else: ?>

<div class="card">
  <h2>Dependencies</h2>
  <div class="dep"><span>PHP 7.4+ <span class="hint">(runs the site)</span></span><span class="pill <?= $php_ok?'ok':'no' ?>"><?= $php_ok?('OK — '.PHP_VERSION):'MISSING' ?></span></div>
  <div class="dep"><span>PDO SQLite <span class="hint">(reads the GOKZ DB)</span></span><span class="pill <?= $sqlite_ok?'ok':'no' ?>"><?= $sqlite_ok?'OK':'MISSING — enable pdo_sqlite' ?></span></div>
  <div class="dep"><span>ffmpeg <span class="hint">(optional — video rendering)</span></span><span class="pill <?= $ffmpeg?'ok':'opt' ?>"><?= $ffmpeg?htmlspecialchars($ffmpeg):'not found — winget install Gyan.FFmpeg' ?></span></div>
  <div class="dep"><span>Node.js <span class="hint">(optional — video rendering)</span></span><span class="pill <?= $node?'ok':'opt' ?>"><?= $node?htmlspecialchars($node):'not found — nodejs.org' ?></span></div>
</div>

<?php foreach ($errors as $e): ?><div class="err"><?= $e ?></div><?php endforeach; ?>

<form method="post" enctype="multipart/form-data">
  <div class="card">
    <h2>Your profile</h2>
    <label>SteamID <span class="hint">(STEAM_0:1:XXXX, SteamID64, or 32-bit account id)</span></label>
    <input type="text" name="steamid" value="<?= htmlspecialchars($_POST['steamid'] ?? (($existing['steamid32']??0)?:'')) ?>" placeholder="STEAM_0:1:651697270" required>
    <label>Display name <span class="hint">(optional — defaults to the alias in the GOKZ DB)</span></label>
    <input type="text" name="alias" value="<?= $val('alias') ?>" placeholder="leave blank to auto-detect">
    <label>Avatar image <span class="hint">(optional upload; jpg/png)</span></label>
    <input type="file" name="avatar" accept="image/*">
  </div>

  <div class="card">
    <h2>Server paths</h2>
    <label>CS:GO "csgo" folder <span class="hint">(the folder that contains <code>addons</code> and <code>maps</code>)</span></label>
    <input type="text" name="csgo_dir" value="<?= htmlspecialchars($_POST['csgo_dir'] ?? $val('csgo_dir')) ?>" placeholder="D:/.../Counter-Strike Global Offensive/csgo" required>
    <div class="hint">Replays and the GOKZ database are auto-found under here. Override below only if non-standard.</div>
    <label>Replays folder override <span class="hint">(optional)</span></label>
    <input type="text" name="replays_dir" value="<?= $val('replays_dir') ?>" placeholder="…/addons/sourcemod/data/gokz-replays/_runs">
    <label>GOKZ SQLite DB override <span class="hint">(optional)</span></label>
    <input type="text" name="live_db" value="<?= $val('live_db') ?>" placeholder="…/addons/sourcemod/data/sqlite/gokz-sqlite.sq3">
  </div>

  <button class="btn" type="submit">Save &amp; continue</button>
</form>
<?php endif; ?>
</div></body></html>
