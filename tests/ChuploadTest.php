<?php


require_once(__DIR__ . '/ChuploadFixture.php');


use BFITech\ZapCore\Logger;
use BFITech\ZapCoreDev\RouterDev;
use BFITech\ZapCoreDev\RoutingDev;
use BFITech\ZapChupload\ChunkUpload;
use BFITech\ZapChupload\ChunkUploadError;


/**
 * Patched class with fingerprinting.
 */
class ChunkUploadPatched extends ChunkUpload {

	public static function get_fingerprint($chunk) {
		return hash_hmac('sha512', $chunk, 'sekrit');
	}

	public function make_fingerprint($chunk) {
		return self::get_fingerprint($chunk);
	}

	public function check_fingerprint($fingerprint, $chunk_recv) {
		// @note We cannot use hash_equals for PHP5.5 support.
		return $fingerprint === $this->make_fingerprint($chunk_recv);
	}

}

/**
 * Patched class where post-processing always fails.
 */
class ChunkUploadPostProc extends ChunkUploadPatched {

	public function post_processing($path) {
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
		$Err = new ChunkUploadError;

		$csz_too_small = pow(2, 8);
		$csz_too_yuuge = pow(2, 24);

		$chup = new ChunkUploadPatched(
			$core, $tdir . '/xtemp', $tdir . '/xdest',
			'_some_pfx', $csz_too_small, MAX_FILESIZE,
			true, $logger
		);
		$this->ae($chup->get_chunk_size(), 1024 * 100);

		$chup = new ChunkUploadPatched(
			$core, $tdir . '/xtemp', $tdir . '/xdest',
			'_some_pfx', $csz_too_yuuge, MAX_FILESIZE,
			true, $logger
		);
		$this->ae($chup->get_chunk_size(), 1024 * 100);

		$chup = new ChunkUploadPatched(
			$core, $tdir . '/xtemp', $tdir . '/xdest',
			'_some_pfx', CHUNK_SIZE, MAX_FILESIZE,
			true, $logger
		);
		$this->ae($chup->get_chunk_size(), CHUNK_SIZE);
		$this->ae($chup->get_post_prefix(), '_some_pfx');
		$this->ae($chup->get_max_filesize(), MAX_FILESIZE);

		$chup = new ChunkUploadPatched(
			$core, $tdir . '/xtemp', $tdir . '/xdest',
			'_some_pfx', CHUNK_SIZE, CHUNK_SIZE - 1,
			true, $logger
		);

		try {
			$chup = new ChunkUploadPatched(
				$core, '', '',
				'_some_pfx', CHUNK_SIZE, MAX_FILESIZE,
				true, $logger
			);
		} catch(ChunkUploadError $e) {
		}

		try {
			$chup = new ChunkUploadPatched(
				$core, 'x', 'x',
				'_some_pfx', CHUNK_SIZE, MAX_FILESIZE,
				true, $logger
			);
		} catch(ChunkUploadError $e) {
		}

		try {
			$chup = new ChunkUploadPatched(
				$core, '/var/chupload/xtemp', '/var/chupload/xdest',
				'_some_pfx', CHUNK_SIZE, MAX_FILESIZE,
				true, $logger
			);
		} catch(ChunkUploadError $e) {
		}
	}

	/**
	 * Make uploader based on patched classes.
	 */
	private function _make_uploader(
		$with_fingerprint=false, $postproc=false
	) {
		$logger = new Logger(Logger::DEBUG, self::$logfile);
		self::$core = $core = (new RouterDev)
			->config('home', '/')
			->config('logger', $logger);
		if ($postproc)
			return new ChunkUploadPostProc(
				$core, self::$tdir . '/xtemp', self::$tdir . '/xdest',
				'__test', CHUNK_SIZE, MAX_FILESIZE,
				$with_fingerprint, $logger
			);
		return new ChunkUploadPatched(
			$core, self::$tdir . '/xtemp', self::$tdir . '/xdest',
			'__test', CHUNK_SIZE, MAX_FILESIZE,
			$with_fingerprint, $logger
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
		$Err = new ChunkUploadError;

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
		$this->ae($core::$errno, $Err::EREQ);
		$this->ae($core::$data, [$Err::EREQ_NO_CHUNK]);

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
		$this->ae($core::$errno, $Err::EREQ);
		$this->ae($core::$data, [$Err::EREQ_DATA_INCOMPLETE]);

		# invalid filesize
		$post['__testsize'] = -1;
		$post['__testindex'] = -1;
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$errno, $Err::ECST);
		$this->ae($core::$data, [$Err::ECST_FSZ_INVALID]);

		# index too small
		$post['__testsize'] = 1000;
		$post['__testindex'] = -1;
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$errno, $Err::ECST);
		$this->ae($core::$data, [$Err::ECST_CID_UNDERSIZED]);

		# file too big
		$post['__testsize'] = filesize($fake_chunk);
		$post['__testindex'] = 0;
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$errno, $Err::ECST);
		$this->ae($core::$data, [$Err::ECST_FSZ_OVERSIZED]);

		# index too big
		$fls = $this->file_list()[1][0];
		## copy from valid sample
		copy($fls, $fake_chunk);
		$post['__testsize'] = filesize($fake_chunk);
		$post['__testindex'] = 100000;
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$errno, $Err::ECST);
		$this->ae($core::$data, [$Err::ECST_CID_OVERSIZED]);

		# simulate upload error
		$files['__testblob']['error'] = UPLOAD_ERR_PARTIAL;
		$post['__testindex'] = 1;
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$errno, $Err::EUPL);
		$this->ae($core::$data, [UPLOAD_ERR_PARTIAL]);

		# chunk too big
		$files['__testblob']['error'] = UPLOAD_ERR_OK;
		$post['__testindex'] = 1;
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$errno, $Err::ECST);
		$this->ae($core::$data, [$Err::ECST_MCH_OVERSIZED]);

		# with fingerprint

		$chup = $this->_make_uploader(true);
		$core = $chup::$core;
		$rdev = new RoutingDev($core);

		# invalid fingerprint
		## fake a chunk from small sample
		$content = file_get_contents($this->file_list()[0][0]);
		file_put_contents($fake_chunk, $content);
		$post['__testsize'] = filesize($fake_chunk);
		$post['__testindex'] = 1;
		$post['__testfingerprint'] = 'wrong-finger';
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$errno, $Err::ECST);
		$this->ae($core::$data, [$Err::ECST_FGP_INVALID]);

		# chunk size gets too big
		## fake a chunk from mid sample
		$content = substr(file_get_contents($this->file_list()[1][0]),
			0, CHUNK_SIZE + 1);
		file_put_contents($fake_chunk, $content);
		$post['__testsize'] = filesize($fake_chunk);
		$post['__testindex'] = 4;
		$post['__testfingerprint'] = $chup::get_fingerprint($content);
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$errno, $Err::ECST);
		$this->ae($core::$data, [$Err::ECST_MCH_OVERSIZED]);

		# success, with valid fingerprint
		$content = file_get_contents($this->file_list()[0][0]);
		file_put_contents($fake_chunk, $content);
		$files['__testblob'] = [
			'error' => UPLOAD_ERR_OK,
			'tmp_name' => $fake_chunk,
		];
		$post['__testsize'] = filesize($fake_chunk);
		$post['__testindex'] = 0;
		$post['__testfingerprint'] = $chup::get_fingerprint($content);
		$this->_make_request($chup, $rdev, $post, $files);
		$this->ae($core::$errno, 0);
		$this->ae($core::$data['path'], $post['__testname']);
	}

	/**
	 * Format chunk data into fake $_POST and $_FILES ready to use
	 * by fake HTTP requests.
	 */
	private function _format_chunk($chunkdata, $callback) {
		$i = 0;
		$post = $files = [];
		foreach ($chunkdata as $key => $val) {
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
		$callback($post, $files);
	}

	/**
	 * Execute fake routing.
	 */
	private function _handle_upload(
		$chunkdata, $chup, $rdev, $callback_res=null, $callback_req=null
	) {
		$core = $chup::$core;
		$this->_format_chunk($chunkdata,
			function($post, $files) use(
				$chup, $core, $rdev, $callback_req, $callback_res
			) {
				if ($callback_req) {
					$args = $callback_req([
						'post' => $post, 'files' =>$files]);
					$post = $args['post'];
					$files = $args['files'];
				}
				$this->_make_request($chup, $rdev, $post, $files);
				if ($callback_res)
					$callback_res($core);
				else
					# assume success if callback doesn't exist
					$this->ae($core::$errno, 0);
		});
	}

	/**
	 * Fake uploading the whole file. Internally uses
	 * $this->upload_chunks. Use $callback_res($core) for
	 * additional response checks. Use $callback_req($args) to
	 * modify request args.
	 */
	private function _process_chunks(
		$fname, $with_fingerprint=false, $postproc=false,
		$callback_res=null, $callback_req=null
	) {
		$chup = $this->_make_uploader($with_fingerprint, $postproc);
		$rdev = new RoutingDev($chup::$core);

		$this->upload_chunks(
			$fname, CHUNK_SIZE,
			function ($chunkdata) use(
				$chup, $rdev, $callback_res, $callback_req
			) {
				$this->_handle_upload(
					$chunkdata, $chup, $rdev, $callback_res,
					$callback_req);
			});
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
		$Err = new ChunkUploadError;
		$fname = self::file_list()[2][0];
		$this->_process_chunks(
			$fname, false, false,
			function($core) use($Err){
				if ($core::$errno !== 0) {
					$this->ae($core::$errno,
						$Err::ECST);
					$this->ae($core::$data[0],
						$Err::ECST_FSZ_OVERSIZED);
				}
			}
		);
		$dname = self::$tdir . '/xdest/' . basename($fname);
		$this->assertFalse(file_exists($dname));
	}

	public function test_upload_file_failed_postproc() {
		$Err = new ChunkUploadError;
		$fname = self::file_list()[0][0];
		$this->_process_chunks(
			$fname, false, true,
			function($core) use($Err){
				if ($core::$errno !== 0) {
					$this->ae($core::$errno,
						$Err::ECST);
					$this->ae($core::$data[0],
						$Err::ECST_POSTPROC_FAIL);
				}
			}
		);
		$dname = self::$tdir . '/xdest/' . basename($fname);
		$this->assertFalse(file_exists($dname));
	}

	public function test_upload_file_unordered_chunks() {
		# @note When index sequence is messed up, upload goes on until
		#     the end. It's the unpacker that will invalidate it.
		#     There's no fail-early mechanism when this happens.
		$Err = new ChunkUploadError;
		$fname = self::file_list()[1][0];
		$this->_process_chunks(
			$fname, false, false,
			function($core) use($Err) {
				if (self::$core->faulty) {
					$this->ae($core::$errno, $Err::ECST);
					$this->ae($core::$data[0],
						$Err::ECST_MCH_UNORDERED);
				} else {
					$this->ae($core::$errno, 0);
				}
			},
			function($args) use($fname) {
				# intentionally breaks indexing
				self::$core->faulty = false;
				if ($args['post']['__testindex'] == 1)
					$args['post']['__testindex'] = 10;
				if (
					$args['post']['__testindex'] == floor(
						filesize($fname) / CHUNK_SIZE) - 1
				)
					self::$core->faulty = true;
				return $args;
			}
		);
		$dname = self::$tdir . '/xdest/' . basename($fname);
		$this->assertFalse(file_exists($dname));
	}

}
