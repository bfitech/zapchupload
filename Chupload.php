<?php


namespace BFITech\ZapChupload;

class ChunkUploadError extends \Exception {}

class ChunkUpload {

	public static $core = null;

	// make sure these are the same with client
	private $post_prefix = '__chupload_';
	private $chunk_size = 1024 * 1024 * 100;
	private $max_filesize = 1024 * 1024 * 1024 * 10;

	private $with_fingerprint = false;

	private $tempdir = null;
	private $destdir = null;

	public function __construct(
		$core, $tempdir, $destdir,
		$post_prefix=null, $chunk_size=null, $max_filesize=null,
		$with_fingerprint=false
	) {
		self::$core = $core;

		if ($post_prefix !== null)
			$this->post_prefix = (string)$post_prefix;
		$this->with_fingerprint = (bool)$with_fingerprint;
		if ($chunk_size) {
			$chunk_size = (int)$chunk_size;
			if ($chunk_size > 1024 * 1024 * 2)
				$chunk_size = null;
			if ($chunk_size < 1024)
				$chunk_size = null;
		}
		if ($chunk_size)
			$this->chunk_size = $chunk_size;

		if ($max_filesize) {
			$max_filesize = (int)$max_filesize;
			$this->max_filesize = $max_filesize;
		}

		if (!$tempdir || !$destdir)
			throw new ChunkUploadError("Directories not set.");

		if ($tempdir == $destdir)
			throw new ChunkUploadError(
				"Temporary and destination directories shan't be the same.");

		if (!is_dir($tempdir)) {
			if (!@mkdir($tempdir, 0755))
				throw new ChunkUploadError(sprintf(
					"Cannot create temporary directory: '%s.'",
					$tempdir));
		}
		$this->tempdir = $tempdir;

		if (!is_dir($destdir)) {
			if (!@mkdir($destdir, 0755))
				throw new ChunkUploadError(sprintf(
					"Cannot create destination directory: '%s.'",
					$destdir));
		}
		$this->destdir = $destdir;
	}

	protected function check_fingerprint($fingerprint, $chunk_received) {
		// patch this
		return true;
	}

	protected function get_basename($path) {
		// patch this
		return basename($path);
	}

	/**
	 * Post-processing.
	 *
	 * Use this for fast post-processing such as pulling and/or
	 * stripping EXIF tags. For longer processing, hide destdir
	 * and process from there to avoid script timeout.
	 *
	 * @param string $path Path to destination path.
	 * @return bool|string $path False for failed post-processing,
	 *     new destination path otherwise, which can be the same
	 *     with input path. Changing path must be wrapped inside
	 *     this method.
	 */
	protected function post_processing($path) {
		// patch this
		return $path;
	}

	/**
	 * Print JSON.
	 *
	 * This is different from typical core JSON since it's
	 * related to upload end status.
	 *
	 * @param int $errno Error number.
	 * @param array $data Data.
	 */
	public static function json($errno, $data) {
		if ($errno == 2 || $errno == 3)
			# request error, constraint violation
			$http_code = 403;
		elseif ($errno == 4 || $errno == 5)
			# upload error, I/O error
			$http_code = 503;
		else
			$http_code = 200;
		return self::$core->print_json($errno, $data, $http_code);
	}

	private function unlink($file) {
		if (!@unlink($file))
			throw new ChunkUploadError(sprintf(
				"Cannot delete '%s'.", $file));
	}

	public function upload($args) {

		$post = $args['post'];
		$files = $args['files'];

		if (!$files || !isset($files[$this->post_prefix . 'blob']))
			# file field incomplete
			return self::json(2, [0]);

		$keys = ['name', 'size', 'index'];
		if ($this->with_fingerprint)
			$keys[] = 'fingerprint';
		$vals = [];
		$pfx = $this->post_prefix;

		foreach($keys as $key) {
			if (!isset($post[$pfx . $key]))
				# post field incomplete
				return self::json(2, [1]);
			$vals[$key] = $post[$pfx . $key];
		}
		extract($vals, EXTR_SKIP);

		$size = intval($size);
		if ($size < 1)
			# size violation
			return self::json(3, [0]);
		$index = intval($index);
		if ($index < 0)
			# index undersized
			return self::json(3, [1]);
		$max_chunk = floor($size / $this->chunk_size);

		if ($size > $this->max_filesize)
			# max size violation
			return self::json(3, [2]);
		if ($index * $this->chunk_size > $this->max_filesize)
			# index oversized
			return self::json(3, [3]);

		$blob = $args['files'][$pfx . 'blob'];
		if ($blob['error'] !== 0)
			# upload error
			return self::json(4, [0]);

		$chunk_path = $blob['tmp_name'];
		if (filesize($chunk_path) > $this->chunk_size) {
			# chunk oversized
			$this->unlink($chunk_path);
			return self::json(3, [4]);
		}

		$chunk = file_get_contents($chunk_path);

		$basename = $this->get_basename($name);
		$tempname = $this->tempdir . '/' . $basename;
		$destname = $this->destdir . '/' . $basename;

		if (file_exists($destname)) {
			# always overwrite old file with the same name, hence
			# make sure get_basename() guarantee uniqueness
			$this->unlink($destname);
		}

		# truncate or append
		if (false === $fn = @fopen(
			$tempname, ($index === 0 ? 'wb' : 'ab'))
		)
			throw new ChunkUploadError("Cannot open '$tempname'.");
		fwrite($fn, $chunk);
		# append index
		if ($index < $max_chunk)
			fwrite($fn, pack('v', $index));
		fclose($fn);
		# remove chunk
		$this->unlink($chunk_path);

		if (
			$this->with_fingerprint &&
			!$this->check_fingerprint($fingerprint, $chunk)
		) {
			# fingerprint violation
			$this->unlink($tempname);
			return self::json(3, [5]);
		}

		// check size on finish
		if ($max_chunk == $index) {
			$merge_status = $this->merge_chunks(
				$tempname, $destname, $max_chunk);
			if ($merge_status !== 0) {
				# typically already caught by [3, [4]]
				$this->unlink($destname);
				$this->unlink($tempname);
				if ($merge_status == 2)
					# max size violation
					return self::json(3, [6]);
				# index order error
				return self::json(3, [7]);
			}
			$this->unlink($tempname);
			$destname = $this->post_processing($destname);
			if (!$destname)
				# TODO: Test this.
				return self::json(3, [8]);
		}

		return self::$core->print_json(0, [
			'path' => $basename,
			'index'  => $index,
		]);
	}

	private function merge_chunks($tempname, $destname, $max_chunk) {
		$hi = fopen($tempname, 'rb');
		if (false === $ho = @fopen($destname, 'wb'))
			throw new ChunkUploadError("Cannot open '$destname'.");
		$total = 0;
		if ($max_chunk == 0) {
			$chunk = fread($hi, $this->chunk_size);
			if (strlen($chunk) > $this->max_filesize)
				return 2;
			fwrite($ho, $chunk);
			return 0;
		}
		for ($i=0; $i<$max_chunk; $i++) {
			$chunk = fread($hi, $this->chunk_size);
			$total += strlen($chunk);
			if ($total > $this->max_filesize)
				return 2;
			fwrite($ho, $chunk);
			$i_unpack = unpack('vint', fread($hi, 2))['int'];
			if ($i !== $i_unpack)
				return 1;
		}
		$chunk = fread($hi, $this->chunk_size);
		fwrite($ho, $chunk);
		return 0;
	}

	public function get_post_prefix() {
		return $this->post_prefix;
	}

	public function get_chunk_size() {
		return $this->chunk_size;
	}

	public function get_max_filesize() {
		return $this->max_filesize;
	}

}

