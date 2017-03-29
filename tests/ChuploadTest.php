<?php


use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use BFITech\ZapCoreDev;


define('CHUNK_SIZE', 1024 * 10);
define('MAX_FILESIZE', 1024 * 500);

class ChunkUploadTest extends TestCase {

	public static $tdir = '/mnt/ramdisk';

	public static $server_pid = null;

	private $response;
	private $code;
	private $body;

	private $pfx = '__chupload_';

	public static function file_list() {
		$tdir = self::$tdir;
		return [
			[$tdir . '/zapchupload-test-1k.dat', 1],
			[$tdir . '/zapchupload-test-200k.dat', 200],
			[$tdir . '/zapchupload-test-520k.dat', 520],
		];
	}

	public static function generate_file($path, $size) {
		exec("dd if=/dev/urandom of=$path bs=1024 count=$size 2>/dev/null");
	}

	public static function make_chunk($file, $chunk_size, $index) {
		$hn = fopen($file, 'rb');
		$pos = $chunk_size * $index;
		fseek($hn, $pos);
		$chunk = fread($hn, $chunk_size);
		fclose($hn);
		return $chunk;
	}

	public static function upload_chunks($file, $chunk_size, $callback) {
		$size = filesize($file);
		$max_chunk = floor($size / $chunk_size);
		$index = 0;
		while (true) {
			$chunk = self::make_chunk($file, $chunk_size, $index);
			if (!$chunk)
				break;
			$post = [
				['name', basename($file)],
				['size', $size],
				['blob', $chunk , basename($file)],
				['index', $index]
			];
			$index++;
			$callback($post);
		}
	}

	public static function setUpBeforeClass() {
		self::$server_pid = ZapCoreDev\CoreDev::server_up(
			__DIR__ . '/htdocs-test');
		foreach (self::file_list() as $file) {
			if (!file_exists($file[0]))
				self::generate_file($file[0], $file[1]);
		}
	}

	public static function tearDownAfterClass() {
		#foreach (self::file_list() as $file)
		#	unlink($file[0]);
		ZapCoreDev\CoreDev::server_down(self::$server_pid);
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
		foreach ($data as $d) {
			$part = [
				'name' => $this->pfx . $d[0],
				'contents' => $d[1],
			];
			if (isset($d[2]))
				$part['filename'] = $d[2];
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
		$this->assertEquals($this->body->errno, 2);
		$this->assertEquals($this->body->data[0], 0);

		$str = (string)rand();
		$post = [
			['name', 'testpost.dat'],
			['size', strlen($str)],
			['blob', $str, 'testpost.dat'],
		];
		$this->POST('/upload', $post);
		$this->assertEquals($this->code, 403);
		$this->assertEquals($this->body->errno, 2);
		$this->assertEquals($this->body->data[0], 1);

		$post[] = ['index', 0];
		$this->POST('/upload', $post);
		$this->assertEquals($this->code, 200);
		$this->assertEquals($this->body->errno, 0);
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
				$this->assertEquals($this->body->errno, 3);
				$this->assertEquals($this->body->data[0], 2);
			}
		});
	}

	public function test_chunk_upload_index_invalid() {
		$files = self::file_list();
		self::upload_chunks($files[1][0], CHUNK_SIZE, function($post) {
			$post = array_map(function($p){
				if ($p[0] == 'index') {
					$p[1] += 1;
				}
				return $p;
			}, $post);
			$this->POST('/upload', $post);
			if ($this->code !== 200) {
				$this->assertEquals($this->code, 403);
				$this->assertEquals($this->body->errno, 3);
				$this->assertEquals($this->body->data[0], 5);
			}
		});
		self::upload_chunks($files[1][0], CHUNK_SIZE, function($post) {
			$post = array_map(function($p){
				if ($p[0] == 'index' && $p[1] == 3) {
					$p[1] = 1000;
				}
				return $p;
			}, $post);
			$this->POST('/upload', $post);
			if ($this->code !== 200) {
				$this->assertEquals($this->code, 403);
				$this->assertEquals($this->body->errno, 3);
				$this->assertEquals($this->body->data[0], 3);
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
				$this->assertEquals($this->body->errno, 3);
				$this->assertEquals($this->body->data[0], 7);
			}
		});
		self::upload_chunks($files[1][0], CHUNK_SIZE + 1, function($post) {
			$this->POST('/upload', $post);
			if ($this->code !== 200) {
				$this->assertEquals($this->code, 403);
				$this->assertEquals($this->body->errno, 3);
				$this->assertEquals($this->body->data[0], 4);
			}
		});
		# broken chunk size received
		self::upload_chunks($files[1][0], CHUNK_SIZE, function($post) {
			$post[2][1] .= pack('v', 1);
			$this->POST('/upload', $post);
			if ($this->code !== 200) {
				$this->assertEquals($this->code, 403);
				$this->assertEquals($this->body->errno, 3);
				$this->assertEquals($this->body->data[0], 4);
			}
		});
	}

	public function test_fingerprint() {
		$files = self::file_list();
		# no fingerprint
		self::upload_chunks($files[1][0], CHUNK_SIZE, function($post) {
			$this->POST('/upload_pp', $post);
			$this->assertEquals($this->code, 403);
			$this->assertEquals($this->body->errno, 2);
			$this->assertEquals($this->body->data[0], 1);
		});
		# fingerprint ok
		self::upload_chunks($files[1][0], CHUNK_SIZE, function($post) {
			$blob = $post[2][1];
			$post[] = ['fingerprint', hash('sha256', $blob)];
			$this->POST('/upload_pp', $post);
			$this->assertEquals($this->code, 200);
		});
		# fingerprint bonkers
		self::upload_chunks($files[1][0], CHUNK_SIZE, function($post) {
			$blob = $post[2][1];
			$post[] = ['fingerprint', hash('sha256', $blob . ' ')];
			$this->POST('/upload_pp', $post);
			if ($this->code !== 200) {
				$this->assertEquals($this->code, 403);
				$this->assertEquals($this->body->errno, 3);
				$this->assertEquals($this->body->data[0], 5);
			}
		});
	}
}


