<?php


require __DIR__ . '/../../vendor/autoload.php';


use BFITech\ZapCore\Router;
use BFITech\ZapCore\Logger;
use BFITech\ZapTemplate\Template;
use BFITech\ZapChupload\ChunkUpload;


define('TOPDIR', __DIR__ . '/uploads');
define('CHUNK_SIZE', 1024 * 10);
define('MAX_FILESIZE', 1024 * 500);


class ChunkRegular extends ChunkUpload {

	public function route_home(array $args) {
		$core = self::$core;

		$get = $args['get'];
		if (empty($get)) {
			$core::start_header(200, 3600);
			return (new Template())->load(__DIR__ . '/home.php', [
				'chunk_size' => $this->get_chunk_size(),
				'max_filesize' => $this->get_max_filesize(),
			]);
		}

		$file = isset($get['file']) ?? $get['file'];
		if ($file)
			$core->static_file(TOPDIR . '/data/' . $file);

	}

	// just put a prefix 'route_*' to comply with zapcore convention
	public function route_upload(array $args) {
		$this->upload($args);
	}

}

class Web {

	public function __construct() {
		if (!is_dir(TOPDIR))
			mkdir(TOPDIR, 0755);

		$logger = new Logger(
			Logger::DEBUG, TOPDIR . '/zapchupload.log');
		$core = (new Router)->config('logger', $logger);

		$chup = new ChunkRegular(
			$core, TOPDIR . '/temp', TOPDIR . '/data',
			null, CHUNK_SIZE, MAX_FILESIZE, $logger
		);

		$core->route('/', [$chup, 'route_home']);
		$core->route('/upload', [$chup, 'route_upload'], 'POST');
	}

}

new Web;
