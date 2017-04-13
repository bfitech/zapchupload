<?php


require_once(__DIR__ . '/ChunkFixture.php');


use BFITech\ZapCore as zc;
use BFITech\ZapChupload\ChunkUpload;
use BFITech\ZapChupload\ChunkUploadError;


class RouterPatched extends zc\Router {

	public static $code = 200;
	public static $head = [];
	public static $body = null;

	public static $errno = 0;
	public static $data = [];

	public static function header($header_string, $replace=false) {
		if (strpos($header_string, 'HTTP/1') !== false) {
			self::$code = explode(' ', $header_string)[1];
		} else {
			self::$head[] = $header_string;
		}
	}

	public static function halt($arg=null) {
		if (!$arg)
			return;
		echo $arg;
	}

	public function wrap_callback($callback, $args=[]) {
		ob_start();
		$callback($args);
		self::$body = json_decode(ob_get_clean(), true);
		self::$errno = self::$body['errno'];
		self::$data = self::$body['data'];
	}
}

class ChunkUploadPatched extends ChunkUpload {

	public static $fingerprint_salt = 'x';

	public function make_fingerprint($chunk) {
		hash_hmac('sha512', $chunk, self::$fingerprint_salt);
	}

	public function check_fingerprint($fingerprint, $chunk_recv) {
		return $fingerprint === $this->make_fingerprint($chunk_recv);
	}
}

class ChunkUploadTest extends ChunkUploadFixture {

	public static function setUpBeforeClass() {
		$_SERVER['REQUEST_URI'] = '/';
		foreach (self::file_list() as $file) {
			if (!file_exists($file[0]))
				self::generate_file($file[0], $file[1]);
		}
		self::$logfile = self::$tdir . '/zapchupload.log';
		if (file_exists(self::$logfile))
			@unlink(self::$logfile);
	}

	public function test_constructor() {

		$logger = new zc\Logger(zc\Logger::ERROR, self::$logfile);
		$core = new RouterPatched(null, null, false, $logger);
		$tdir = self::$tdir;
		$Err = new ChunkUploadError;

		$csz_too_small = pow(2, 8);
		$csz_too_yuuge = pow(2, 24);

		$chup = new ChunkUploadPatched(
			$core, $tdir . '/xtemp', $tdir . '/xdest',
			'_some_pfx', $csz_too_small, MAX_FILESIZE,
			true, $logger
		);
		$this->assertEquals(
			$chup->get_chunk_size(), 1024 * 100);

		$chup = new ChunkUploadPatched(
			$core, $tdir . '/xtemp', $tdir . '/xdest',
			'_some_pfx', $csz_too_yuuge, MAX_FILESIZE,
			true, $logger
		);
		$this->assertEquals(
			$chup->get_chunk_size(), 1024 * 100);

		$chup = new ChunkUploadPatched(
			$core, $tdir . '/xtemp', $tdir . '/xdest',
			'_some_pfx', CHUNK_SIZE, MAX_FILESIZE,
			true, $logger
		);
		$this->assertEquals(
			$chup->get_chunk_size(), CHUNK_SIZE);
		$this->assertEquals(
			$chup->get_post_prefix(), '_some_pfx');
		$this->assertEquals(
			$chup->get_max_filesize(), MAX_FILESIZE);

		try {
			$chup = new ChunkUploadPatched(
				$core, '', '',
				'_some_pfx', CHUNK_SIZE, MAX_FILESIZE,
				true, $logger
			);
		} catch(ChunkUploadError $e) {}

		try {
			$chup = new ChunkUploadPatched(
				$core, 'x', 'x',
				'_some_pfx', CHUNK_SIZE, MAX_FILESIZE,
				true, $logger
			);
		} catch(ChunkUploadError $e) {}

		try {
			$chup = new ChunkUploadPatched(
				$core, '/var/chupload/xtemp', '/var/chupload/xdest',
				'_some_pfx', CHUNK_SIZE, MAX_FILESIZE,
				true, $logger
			);
		} catch(ChunkUploadError $e) {}
	}

	public function test_json() {
	}

	private function make_uploader($core=null) {
		$logger = new zc\Logger(zc\Logger::DEBUG, self::$logfile);
		if (!$core)
			$core = new RouterPatched(null, null, false, $logger);
		return new ChunkUploadPatched(
			$core, self::$tdir . '/xtemp', self::$tdir . '/xdest',
			'__test', CHUNK_SIZE, MAX_FILESIZE,
			true, $logger
		);
	}

	private function reset_env($core=null) {
		if ($core) {
			$core::$code = 200;
			$core::$head = [];
			$core::$body = null;
			$core::$errno = 0;
			$core::$data = [];
		}
		$_SERVER['REQUEST_URI'] = '/';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = $_FILES = [];
	}

	public function test_upload_one_chunk() {
		$Err = new ChunkUploadError;
		$this->reset_env();

		$chup = $this->make_uploader();
		$core = $chup::$core;

		$core->route('/', [$chup, 'upload'], 'POST');
		$this->assertEquals($core::$errno, $Err::EREQ);
	}

}

