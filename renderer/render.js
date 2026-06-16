// Headless replay -> mp4 renderer.
// Loads ../render.html in headless Chromium, records one pass of the replay via
// MediaRecorder (canvas.captureStream), then encodes to mp4 with ffmpeg (NVENC).
//
// CLI:  node render.js <map> <replayfile.replay> [outPath.mp4]
//   (starts its own `php -S` for the app root, renders, then stops it)
//
// Programmatic:  const { renderReplay } = require('./render');  await renderReplay({...})

const { chromium } = require('playwright');
const { spawn, spawnSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const http = require('http');

const APP_ROOT = path.resolve(__dirname, '..');                 // project root
const SU_EXE = path.join(APP_ROOT, 'tools', 'SourceUtils', 'bin', 'SourceUtils.WebExport.exe');
function loadConfig() { try { return JSON.parse(fs.readFileSync(path.join(APP_ROOT, 'config.json'), 'utf8')); } catch (e) { return {}; } }
const CFG = loadConfig();
const CSGO_DIR = String(CFG.csgo_dir || '').replace(/\\/g, '/');  // from config.json (written by setup.php)
const WIDTH = 1280, HEIGHT = 720, FPS = 60;

function log(...a) { console.log('[render]', ...a); }

// ---- locate ffmpeg (winget Links dir may not be on a stale PATH) ----
function ffmpegPath() {
  const cand = [
    'ffmpeg',
    path.join(process.env.LOCALAPPDATA || '', 'Microsoft', 'WinGet', 'Links', 'ffmpeg.exe'),
  ];
  for (const c of cand) {
    try { const r = spawnSync(c, ['-version'], { stdio: 'ignore' }); if (r.status === 0) return c; } catch (e) {}
  }
  return 'ffmpeg';
}
const FFMPEG = ffmpegPath();

// ---- pick the best available GPU h264 encoder, else libx264 ----
function pickEncoder() {
  try {
    const out = spawnSync(FFMPEG, ['-hide_banner', '-encoders'], { encoding: 'utf8' }).stdout || '';
    if (/h264_nvenc/.test(out)) return ['h264_nvenc', '-preset', 'p5', '-cq', '21'];
    if (/h264_amf/.test(out))   return ['h264_amf', '-quality', 'quality', '-rc', 'cqp', '-qp_i', '21', '-qp_p', '21'];
    if (/h264_qsv/.test(out))   return ['h264_qsv', '-global_quality', '21'];
  } catch (e) {}
  return ['libx264', '-preset', 'medium', '-crf', '20'];
}

function waitForServer(url, timeoutMs) {
  return new Promise((resolve, reject) => {
    const t0 = Date.now();
    (function tick() {
      http.get(url, r => { r.resume(); resolve(); }).on('error', () => {
        if (Date.now() - t0 > timeoutMs) reject(new Error('server did not start'));
        else setTimeout(tick, 250);
      });
    })();
  });
}

// Start a php dev server rooted at the app, return { stop, base }.
async function startPhpServer(port) {
  const proc = spawn('php', ['-S', '127.0.0.1:' + port, 'router.php'], { cwd: APP_ROOT });
  proc.on('error', e => log('php spawn error:', e.message));
  const base = 'http://127.0.0.1:' + port;
  await waitForServer(base + '/router.php', 15000).catch(() => {});
  return { base, stop: () => { try { proc.kill(); } catch (e) {} } };
}

// Ensure the map's geometry is exported (mapdata/maps/<map>/index.json). If not, run SourceUtils.
function ensureMapExported(map) {
  const idx = path.join(APP_ROOT, 'mapdata', 'maps', map, 'index.json');
  if (fs.existsSync(idx)) return true;
  if (!fs.existsSync(SU_EXE)) { log('SourceUtils not found, cannot export', map); return false; }
  log('exporting map geometry for', map, '...');
  const r = spawnSync(SU_EXE, [
    'export', '--maps', map, '--outdir', path.join(APP_ROOT, 'mapdata'),
    '--gamedir', CSGO_DIR, '--mapsdir', 'maps', '--packages', 'pak01_dir.vpk',
    '--url-prefix', '/mapdata', '--untextured', '--overwrite',
  ], { stdio: 'inherit' });
  return r.status === 0 && fs.existsSync(idx);
}

async function renderReplay({ map, file, out, base, keepServer }) {
  if (!ensureMapExported(map)) throw new Error('map geometry unavailable for ' + map);

  let server = null;
  if (!base) { server = await startPhpServer(8077); base = server.base; }

  const browser = await chromium.launch({
    headless: true,
    args: [
      '--use-angle=swiftshader', '--use-gl=angle', '--enable-unsafe-swiftshader',
      '--ignore-gpu-blocklist', '--enable-webgl',
      '--disable-background-timer-throttling', '--disable-renderer-backgrounding',
      '--disable-backgrounding-occluded-windows', '--autoplay-policy=no-user-gesture-required',
      '--hide-scrollbars', '--mute-audio',
    ],
  });
  const vidDir = fs.mkdtempSync(path.join(os.tmpdir(), 'epvid_'));
  let rawWebm, startMs = 0, endMs = 0;
  try {
    // Playwright records the WHOLE page (canvas + the HTML mhud HUD overlay).
    const ctx = await browser.newContext({
      viewport: { width: WIDTH, height: HEIGHT }, deviceScaleFactor: 1,
      recordVideo: { dir: vidDir, size: { width: WIDTH, height: HEIGHT } },
    });
    const t0 = Date.now();                       // ~ when page recording starts
    const page = await ctx.newPage();
    const video = page.video();
    page.on('console', m => { if (m.type() === 'error') log('page error:', m.text()); });
    const url = base + '/render.html?map=' + encodeURIComponent(map) + '&file=' + encodeURIComponent(file);
    log('loading', url);
    await page.goto(url, { waitUntil: 'load', timeout: 60000 });
    await page.waitForFunction('window.__ready===true || window.__error', { timeout: 120000 });
    const err = await page.evaluate('window.__error');
    if (err) throw new Error('render page: ' + err);
    log('map ready, recording one pass...');
    startMs = Date.now() - t0;
    await page.evaluate('window.__play()');
    await page.waitForFunction('window.__done===true', { timeout: 30 * 60 * 1000 });
    endMs = Date.now() - t0;
    await ctx.close();                           // finalises the video file
    rawWebm = await video.path();
    log('recorded', ((endMs - startMs) / 1000).toFixed(1) + 's of playback');
  } finally {
    await browser.close();
    if (server && !keepServer) server.stop();
  }

  fs.mkdirSync(path.dirname(out), { recursive: true });
  const enc = pickEncoder();
  const ss = Math.max(0, startMs / 1000 - 0.15);
  const dur = (endMs - startMs) / 1000 + 0.3;
  log('encoding ->', path.basename(out), 'with', enc[0]);
  const r = spawnSync(FFMPEG, [
    '-y', '-ss', ss.toFixed(3), '-i', rawWebm, '-t', dur.toFixed(3),
    '-c:v', ...enc, '-pix_fmt', 'yuv420p', '-movflags', '+faststart', out,
  ], { stdio: 'inherit' });
  try { fs.rmSync(vidDir, { recursive: true, force: true }); } catch (e) {}
  if (r.status !== 0 || !fs.existsSync(out)) throw new Error('ffmpeg failed');
  log('done:', out);
  return out;
}

module.exports = { renderReplay, startPhpServer, ensureMapExported };

if (require.main === module) {
  const [map, file, out] = process.argv.slice(2);
  if (!map || !file) { console.error('usage: node render.js <map> <replayfile.replay> [out.mp4]'); process.exit(1); }
  const outPath = out || path.join(APP_ROOT, 'renders', map + '__' + file.replace(/\.replay$/i, '') + '.mp4');
  renderReplay({ map, file, out: outPath })
    .then(() => process.exit(0))
    .catch(e => { console.error('[render] FAILED:', e.message); process.exit(1); });
}
