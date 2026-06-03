#!/usr/bin/env bash
set -euo pipefail

HOST=${HOST:-wlcb1}
DEPLOY_DIR=${DEPLOY_DIR:-/opt/openlskypro}
IMAGE=${IMAGE:-ghcr.io/talexdreamsoul/openlskypro:master}

ssh "$HOST" "mkdir -p '$DEPLOY_DIR'"
scp docker-compose.prod.yml "$HOST:$DEPLOY_DIR/docker-compose.prod.yml"
scp deploy/openlskypro.env.example "$HOST:$DEPLOY_DIR/.env.example"

ssh "$HOST" "cat > '$DEPLOY_DIR/README.deploy.txt' <<'EOF'
OpenLskyPro deploy directory.

1. Copy .env.example to .env and fill real production values:
   cp .env.example .env
   php artisan key:generate --show can be run locally/in a temp container to create APP_KEY.

2. Login to GHCR if your package is private:
   echo '<token>' | docker login ghcr.io -u '<github-user>' --password-stdin

3. Deploy:
   OPENLSKYPRO_IMAGE=$IMAGE docker compose -f docker-compose.prod.yml pull
   OPENLSKYPRO_IMAGE=$IMAGE docker compose -f docker-compose.prod.yml up -d
   docker compose -f docker-compose.prod.yml exec -T openlskypro php artisan migrate --force
EOF"

echo "Prepared $HOST:$DEPLOY_DIR"
echo "Next: ssh $HOST, edit $DEPLOY_DIR/.env from .env.example, then run Jenkins deploy."
