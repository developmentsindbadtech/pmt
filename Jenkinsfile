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
                    // The app directory is owned by www-data after the last deploy, so a plain
                    // git pull cannot create .git/index.lock. Take ownership for the pull, then
                    // give storage and bootstrap/cache back to PHP-FPM. Fail if HEAD did not move.
                    sh '''
                        set -eu
                        ssh -o StrictHostKeyChecking=no jenkins-deploy-key@34.1.61.181 "sudo -n chown -R jenkins-deploy-key:www-data /var/www/pmt-prod && bash /home/bong/pmt.sh && sudo -n chown -R www-data:www-data /var/www/pmt-prod/storage /var/www/pmt-prod/bootstrap/cache && sudo -n chmod -R ug+rwX /var/www/pmt-prod/storage /var/www/pmt-prod/bootstrap/cache"
                        REMOTE=$(ssh -o StrictHostKeyChecking=no jenkins-deploy-key@34.1.61.181 "git -C /var/www/pmt-prod rev-parse HEAD")
                        echo "Server HEAD: $REMOTE"
                        echo "Expected: $GIT_COMMIT"
                        test "$REMOTE" = "$GIT_COMMIT"
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
                            'APP_DIR="${PMT_APP_DIR:-/var/www/pmt-prod}"; cd "$APP_DIR" && php artisan migrate --force --no-interaction'
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
                            'APP_DIR="${PMT_APP_DIR:-/var/www/pmt-prod}"; cd "$APP_DIR" && php artisan optimize:clear || true'
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
