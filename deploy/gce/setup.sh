#!/bin/sh
# One-time (idempotent) setup of the Habi VM on a fresh Debian e2-micro.
# Run as root from a checkout of this repository, for example:
#   sudo HABI_DOMAIN=shop.example.com \
#        ADMIN_SEED_EMAIL=you@example.com \
#        ADMIN_SEED_PASSWORD_HASH='$2y$12$…' \
#        DEPLOY_PUBKEY='ssh-ed25519 AAAA… habi-deploy' \
#        sh deploy/gce/setup.sh
# Existing /srv/habi/*.env files and data are never overwritten, so re-running
# is safe and never reseeds. HABI_BRANCH (default main) picks the first deploy.
set -eu

main() {
    repo_url=${HABI_REPO:-https://github.com/fr-anz/COMP-20163-WEB-DEVELOPMENT.git}
    branch=${HABI_BRANCH:-main}

    if [ "$(id -u)" -ne 0 ]; then
        echo "setup.sh: run with sudo" >&2
        exit 1
    fi

    echo "== packages and Docker"
    if ! command -v docker >/dev/null 2>&1; then
        apt-get update -q
        apt-get install -y -q ca-certificates curl git
        curl -fsSL https://get.docker.com | sh
    fi
    command -v git >/dev/null 2>&1 || apt-get install -y -q git

    # e2-micro has 1 GB RAM; swap keeps image builds from being OOM-killed.
    echo "== swap"
    if ! swapon --show=NAME --noheadings | grep -q '^/swapfile$'; then
        [ -f /swapfile ] || { fallocate -l 1G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null; }
        swapon /swapfile
        grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
    fi

    echo "== deploy user and directories"
    id deploy >/dev/null 2>&1 || useradd --create-home --shell /bin/sh deploy
    usermod -aG docker deploy
    install -d -o deploy -g deploy -m 750 /srv/habi
    install -d -m 755 /srv/habi/data

    echo "== application checkout ($branch)"
    if [ ! -d /srv/habi/app/.git ]; then
        su deploy -c "git clone --quiet --branch '$branch' '$repo_url' /srv/habi/app"
    fi

    echo "== settings"
    write_once /srv/habi/habi.env "ADMIN_SEED_EMAIL=${ADMIN_SEED_EMAIL:?set ADMIN_SEED_EMAIL}
ADMIN_SEED_PASSWORD_HASH=${ADMIN_SEED_PASSWORD_HASH:?set ADMIN_SEED_PASSWORD_HASH}
ADMIN_SEED_NAME=${ADMIN_SEED_NAME:-Habi administrator}
APP_URL=https://${HABI_DOMAIN:?set HABI_DOMAIN}"
    write_once /srv/habi/caddy.env "HABI_DOMAIN=$HABI_DOMAIN"

    if [ -n "${DEPLOY_PUBKEY:-}" ]; then
        echo "== GitHub Actions deploy key (forced command: update.sh only)"
        install -d -o deploy -g deploy -m 700 /home/deploy/.ssh
        line="command=\"/srv/habi/app/deploy/gce/update.sh\",restrict $DEPLOY_PUBKEY"
        keys=/home/deploy/.ssh/authorized_keys
        touch "$keys"
        grep -qxF "$line" "$keys" || echo "$line" >> "$keys"
        chown deploy:deploy "$keys"
        chmod 600 "$keys"
    fi

    echo "== first deploy"
    su deploy -c "HABI_BRANCH='$branch' /srv/habi/app/deploy/gce/update.sh"

    echo
    echo "Done. GitHub repository variable GCE_KNOWN_HOSTS:"
    echo "$HABI_DOMAIN $(cut -d' ' -f1,2 /etc/ssh/ssh_host_ed25519_key.pub)"
}

# Creates a settings file readable only by the deploy user; never overwrites.
write_once() {
    if [ -e "$1" ]; then
        echo "kept existing $1"
        return
    fi
    umask 077
    printf '%s\n' "$2" > "$1"
    chown deploy:deploy "$1"
    echo "wrote $1"
}

main "$@"
