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
            when { branch 'develop' }
            steps {
                sshagent(credentials: ['7b54feb5-8d16-4f91-8408-69b772e863dd']) {
                    sh '''
                        ssh -o StrictHostKeyChecking=no jenkins-deploy-key@34.1.61.181 \
                            "bash /home/bong/pmt.sh"
                    '''
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
