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

### Web Push notifications

Web Push needs two things: the server library, and HTTPS (browsers also allow plain
`http://localhost`, so local testing works without certificates).

```
composer install
```

That's the whole setup. Todoer generates its own VAPID key pair on first use and stores it in
`data/vapid.json` (mode 0600, and `data/` is gitignored) — there is nothing to configure. If you'd
rather manage keys yourself, set both environment variables and they take precedence over the
file:

```
TODOER_VAPID_PUBLIC_KEY=...
TODOER_VAPID_PRIVATE_KEY=...
TODOER_VAPID_SUBJECT=mailto:you@example.com   # optional, used as the VAPID contact
```

After signing in, each browser/device can choose **Enable notifications**. In-app notifications
work regardless — if Composer hasn't been run, or the key file can't be written, push quietly
stays off (the reason is written to the PHP error log) and nothing else is affected.

Rotating the keypair (deleting `data/vapid.json`, or switching to environment variables) makes
every existing browser subscription invalid. That's handled: the server drops subscriptions the
push service rejects, and each browser notices the key changed on its next visit and
re-subscribes. Push delivery is also best-effort — an unreachable push service is logged and
ignored, never surfaced as a failed task completion.

To run it permanently in the background instead of a one-off terminal window,
put it behind any standard PHP web server (Apache/Nginx + php-fpm) pointed at
this folder — no code changes needed.

## Groups: who you play with (and who can't see you)

Everything in Todoer happens inside a **group**. A group is the answer to "whose
tasks can I see, and who am I competing against":

- Every account belongs to exactly one group. Registering without an invite code
  creates a personal group with you as its **admin** — you can use the app alone,
  and nobody else can see any of it.
- A group **admin** can add people: either an existing Todoer account (by
  username) or a brand-new account they create on the spot for someone who
  doesn't have one. Admins can also remove members, promote another member to
  admin, rename the group, and roll its invite code.
- Anyone can join a group themselves with its **invite code** — on the Join form
  at sign-up, or from the Group page later. Joining moves you out of your current
  group, since membership is one group at a time.
- Inside a group, everyone sees everything: all members' tasks on the team board,
  the same daily/weekly/monthly lists, the same leaderboard, the same prize
  history.
- Outside a group, nothing is visible. A different group on the same install has
  its own tasks, its own Start/Stop state, its own standings and its own prize
  draw for the very same day/week/month. Members of one never appear in the
  other's leaderboard or prize list, can't be assigned its tasks, and don't
  receive its notifications.
- Leaving or being removed gives you a fresh personal group. Your account
  survives; the tasks, points and prizes you earned stay with the group they were
  earned in, because that's the competition they belonged to.

Scoping is enforced server-side, not in the UI: the group is resolved from your
session membership on every request (never from anything the client sends), every
task/leaderboard/prize/award query is filtered by `group_id`, and cross-user
references (like locking a task to a specific person) are rejected unless that
person is in your group. See `includes/groups.php`.

## How the game works

- Tasks live in a shared pool per Daily/Weekly/Monthly list *within your group*,
  not a private per-player list. Any group member can add a task to any of the
  group's lists; points are flat by list: Daily = 1pt, Weekly = 3pts,
  Monthly = 5pts.
- The moment a day/week/month has fully elapsed (or every task in it is done
  or missed — see below), the app tallies the group's points for that period,
  crowns whoever scored highest (ties are broken randomly), and awards them a
  random, not-yet-used prize from the pool. This check runs automatically
  whenever anyone loads a page — no cron job needed.
- The "Prizes" page shows your group's full history of who won what and when. A winner
  can mark their own prize as "claimed" once it's been redeemed in real life.
- The sidebar leaderboard shows Today / This week / This month / All-time
  standings side by side.
- The dashboard shows in-app notifications for the group when a list starts, for the current holder
  when a task reaches the final 10% of its effective time window, and for everyone when daily results
  are calculated. Notifications are stored, delivered on the next dashboard visit, and sent only once
  per event.

## Game mode: the shared board

Pressing **Start** on a list puts it in game mode. While a list is running:

- Adding, editing and deleting are locked; the list becomes the board you play on.
- The list shows **every** task in that period, not just yours, with **your own tasks at the
  top** — so "what am I meant to be doing" is still the first thing you read.
- Below the "Up for grabs" divider are the other players' live tasks. Any of them can be **taken
  over**: if its time window is open and the person holding it hasn't ticked it off yet, you can
  do it instead and the points come to you. This is the race the game is built around.
- Taking over and completing are one step, so nobody can reserve other people's tasks and sit on
  them. If two people tick the same task at once, the loser gets "somebody else just took that
  one" rather than a double completion.
- A task **locked to a specific person** is never up for grabs, and a task whose window hasn't
  opened yet (or has already closed) can't be taken either. The row says which of those it is.
- Whoever lost the task gets a notification naming who took it and how many points went with it
  (in-app, plus a push notification if they've enabled it).
- Un-ticking a task you took over hands it back to the person you took it from, rather than
  leaving you holding their task.

## Task assignment, priority & timers

Each task can carry, in addition to its title:

- **Window** (`window_start` → `window_end`): the earliest time it can be done
  and the latest time it must be finished by.
- **Assignment**: `ANY_USER` (goes into the shared pool to be distributed) or
  `SPECIFIC_USER` (locked to one designated player from the moment it's
  created).
- **Priority**: `HIGH`, `MODERATE`, or `LOW`. `HIGH` tasks always use a short,
  fixed completion timer (`TODOER_HIGH_PRIORITY_TIME_LIMIT_MINUTES` in
  `includes/assignment.php`, 30 minutes by default) instead of whatever
  per-task timer was set, and that timer restarts fresh for whoever it gets
  handed to.
- **Time limit** (minutes): an optional per-task timer that starts counting
  the moment the task is assigned to its current holder (ignored for `HIGH`
  tasks, which always use the timer above instead).

**Starting a list.** Newly-added tasks sit as `unassigned` until someone
clicks the **Start** button on that list (daily/weekly/monthly). Start locks
every `SPECIFIC_USER` task straight to its designated player, then hands out
the remaining `ANY_USER` tasks so everyone's open-task count for that period
stays as even as possible. Starting is safe to click again later — it only
ever picks up tasks that are still `unassigned` (e.g. ones added after the
list was already started); it never reshuffles work that's already been
handed out.

**Missed tasks.** Every page load sweeps for tasks past their deadline (the
earlier of `window_end` and "assigned + its time limit"). An `ANY_USER` task
that's overdue is taken from its current holder and handed to a different
active player (whoever has the lightest load), and if it's `HIGH` priority the
new holder starts on the same short timer. A locked `SPECIFIC_USER` task has
nobody else it's allowed to go to, so a missed one is simply marked
**expired** instead.

**Ending a period early.** If every task in a started period is done or
expired before the day/week/month is actually over, the period closes and
awards its prize right away rather than waiting for the clock — the "Daily
Game: ends when all daily tasks are completed" rule from the spec.

Users can be marked inactive (`users.active = 0`) to exclude them from
distribution and reassignment — there's no UI for this yet; toggle it directly
in `data/todoer.sqlite` if someone is sitting a period out.

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
  login.php            sign in / join (optionally with a group invite code)
  group.php            group page: members, add/remove, invite code, join/leave
  prizes.php           prize history + claim button
  import.php           Google Keep import page
  logout.php
  includes/
    schema.sql         SQLite schema
    db.php             DB bootstrap + prize seeding + old-install migration (incl. groups)
    auth.php           register/login/session helpers
    groups.php         group membership, admin actions, invite codes — the privacy boundary
    period.php         period keys, auto-close + prize award logic, leaderboards
    assignment.php      distribution on Start, view ordering, expiration/reassignment sweep
    api_helpers.php     JSON request/response helpers
    bootstrap.php       wires the above together, runs the expiration sweep + period auto-close
    keep_import.php     parses Google Takeout Keep .json/.zip exports
  api/
    tasks.php           list mine/board, add, start, complete/reopen/delete tasks (JSON)
    leaderboard.php      leaderboard data (JSON)
    prizes.php           prize history + claim (JSON)
    group.php            group members + admin actions (JSON)
    import.php           Keep upload parsing (step 1) + bulk task insert (step 2)
  assets/
    css/style.css
    js/app.js            dashboard behaviour
    js/group.js           group page behaviour
    js/prizes.js          prizes page behaviour
    js/import.js          Keep import scan/preview/commit flow
  data/
    todoer.sqlite         created automatically on first run
```

## Notes / things you might want to change later

- Passwords are hashed with PHP's `password_hash` — fine for a household app,
  not audited for anything more sensitive than that.
- Tasks are a shared pool per period rather than private lists — everyone *in
  your group* can see and add to the same daily/weekly/monthly board, and the
  "Team board" panel on each list shows who currently holds what.
- Upgrading a database that predates groups folds the whole install into a single
  group named "Our group" (the first registered user becomes its admin), which
  keeps every existing task, score and prize exactly where it was. The prize
  *pool* stays shared install-wide, but "already won" is tracked per group, so a
  new group still has the full list of prizes to work through.
- Ties for a period split the prize randomly between the tied top scorers
  rather than awarding it to both — easy to change in
  `todoer_close_one_period()` in `includes/period.php` if you'd rather award
  every tied winner.
