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
                    sh '''
                        ssh -o StrictHostKeyChecking=no jenkins-deploy-key@34.1.61.181 \
                            "bash /home/bong/pmt.sh"
                    '''
                }
            }
        }
        stage('Run DB migrations') {
            when { branch 'main' }
            steps {
                sshagent(credentials: ['7b54feb5-8d16-4f91-8408-69b772e863dd']) {
                    // Applies pending Laravel migrations (e.g. archived_at). No manual DB work needed.
                    // If the app path differs on the server, update APP_DIR below (or add migrate to pmt.sh).
                    sh '''
                        ssh -o StrictHostKeyChecking=no jenkins-deploy-key@34.1.61.181 \
                            'APP_DIR="${PMT_APP_DIR:-/var/www/pmt}"; cd "$APP_DIR" && php artisan migrate --force --no-interaction'
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
