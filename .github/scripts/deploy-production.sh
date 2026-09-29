#!/usr/bin/env bash
set -Eeuo pipefail

readonly TARGET_ROOT='/home/s/spugovxsim/public_html/fregat/feo'
readonly STATE_DIR='.fregat-deploy-feo'
readonly SHA="${GITHUB_SHA:?GITHUB_SHA is required}"
readonly REMOTE="${FREGAT_DEPLOY_USER:?FREGAT_DEPLOY_USER is required}@${FREGAT_DEPLOY_HOST:?FREGAT_DEPLOY_HOST is required}"
readonly PORT="${FREGAT_DEPLOY_PORT:-22}"
readonly KEY_FILE="${FREGAT_DEPLOY_KEY_FILE:?FREGAT_DEPLOY_KEY_FILE is required}"
readonly SSH_BASE=(ssh -i "$KEY_FILE" -p "$PORT" -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes)
readonly RSYNC_SSH="ssh -i $KEY_FILE -p $PORT -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes"

is_deployable() {
    local path="$1"

    case "$path" in
        map8000_data/requests.xlsx|map8000_data/.htaccess)
            return 0
            ;;
    esac

    case "$path" in
        map2.php|*/config.php|config.php|*.example.php|*.sql|*.log|*.gz|*.bak|*.backup|*.tmp|*.cache)
            return 1
            ;;
        .github/*|.gitignore|.env*|AGENTS.md|CODEX_WORKFLOW_RULES.md|README.md|compose.yaml|Dockerfile|composer.json)
            return 1
            ;;
        docs/*|docs2/*|docs3/*|docker/*|storage/*|uploads/*|vendor/*|*/venv/*|*/__pycache__/*)
            return 1
            ;;
    esac

    case "$path" in
        *.php|*.js|*.css|*.json|*.html|*.svg|*.png|*.jpg|*.jpeg|*.gif|*.webp|*.ico)
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

if [[ ! "$SHA" =~ ^[0-9a-f]{40}$ ]]; then
    echo "Invalid candidate SHA: $SHA" >&2
    exit 1
fi

remote_state="$(${SSH_BASE[@]} "$REMOTE" "test -f '$STATE_DIR/current-sha' && cat '$STATE_DIR/current-sha' || true")"
if [[ -z "$remote_state" ]]; then
    echo 'Remote deploy state is not initialized.' >&2
    exit 1
fi
if [[ ! "$remote_state" =~ ^[0-9a-f]{40}$ ]]; then
    echo 'Remote deploy state is invalid.' >&2
    exit 1
fi
if ! git cat-file -e "${remote_state}^{commit}"; then
    echo "Remote base commit is unavailable locally: $remote_state" >&2
    exit 1
fi
if ! git merge-base --is-ancestor "$remote_state" "$SHA"; then
    echo "Refusing non-fast-forward deployment: $remote_state is not an ancestor of $SHA" >&2
    exit 1
fi

work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT
manifest="$work_dir/manifest.txt"
deleted="$work_dir/deleted.txt"
: > "$manifest"
: > "$deleted"

while IFS= read -r path; do
    [[ -n "$path" ]] || continue
    if is_deployable "$path"; then
        printf '%s\n' "$path" >> "$manifest"
    fi
done < <(git diff --name-only --diff-filter=AM "$remote_state" "$SHA")

while IFS= read -r path; do
    [[ -n "$path" ]] || continue
    if is_deployable "$path"; then
        printf '%s\n' "$path" >> "$deleted"
    fi
done < <(git diff --name-only --diff-filter=DR "$remote_state" "$SHA")

if [[ -s "$deleted" ]]; then
    echo 'Automatic deletion/rename of runtime files is forbidden:' >&2
    cat "$deleted" >&2
    exit 1
fi

sort -u -o "$manifest" "$manifest"

${SSH_BASE[@]} "$REMOTE" "mkdir -p '$STATE_DIR/control' '$STATE_DIR/staging/$SHA/files'"
rsync -a --relative --files-from="$manifest" -e "$RSYNC_SSH" ./ "$REMOTE:$STATE_DIR/staging/$SHA/files/"
scp -q -i "$KEY_FILE" -P "$PORT" -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes \
    "$manifest" "$REMOTE:$STATE_DIR/staging/$SHA/manifest.txt"
scp -q -i "$KEY_FILE" -P "$PORT" -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes \
    .github/scripts/remote-apply.sh "$REMOTE:$STATE_DIR/control/remote-apply.sh"

${SSH_BASE[@]} "$REMOTE" \
    "bash '$STATE_DIR/control/remote-apply.sh' '$remote_state' '$SHA' '$TARGET_ROOT' '$STATE_DIR'"
