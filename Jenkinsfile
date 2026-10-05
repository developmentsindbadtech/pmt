pipeline {
    agent any
    environment {
        APP_NAME    = 'sindbadtech-BotAnalytics'
        BRANCH_NAME = "${env.GIT_BRANCH?.tokenize('/')?.last() ?: 'main'}"
    }
    triggers {
        githubPush()
    }
    stages {
        stage('Checkout') {
            steps {
                checkout scm
                echo "Branch: ${BRANCH_NAME}"
            }
        }
        stage('Deploy to Staging') {
            when { branch 'main' }
            steps {
                sshagent(credentials: ['7b54feb5-8d16-4f91-8408-69b772e863dd']) {
                    // Do not call /home/bong/pmt.sh. Its first step takes the directory away from
                    // the deploy user, then git pull fails and the script still exits 0.
                    sh '''
                        set -eu
                        ssh -o StrictHostKeyChecking=no jenkins-deploy-key@34.1.61.181 "bash -s -- $GIT_COMMIT" <<'REMOTE'
set -eu
EXPECTED="$1"
APP=/var/www/pmt-prod
sudo -n chown -R "$(id -un):www-data" "$APP"
cd "$APP"
git fetch origin main
git reset --hard origin/main
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
npm ci
npm run build
php artisan migrate --force --no-interaction
php artisan optimize:clear || true
sudo -n chown -R www-data:www-data storage bootstrap/cache
sudo -n chmod -R ug+rwX storage bootstrap/cache
HEAD=$(git rev-parse HEAD)
echo "Server HEAD: $HEAD"
echo "Expected: $EXPECTED"
test "$HEAD" = "$EXPECTED"
REMOTE
                    '''
                }
            }
        }
        stage('Run DB migrations') {
            when { branch 'main' }
            steps {
                sshagent(credentials: ['7b54feb5-8d16-4f91-8408-69b772e863dd']) {
                    // App lives at /var/www/pmt-prod (see pmt.sh deploy). Override with PMT_APP_DIR if needed.
                    // Note: pmt.sh already runs migrate; this stage is a safety net after deploy.
                    sh '''
                        ssh -o StrictHostKeyChecking=no jenkins-deploy-key@34.1.61.181 \
                            'cd /var/www/pmt-prod && sudo -n -u www-data php artisan migrate --force --no-interaction'
                    '''
                }
            }
        }
        stage('Refresh Laravel caches') {
            when { branch 'main' }
            steps {
                sshagent(credentials: ['7b54feb5-8d16-4f91-8408-69b772e863dd']) {
                    // Clear caches only. Do not rebuild as jenkins-deploy-key — that can leave
                    // bootstrap/cache + storage/logs owned by deploy user while PHP-FPM (www-data)
                    // cannot write (Permission denied on laravel.log / stale routes).
                    // Prefer on server (once, as root): chown -R www-data:www-data storage bootstrap/cache
                    sh '''
                        ssh -o StrictHostKeyChecking=no jenkins-deploy-key@34.1.61.181 \
                            'cd /var/www/pmt-prod && sudo -n -u www-data php artisan optimize:clear'
                    '''
                }
            }
        }
    }
    post {
        success {
            echo "✅ ${APP_NAME} deployed — branch: ${BRANCH_NAME}"
        }
        failure {
            echo "❌ ${APP_NAME} FAILED — branch: ${BRANCH_NAME}"
        }
        always {
            cleanWs()
        }
    }
}
