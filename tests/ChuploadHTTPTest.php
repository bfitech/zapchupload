<?php


require_once(__DIR__ . '/ChuploadFixture.php');


use BFITech\ZapCore\Common;
use BFITech\ZapCoreDev\CoreDev;
use BFITech\ZapChupload\ChunkUploadError as Err;
use GuzzleHttp\Client;

class ChunkUploadHTTPTest extends ChunkUploadFixture {


	public static function setUpBeforeClass() {
		self::$server_pid = CoreDev::server_up(
			__DIR__ . '/htdocs-test');
		if (!file_exists('/dev/urandom')) {
			printf("ERROR: Cannot find '/dev/urandom'.\n");
			printf("       Most likely your OS is not supported.\n");
			exit(1);
		}
		foreach (self::file_list() as $file) {
			if (!file_exists($file[0]))
				self::generate_file($file[0], $file[1]);
		}
		self::$logfile = self::$tdir . '/zapchupload-http.log';
		if (file_exists(self::$logfile))
			@unlink(self::$logfile);
	}

	public static function tearDownAfterClass() {
		#foreach (self::file_list() as $file)
		#	unlink($file[0]);
		CoreDev::server_down(self::$server_pid);
	}

	public static function client() {
		return new Client([
			'base_uri' => 'http://localhost:9999',
			'timeout' => 2,
		]);
	}

	public function download($query) {
		$response = self::client()->request('GET', '/', [
			'http_errors' => false,
			'query' => $query,
		]);
		$this->response = $response;
		$this->code = $response->getStatusCode();
		$this->body = (string)$response->getBody(); 
	}

	public function POST($path, $data=[]) {
		$post = [];
		foreach ($data as $key => $val) {
			$part = ['name' => $this->pfx. $key];
			if ($key == 'blob') {
				$part['contents'] = $val[0];
				$part['filename'] = $val[1];
			} else {
				$part['contents'] = $val;
			}
			$post[] = $part;
		}
		$response = self::client()->request('POST', $path, [
			'http_errors' => false,
			'multipart' => $post,
		]);
		$this->response = $response;
		$this->code = $response->getStatusCode();
		$this->body = json_decode((string)$response->getBody()); 
	}

	public function test_chunker() {
		$fname = self::$tdir . '/zapchunktest.txt';
		$str = '123456789abcdef';
		file_put_contents($fname, $str);

		$chunk0 = self::make_chunk($fname, 4, 0);
		$this->assertEquals($chunk0, '1234');

		$chunk1 = self::make_chunk($fname, 4, 2);
		$this->assertEquals($chunk1, '9abc');

		unlink($fname);
	}

	public function test_simple_upload() {

		$this->POST('/upload', ['size' => 2]);
		$this->assertEquals($this->code, 403);
		$this->assertEquals($this->body->errno,
			Err::EREQ);
		$this->assertEquals($this->body->data[0],
			Err::EREQ_NO_CHUNK);

		$str = (string)rand();
		$post = [
			'name' => 'testpost.dat',
			'size' => strlen($str),
			'blob' => [$str, 'testpost.dat'],
		];
		$this->POST('/upload', $post);
		$this->assertEquals($this->code, 403);
		$this->assertEquals($this->body->errno,
			Err::EREQ);
		$this->assertEquals($this->body->data[0],
			Err::EREQ_DATA_INCOMPLETE);

		$post['index'] = 0;
		$this->POST('/upload', $post);
		$this->assertEquals($this->code, 200);
		$this->assertEquals($this->body->data->path, 'testpost.dat');
		$this->assertEquals($this->body->data->index, 0);

		$this->download(['file' => 'testpost.dat']);
		$this->assertEquals($this->code, 200);
		$this->assertEquals($this->body, $str);
	}

	public function test_chunk_upload() {
		$files = self::file_list();
		self::upload_chunks($files[0][0], CHUNK_SIZE, function($post) {
			$this->POST('/upload', $post);
			$this->assertEquals($this->code, 200);
		});
		self::upload_chunks($files[1][0], CHUNK_SIZE, function($post) {
			$this->POST('/upload', $post);
			$this->assertEquals($this->code, 200);
		});
	}

	public function test_chunk_upload_oversized() {
		$files = self::file_list();
		self::upload_chunks($files[2][0], CHUNK_SIZE, function($post) {
			$this->POST('/upload', $post);
			if ($this->code !== 200) {
				$this->assertEquals($this->code, 403);
				$this->assertEquals($this->body->errno,
					Err::ECST);
				$this->assertEquals($this->body->data[0],
					Err::ECST_FSZ_OVERSIZED);
			}
		});
	}

	public function test_chunk_upload_index_invalid() {
		$files = self::file_list();
		self::upload_chunks($files[1][0], CHUNK_SIZE, function($post) {
			$post['index']++;
			$this->POST('/upload', $post);
			if ($this->code !== 200) {
				$this->assertEquals($this->code, 403);
				$this->assertEquals($this->body->errno,
					Err::ECST);
				$this->assertEquals($this->body->data[0],
					Err::ECST_MCH_UNORDERED);
			}
		});
		self::upload_chunks($files[1][0], CHUNK_SIZE, function($post) {
			if ($post['index'] == 3)
				$post['index'] = 1000;
			$this->POST('/upload', $post);
			if ($this->code !== 200) {
				$this->assertEquals($this->code, 403);
				$this->assertEquals($this->body->errno,
					Err::ECST);
				$this->assertTrue(in_array($this->body->data[0],
					[
						Err::ECST_CID_OVERSIZED,   # on packing
						Err::ECST_MCH_UNORDERED,   # on merging
					]
				));
			}
		});
	}

	public function test_chunk_upload_chunk_size_invalid() {
		# messed-up order due to broken chunk size sent
		$files = self::file_list();
		self::upload_chunks($files[1][0], CHUNK_SIZE - 1, function($post) {
			$this->POST('/upload', $post);
			if ($this->code !== 200) {
				$this->assertEquals($this->code, 403);
				$this->assertEquals($this->body->errno,
					Err::ECST);
				$this->assertEquals($this->body->data[0],
					Err::ECST_MCH_UNORDERED);
			}
		});
		self::upload_chunks($files[1][0], CHUNK_SIZE + 1, function($post) {
			$this->POST('/upload', $post);
			if ($this->code !== 200) {
				$this->assertEquals($this->code, 403);
				$this->assertEquals($this->body->errno, Err::ECST);
				$this->assertTrue(in_array($this->body->data[0],
					[
						Err::ECST_MCH_OVERSIZED,   # on packing
						Err::ECST_MCH_UNORDERED,   # on merging
					]
				));
			}
		});
		# broken chunk size received
		self::upload_chunks($files[1][0], CHUNK_SIZE, function($post) {
			$post['blob'][0] .= pack('v', 1);
			$this->POST('/upload', $post);
			if ($this->code !== 200) {
				$this->assertEquals($this->code, 403);
				$this->assertEquals($this->body->errno,
					Err::ECST);
				$this->assertEquals($this->body->data[0],
					Err::ECST_MCH_OVERSIZED);
			}
		});
	}

	public function test_fingerprint() {
		$files = self::file_list();
		# no fingerprint
		self::upload_chunks($files[1][0], CHUNK_SIZE, function($post) {
			$this->POST('/upload_pp', $post);
			$this->assertEquals($this->code, 403);
			$this->assertEquals($this->body->errno,
				Err::EREQ);
			$this->assertEquals($this->body->data[0],
				Err::EREQ_DATA_INCOMPLETE);
		});
		# fingerprint ok
		self::upload_chunks($files[1][0], CHUNK_SIZE, function($post) {
			$blob = $post['blob'][0];
			$post['fingerprint'] = hash('sha256', $blob);
			$this->POST('/upload_pp', $post);
			$this->assertEquals($this->code, 200);
		});
		# fingerprint bonkers
		self::upload_chunks($files[1][0], CHUNK_SIZE, function($post) {
			$blob = $post['blob'][0];
			$post['fingerprint'] = hash('sha256', $blob . ' ');
			$this->POST('/upload_pp', $post);
			if ($this->code !== 200) {
				$this->assertEquals($this->code, 403);
				$this->assertEquals($this->body->errno,
					Err::ECST);
				$this->assertEquals($this->body->data[0],
					Err::ECST_FGP_INVALID);
			}
		});
	}
}


