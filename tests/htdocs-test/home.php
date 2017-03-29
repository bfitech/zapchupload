<?php

// chupload test

?><!doctype html>
<html>
<title>Uploader</title>
<base href=/>
<script src='/bower_components/angular/angular.min.js'></script>
<script src='/bower_components/angular-chupload/chupload.js'></script>
<script type=text/javascript>
(function(){
	"use strict";
	angular.module('chup', [
		'chupload',
	]).controller('top', function($scope, ChunkUploader){

		$scope.progress = -1;
		$scope.path = null;

		$scope.errno = 0;
		$scope.data = null;

		document.querySelector('#upload').onchange = (event) => {
			$scope.errno = 0;
			$scope.data = null;
			ChunkUploader.uploadFiles(
				event, '/upload', {}, 1024 * 10,
				function(ret, upl) {
					$scope.progress = upl.progress;
				},
				function(ret, upl) {
					$scope.progress = upl.progress;
					$scope.path = encodeURI(ret.data.data.path);
				},
				function(ret, upl) {
					$scope.progress = -1;
					$scope.path = null;
					$scope.http = ret.status;
					$scope.errno = ret.data.errno;
					$scope.data = JSON.stringify(ret.data.data);
				}
			);
		};
	});
})();
	</script>
<style>
#wrap{
	display:flex;
	align-items:center;
	justify-content:center;
	height:90vh;
	font-family:monospace;
}
#box{
	height:8em;
	padding:8px;
	border:1px solid rgba(0,0,0,.3);
}
</style>
<!-- neck -->
<div ng-app=chup id=wrap ng-controller=top>
	<div id=box>
		<input type=file id=upload>
		<hr>
		<p ng-show='progress==-1&&path&&!errno'>
			file: <a href='./?file={{path}}' target=_blank>{{path}}</a>
		</p>
		<p ng-show="progress>-1&&!errno">
			progress: {{progress}}
		</p>
		<p ng-show="errno!==0">
			http: {{http}}<br>
			error: {{errno}}, {{data}}
		</p>
	</div>
</div>
</html>
