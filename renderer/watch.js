// Auto-checker + renderer.
// Watches the GOKZ _runs folders for NEW (or changed) .replay files and renders
// each to mp4 via render.js. On first run it baselines the existing replays
// (marks them seen WITHOUT rendering) so it doesn't render ~1000 at once — only
// new replays from then on get rendered.
//
//   node watch.js
//
// Env:
//   PLAYERS=<steamid32>         only render replays by these SteamID32 (comma list); empty = all new
//   POLL=30                     poll interval seconds (default 30)
//   RENDER_EXISTING=1           also render everything already present (full backfill; slow)
//   DRY=1                       log what would render, don't render

const fs = require('fs');
const path = require('path');
const { renderReplay, startPhpServer } = require('./render');

const APP_ROOT = path.resolve(__dirname, '..');
function loadConfig() { try { return JSON.parse(fs.readFileSync(path.join(APP_ROOT, 'config.json'), 'utf8')); } catch (e) { return {}; } }
const CFG = loadConfig();
const CSGO_DIR = String(CFG.csgo_dir || '').replace(/\\/g, '/');
const RUNS = (String(CFG.replays_dir || '').trim() || (CSGO_DIR + '/addons/sourcemod/data/gokz-replays/_runs')).replace(/\\/g, '/');
const OUT = path.resolve(__dirname, '..', 'renders');
const STATE = path.join(__dirname, 'rendered.json');
const POLL_MS = (parseInt(process.env.POLL, 10) || 30) * 1000;
const PLAYERS = (process.env.PLAYERS || '').split(',').map(s => s.trim()).filter(Boolean).map(Number);
const RENDER_EXISTING = process.env.RENDER_EXISTING === '1';
const DRY = process.env.DRY === '1';

function log(...a) { console.log('[watch]', ...a); }
function readState() { try { return JSON.parse(fs.readFileSync(STATE, 'utf8')); } catch (e) { return {}; } }
function writeState(s) { try { fs.writeFileSync(STATE, JSON.stringify(s)); } catch (e) {} }

// Minimal GOKZ v2 header read -> playerSteamID (account id), or null.
function replaySteamId(p) {
  let fd;
  try { fd = fs.openSync(p, 'r'); } catch (e) { return null; }
  const buf = Buffer.alloc(256);
  const n = fs.readSync(fd, buf, 0, 256, 0); fs.closeSync(fd);
  if (n < 24 || buf.readUInt32LE(0) !== 0x676F6B7A) return null;
  let o = 4;
  const fmt = buf[o++]; if (fmt < 2) return null;
  o++;                                   // replayType
  const skipStr = () => { const l = buf[o++]; o += l; };
  skipStr(); skipStr();                  // pluginVersion, mapName
  o += 12;                               // mapFileSize, serverIP, timestamp
  skipStr();                             // playerAlias
  if (o + 4 > n) return null;
  return buf.readUInt32LE(o);            // playerSteamID
}

function listReplays() {
  const out = [];
  let maps;
  try { maps = fs.readdirSync(RUNS); } catch (e) { return out; }
  for (const map of maps) {
    const d = path.join(RUNS, map);
    let st; try { st = fs.statSync(d); } catch (e) { continue; }
    if (!st.isDirectory()) continue;
    let files; try { files = fs.readdirSync(d); } catch (e) { continue; }
    for (const f of files) {
      if (!f.toLowerCase().endsWith('.replay')) continue;
      const fp = path.join(d, f);
      let s; try { s = fs.statSync(fp); } catch (e) { continue; }
      out.push({ map: map.toLowerCase(), file: f, fp, mtime: Math.round(s.mtimeMs) });
    }
  }
  return out;
}

async function tick(server) {
  const state = readState();
  const firstRun = !state.__init;
  const all = listReplays();
  const todo = [];
  for (const r of all) {
    const key = r.map + '/' + r.file;
    const prev = state[key];
    if (prev && prev.rendered && prev.mtime === r.mtime) continue;        // already rendered, unchanged
    // first-run baseline: mark seen, don't render the back catalogue
    if (firstRun && !RENDER_EXISTING) { state[key] = { mtime: r.mtime, rendered: false, baseline: true }; continue; }
    if (prev && !prev.rendered && prev.mtime === r.mtime && (prev.error || prev.skipped)) continue; // don't retry forever
    if (PLAYERS.length) {
      const sid = replaySteamId(r.fp);
      if (sid === null || !PLAYERS.includes(sid)) { state[key] = { mtime: r.mtime, rendered: false, skipped: 'player' }; continue; }
    }
    todo.push(r);
  }
  if (firstRun) { state.__init = true; writeState(state); log('baseline set:', all.length, 'existing replays marked seen (not rendered).'); }

  if (todo.length) log(todo.length, 'replay(s) to render');
  for (const r of todo) {
    const out = path.join(OUT, r.map + '__' + r.file.replace(/\.replay$/i, '') + '.mp4');
    if (DRY) { log('WOULD render', r.map, r.file, '->', path.basename(out)); state[r.map + '/' + r.file] = { mtime: r.mtime, rendered: false, dry: true }; continue; }
    try {
      log('rendering', r.map, r.file, '...');
      await renderReplay({ map: r.map, file: r.file, out, base: server.base, keepServer: true });
      state[r.map + '/' + r.file] = { mtime: r.mtime, rendered: true, out };
    } catch (e) {
      log('FAILED', r.map, r.file, '-', e.message);
      state[r.map + '/' + r.file] = { mtime: r.mtime, rendered: false, error: e.message };
    }
    writeState(state);
  }
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const server = await startPhpServer(8077);
  log('serving at', server.base, '| output ->', OUT);
  log('filter:', PLAYERS.length ? ('players ' + PLAYERS.join(',')) : '(all new replays)', '| poll', POLL_MS / 1000 + 's', DRY ? '| DRY-RUN' : '');
  await tick(server);
  setInterval(() => tick(server).catch(e => log('tick error', e.message)), POLL_MS);
})().catch(e => { console.error(e); process.exit(1); });
