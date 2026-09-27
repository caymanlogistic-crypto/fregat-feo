#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

readonly EXPECTED_BASE="${1:?expected base SHA is required}"
readonly CANDIDATE_SHA="${2:?candidate SHA is required}"
readonly TARGET_ROOT="${3:?target root is required}"
readonly STATE_DIR="${4:?state directory is required}"
readonly STAGE_DIR="$STATE_DIR/staging/$CANDIDATE_SHA"
readonly FILES_DIR="$STAGE_DIR/files"
readonly MANIFEST="$STAGE_DIR/manifest.txt"
readonly BACKUP_DIR="$STATE_DIR/backups/$(date -u +%Y%m%dT%H%M%SZ)-$CANDIDATE_SHA"
readonly CURRENT_FILE="$STATE_DIR/current-sha"
readonly MAPMINI_ALIAS_ROOT="${TARGET_ROOT%/feo}"
readonly MAPMINI_ALIAS_TARGET="$MAPMINI_ALIAS_ROOT/mapmini.php"

for value in "$EXPECTED_BASE" "$CANDIDATE_SHA"; do
    [[ "$value" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid SHA argument.' >&2; exit 1; }
done
[[ "$TARGET_ROOT" == '/home/s/spugovxsim/public_html/fregat/feo' ]] || {
    echo 'Unexpected production target.' >&2
    exit 1
}
[[ -d "$TARGET_ROOT" && -w "$TARGET_ROOT" ]] || {
    echo 'Production target is unavailable or not writable.' >&2
    exit 1
}
[[ -f "$MANIFEST" ]] || { echo 'Deployment manifest is missing.' >&2; exit 1; }

mkdir -p "$STATE_DIR" "$BACKUP_DIR"
exec 9>"$STATE_DIR/deploy.lock"
flock -n 9 || { echo 'Another FREGAT deployment is already running.' >&2; exit 1; }

current="$(cat "$CURRENT_FILE" 2>/dev/null || true)"
[[ "$current" == "$EXPECTED_BASE" ]] || {
    echo "Remote state changed: expected $EXPECTED_BASE, found ${current:-none}." >&2
    exit 1
}

existing_manifest="$BACKUP_DIR/existing.txt"
created_manifest="$BACKUP_DIR/created.txt"
mapmini_alias_backup="$BACKUP_DIR/mapmini-parent.php"
mapmini_alias_required=0
: > "$existing_manifest"
: > "$created_manifest"

while IFS= read -r relative; do
    [[ -n "$relative" ]] || continue
    [[ "$relative" != /* && "$relative" != *'..'* && "$relative" != *$'\n'* && "$relative" != *$'\r'* ]] || {
        echo "Unsafe manifest path: $relative" >&2
        exit 1
    }
    [[ -f "$FILES_DIR/$relative" ]] || { echo "Staged file is missing: $relative" >&2; exit 1; }
    case "$relative" in
        map2.php|*/config.php|config.php|*.example.php|*.sql|*.log|*.gz|*.bak|*.backup|*.tmp|*.cache)
            echo "Protected path cannot be deployed: $relative" >&2
            exit 1
            ;;
    esac
    if [[ "$relative" == 'mapmini.php' ]]; then
        mapmini_alias_required=1
        [[ "$MAPMINI_ALIAS_TARGET" == '/home/s/spugovxsim/public_html/fregat/mapmini.php' ]] || {
            echo 'Unexpected mapmini alias target.' >&2
            exit 1
        }
        [[ -d "$MAPMINI_ALIAS_ROOT" && -w "$MAPMINI_ALIAS_ROOT" && -f "$MAPMINI_ALIAS_TARGET" && -w "$MAPMINI_ALIAS_TARGET" ]] || {
            echo 'Mapmini alias target is unavailable or not writable.' >&2
            exit 1
        }
    fi
    if [[ "$relative" == *.php ]]; then
        /usr/bin/php8.4 -l "$FILES_DIR/$relative"
    fi
    if [[ -e "$TARGET_ROOT/$relative" ]]; then
        printf '%s\n' "$relative" >> "$existing_manifest"
    else
        printf '%s\n' "$relative" >> "$created_manifest"
    fi
done < "$MANIFEST"

if [[ -s "$existing_manifest" ]]; then
    tar -C "$TARGET_ROOT" -cf "$BACKUP_DIR/existing.tar" -T "$existing_manifest"
fi
cp "$MANIFEST" "$BACKUP_DIR/deployed.txt"
printf '%s\n' "$EXPECTED_BASE" > "$BACKUP_DIR/previous-sha"
if [[ "$mapmini_alias_required" == 1 && -e "$MAPMINI_ALIAS_TARGET" ]]; then
    cp -p -- "$MAPMINI_ALIAS_TARGET" "$mapmini_alias_backup"
fi

rollback_required=1
rollback() {
    local rc=$?
    if [[ "$rollback_required" == 1 ]]; then
        if [[ -f "$BACKUP_DIR/existing.tar" ]]; then
            tar -C "$TARGET_ROOT" -xf "$BACKUP_DIR/existing.tar" || true
        fi
        while IFS= read -r relative; do
            [[ -n "$relative" ]] && rm -f -- "$TARGET_ROOT/$relative"
        done < "$created_manifest"
        if [[ "$mapmini_alias_required" == 1 && -f "$mapmini_alias_backup" ]]; then
            cp -p -- "$mapmini_alias_backup" "$MAPMINI_ALIAS_TARGET" || true
        fi
        echo "Deployment rolled back after an error (exit $rc)." >&2
    fi
    exit "$rc"
}
trap rollback ERR INT TERM

while IFS= read -r relative; do
    [[ -n "$relative" ]] || continue
    install -d "$(dirname "$TARGET_ROOT/$relative")"
    cp -p -- "$FILES_DIR/$relative" "$TARGET_ROOT/$relative"
done < "$MANIFEST"

if [[ "$mapmini_alias_required" == 1 ]]; then
    cp -p -- "$FILES_DIR/mapmini.php" "$MAPMINI_ALIAS_TARGET"
fi

while IFS= read -r relative; do
    [[ -n "$relative" ]] || continue
    expected_hash="$(sha256sum "$FILES_DIR/$relative" | awk '{print $1}')"
    actual_hash="$(sha256sum "$TARGET_ROOT/$relative" | awk '{print $1}')"
    [[ "$actual_hash" == "$expected_hash" ]] || {
        echo "Checksum mismatch after copy: $relative" >&2
        false
    }
    if [[ "$relative" == *.php ]]; then
        /usr/bin/php8.4 -l "$TARGET_ROOT/$relative"
    fi
done < "$MANIFEST"

if [[ "$mapmini_alias_required" == 1 ]]; then
    expected_hash="$(sha256sum "$FILES_DIR/mapmini.php" | awk '{print $1}')"
    alias_hash="$(sha256sum "$MAPMINI_ALIAS_TARGET" | awk '{print $1}')"
    [[ "$alias_hash" == "$expected_hash" ]] || {
        echo 'Checksum mismatch after mapmini alias copy.' >&2
        false
    }
    /usr/bin/php8.4 -l "$MAPMINI_ALIAS_TARGET"
fi

printf '%s\n' "$CANDIDATE_SHA" > "$CURRENT_FILE.tmp"
mv -f "$CURRENT_FILE.tmp" "$CURRENT_FILE"
rollback_required=0
trap - ERR INT TERM
rm -rf -- "$STAGE_DIR"

echo "DEPLOYED_SHA=$CANDIDATE_SHA"
echo 'DEPLOYED_FILES:'
sed 's/^/- /' "$BACKUP_DIR/deployed.txt"
if [[ "$mapmini_alias_required" == 1 ]]; then
    echo '- mapmini.php -> /home/s/spugovxsim/public_html/fregat/mapmini.php'
fi
