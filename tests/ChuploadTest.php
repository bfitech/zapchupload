<?php declare(strict_types=1);


require_once(__DIR__ . '/ChuploadFixture.php');


use BFITech\ZapCore\Logger;
use BFITech\ZapCoreDev\RouterDev;
use BFITech\ZapCoreDev\RoutingDev;
use BFITech\ZapChupload\ChunkUpload;
use BFITech\ZapChupload\ChunkUploadError as Err;


/**
 * Class constant overrides.
 */
class ChunkUploadConst extends ChunkUpload {

	const CHUNK_SIZE_MIN = 100;
	const CHUNK_SIZE_DEFAULT = 100;
	const CHUNK_SIZE_MAX = 100;

}

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
		int &$errno, array &$data=null, int &$http_code=200
	) {
		self::$core::print_json($errno, $data, $http_code);
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

	#public static $core;

	public static function setUpBeforeClass() {
		foreach (self::file_list() as $file) {
			if (!file_exists($file[0]))
				self::generate_file($file[0], $file[1]);
		}
		self::$logfile = self::$udir . '/zapchupload.log';
		if (file_exists(self::$logfile))
			@unlink(self::$logfile);
	}

	public function test_constructor() {
		$eq = self::eq();

		$log = new Logger(Logger::ERROR, self::$logfile);
		$core = (new RouterDev)->config('logger', $log);
		$udir = self::$udir;

		$tempdir = $udir . "/xtemp";
		$destdir = $udir . "/xdest";

		$err = null;

		# constant overrides invalid
		try {
			new ChunkUploadConst(
				$core, $tempdir, $destdir,
				null, null, null, $log);
		} catch(Err $err) {
			// no-op
		}
		$eq($err->code, Err::EINI_CONST_INVALID);

		$new = function(
			$tempdir, $destdir,
			$prefix, $chunksz, $maxsz
		) use($core, $log) {
			try {
				new ChunkUpload(
					$core, $tempdir, $destdir,
					$prefix, $chunksz, $maxsz, $log);
			} catch(Err $err) {
				return $err;
			}
			return null;
		};

		# chunk too small
		$csz_too_small = pow(2, 8);
		$err = $new($tempdir, $destdir,
			'_pfx', $csz_too_small, MAX_FILESIZE);
		$eq($err->code, Err::EINI_CHUNK_TOO_SMALL);

		# chunk too big
		$csz_too_yuuge = pow(2, 32);
		$err = $new($tempdir, $destdir,
			'_pfx', $csz_too_yuuge, MAX_FILESIZE);
		$eq($err->code, Err::EINI_CHUNK_TOO_BIG);

		# max filesize too big
		$err = $new($tempdir, $destdir,
			'_pfx', CHUNK_SIZE, CHUNK_SIZE - 1);
		$eq($err->code, Err::EINI_MAX_FILESIZE_TOO_SMALL);

		# empty dirs
		$err = $new('', '',
			'_pfx', CHUNK_SIZE, MAX_FILESIZE);
		$eq($err->code, Err::EINI_DIRS_NOT_SET);

		# dirs identical
		$err = $new('/dev/null', '/dev/null',
			'_pfx', CHUNK_SIZE, MAX_FILESIZE);
		$eq($err->code, Err::EINI_DIRS_IDENTICAL);

		# destdir invalid
		$err = $new($tempdir, __FILE__,
			'_pfx', CHUNK_SIZE, MAX_FILESIZE);
		$eq($err->code, Err::EINI_DIRS_NOT_CREATED);

		# prefix invalid
		$err = $new($tempdir, $destdir,
			'1', CHUNK_SIZE, MAX_FILESIZE);
		$eq($err->code, Err::EINI_PREFIX_INVALID);

		# ok
		$chup = new ChunkUpload(
			$core, $tempdir, $destdir,
			null, CHUNK_SIZE, MAX_FILESIZE, $log
		);
		$config = $chup->get_config();
		$eq($config['chunk_size'], CHUNK_SIZE);
		$eq($config['post_prefix'], '__chupload_');

		# cannot get request-related props without routing
		try {
			$chup->get_args();
		} catch(Err $err) {
			// no-op
		}
		$eq($err->code, Err::EINI_PROPERTY_EMPTY);
		$err = null;

		try {
			$chup->get_request();
		} catch(Err $err) {
			// no-op
		}
		$eq($err->code, Err::EINI_PROPERTY_EMPTY);
		$err = null;

		try {
			$chup->get_chunk_data();
		} catch(Err $err) {
			// no-op
		}
		$eq($err->code, Err::EINI_PROPERTY_EMPTY);
	}

	/**
	 * Execute simulated request and response sequence. Use $cls to
	 * use patched ChunkUpload class.
	 */
	private function _exec($post, $files, $cls='') {
		# upload router
		$log = new Logger(Logger::DEBUG, self::$logfile);
		$core = (new RouterDev)
			->config('home', '/')
			->config('logger', $log);
		if (!$cls)
			$cls = 'BFITech\ZapChupload\ChunkUpload';
		$chup = new $cls(
			$core, self::$udir . '/xtemp', self::$udir . '/xdest',
			'__test', CHUNK_SIZE, MAX_FILESIZE, $log
		);

		# routing
		$rdev = new RoutingDev($core);
		$rdev
			->request('/', 'POST',
				['post' =>  $post, 'files' => $files])
			->route('/', [$chup, 'upload'], 'POST');

		return [$chup, $core];
	}

	public function test_upload_request() {
		$eq = $this->eq();

		## vanilla

		$core = null;
		$exec = function($post, $files) use(&$core) {
			list($_, $core) = $this->_exec($post, $files);
		};

		# chunk doesn't exist
		$post = [
			'__testname' => 'somefile.dat',
			'__testsize' => 1000,
		];
		$files = [];
		$exec($post, $files);
		$eq($core::$errno, Err::EREQ_NO_CHUNK);

		$fake_chunk = self::$udir . '/fakechunk.dat';
		$fls = $this->file_name('excessive');
		## copy from overly-big sample
		copy($fls, $fake_chunk);

		# form incomplete
		$files['__testblob'] = [
			'error' => UPLOAD_ERR_OK,
			'tmp_name' => $fake_chunk,
		];
		$exec($post, $files);
		$eq($core::$code, 403);
		$eq($core::$errno, Err::EREQ_DATA_INCOMPLETE);

		# invalid filesize
		$post['__testsize'] = -1;
		$post['__testindex'] = -1;
		$exec($post, $files);
		$eq($core::$errno, Err::ECST_FSZ_INVALID);

		# index too small
		$post['__testsize'] = 1000;
		$post['__testindex'] = -1;
		$exec($post, $files);
		$eq($core::$errno, Err::ECST_CID_UNDERSIZED);

		# file too big
		$post['__testsize'] = filesize($fake_chunk);
		$post['__testindex'] = 0;
		$exec($post, $files);
		$eq($core::$errno, Err::ECST_FSZ_OVERSIZED);

		# index too big
		$fls = $this->file_name('in-range');
		## copy from valid sample
		copy($fls, $fake_chunk);
		$post['__testsize'] = filesize($fake_chunk);
		$post['__testindex'] = 100000;
		$exec($post, $files);
		$eq($core::$errno, Err::ECST_CID_OVERSIZED);

		# simulate upload error
		$files['__testblob']['error'] = UPLOAD_ERR_PARTIAL;
		$post['__testindex'] = 1;
		$exec($post, $files);
		$eq($core::$code, 503);
		$eq($core::$errno, UPLOAD_ERR_PARTIAL);

		# chunk too big
		$files['__testblob']['error'] = UPLOAD_ERR_OK;
		$post['__testindex'] = 1;
		$exec($post, $files);
		$eq($core::$errno, Err::ECST_MCH_OVERSIZED);

		## pre-processing and chunk processing

		$chup = null;
		$exec = function($post, $files) use(&$chup, &$core) {
			list($chup, $core) = $this->_exec(
				$post, $files, 'ChunkUploadChunkProc');
		};

		# failed pre-processing
		## fake a chunk from small sample
		$content = file_get_contents($this->file_name('single'));
		file_put_contents($fake_chunk, $content);
		$post['__testindex'] = 0;
		$post['__testsize'] = filesize($fake_chunk);
		$post['dontsend'] = 1;
		$exec($post, $files);
		$eq($core::$errno, Err::ECST_PREPROC_FAIL);
		unset($post['dontsend']);

		# invalid chunk processing
		file_put_contents($fake_chunk, $content);
		$post['__testindex'] = 1;
		$post['__testsize'] = filesize($fake_chunk);
		$post['fingerprint'] = 'wrong-finger';
		$exec($post, $files);
		$eq($core::$errno, Err::ECST_CHUNKPROC_FAIL);

		# chunk size gets too big
		## fake a chunk from mid sample
		$content = substr(
			file_get_contents($this->file_name('in-range')),
			0, CHUNK_SIZE + 1);
		file_put_contents($fake_chunk, $content);
		$post['__testsize'] = filesize($fake_chunk);
		$post['__testindex'] = 4;
		$post['fingerprint'] = $chup::get_fingerprint($content);
		$exec($post, $files);
		$eq($core::$errno, Err::ECST_MCH_OVERSIZED);

		## intercept

		$exec = function($post, $files) {
			list($chup, $core) = $this->_exec(
				$post, $files, 'ChunkUploadIntercept');
		};

		# success, with valid chunk processing
		$content = file_get_contents($this->file_name('single'));
		file_put_contents($fake_chunk, $content);
		$files['__testblob'] = [
			'error' => UPLOAD_ERR_OK,
			'tmp_name' => $fake_chunk,
		];
		$post['__testsize'] = filesize($fake_chunk);
		$post['__testindex'] = 0;
		$post['fingerprint'] = $chup::get_fingerprint($content);
		$exec($post, $files);
		$eq($core::$errno, 0);
		$eq($core::$data['path'], $post['__testname']);
	}

	/**
	 * Execute simulated file upload with optional patched ChunkUpload
	 * class, payload tampering and response verification.
	 */
	private function _exec_file(
		$fname, $cls='', $tamper=null, $cb_resp=null
	) {
		$handler = function($_postfiles) use($cls, $cb_resp) {
			$i = 0;
			$post = $files = [];
			# simulate PHP behavior of separating _POST and _FILES
			foreach ($_postfiles as $key => $val) {
				if ($key == 'blob') {
					$base = $val[1];
					$ftmp = sprintf('%s/.%s-%s', self::$udir . '/xtemp',
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
			list($chup, $core) = $this->_exec($post, $files, $cls);
			if (is_callable($cb_resp))
				$cb_resp($core);
		};
		$this->upload_chunks($fname, CHUNK_SIZE, $handler, $tamper);
	}

	# verify upload result

	private function _upload_ok($fname) {
		$dname = self::$udir . '/xdest/' . basename($fname);
		$this->eq()(
			sha1(file_get_contents($fname)),
			sha1(file_get_contents($dname)));
	}

	private function _upload_error($fname) {
		$dname = self::$udir . '/xdest/' . basename($fname);
		$this->fl()(file_exists($dname));
	}

	# file tests

	public function test_upload_file_single_chunk() {
		$fname = self::file_name('single');
		$this->_exec_file($fname);
		$this->_upload_ok($fname);
	}

	public function test_upload_file_rounded_2_chunks() {
		$fname = self::file_name('double-rounded');
		$this->_exec_file(
			$fname, '', null, function($core) {
				$data = $core::$data;
				if ($data['index'] == 0)
					$this->eq()($data['done'], false);
				else
					$this->eq()($data['done'], true);
		});
		$this->_upload_ok($fname);
	}

	public function test_upload_file_exactly_2_chunks() {
		$fname = self::file_name('double-exact');
		$this->_exec_file($fname);
		$this->_upload_ok($fname);
	}

	public function test_upload_multi_chunks() {
		$fname = self::file_name('in-range');
		$this->_exec_file($fname);
		$this->_upload_ok($fname);
	}

	public function test_upload_file_too_big() {
		$fname = self::file_name('excessive');
		$this->_exec_file(
			$fname, '', null,
			function($core) {
				$this->eq()($core::$errno, Err::ECST_FSZ_OVERSIZED);
			}
		);
		$this->_upload_error($fname);
	}

	public function test_upload_file_failed_postproc() {
		$fname = self::file_name('single');
		$this->_exec_file(
			$fname, 'ChunkUploadPostProc', null,
			function($core) {
				if ($core::$errno !== 0)
					$this->eq()($core::$errno, Err::ECST_POSTPROC_FAIL);
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
		$fname = self::file_name('in-range');
		$this->_exec_file(
			$fname, '', function($postfiles) {
				if ($postfiles['index'] == 5)
					$postfiles['index'] = 4;
				return $postfiles;
			},
			function($core) {
				if ($core::$errno !== 0)
					$this->eq()($core::$errno, Err::ECST_MCH_UNORDERED);
			}
		);
		$this->_upload_error($fname);

		## broken chunk
		##
		## Tampering with a mid chunk will break the tail, hence
		## the order will be messed up, unless packed chunk
		## coincidentally unfolds, which is very unlikely.
		$fname = self::file_name('in-range');
		$this->_exec_file(
			$fname, '', function($postfiles) {
				list($chunk, $base) = $postfiles['blob'];
				if ($postfiles['index'] == 4)
					$postfiles['blob'] = [substr($chunk, 0, 45), $base];
				return $postfiles;
			},
			function($core) {
				if ($core::$errno !== 0)
					$this->eq()($core::$errno, Err::ECST_MCH_UNORDERED);
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
		$fname = self::file_name('in-range');
		$this->_exec_file(
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
					$this->tr()(
						$errno == Err::ECST_FSZ_INVALID ||
						$errno == Err::ECST_FSZ_OVERSIZED ||
						$errno == Err::ECST_MCH_UNORDERED
					);
				}
			}
		);
		$this->_upload_error($fname);

		## TODO: broken base
		$fname = self::file_name('in-range');
		$this->_exec_file(
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
