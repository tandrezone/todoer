# Todoer — the competitive to-do list

A to-do list for the day, the week, and the month — except now it's a game.
Everyone playing creates an account, adds tasks to their own daily/weekly/monthly
lists, and earns points for finishing them. When a day, week, or month ends, the
top scorer for that period is automatically crowned and wins a random prize from
a pool of 20 real-life rewards.

## Stack

Plain PHP 8+ (no framework), SQLite (via PDO), HTML/CSS/vanilla JS. No build step,
no dependencies to install.

## How to run it

You need PHP with the `pdo_sqlite` extension (bundled with PHP on most systems).

```
cd todoer
php -S 0.0.0.0:8080
```

Then open `http://localhost:8080/login.php` in a browser. Using `0.0.0.0` instead
of `127.0.0.1` lets other players on the same Wi-Fi/LAN open
`http://<your-computer's-IP>:8080/login.php` on their own phone or laptop and
join with their own account — that's the "join 2+ persons" part.

On first run it creates `data/todoer.sqlite` automatically and seeds the 20-prize
pool. Nothing else to configure.

To run it permanently in the background instead of a one-off terminal window,
put it behind any standard PHP web server (Apache/Nginx + php-fpm) pointed at
this folder — no code changes needed.

## How the game works

- Each player has their own Daily, Weekly, and Monthly lists. Add a task, check
  it off when it's done.
- Points are flat by list: Daily = 1pt, Weekly = 3pts, Monthly = 5pts.
- The moment a day/week/month has fully elapsed, the app tallies everyone's
  points for that period, crowns whoever scored highest (ties are broken
  randomly), and awards them a random, not-yet-used prize from the pool. This
  check runs automatically whenever anyone loads a page — no cron job needed.
- The "Prizes" page shows the full history of who won what and when. A winner
  can mark their own prize as "claimed" once it's been redeemed in real life.
- The sidebar leaderboard shows Today / This week / This month / All-time
  standings side by side.

## Importing tasks from Google Keep

Google Keep has no public API for personal accounts, so the import goes through
a Google Takeout export instead:

1. Go to [takeout.google.com](https://takeout.google.com), click **Deselect
   all**, check only **Keep**, and start the export.
2. Download and unzip it. Inside is a `Takeout/Keep/` folder with one `.json`
   file per note (plus a matching `.html` copy of each you can ignore).
3. In the app, open **Import** from the top nav, choose either that whole
   `.zip` or just the `.json` files themselves, and click **Scan files**.
4. Checklist notes get split one row per item; plain notes (no checklist) can
   be split one row per line, reduced to just their title, or skipped —
   there's a dropdown for that. Items already checked off in Keep are left out
   by default (so you don't get free points for old, already-done notes) —
   tick "also include items already checked off" if you want them anyway.
5. Review the preview list: untick anything you don't want, set which list
   (daily/weekly/monthly) each row lands on — there's a bulk "set list for
   selected" control — then click **Import selected tasks**. Imported tasks
   always land as open/incomplete regardless of their Keep state, so points
   are only ever earned by finishing them inside Todoer.

This is handled by `import.php` (page), `assets/js/import.js` (scan/preview/
commit flow), `api/import.php` (upload parsing + bulk insert), and
`includes/keep_import.php` (the actual Keep JSON parsing). If the server's
PHP doesn't have the `zip` extension, `.zip` uploads fail with a clear message
telling you to upload the extracted `.json` files instead — that path always
works since it doesn't touch ZipArchive at all.

## The prize pool

Edit the list in `includes/db.php` (inside the `todoer_db()` seeding block) to
change the 20 prizes — things like "1 hour of uninterrupted rest", "a 20-minute
massage from the runner-up", "pick the next movie night", "skip a chore", etc.
Edits there only affect brand-new installs (a fresh `data/todoer.sqlite`); to
change the pool on an existing install, edit the `prizes` table directly, e.g.
with a one-off `php -r` script using PDO.

## Project layout

```
todoer/
  index.php            dashboard: lists + leaderboard
  login.php            sign in / join
  prizes.php           prize history + claim button
  import.php           Google Keep import page
  logout.php
  includes/
    schema.sql         SQLite schema
    db.php             DB bootstrap + prize seeding
    auth.php           register/login/session helpers
    period.php         period keys, auto-close + prize award logic, leaderboards
    api_helpers.php     JSON request/response helpers
    bootstrap.php       wires the above together, runs the period auto-close
    keep_import.php     parses Google Takeout Keep .json/.zip exports
  api/
    tasks.php           list/add/complete/reopen/delete tasks (JSON)
    leaderboard.php      leaderboard data (JSON)
    prizes.php           prize history + claim (JSON)
    import.php           Keep upload parsing (step 1) + bulk task insert (step 2)
  assets/
    css/style.css
    js/app.js            dashboard behaviour
    js/prizes.js          prizes page behaviour
    js/import.js          Keep import scan/preview/commit flow
  data/
    todoer.sqlite         created automatically on first run
```

## Notes / things you might want to change later

- Passwords are hashed with PHP's `password_hash` — fine for a household app,
  not audited for anything more sensitive than that.
- Task lists are private per player; only totals are visible to others, which
  keeps the competitive framing without turning it into surveillance.
- Ties for a period split the prize randomly between the tied top scorers
  rather than awarding it to both — easy to change in
  `todoer_close_one_period()` in `includes/period.php` if you'd rather award
  every tied winner.
