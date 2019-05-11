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

		var s = $scope;

		s.progress = -1;
		s.path = null;

		s.errno = 0;
		s.data = null;

		var uploader = document.querySelector('#upload');

		uploader.onchange = (event) => {
			s.errno = 0;
			s.data = null;
			ChunkUploader.uploadFiles(
				event, '/upload', {}, <?php echo $chunk_size ?>,
				function(ret, upl) {
					s.progress = upl.progress;
				},
				function(ret, upl) {
					s.progress = upl.progress;
					s.path = encodeURI(ret.data.data.path);
					uploader.value = '';
				},
				function(ret, upl) {
					s.progress = -1;
					s.path = null;
					s.http = ret.status;
					s.errno = ret.data.errno;
					s.data = JSON.stringify(ret.data.data);
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
	overflow:auto;
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
		<p>max filesize: <?php echo $max_filesize ?></p>
		<p>chunk size: <?php echo $chunk_size ?></p>
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
