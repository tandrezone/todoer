#!/usr/bin/env bash
# Same as setup-mvc-branch.ps1, for Git Bash / WSL / macOS / Linux.
#
#   bash setup-mvc-branch.sh
#
# Creates the MVC branch, removes the procedural files the refactor replaces (they stay in git
# history on your current branch), expands todoer-mvc.zip over the repository, installs the
# Composer dependencies and commits. Your data/ directory is never touched.

set -euo pipefail

[ -d .git ] || { echo "ERROR: no .git here -- run this from the todoer repository root." >&2; exit 1; }
[ -f todoer-mvc.zip ] || { echo "ERROR: todoer-mvc.zip not found in $(pwd)." >&2; exit 1; }

if [ -n "$(git status --porcelain | grep -v -E 'todoer-mvc\.zip|setup-mvc-branch\.(ps1|sh)' || true)" ]; then
    echo "Your working tree has uncommitted changes:"
    git status --short
    read -r -p "Commit or stash them first. Continue anyway? (y/N) " answer
    [ "$answer" = "y" ] || exit 1
fi

starting_branch="$(git rev-parse --abbrev-ref HEAD)"
echo "Starting from branch '$starting_branch'."

if git show-ref --verify --quiet refs/heads/MVC; then
    echo "Branch MVC already exists -- switching to it."
    git switch MVC
else
    git switch -c MVC
fi

for path in index.php login.php logout.php group.php prizes.php import.php backup.php \
            service-worker.js site.webmanifest favicon.ico includes api assets; do
    if [ -e "$path" ]; then
        git rm -r -q --ignore-unmatch -- "$path" >/dev/null
        rm -rf -- "$path"
        echo "  removed $path"
    fi
done

echo "Expanding todoer-mvc.zip..."
if command -v unzip >/dev/null 2>&1; then
    unzip -oq todoer-mvc.zip
else
    php -r '$z = new ZipArchive(); $z->open("todoer-mvc.zip"); $z->extractTo("."); $z->close();'
fi
rm -f todoer-mvc.zip

if command -v composer >/dev/null 2>&1; then
    echo "Running composer install..."
    composer install --no-interaction || echo "composer install failed -- run it by hand before starting the app."
else
    echo "Composer is not on PATH. Run 'composer install' yourself before starting the app."
fi

git add -A
git commit -q -F - <<'MSG'
Refactor into an MVC architecture

Replace the procedural page/api/includes layout with a layered MVC application:
a single front controller under public/, a PSR-15 middleware pipeline, PSR-11
dependency injection, controllers that only translate requests, services holding
the game rules, a domain model with typed enums and value objects, and one
repository per table where every statement is a prepared statement.

Security: the web root is now public/ only (the SQLite database and the Web Push
private key were previously downloadable), CSRF is enforced for every unsafe
method instead of per endpoint, sign-out is a POST, migrations no longer
interpolate ids into SQL, errors never leak stack traces or corrupt a response
body, output escaping is applied uniformly in templates, and task timers no
longer skew by the UTC offset on servers that are not set to UTC.

The database schema, every JSON key the front-end reads, and the game's rules are
unchanged. tests/smoke.php covers the stack end to end.
MSG

echo
echo "Done. You are on branch MVC."
echo "Start the app with:  php -S 0.0.0.0:8080 -t public public/index.php"
echo "Run the tests with:  php tests/smoke.php"
echo "Go back with:        git switch $starting_branch"
