pipeline {
    agent any
    environment {
        PROJECT = 'bfin--zapchupload'
    }
    stages {
        stage('branch: master') {
            when {
                branch 'master'
            }
            steps {
                sh 'docker-phpunit -u 7.0 7.1 7.2 7.3'
            }
            post {
                success {
                    sh 'jenkins-postproc'
                }
            }
        }
    }
}
