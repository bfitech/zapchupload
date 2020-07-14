<?php


require __DIR__ . '/../vendor/autoload.php';


use BFITech\ZapCore\Common;
use BFITech\ZapCore\Config;
use BFITech\ZapCore\Router;
use BFITech\ZapCore\Logger;
use BFITech\ZapTemplate\Template;
use BFITech\ZapChupload\ChunkUpload;


define('TOPDIR', __DIR__ . '/uploads');


class ChunkUploadDemo extends ChunkUpload {

	public static $datadir;

	protected function intercept_response(
		int &$errno, array &$data=null, int &$http_code=200
	) {
		if ($errno !== 0)
			return true;
		if (!isset($data['done']))
			return true;
		$fname = self::$datadir . '/' . $data['path'];
		# add additional attributes
		$data['size'] = filesize($fname);
		$data['modified'] = gmdate(DATE_ATOM, filemtime($fname));
		$data['content_type'] = Common::get_mimetype($fname);
		return true;
	}
}

class Web {

	const FILE_NOT_FOUND = 0x0400;
	const DELETE_FAILED = 0x0401;

	public static $cnf;
	public static $core;
	public static $chup;

	public function __construct() {
		if (!is_dir(TOPDIR))
			mkdir(TOPDIR, 0755);

		$this->prepare_config();
		$gcf = function($key) {
			return self::$cnf->get($key);
		};

		$log = new Logger(
			constant('BFITech\ZapCore\Logger::' . $gcf('log.level')),
		   	$gcf('log.file'));
		$core = self::$core = (new Router)
			->config('logger', $log);

		$destdir = $gcf('dir.dest');
		$chup = self::$chup = new ChunkUploadDemo(
			$core, $gcf('dir.temp'), $destdir,
			'demo', $gcf('size.chunk'), $gcf('size.max'), $log);
		$chup::$datadir = $destdir;

		$this->run();
	}

	private function prepare_config() {
		$cnfile = TOPDIR . '/demo.json';
		if (!file_exists($cnfile)) {
			file_put_contents($cnfile, '{}');
			$cnf = new Config($cnfile);
			$cnf->add('dir.temp', TOPDIR . '/temp');
			$cnf->add('dir.dest', TOPDIR . '/data');
			$cnf->add('size.chunk', 1024 * 300);
			$cnf->add('size.max', 1024 * 1024 * 20);
			$cnf->add('log.level', 'DEBUG');
			$cnf->add('log.file', TOPDIR . '/demo.log');
		} else {
			$cnf = new Config($cnfile);
		}
		self::$cnf = $cnf;
	}

	public function route_home(array $args) {
		$mithril = '//cdnjs.cloudflare.com/ajax/libs/mithril/' .
			'2.0.4/mithril.min.js';
		self::$core::start_header(200, 30);
		echo <<<EOD
<!doctype html>
<html>
<head>
	<meta name=viewport
		content='width=device-width, initial-scale=1.0'>
	<title>zapchupload demo</title>
	<link href=./static/style.css rel=stylesheet>
</head>
<body>
<div id=wrap>
	<div id=box></div>
</div>
<script src=$mithril></script>
<script src=./static/script.js></script>
EOD;
		self::$core::halt();
	}

	public function route_static(array $args) {
		$fname = __DIR__ . '/static/' . $args['params']['path'];
		self::$core->static_file($fname, [
			'code' => 200,
			'cache' => 60,
			'reqheaders' => $args['header'],
		]);
	}

	public function route_upload(array $args) {
		self::$chup->upload($args);
	}

	public function route_download(array $args) {
		$fname = self::$chup::$datadir . '/' . $args['get']['fname'];
		self::$core->static_file($fname, [
			'code' => 200,
			'cache' => 3600,
			'reqheaders' => $args['header'],
		]);
	}

	public function route_remove(array $args) {
		$core = self::$core;
		$path = self::$chup::$datadir . '/' . $args['delete'];
		if (!file_exists($path))
			$core::pj([self::FILE_NOT_FOUND, null], 403);
		if (!@unlink($path))
			$core::pj([self::DELETE_FAILED, null], 403);
		$core::pj([0, null], 200);
	}

	public function route_list(array $args) {
		$list = [];
		foreach (glob(self::$chup::$datadir . '/*') as $fname) {
			if (is_dir($fname))
				continue;
			$list[] = basename($fname);
		}
		return self::$core::pj([0, $list]);
	}

	public function route_config(array $args) {
		return self::$core::pj([0, self::$chup->get_config()]);
	}

	private function run() {
		self::$core
			->route('/',
				[$this, 'route_home'])
			->route('/static',
				[$this, 'route_static'])
			->route('/config',
				[$this, 'route_config'])
			->route('/list',
				[$this, 'route_list'])
			->route('/upload',
				[$this, 'route_upload'], 'POST')
			->route('/download',
				[$this, 'route_download'])
			->route('/remove',
				[$this, 'route_remove'], 'DELETE');
	}

}


new Web;
