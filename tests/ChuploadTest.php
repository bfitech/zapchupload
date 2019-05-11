<?php


require_once(__DIR__ . '/ChuploadFixture.php');

use BFITech\ZapCore\Logger;
use BFITech\ZapCoreDev\RouterDev;
use BFITech\ZapCoreDev\RoutingDev;
use BFITech\ZapChupload\ChunkUpload;
use BFITech\ZapChupload\ChunkUploadError as Err;


/**
 * Pre-processing and chunk processing test.
 */
class ChunkUploadChunkProc extends ChunkUpload {

	public static function get_fingerprint(string $chunk) {
		return hash_hmac('sha512', $chunk, 'sekrit');
	}

	protected function pre_processing() {
		$post = $this->get_args()['post'];
		return !isset($post['dontsend']);
	}

	protected function chunk_processing() {
		$post = $this->get_args()['post'];
		if (!isset($post['fingerprint']))
			return false;
		$hash = self::get_fingerprint($this->get_chunk_data()['chunk']);
		return hash_equals($post['fingerprint'], $hash);
	}

}

/**
 * Interceptor test.
 */
class ChunkUploadIntercept extends ChunkUploadChunkProc {

	public function intercept_response(
		int $errno, array $data=null, int $http_code=200
	) {
		self::$core->print_json($errno, $data, $http_code);
		return false;
	}
}

/**
 * Post-processing test.
 */
class ChunkUploadPostProc extends ChunkUpload {

	protected function post_processing() {
		return false;
	}

}

class ChunkUploadTest extends ChunkUploadFixture {

	public static $core;

	public static function setUpBeforeClass() {
		foreach (self::file_list() as $file) {
			if (!file_exists($file[0]))
				self::generate_file($file[0], $file[1]);
		}
		self::$logfile = self::$tdir . '/zapchupload.log';
		if (file_exists(self::$logfile))
			@unlink(self::$logfile);
	}

	public function test_constructor() {

		$logger = new Logger(Logger::ERROR, self::$logfile);
		$core = (new RouterDev)->config('logger', $logger);
		$tdir = self::$tdir;

		$csz_too_small = pow(2, 8);
		$csz_too_yuuge = pow(2, 24);

		$chup = new ChunkUpload(
			$core, $tdir . '/xtemp', $tdir . '/xdest',
			'_some_pfx', $csz_too_small, MAX_FILESIZE,
			$logger
		);
		$this->ae($chup->get_chunk_size(), 1024 * 100);
		try {
			$chup->get_args();
		} catch(Err $e) {
		}
		try {
			$chup->get_request();
		} catch(Err $e) {
		}
		try {
			$chup->get_chunk_data();
		} catch(Err $e) {
		}

		$chup = new ChunkUpload(
			$core, $tdir . '/xtemp', $tdir . '/xdest',
			'_some_pfx', $csz_too_yuuge, MAX_FILESIZE, $logger
		);
		$this->ae($chup->get_chunk_size(), 1024 * 100);

		$chup = new ChunkUpload(
			$core, $tdir . '/xtemp', $tdir . '/xdest',
			'_some_pfx', CHUNK_SIZE, MAX_FILESIZE, $logger
		);
		$this->ae($chup->get_chunk_size(), CHUNK_SIZE);
		$this->ae($chup->get_post_prefix(), '_some_pfx');
		$this->ae($chup->get_max_filesize(), MAX_FILESIZE);

		$chup = new ChunkUpload(
			$core, $tdir . '/xtemp', $tdir . '/xdest',
			'_some_pfx', CHUNK_SIZE, CHUNK_SIZE - 1, $logger
		);

		try {
			$chup = new ChunkUpload(
				$core, '', '',
				'_some_pfx', CHUNK_SIZE, MAX_FILESIZE, $logger
			);
		} catch(Err $e) {
		}

		try {
			$chup = new ChunkUpload(
				$core, 'x', 'x',
				'_some_pfx', CHUNK_SIZE, MAX_FILESIZE, $logger
			);
		} catch(Err $e) {
		}

		try {
			$chup = new ChunkUpload(
				$core, '/var/chupload/xtemp', '/var/chupload/xdest',
				'_some_pfx', CHUNK_SIZE, MAX_FILESIZE, $logger
			);
		} catch(Err $e) {
		}
	}

	/**
	 * Make uploader based on patched classes.
	 */
	private function _make_uploader($cls='') {
		$logger = new Logger(Logger::DEBUG, self::$logfile);
		self::$core = $core = (new RouterDev)
			->config('home', '/')
			->config('logger', $logger);
		if (!$cls)
			$cls = 'BFITech\ZapChupload\ChunkUpload';
		return new $cls(
			$core, self::$tdir . '/xtemp', self::$tdir . '/xdest',
			'__test', CHUNK_SIZE, MAX_FILESIZE, $logger
		);
	}

	/**
	 * Wrap a fake request and a matching route.
	 */
	private function _make_request($chup, $rdev, $post, $files) {
		$rdev
			->request('/', 'POST',
				['post' =>  $post, 'files' => $files])
			->route('/', [$chup, 'upload'], 'POST');
	}

	public function test_upload_request() {
		$chup = $this->_make_uploader();
		$core = $chup::$core;
		$rdev = new RoutingDev($core);

		# chunk doesn't exist
		$post = [
			'__testname' => 'somefile.dat',
			'__testsize' => 1000,
		];
		$files = [];
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$errno, Err::EREQ_NO_CHUNK);

		$fake_chunk = self::$tdir . '/fakechunk.dat';
		$fls = $this->file_list()[2][0];
		## copy from overly-big sample
		copy($fls, $fake_chunk);

		# form incomplete
		$files['__testblob'] = [
			'error' => UPLOAD_ERR_OK,
			'tmp_name' => $fake_chunk,
		];
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$code, 403);
		$this->ae($core::$errno, Err::EREQ_DATA_INCOMPLETE);

		# invalid filesize
		$post['__testsize'] = -1;
		$post['__testindex'] = -1;
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$errno, Err::ECST_FSZ_INVALID);

		# index too small
		$post['__testsize'] = 1000;
		$post['__testindex'] = -1;
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$errno, Err::ECST_CID_UNDERSIZED);

		# file too big
		$post['__testsize'] = filesize($fake_chunk);
		$post['__testindex'] = 0;
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$errno, Err::ECST_FSZ_OVERSIZED);

		# index too big
		$fls = $this->file_list()[1][0];
		## copy from valid sample
		copy($fls, $fake_chunk);
		$post['__testsize'] = filesize($fake_chunk);
		$post['__testindex'] = 100000;
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$errno, Err::ECST_CID_OVERSIZED);

		# simulate upload error
		$files['__testblob']['error'] = UPLOAD_ERR_PARTIAL;
		$post['__testindex'] = 1;
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$code, 503);
		$this->ae($core::$errno, UPLOAD_ERR_PARTIAL);

		# chunk too big
		$files['__testblob']['error'] = UPLOAD_ERR_OK;
		$post['__testindex'] = 1;
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$errno, Err::ECST_MCH_OVERSIZED);

		## pre-processing and chunk processing

		$chup = $this->_make_uploader('ChunkUploadChunkProc');
		$core = $chup::$core;
		$rdev = new RoutingDev($core);

		# failed pre-processing
		## fake a chunk from small sample
		$content = file_get_contents($this->file_list()[0][0]);
		file_put_contents($fake_chunk, $content);
		$post['__testindex'] = 0;
		$post['__testsize'] = filesize($fake_chunk);
		$post['dontsend'] = 1;
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$errno, Err::ECST_PREPROC_FAIL);
		unset($post['dontsend']);

		# invalid chunk processing
		file_put_contents($fake_chunk, $content);
		$post['__testindex'] = 1;
		$post['__testsize'] = filesize($fake_chunk);
		$post['fingerprint'] = 'wrong-finger';
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$errno, Err::ECST_CHUNKPROC_FAIL);

		# chunk size gets too big
		## fake a chunk from mid sample
		$content = substr(file_get_contents($this->file_list()[1][0]),
			0, CHUNK_SIZE + 1);
		file_put_contents($fake_chunk, $content);
		$post['__testsize'] = filesize($fake_chunk);
		$post['__testindex'] = 4;
		$post['fingerprint'] = $chup::get_fingerprint($content);
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$errno, Err::ECST_MCH_OVERSIZED);

		$chup = $this->_make_uploader('ChunkUploadIntercept');
		$core = $chup::$core;
		$rdev = new RoutingDev($core);

		# success, with valid chunk processing
		$content = file_get_contents($this->file_list()[0][0]);
		file_put_contents($fake_chunk, $content);
		$files['__testblob'] = [
			'error' => UPLOAD_ERR_OK,
			'tmp_name' => $fake_chunk,
		];
		$post['__testsize'] = filesize($fake_chunk);
		$post['__testindex'] = 0;
		$post['fingerprint'] = $chup::get_fingerprint($content);
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$errno, 0);
		$this->ae($core::$data['path'], $post['__testname']);

	}

	/**
	 * Format chunk data into fake $_POST and $_FILES ready to use
	 * by fake HTTP requests.
	 */
	private function _format_chunk($postfiles, $callback_route) {
		$i = 0;
		$post = $files = [];
		foreach ($postfiles as $key => $val) {
			if ($key == 'blob') {
				$base = $val[1];
				$ftmp = sprintf('%s/.%s-%s', self::$tdir . '/xtemp',
					$base, $i);
				file_put_contents($ftmp, $val[0]);
				$files['__test' . $key ] = [
					'error' => 0,
					'tmp_name' => $ftmp,
				];
			} else {
				$post['__test' . $key] = $val;
			}
			$i++;
		}
		$callback_route($post, $files);
	}

	/**
	 * Execute fake routing.
	 */
	private function _handle_upload($postfiles, $chup, $rdev) {
		$core = $chup::$core;
		$this->_format_chunk(
			$postfiles,
			function($post, $files) use($chup, $core, $rdev) {
				$this->_make_request($chup, $rdev, $post, $files);
			}
		);
	}

	/**
	 * Fake uploading the whole file. Internally uses
	 * $this->upload_chunks. Use $cb_resp($core) for
	 * additional response checks.
	 */
	private function _process_chunks(
		$fname, $cls='', $tamper=null, $cb_resp=null
	) {
		$chup = $this->_make_uploader($cls);
		$rdev = new RoutingDev($chup::$core);

		$this->upload_chunks(
			$fname, CHUNK_SIZE,
			function ($postfiles) use($chup, $rdev, $cb_resp) {
				$this->_handle_upload($postfiles, $chup, $rdev);
				if (is_callable($cb_resp))
					$cb_resp($chup::$core);
			},
			$tamper
		);
	}

	/**
	 * Verify uploaded file.
	 */
	private function _upload_ok($fname) {
		$dname = self::$tdir . '/xdest/' . basename($fname);
		$this->ae(
			sha1(file_get_contents($fname)),
			sha1(file_get_contents($dname)));
	}

	private function _upload_error($fname) {
		$dname = self::$tdir . '/xdest/' . basename($fname);
		$this->assertFalse(file_exists($dname));
	}

	public function test_upload_file_single_chunk() {
		$fname = self::file_list()[0][0];
		$this->_process_chunks($fname);
		$this->_upload_ok($fname);
	}

	public function test_upload_file_rounded_2_chunks() {
		$fname = self::file_list()[3][0];
		$this->_process_chunks($fname);
		$this->_upload_ok($fname);
	}

	public function test_upload_file_exactly_2_chunks() {
		$fname = self::file_list()[4][0];
		$this->_process_chunks($fname);
		$dname = self::$tdir . '/xdest/' . basename($fname);
		$this->_upload_ok($fname);
	}

	public function test_upload_multi_chunks() {
		$fname = self::file_list()[1][0];
		$this->_process_chunks($fname);
		$this->_upload_ok($fname);
	}

	public function test_upload_file_too_big() {
		$fname = self::file_list()[2][0];
		$this->_process_chunks(
			$fname, '', null,
			function($core) {
				$this->ae($core::$errno, Err::ECST_FSZ_OVERSIZED);
			}
		);
		$this->_upload_error($fname);
	}

	public function test_upload_file_failed_postproc() {
		$fname = self::file_list()[0][0];
		$this->_process_chunks(
			$fname, 'ChunkUploadPostProc', null,
			function($core) {
				if ($core::$errno !== 0)
					$this->ae($core::$errno, Err::ECST_POSTPROC_FAIL);
			}
		);
		$this->_upload_error($fname);
	}

	public function test_upload_file_temper() {

		## broken index
		##
		## When index sequence is messed up, upload goes on until
		## the end. It's the unpacker that will invalidate it.
		## There's no fail-early mechanism when this happens.
		$fname = self::file_list()[1][0];
		$this->_process_chunks(
			$fname, '', function($postfiles) {
				if ($postfiles['index'] == 5)
					$postfiles['index'] = 4;
				return $postfiles;
			},
			function($core) {
				if ($core::$errno !== 0)
					$this->ae($core::$errno, Err::ECST_MCH_UNORDERED);
			}
		);
		$this->_upload_error($fname);

		## broken chunk
		##
		## Tampering with a mid chunk will break the tail, hence
		## the order will be messed up, unless packed chunk
		## coincidentally unfolds, which is very unlikely.
		$fname = self::file_list()[1][0];
		$this->_process_chunks(
			$fname, '', function($postfiles) {
				list($chunk, $base) = $postfiles['blob'];
				if ($postfiles['index'] == 4)
					$postfiles['blob'] = [substr($chunk, 0, 45), $base];
				return $postfiles;
			},
			function($core) {
				if ($core::$errno !== 0)
					$this->ae($core::$errno, Err::ECST_MCH_UNORDERED);
			}
		);
		$this->_upload_error($fname);

		## broken size
		##
		## Excessive size may cause oversizing, and since the uploader
		## proceeds with the remaining chunks regardless the size
		## error, later it invokes unordered error. Client ideally
		## stops at first error occurrence. Slight addition to the
		## size not necessarily causes error since it's factored
		## and rounded by chunk_size.
		$fname = self::file_list()[1][0];
		$this->_process_chunks(
			$fname, '', function($postfiles) {
				# causes FSZ_INVALID
				if ($postfiles['index'] == 3)
					$postfiles['size'] -= 200000;
				# causes FSZ_OVERSIZED
				if ($postfiles['index'] == 4)
					$postfiles['size'] += 200000;
				# slight addition, noop
				if ($postfiles['index'] == 5)
					$postfiles['size'] += 20;
				return $postfiles;
			},
			function($core) {
				$errno = $core::$errno;
				if ($errno !== 0) {
					$this->assertTrue(
						$errno == Err::ECST_FSZ_INVALID ||
						$errno == Err::ECST_FSZ_OVERSIZED ||
						$errno == Err::ECST_MCH_UNORDERED
					);
				}
			}
		);
		$this->_upload_error($fname);

		## TODO: broken base
		$fname = self::file_list()[1][0];
		$this->_process_chunks(
			$fname, '', function($postfiles) {
				list($chunk, $base) = $postfiles['blob'];
				if ($postfiles['index'] == 5)
					$postfiles['blob'] = [$chunk, $base . 'xx']; 
				return $postfiles;
			},
			function($core) {
			}
		);
	}
}
