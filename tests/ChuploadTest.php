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

		# chunk size gets too big
		## fake a chunk from mid sample
		$content = substr(file_get_contents($this->file_list()[1][0]),
			0, CHUNK_SIZE + 1);
		file_put_contents($fake_chunk, $content);
		$_POST['__testsize'] = filesize($fake_chunk);
		$_POST['__testindex'] = 4;
		$_POST['__testfingerprint'] = $chup::get_fingerprint($content);
		$chup = $this->make_uploader(true);
		$core = $chup::$core;
		$core->route('/', [$chup, 'upload'], 'POST');
		$this->assertEquals($core::$errno, $Err::ECST);
		$this->assertEquals($core::$data, [$Err::ECST_MCH_OVERSIZED]);

	}

	private function format_chunk($chunkdata, $callback) {
		$this->reset_env();
		$i = 0;
		foreach ($chunkdata as $key => $val) {
			if ($key == 'blob') {
				$base = $val[1];
				$ftmp = sprintf('%s/.%s-%s', self::$tdir . '/xtemp',
					$base, $i);
				file_put_contents($ftmp, $val[0]);
				$_FILES['__test' . $key] = [
					'error' => 0,
					'tmp_name' => $ftmp,
				];
			} else {
				$_POST['__test' . $key] = $val;
			}
			$i++;
		}
		$callback();
	}

	public function test_upload() {
		$Err = new ChunkUploadError;
		$fls = self::file_list();

		# single-chunk file
		$fname = $fls[0][0];
		$this->upload_chunks(
			$fname, CHUNK_SIZE, function($chunkdata)
		{
			$this->format_chunk($chunkdata, function(){
				$chup = $this->make_uploader();
				$core = $chup::$core;
				$core->route('/', [$chup, 'upload'], 'POST');
				$this->assertEquals($core::$errno, 0);
			});
		});
		$dname = self::$tdir . '/xdest/' . basename($fname);
		$this->assertEquals(
			sha1(file_get_contents($fname)),
			sha1(file_get_contents($dname)));

		# multi-chunk file
		$fname = $fls[1][0];
		$this->upload_chunks(
			$fname, CHUNK_SIZE, function($chunkdata)
		{
			$this->format_chunk($chunkdata, function(){
				$chup = $this->make_uploader();
				$core = $chup::$core;
				$core->route('/', [$chup, 'upload'], 'POST');
				$this->assertEquals($core::$errno, 0);
			});
		});
		$dname = self::$tdir . '/xdest/' . basename($fname);
		$this->assertEquals(
			sha1(file_get_contents($fname)),
			sha1(file_get_contents($dname)));

		# fail post-proc
		$fname = $fls[0][0];
		$this->upload_chunks(
			$fname, CHUNK_SIZE, function($chunkdata) use($Err)
		{
			$this->format_chunk($chunkdata, function() use($Err){
				$chup = $this->make_uploader(false, true);
				$core = $chup::$core;
				$core->route('/', [$chup, 'upload'], 'POST');
				if ($core::$errno !== 0) {
					$this->assertEquals($core::$errno,
						$Err::ECST);
					$this->assertEquals($core::$data[0],
						$Err::ECST_POSTPROC_FAIL);
				}
			});
		});
		$dname = self::$tdir . '/xdest/' . basename($fname);
		$this->assertFalse(file_exists($dname));

		# multi-chunk file with messed up order
		# @note When index sequence is messed up, upload goes on. It's
		#     the unpacker that will invalidate it.
		$fname = $fls[1][0];
		$this->upload_chunks(
			$fname, CHUNK_SIZE, function($chunkdata) use($fname, $Err)
		{
			$this->format_chunk($chunkdata, function() use($fname, $Err){
				$faulty = false;
				if ($_POST['__testindex'] == 1) {
					$_POST['__testindex'] = 10;
				}
				if ($_POST['__testindex'] == floor(
					filesize($fname) / CHUNK_SIZE) - 1)
				{
					$faulty = true;
				}
				$chup = $this->make_uploader();
				$core = $chup::$core;
				$core->route('/', [$chup, 'upload'], 'POST');
				if ($faulty) {
					$this->assertEquals($core::$errno, $Err::ECST);
					$this->assertEquals($core::$data[0],
						$Err::ECST_MCH_UNORDERED);
				}
			});
		});
		$dname = self::$tdir . '/xdest/' . basename($fname);
		$this->assertFalse(file_exists($dname));

	}

}

