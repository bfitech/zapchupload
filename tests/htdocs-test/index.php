<?php


require(__DIR__ . '/../../vendor/autoload.php');

use BFITech\ZapCore\Router;
use BFITech\ZapCore\Logger;
use BFITech\ZapTemplate\Template;
use BFITech\ZapChupload\ChunkUpload;

define('TOPDIR', __DIR__ . '/uploads');
if (!is_dir(TOPDIR))
	mkdir(TOPDIR, 0755);

define('CHUNK_SIZE', 1024 * 10);
define('MAX_FILESIZE', 1024 * 500);

$logger = new Logger(Logger::DEBUG, TOPDIR . '/zapchupload.log');
$core = (new Router)->config('logger', $logger);

class ChunkPostProcessed extends ChunkUpload {
	// todo: client-side hash
}

$core->route('/', function($args) use($logger, $core) {
	$get = $args['get'];
	if (empty($get))
		return (new Template())->load(__DIR__ .'/home.php');
	if (!isset($get['file']))
		$core->abort(404);
	$core->static_file(TOPDIR . '/data/' . $get['file']);
});

$core->route('/upload', function($args) use($logger, $core) {
	$cu = new ChunkUpload(
		$core, TOPDIR . '/temp', TOPDIR . '/data',
		null, CHUNK_SIZE, MAX_FILESIZE, $logger);
	$cu->upload($args);
}, 'POST');

$core->route('/upload_pp', function($args) use($logger, $core) {
	$cu = new ChunkPostProcessed(
		$core, TOPDIR . '/temp', TOPDIR . '/data',
		null, CHUNK_SIZE, MAX_FILESIZE, $logger);
	$cu->upload($args);
}, 'POST');
