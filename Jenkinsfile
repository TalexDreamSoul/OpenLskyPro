pipeline {
  agent any

  parameters {
    string(name: 'IMAGE', defaultValue: 'ghcr.io/talexdreamsoul/openlskypro:master', description: 'GHCR image to deploy')
    string(name: 'DEPLOY_DIR', defaultValue: '/opt/openlskypro', description: 'Directory on the Jenkins deploy host')
    booleanParam(name: 'RUN_MIGRATIONS', defaultValue: true, description: 'Run php artisan migrate --force after container starts')
  }

  environment {
    COMPOSE_FILE = 'docker-compose.prod.yml'
  }

  stages {
    stage('Prepare deploy dir') {
      steps {
        sh '''
          set -eu
          mkdir -p "$DEPLOY_DIR"
          cp docker-compose.prod.yml "$DEPLOY_DIR/docker-compose.prod.yml"
          if [ ! -f "$DEPLOY_DIR/.env" ]; then
            echo "Missing $DEPLOY_DIR/.env. Create it on the server before deploying." >&2
            exit 1
          fi
        '''
      }
    }

    stage('Pull image') {
      steps {
        sh '''
          set -eu
          cd "$DEPLOY_DIR"
          OPENLSKYPRO_IMAGE="$IMAGE" docker compose -f "$COMPOSE_FILE" pull
        '''
      }
    }

    stage('Deploy') {
      steps {
        sh '''
          set -eu
          cd "$DEPLOY_DIR"
          OPENLSKYPRO_IMAGE="$IMAGE" docker compose -f "$COMPOSE_FILE" up -d --remove-orphans
        '''
      }
    }

    stage('Migrate') {
      when {
        expression { return params.RUN_MIGRATIONS }
      }
      steps {
        sh '''
          set -eu
          cd "$DEPLOY_DIR"
          docker compose -f "$COMPOSE_FILE" exec -T openlskypro php artisan migrate --force
          docker compose -f "$COMPOSE_FILE" exec -T openlskypro php artisan optimize
        '''
      }
    }

    stage('Health check') {
      steps {
        sh '''
          set -eu
          cd "$DEPLOY_DIR"
          HTTP_PORT=$(grep -E '^HTTP_PORT=' .env | tail -n1 | cut -d= -f2- || true)
          HTTP_PORT=${HTTP_PORT:-8080}
          for i in $(seq 1 30); do
            if curl -fsS "http://127.0.0.1:${HTTP_PORT}/healthz" >/dev/null; then
              docker compose -f "$COMPOSE_FILE" ps
              exit 0
            fi
            sleep 2
          done
          docker compose -f "$COMPOSE_FILE" logs --tail=200 openlskypro
          exit 1
        '''
      }
    }
  }

  post {
    always {
      sh '''
        docker image prune -f >/dev/null 2>&1 || true
      '''
    }
  }
}
