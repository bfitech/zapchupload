<?php


use BFITech\ZapCoreDev\TestCase;


define('CHUNK_SIZE', 1024 * 10);
define('MAX_FILESIZE', 1024 * 500);


/**
 * Test data generator and test wrappers.
 *
 * @requires OS Linux
 */
class ChunkUploadFixture extends TestCase {

	public static $udir;
	public static $logfile;

	protected $response;
	protected $code;
	protected $body;

	protected $pfx = '__chupload_';

	private static $flist = [];

	public static function file_list() {
		self::$udir = $udir = self::tdir(__FILE__);
		if (self::$flist)
			return self::$flist;
		return self::$flist = [
			# single chunk
			'single' => [
				$udir . '/zapchupload-test-1k.dat', 1],
			# within-range chunks
			'in-range' => [
				$udir . '/zapchupload-test-200k.dat', 200],
			# excessive chunks
			'excessive' => [
				$udir . '/zapchupload-test-520k.dat', 520],
			# rounded to 2 chunks
			'double-rounded' => [
				$udir . '/zapchupload-test-19k.dat', 19],
			# exactly 2 chunks
			'double-exact' => [
				$udir . '/zapchupload-test-20k.dat', 20],
		];
	}

	public static function file_name($key) {
		return self::file_list()[$key][0];
	}

	public static function generate_file($path, $size) {
		exec(
			"dd if=/dev/urandom of=$path " .
				"bs=1024 count=$size 2>/dev/null"
		);
	}

	/**
	 * Read chunk of a file.
	 */
	public static function make_chunk($file, $chunk_size, $index) {
		$hn = fopen($file, 'rb');
		$pos = $chunk_size * $index;
		fseek($hn, $pos);
		$chunk = fread($hn, $chunk_size);
		fclose($hn);
		return $chunk;
	}

	/**
	 * Simulate client-side uploading the whole chunks of a file.
	 *
	 * All payload is sent as _POST since client doesn't differ _POST
	 * and _FILES as PHP does.
	 *
	 * @param string $file Filename.
	 * @param int $size Chunk size.
	 * @param callable $callback What to do with the payload. Execute
	 *     RouterDev::request here.
	 * @param callable $tamper Method to tamper with the payload.
	 */
	public static function upload_chunks(
		$file, $chunk_size, $callback, $tamper=null
	) {
		$size = filesize($file);
		# base is used for 'name' AND simulating upload in 'blob'
		# that later is expanded to _FILE['tmp_name']; used with care
		$base = basename($file);
		$index = 0;
		while (true) {
			$chunk = self::make_chunk($file, $chunk_size, $index);
			if (!$chunk)
				break;
			$postfiles = [
				'index' => $index,
				'name' => $base,
				'size' => $size,
				'blob' => [$chunk, $base],
			];
			$index++;
			if (is_callable($tamper))
				$postfiles = $tamper($postfiles);
			$callback($postfiles);
		}
	}

	public function test_prefix() {
		$this->sm()($this->pfx, '__chupload_');
	}

}
