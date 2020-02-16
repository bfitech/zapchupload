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
                sh 'docker-phpunit 7.0 7.4'
            }
            post {
                success {
                    sh 'jenkins-postproc'
                }
            }
        }
    }
}
