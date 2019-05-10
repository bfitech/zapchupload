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

		var uploader = document.querySelector('#upload');

		uploader.onchange = (event) => {
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
					uploader.value = '';
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
	height:16em;
	padding:8px 16px;
	border:1px solid rgba(0,0,0,.2);
	width: 400px;
}
hr{
	height:0;
	border:0;
	border-bottom:3px solid rgba(0,0,0,.1);
}
</style>
<!-- neck -->
<div ng-app=chup id=wrap ng-controller=top>
	<div id=box>
		<p>max filesize: <?php echo MAX_FILESIZE ?></p>
		<p>chunk size: <?php echo CHUNK_SIZE ?></p>
		<hr>
		<input type=file id=upload>
		<hr>
		<p ng-show='progress==-1&&path&&!errno'>
			file: <a href='./?file={{path}}' target=_blank>{{path}}</a>
		</p>
		<p ng-show="progress>-1&&!errno">
			progress: {{progress}}
		</p>
		<div ng-show="errno!==0">
			<p>http: {{http}}</p>
			<p>error: {{errno}}, {{data}}</p>
		</div>
	</div>
</div>
</html>
