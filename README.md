# GOKZ Profile Viewer

A self-hosted web profile + replay viewer for a **GOKZ** (CS:GO Kreedz) server. It reads
your server's GOKZ SQLite database and replay files and gives you:

- **Maps tab** — your completion stats (per-tier progress, PRO/TP times, run history, search/sort/filter, grid & table views, favorites).
- **Replays tab** — browse maps that have a recording and **watch the replay in 3D in the browser** (WebGL), with an *mhud*-style speed + key overlay. Filter by which player set the run.
- **Video rendering** (optional) — export any replay to **mp4** (GPU/NVENC), plus a **watcher** that auto-renders new replays as they appear.

Everything personal lives in a git-ignored `config.json`, so the repo is safe to share — each
user points it at **their own** server.

---

## Requirements

| | Needed for | Install |
|---|---|---|
| **PHP 8+** (with `pdo_sqlite`) | the website | `winget install PHP.PHP` |
| A local **GOKZ server** install | the data (DB + replays) | your CS:GO dedicated server |
| **Node.js 18+** | video rendering (optional) | https://nodejs.org |
| **ffmpeg** (with NVENC ideally) | video rendering (optional) | `winget install Gyan.FFmpeg` |
| **SourceUtils** | map geometry export | auto-downloaded by `start-renderer.bat` |

## Quick start

1. Install PHP (above). Clone this repo.
2. Run **`install.bat`** — it checks your dependencies, starts the server, and opens the
   **setup wizard** in your browser.
3. In setup: enter your **SteamID**, optionally upload an **avatar**, and point it at your
   **CS:GO `csgo` folder**. It auto-finds the GOKZ replays + database underneath. Save.
4. Your profile opens at `http://localhost:8000/`.

## Watching replays in 3D

The viewer needs each map's geometry exported once (from its `.bsp`):

- It exports **on demand** the first time you open a replay (via SourceUtils, into `mapdata/`), or
- run `tools\export-maps.bat` to batch-export.

Only maps whose `.bsp` is present in `csgo\maps` can be exported.

## Video rendering (optional)

Run **`start-renderer.bat`**. On first run it installs the renderer dependencies (Playwright +
Chromium) and SourceUtils, then:

- **baselines** the existing replays (won't render your whole back-catalogue), and
- **auto-renders new replays** to `renders\*.mp4` as they appear (with the mhud HUD, GPU-encoded).

Options (set before running, or in the bat): `PLAYERS=<steamid32>` to render only one player's
replays, `RENDER_EXISTING=1` to backfill everything, `POLL=<seconds>`.

## How your data is kept safe

- The GOKZ database is **never modified** — it's copied (with its WAL) into `cache/` and read there.
- Replay files are streamed read-only through PHP.
- `config.json`, the DB copies, exported maps, rendered videos, and your avatar are all
  **git-ignored**.

## Credits

Built around [GameChaos/GlobalReplays](https://github.com/GameChaos/GlobalReplays) (a fork of
[Metapyziks/GOKZReplayViewer](https://github.com/Metapyziks/GOKZReplayViewer)) and
[Metapyziks/SourceUtils](https://github.com/Metapyziks/SourceUtils). See `LICENSE`.
