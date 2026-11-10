#!/bin/sh
# Deploys a commit of main to the Habi VM: checkout, rebuild, restart, health
# check. Run as the `deploy` user. It is also that user's SSH forced command,
# so GitHub Actions can only request a commit SHA, nothing else:
#   ssh deploy@<host> <40-hex sha>   → that commit, which must be on main
#   ssh deploy@<host>                → current origin/main
# HABI_BRANCH (local runs only; sshd does not pass it) deploys another branch.
#
# Everything lives in main() so the shell has parsed the whole script before
# `git checkout` replaces this file on disk.
set -eu

main() {
    app_dir=/srv/habi/app
    branch=${HABI_BRANCH:-main}
    requested=${SSH_ORIGINAL_COMMAND:-${1:-}}

    cd "$app_dir"
    git fetch --quiet origin "$branch"

    case "$requested" in
        '')
            target=$(git rev-parse "origin/$branch")
            ;;
        *[!0-9a-f]*)
            echo "update.sh: expected a 40-character commit SHA" >&2
            exit 2
            ;;
        *)
            if [ "${#requested}" -ne 40 ]; then
                echo "update.sh: expected a 40-character commit SHA" >&2
                exit 2
            fi
            if ! git merge-base --is-ancestor "$requested" "origin/$branch" 2>/dev/null; then
                echo "update.sh: $requested is not on $branch" >&2
                exit 2
            fi
            target=$requested
            ;;
    esac

    git checkout --quiet --force --detach "$target"
    echo "update.sh: deploying $(git log -1 --format='%h %s')"

    compose="docker compose -f deploy/gce/compose.yaml"
    $compose up -d --build --remove-orphans

    # The app answers only inside the stack; any status >= 400 fails here.
    attempt=0
    until $compose exec -T app php -r 'exit(@file_get_contents("http://127.0.0.1:8080/") === false ? 1 : 0);'; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge 30 ]; then
            echo "update.sh: GET / did not return 2xx/3xx; recent logs:" >&2
            $compose logs --tail 40 app >&2
            exit 1
        fi
        sleep 2
    done

    docker image prune -f >/dev/null
    echo "update.sh: healthy at $(git rev-parse --short HEAD)"
}

main "$@"
