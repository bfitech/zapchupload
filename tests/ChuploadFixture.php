<?php


use PHPUnit\Framework\TestCase;


define('CHUNK_SIZE', 1024 * 10);
define('MAX_FILESIZE', 1024 * 500);

class ChunkUploadFixture extends TestCase {

	public static $tdir;
	public static $server_pid = null;
	public static $logfile;

	protected $response;
	protected $code;
	protected $body;

	protected $pfx = '__chupload_';

	public static function file_list() {
		$tdir = getenv('CHUPLOAD_TESTDIR');
		if (!$tdir || !is_dir($tdir))
			$tdir = __DIR__ . '/htdocs-test/uploads';
		if (!is_dir($tdir))
			mkdir($tdir);
		self::$tdir = $tdir;
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
		$index = 0;
		while (true) {
			$chunk = self::make_chunk($file, $chunk_size, $index);
			if (!$chunk)
				break;
			$post = [
				'index' => $index,
				'name' => basename($file),
				'size' => $size,
				'blob' => [$chunk , basename($file)],
			];
			$index++;
			$callback($post);
		}
	}

	public function test_prefix() {
		$this->assertSame($this->pfx, '__chupload_');
	}

}
