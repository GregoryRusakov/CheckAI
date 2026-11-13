#!/bin/sh
set -eu

PATH=/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin
export PATH
LC_ALL=C
export LC_ALL
GIT_TERMINAL_PROMPT=0
export GIT_TERMINAL_PROMPT

# Keep this script outside the Git checkout at /home/main/scripts/repos/checkai-auto-deploy.sh.
REPOSITORY=/var/repos/checkai.analitica-soft.ru
TARGET=/var/www/checkai.analitica-soft.ru
REMOTE_URL=git@github.com:GregoryRusakov/checkai.git
BRANCH=main
PHP_BIN=${CHECKAI_PHP_BIN:-php8.3}
SSH_KEY=/root/.ssh/checkai_github_deploy_key
STATE=/var/lib/checkai-analitica-soft-deploy
WEBHOOK_STATE=/var/lib/checkai-webhook
WEBHOOK_SECRET=/home/main/scripts/repos/secrets/checkai-webhook.secret
REPORT_FROM=site@analitica-soft.ru
REPORT_TO=site@analitica-soft.ru

fail() { echo "ERROR: $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || fail 'Run this script as root.'

for tool in git ssh ssh-keygen flock rsync runuser install openssl find xargs cat chmod chown date sed sendmail "$PHP_BIN"; do
    command -v "$tool" >/dev/null 2>&1 || fail "Missing command: $tool"
done

"$PHP_BIN" -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' ||
    fail "$PHP_BIN must have the PDO SQLite extension enabled."

install -d -o root -g root -m 0700 /root/.ssh
if [ ! -s "$SSH_KEY" ]; then
    if [ -e "$SSH_KEY" ] || [ -e "$SSH_KEY.pub" ]; then
        fail "Incomplete SSH key pair exists at $SSH_KEY; refusing to overwrite it."
    fi
    ssh-keygen -q -t ed25519 -N '' -C 'checkai-deploy@itirus.ru' -f "$SSH_KEY"
    chmod 0600 "$SSH_KEY"
    chmod 0644 "$SSH_KEY.pub"
    echo 'A read-only GitHub deploy key was created. Add this public key to the checkai repository:'
    cat "$SSH_KEY.pub"
    fail 'Register the public key in GitHub repository Settings > Deploy keys, then run this script again.'
fi

if [ ! -s "$SSH_KEY.pub" ]; then
    ssh-keygen -y -f "$SSH_KEY" > "$SSH_KEY.pub"
    chmod 0644 "$SSH_KEY.pub"
fi
chmod 0600 "$SSH_KEY"

export GIT_SSH_COMMAND="ssh -i $SSH_KEY -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes"

install -d -o www-data -g www-data -m 0750 "$WEBHOOK_STATE"
if [ ! -e "$WEBHOOK_SECRET" ]; then
    umask 077
    openssl rand -hex 32 > "$WEBHOOK_SECRET" || fail 'Cannot create the GitHub webhook secret.'
    chown www-data:www-data "$WEBHOOK_SECRET"
    chmod 0600 "$WEBHOOK_SECRET"
    echo 'A GitHub webhook secret was created. Save it in the GitHub webhook settings:'
    cat "$WEBHOOK_SECRET"
elif [ ! -s "$WEBHOOK_SECRET" ]; then
    fail "Webhook secret exists but is empty: $WEBHOOK_SECRET."
fi
runuser -u www-data -- test -r "$WEBHOOK_SECRET" ||
    fail "The web server cannot read the webhook secret: $WEBHOOK_SECRET."

install -d -o root -g root -m 0755 "$STATE"
exec 9>"$STATE/lock"
flock 9

send_pending_report() {
    [ -f "$STATE/repository-report.pending" ] || return 0
    if sendmail -f "$REPORT_FROM" -t -oi < "$STATE/repository-report.pending"; then
        mv "$STATE/repository-report.pending" "$STATE/repository-report.sent"
    else
        fail 'Mail submission failed. Report saved for the next run.'
    fi
}

# Retry an unsent report even if there is no new commit.
send_pending_report

if [ -L "$REPOSITORY" ] || [ -L "$TARGET" ]; then
    fail 'Repository and target paths must not be symbolic links.'
fi

if [ -e "$REPOSITORY/.git" ]; then
    [ -d "$REPOSITORY/.git" ] || fail "Repository metadata is not a directory: $REPOSITORY/.git."
    cd "$REPOSITORY"
    CURRENT_BRANCH=$(git branch --show-current) || fail 'Cannot read repository branch.'
    [ "$CURRENT_BRANCH" = "$BRANCH" ] || fail "Repository must be on $BRANCH."
    ORIGIN=$(git remote get-url origin) || fail 'Cannot read origin URL.'
    case "$ORIGIN" in
        https://github.com/GregoryRusakov/checkai.git|git@github.com:GregoryRusakov/checkai.git|ssh://git@github.com/GregoryRusakov/checkai.git) ;;
        *) fail "Unexpected origin URL: $ORIGIN" ;;
    esac
    CHANGES=$(git status --porcelain) || fail 'Cannot read repository status.'
    [ -z "$CHANGES" ] || fail 'Repository contains local changes.'
    git fetch --quiet "$REMOTE_URL" "refs/heads/$BRANCH:refs/remotes/origin/$BRANCH"
    git merge-base --is-ancestor HEAD "refs/remotes/origin/$BRANCH" ||
        fail "Local $BRANCH has diverged from origin/$BRANCH."
    git merge --ff-only --quiet "refs/remotes/origin/$BRANCH"
else
    if [ -e "$REPOSITORY" ]; then
        [ -d "$REPOSITORY" ] || fail "Repository path is not a directory: $REPOSITORY."
        CONTENTS=$(find "$REPOSITORY" -mindepth 1 -maxdepth 1 -print -quit)
        [ -z "$CONTENTS" ] || fail "Cannot clone into nonempty directory: $REPOSITORY."
    fi
    install -d -o root -g root -m 0755 "$REPOSITORY"
    git clone --branch "$BRANCH" --single-branch "$REMOTE_URL" "$REPOSITORY"
fi

COMMIT=$(git -C "$REPOSITORY" rev-parse HEAD)
SOURCE=$REPOSITORY
if [ ! -f "$SOURCE/bin/init-db.php" ] && [ -f "$SOURCE/current/bin/init-db.php" ]; then
    SOURCE=$SOURCE/current
fi
[ -f "$SOURCE/bin/init-db.php" ] || fail 'Missing bin/init-db.php in the checkout.'
[ -f "$SOURCE/database/schema.sql" ] || fail 'Missing database/schema.sql in the checkout.'
[ -f "$SOURCE/public/index.php" ] || fail 'Missing public/index.php in the checkout.'

install -d -o root -g www-data -m 0755 "$TARGET"
if [ -L "$TARGET/var" ]; then
    fail "Application data path must not be a symbolic link: $TARGET/var."
fi
install -d -o www-data -g www-data -m 0770 "$TARGET/var"

# Copy tracked files only. Existing app data and unrelated server files are preserved.
FILE_LIST=$STATE/repository-files.tmp
git -C "$SOURCE" ls-files -z > "$FILE_LIST"
xargs -0 -r sh -c '
    target=$1
    shift
    for file do
        if [ -d "$target/$file" ]; then
            printf "ERROR: Cannot replace a directory with a file: %s\\n" "$target/$file" >&2
            exit 255
        fi
    done
' sh "$TARGET" < "$FILE_LIST"

STATS=$(rsync -lc --omit-dir-times --8-bit-output --out-format='%i %b %n' \
    --from0 --files-from="$FILE_LIST" "$SOURCE/" "$TARGET/")
FILES=$(printf '%s\n' "$STATS" | awk -v target="$TARGET/" '
    /^>f|^cL/ {
        path = substr($0, 13)
        sub(/^[0-9]+ /, "", path)
        print target path
    }
')
COPIED=$(printf '%s\n' "$FILES" | awk 'NF { count++ } END { print count+0 }')

WEBHOOK_ROOT=/usr/local/lib/checkai-webhook
[ -f "$WEBHOOK_ROOT/github-webhook.php" ] ||
    fail "Missing webhook endpoint: $WEBHOOK_ROOT/github-webhook.php."

runuser -u www-data -- "$PHP_BIN" "$TARGET/bin/init-db.php" ||
    fail 'SQLite initialization or migration failed.'

REPORTED=''
if [ -f "$STATE/repository-report.sent" ]; then
    REPORTED=$(sed -n 's/^X-CheckAI-Commit: //p' "$STATE/repository-report.sent")
fi
[ "$REPORTED" != "$COMMIT" ] || [ "$COPIED" -gt 0 ] || exit 0

{
    printf 'From: %s\n' "$REPORT_FROM"
    printf 'To: %s\n' "$REPORT_TO"
    printf 'Subject: checkai.analitica-soft.ru deployment: %s files copied\n' "$COPIED"
    printf 'X-CheckAI-Commit: %s\n' "$COMMIT"
    printf 'MIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\n\n'
    printf 'Обновлён сайт https://checkai.analitica-soft.ru\n'
    printf 'Repository: %s\nBranch: %s\nDate: %s\nCommit: %s\n' \
        "$REPOSITORY" "$BRANCH" "$(date -Is)" "$COMMIT"
    printf 'Destination: %s\nFiles copied: %s\n' "$TARGET" "$COPIED"
    if [ "$COPIED" -gt 0 ]; then
        printf '\nCopied files:\n%s\n' "$FILES"
    fi
} > "$STATE/repository-report.tmp"
mv "$STATE/repository-report.tmp" "$STATE/repository-report.pending"
printf '%s Update completed. Commit: %s. Files copied: %s\n' \
    "$(date -Is)" "$COMMIT" "$COPIED"
send_pending_report
