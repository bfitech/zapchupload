<?php


require(__DIR__ . '/../../vendor/autoload.php');

use BFITech\ZapCore as zc;
use BFITech\ZapTemplate as zt;
use BFITech\ZapChupload as zu;

define('TOPDIR', __DIR__ . '/uploads');
if (!is_dir(TOPDIR))
	mkdir(TOPDIR, 0755);

define('CHUNK_SIZE', 1024 * 10);
define('MAX_FILESIZE', 1024 * 500);

$logger = new zc\Logger(
	zc\Logger::DEBUG, TOPDIR . '/zapchupload.log');
$core = new zc\Router(null, null, true, $logger);

class ChunkPostProcessed extends zu\ChunkUpload {
	protected function check_fingerprint($fingerprint, $chunk) {
		return $fingerprint == hash('sha256', $chunk);
	}
}

$core->route('/', function($args) use($logger, $core) {
	$get = $args['get'];
	if (empty($get))
		return (new zt\Template())->load(__DIR__ .'/home.php');
	if (!isset($get['file']))
		$core->abort(404);
	$core->static_file(TOPDIR . '/data/' . $get['file']);
});

$core->route('/upload', function($args) use($logger, $core) {
	$cu = new zu\ChunkUpload(
		$core, TOPDIR . '/temp', TOPDIR . '/data',
		null, CHUNK_SIZE, MAX_FILESIZE, false, $logger);
	$cu->upload($args);
}, 'POST');

$core->route('/upload_pp', function($args) use($logger, $core) {
	$cu = new ChunkPostProcessed(
		$core, TOPDIR . '/temp', TOPDIR . '/data',
		null, CHUNK_SIZE, MAX_FILESIZE, true, $logger);
	$cu->upload($args);
}, 'POST');

