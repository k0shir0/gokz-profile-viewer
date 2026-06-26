# GOKZ Profile Viewer

A **self-hosted web stats page for your own localhosted GOKZ (CS:GO Kreedz) server.** Point it at
your server's GOKZ database and replay files and it gives you, in your browser:

- **Maps** — your completion stats: per-tier progress, PRO/TP personal bests, run history,
  search / sort / filter, grid & table views, and favorites.
- **Replays** — every map that has a recording, played back **in 3D in the browser** (WebGL) with an
  *mhud*-style speed + keys overlay. Filter by which player set the run.
- **Video export** *(optional)* — render any replay to an **mp4** (GPU/NVENC) and auto-render new
  runs as they happen.

Your server stays untouched — the app only **reads** your data, and everything personal lives in a
git-ignored `config.json`. This repo ships with **no one's stats**; each person sets up their own.

---

## 1. Before you start (what you need)

| Requirement | Why | How to get it |
|---|---|---|
| A **localhosted GOKZ server** | it's the source of your data (the SQLite DB + `.replay` files) | you already run this — a CS:GO dedicated server with the GOKZ plugins |
| **PHP 8+** with the `pdo_sqlite` extension | runs the website + reads the DB | `winget install PHP.PHP` (Windows) |
| Your **SteamID** | tells the app whose stats to show | any format works — see step 4 |
| *(optional)* **Node.js 18+** and **ffmpeg** | only for the video renderer | `winget install OpenJS.NodeJS` and `winget install Gyan.FFmpeg` |

> The GOKZ server does **not** need to be running while you browse — only its files need to exist.
> By default they live under `…/csgo/addons/sourcemod/data/` (the `gokz-replays/_runs` folder and
> the `sqlite/gokz-sqlite.sq3` database). The setup wizard finds them for you.

---

## 2. Get the code

```sh
git clone https://github.com/k0shir0/gokz-profile-viewer.git
cd gokz-profile-viewer
```
(or download the ZIP from GitHub and extract it).

## 3. Install PHP (if you don't have it)

```sh
winget install PHP.PHP
```
Close and reopen your terminal afterwards so `php` is on your PATH. (`pdo_sqlite` ships enabled with
the winget build; if you use another PHP, make sure `extension=pdo_sqlite` is on in `php.ini`.)

## 4. Run the installer

Double-click **`install.bat`**. It will:

1. Check your dependencies (PHP + `pdo_sqlite` are required; Node + ffmpeg are reported as optional).
2. Start the local web server on `http://localhost:8000`.
3. Open the **setup wizard** in your browser (because there's no `config.json` yet).

In the wizard, fill in:

- **SteamID** — paste it in any form: `STEAM_0:1:651697270`, a 17-digit SteamID64
  (`7656119…`), or a 32-bit account id. (Find yours at e.g. *steamid.io*, or type `status` in your
  server console.) The app converts it automatically.
- **Display name** — optional; leave blank to use the alias stored in the GOKZ DB.
- **Avatar** — optional image upload.
- **CS:GO `csgo` folder** — the folder that contains `addons/` and `maps/`, e.g.
  `D:/SteamLibrary/steamapps/common/Counter-Strike Global Offensive/csgo`. The replays folder and the
  GOKZ database are auto-detected underneath it (you can override them if your layout is custom).

Click **Save & continue** → your profile loads at `http://localhost:8000/`.

From now on, just run **`start.bat`** (or `install.bat`) to launch it.

---

## 5. Watching replays in 3D

Open the **Replays** tab, find a map with a ▶ button, and click it. Each map's 3D geometry has to be
exported once from its `.bsp`:

- It exports **automatically** the first time you open a replay for that map, **or**
- run `tools\export-maps.bat` to batch-export all available maps.

Only maps whose `.bsp` exists in `csgo\maps` can be exported (download the workshop maps you want on
your server first). Map export uses **SourceUtils**, which the renderer setup downloads for you; if
you only use the web viewer it's fetched on first use as well.

## 6. Video export (optional)

Run **`start-renderer.bat`**. The first run installs the renderer dependencies (Playwright + a headless
Chromium) and SourceUtils, then:

- **baselines** your existing replays (so it won't render your whole back-catalogue), and
- **auto-renders new replays** to the `renders\` folder as they appear — with the mhud HUD, encoded on
  your GPU via ffmpeg/NVENC.

Tweak it with environment variables (set before running, or edit the `.bat`):

| Variable | Effect |
|---|---|
| `PLAYERS=<steamid32>` | only render that player's replays (comma-separated for several) |
| `RENDER_EXISTING=1` | also render everything already present (full backfill — slow) |
| `POLL=15` | how often (seconds) to check for new replays (default 30) |

You can also render a single replay by hand: `cd renderer && node render.js <map> <file>.replay`.

## 7. Gallary
<img width="1914" height="951" alt="Screenshot 2026-06-26 101023" src="https://github.com/user-attachments/assets/3e86d7f0-71e6-4417-8135-d41a7cb43f85" />




## Troubleshooting

- **It keeps opening the setup page** → `config.json` is missing or has a blank `csgo_dir`. Re-run setup.
- **"Database not found" / no stats** → the `csgo` path is wrong, or the GOKZ SQLite DB isn't there
  yet. Confirm `…/csgo/addons/sourcemod/data/sqlite/gokz-sqlite.sq3` exists.
- **Replay opens but the map is blank** → that map isn't exported yet (run `tools\export-maps.bat`),
  or its `.bsp` isn't in `csgo\maps`.
- **`php` not recognized** → reopen your terminal after installing PHP so PATH refreshes.
- **Renderer can't find ffmpeg** → install it (`winget install Gyan.FFmpeg`) and reopen the terminal.

## Credits
Built around [GameChaos/GlobalReplays](https://github.com/GameChaos/GlobalReplays) (a fork of
[Metapyziks/GOKZReplayViewer](https://github.com/Metapyziks/GOKZReplayViewer)) and
[Metapyziks/SourceUtils](https://github.com/Metapyziks/SourceUtils). MIT licensed — see `LICENSE`.
