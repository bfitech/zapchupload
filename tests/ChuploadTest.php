<?php


require_once(__DIR__ . '/ChuploadFixture.php');


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

	public static function get_fingerprint($chunk) {
		return hash_hmac('sha512', $chunk, 'xxx');
	}

	public function make_fingerprint($chunk) {
		return self::get_fingerprint($chunk);
	}

	public function check_fingerprint($fingerprint, $chunk_recv) {
		return $fingerprint === $this->make_fingerprint($chunk_recv);
	}
}

class ChunkUploadPostProc extends ChunkUploadPatched {

	public function post_processing($path) {
		return false;
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

	private function make_uploader(
		$with_fingerprint=false, $postproc=false
	) {
		$logger = new zc\Logger(zc\Logger::DEBUG, self::$logfile);
		$core = new RouterPatched('/', null, false, $logger);
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

	private function reset_env() {
		$_SERVER['REQUEST_URI'] = '/';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST = $_FILES = [];
	}

	public function test_upload_request() {
		$Err = new ChunkUploadError;
		$this->reset_env();

		# chunk doesn't exist
		$_POST = [
			'__testname' => 'somefile.dat',
			'__testsize' => 1000,
		];
		$chup = $this->make_uploader();
		$core = $chup::$core;
		$core->route('/', [$chup, 'upload'], 'POST');
		$this->assertEquals($core::$errno, $Err::EREQ);
		$this->assertEquals($core::$data, [$Err::EREQ_NO_CHUNK]);

		$fake_chunk = self::$tdir . '/fakechunk.dat';
		$fls = $this->file_list()[2][0];
		## copy from overly-big sample
		copy($fls, $fake_chunk);

		# form incomplete
		$_FILES['__testblob'] = [
			'error' => UPLOAD_ERR_OK,
			'tmp_name' => $fake_chunk,
		];
		$chup = $this->make_uploader();
		$core = $chup::$core;
		$core->route('/', [$chup, 'upload'], 'POST');
		$this->assertEquals($core::$errno, $Err::EREQ);
		$this->assertEquals($core::$data, [$Err::EREQ_DATA_INCOMPLETE]);

		# invalid filesize
		$_POST['__testsize'] = -1;
		$_POST['__testindex'] = -1;
		$chup = $this->make_uploader();
		$core = $chup::$core;
		$core->route('/', [$chup, 'upload'], 'POST');
		$this->assertEquals($core::$errno, $Err::ECST);
		$this->assertEquals($core::$data, [$Err::ECST_FSZ_INVALID]);

		# index too small
		$_POST['__testsize'] = 1000;
		$_POST['__testindex'] = -1;
		$chup = $this->make_uploader();
		$core = $chup::$core;
		$core->route('/', [$chup, 'upload'], 'POST');
		$this->assertEquals($core::$errno, $Err::ECST);
		$this->assertEquals($core::$data, [$Err::ECST_CID_UNDERSIZED]);
	
		# file too big
		$_POST['__testsize'] = filesize($fake_chunk);
		$_POST['__testindex'] = 0;
		$chup = $this->make_uploader();
		$core = $chup::$core;
		$core->route('/', [$chup, 'upload'], 'POST');
		$this->assertEquals($core::$errno, $Err::ECST);
		$this->assertEquals($core::$data, [$Err::ECST_FSZ_OVERSIZED]);

		# index too big
		$fls = $this->file_list()[1][0];
		## copy from valid sample
		copy($fls, $fake_chunk);
		$_POST['__testsize'] = filesize($fake_chunk);
		$_POST['__testindex'] = 100000;
		$chup = $this->make_uploader();
		$core = $chup::$core;
		$core->route('/', [$chup, 'upload'], 'POST');
		$this->assertEquals($core::$errno, $Err::ECST);
		$this->assertEquals($core::$data, [$Err::ECST_CID_OVERSIZED]);

		# simulate upload error
		$_FILES['__testblob']['error'] = UPLOAD_ERR_PARTIAL;
		$_POST['__testindex'] = 1;
		$chup = $this->make_uploader();
		$core = $chup::$core;
		$core->route('/', [$chup, 'upload'], 'POST');
		$this->assertEquals($core::$errno, $Err::EUPL);
		$this->assertEquals($core::$data, [UPLOAD_ERR_PARTIAL]);

		# chunk too big
		$_FILES['__testblob']['error'] = UPLOAD_ERR_OK;
		$_POST['__testindex'] = 1;
		$chup = $this->make_uploader();
		$core = $chup::$core;
		$core->route('/', [$chup, 'upload'], 'POST');
		$this->assertEquals($core::$errno, $Err::ECST);
		$this->assertEquals($core::$data, [$Err::ECST_MCH_OVERSIZED]);

		# invalid fingerprint
		## fake a chunk from small sample
		file_put_contents($fake_chunk,
			file_get_contents($this->file_list()[0][0]));
		$_POST['__testsize'] = filesize($fake_chunk);
		$_POST['__testindex'] = 1;
		$_POST['__testfingerprint'] = 'xxx';
		$chup = $this->make_uploader(true);
		$core = $chup::$core;
		$core->route('/', [$chup, 'upload'], 'POST');
		$this->assertEquals($core::$errno, $Err::ECST);
		$this->assertEquals($core::$data, [$Err::ECST_FGP_INVALID]);

		# chunk comes not in correct order
		## fake a chunk from mid sample
		file_put_contents($fake_chunk,
			substr(file_get_contents($this->file_list()[1][0]),
			0, CHUNK_SIZE));
		$_POST['__testsize'] = filesize($fake_chunk);
		$_POST['__testindex'] = 1;
		$_POST['__testfingerprint'] = ChunkUploadPatched::get_fingerprint(
			file_get_contents($fake_chunk));
		$chup = $this->make_uploader(true);
		$core = $chup::$core;
		$core->route('/', [$chup, 'upload'], 'POST');
		$this->assertEquals($core::$errno, $Err::ECST);
		$this->assertEquals($core::$data, [$Err::ECST_MCH_UNORDERED]);

	}

	private function format_chunk($chunkdata, $fname) {
		$this->reset_env();
		$idxs = array_map(function($ele) {
			return $ele[1];
		}, array_filter($chunkdata, function($ele){
			return $ele[0] == 'index';
		}));
		foreach ($idxs as $idxv) {
			$idx = $idxv;
			break;
		}
		foreach ($chunkdata as $cdat) {
			$key = '__test' . $cdat[0];
			if ($cdat[0] == 'blob') {
				$ftmp = sprintf('%s/.%s-%s', dirname($fname),
					basename($fname), $idx);
				file_put_contents($ftmp, $cdat[1]);
				$_FILES[$key] = [
					'error' => 0,
					'tmp_name' => $ftmp,
				];
			} else {
				$_POST[$key] = $cdat[1];
			}
		}
	}

	public function test_upload() {
		$Err = new ChunkUploadError;
		$fls = self::file_list();

		# single-chunk file
		$fname = $fls[0][0];
		$this->upload_chunks(
			$fname, CHUNK_SIZE,
			function($chunkdata) use($fname, $Err)
		{
			$this->format_chunk($chunkdata, $fname);
			$chup = $this->make_uploader();
			$core = $chup::$core;
			$core->route('/', [$chup, 'upload'], 'POST');
			$this->assertEquals($core::$errno, 0);
		});
		$this->assertEquals(
			sha1(file_get_contents($fname)), 
			sha1(file_get_contents(
				self::$tdir . '/xdest/' . basename($fname))));

		# multi-chunk file
		$fname = $fls[1][0];
		$this->upload_chunks(
			$fname, CHUNK_SIZE,
			function($chunkdata) use($fname, $Err)
		{
			$this->format_chunk($chunkdata, $fname);
			$chup = $this->make_uploader();
			$core = $chup::$core;
			$core->route('/', [$chup, 'upload'], 'POST');
			$this->assertEquals($core::$errno, 0);
		});
		#$this->assertEquals(
		#	sha1(file_get_contents($fname)), 
		#	sha1(file_get_contents(
		#		self::$tdir . '/xdest/' . basename($fname))));

		# fail post-proc
		$fname = $fls[0][0];
		$this->upload_chunks(
			$fname, CHUNK_SIZE,
			function($chunkdata) use($fname, $Err)
		{
			$this->format_chunk($chunkdata, $fname);
			$chup = $this->make_uploader(false, true);
			$core = $chup::$core;
			$core->route('/', [$chup, 'upload'], 'POST');
			$this->assertEquals($core::$errno, $Err::ECST);
			$this->assertEquals($core::$data, [
				$Err::ECST_POSTPROC_FAIL]);
		});
	}

}

