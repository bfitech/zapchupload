<?php


require(__DIR__ . '/../../vendor/autoload.php');

use BFITech\ZapCore as zc;
use BFITech\ZapTemplate as zt;
use BFITech\ZapChupload as zu;

define('TOPDIR', '/mnt/ramdisk/zapchupload-test');
if (!is_dir(TOPDIR))
	mkdir(TOPDIR, 0755);

define('CHUNK_SIZE', 1024 * 10);
define('MAX_FILESIZE', 1024 * 500);

$core = new zc\Router();

class ChunkPostProcessed extends zu\ChunkUpload {
	protected function check_fingerprint($fingerprint, $chunk) {
		return $fingerprint == hash('sha256', $chunk);
	}
}

$core->route('/', function($args) use($core) {
	$get = $args['get'];
	if (empty($get))
		return (new zt\Template())->load(__DIR__ .'/home.php');
	if (!isset($get['file']))
		$core->abort(404);
	$core->static_file(TOPDIR . '/data/' . $get['file']);
});

$core->route('/upload', function($args) use($core) {
	$cu = new zu\ChunkUpload(
		$core, TOPDIR . '/temp', TOPDIR . '/data',
		null, CHUNK_SIZE, MAX_FILESIZE);
	$cu->upload($args);
}, 'POST');

$core->route('/upload_pp', function($args) use($core) {
	$cu = new ChunkPostProcessed(
		$core, TOPDIR . '/temp', TOPDIR . '/data',
		null, CHUNK_SIZE, MAX_FILESIZE, true);
	$cu->upload($args);
}, 'POST');

