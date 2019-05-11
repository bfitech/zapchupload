<?php


namespace BFITech\ZapChupload;


use BFITech\ZapCore\Router;
use BFITech\ZapCore\Logger;


/**
 * ChunkUpload class.
 *
 * Example:
 * @code
 * <?php
 *
 * use BFITech\ZapCore\Router;
 * use BFITech\ZapCore\Logger;
 * use BFITech\ZapChupload\ChunkUpload;
 *
 * $logger = new Logger;
 * $core = (new Router)->config('logger', $logger);
 * $chup = new ChunkUpload(
 *     $core, '/tmp/tempdir', '/tmp/destdir',
 *     null, null, null, $logger);
 * $core->route('/upload', [$chup, 'upload'], 'POST']);
 * @endcode
 *
 * @see https://git.io/fjCho for sample client implementation using
 *     AngularJS.
 *
 * @cond
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @endcond
 *
 */
class ChunkUpload {

	/** Router instance. */
	public static $core = null;
	/** Logger instance. */
	public static $logger = null;

	// make sure these are the same with client
	private $post_prefix = '__chupload_';
	private $chunk_size = 1024 * 100;
	private $max_filesize = 1024 * 1024 * 10;

	private $tempdir = null;
	private $destdir = null;

	private $args = [];
	private $request = [];
	private $chunk_data = [];

	private $upload_error = false;

	/**
	 * Constructor.
	 *
	 * @param Router $core Router instance.
	 * @param string $tempdir Temporary upload directory.
	 * @param string $destdir Destination directory.
	 * @param string $post_prefix POST data prefix. Defaults to
	 *     '__chupload_'.
	 * @param int $chunk_size Chunk size, defaults to 2M. Make sure
	 *     this is below PHP `upload_max_filesize`.
	 * @param int $max_filesize Maximum filesize, defaults to 10M.
	 *     Not affected by `upload_max_filesize`.
	 * @param Logger $logger An instance of logging service.
	 *     If null, default logger with error log level and STDERR
	 *     are used.
	 */
	public function __construct(
		Router $core, string $tempdir, string $destdir,
		string $post_prefix=null, int $chunk_size=null,
		int $max_filesize=null, Logger $logger=null
	) {
		self::$core = $core;
		self::$logger = $logger = $logger ?? new Logger;

		if ($post_prefix)
			$this->post_prefix = $post_prefix;

		if ($chunk_size) {
			if ($chunk_size > $this->chunk_size) {
				$logger->warning(
					"Chupload: chunk size > 2M. Default 100k is used.");
			} elseif ($chunk_size < 1024) {
				$logger->warning(
					"Chupload: chunk size < 1k. Default 100k is used.");
			} else {
				$logger->debug(
					"Chupload: using chunk size: $chunk_size.");
				$this->chunk_size = $chunk_size;
			}
		}

		if ($max_filesize) {
			if ($max_filesize < $this->chunk_size) {
				$logger->warning(
					"Chupload: max filesize < chunk size. ".
					"Default 10M is used.");
			} else {
				$this->max_filesize = $max_filesize;
			}
		}

		$logger->debug(sprintf(
			"Chupload: using max filesize: %s.", $this->max_filesize));

		$this->prepare_dir($tempdir, $destdir);
	}

	/**
	 * Prepare temporary and destination directories.
	 */
	private function prepare_dir($tempdir, $destdir) {
		$log = self::$logger;

		if (!$tempdir || !$destdir) {
			$errmsg = sprintf('%s not set.',
				(!$tempdir ? 'Temporary' : 'Destination'));
			$log->error("Chupload: $errmsg");
			throw new ChunkUploadError($errmsg);
		}

		if ($tempdir == $destdir) {
			$errmsg = "Temporary and destination dirs mustn't " .
				"be the same.";
			$log->error("Chupload: $errmsg");
			throw new ChunkUploadError($errmsg);
		}

		foreach ([
			'tempdir' => $tempdir,
			'destdir' => $destdir,
		] as $dname => $dpath) {
			if (!is_dir($dpath) && !@mkdir($dpath, 0755)) {
				$errmsg = sprintf(
					"Cannot create %s directory: '%s.'",
					$dname, $dpath);
				$log->error("Chupload: $errmsg");
				throw new ChunkUploadError($errmsg);
			}
			$this->$dname = $dpath;
		}
	}

	/**
	 * Default basename generator.
	 *
	 * Basename sent by POST data under the key 'name' is used by
	 * default. Override this if you want to rename uploaded file
	 * according to certain rule.
	 *
	 * @return string File basename.
	 * @see ChunkUpload::get_request.
	 */
	protected function get_basename() {
		return basename($this->get_request()['name']);
	}

	/**
	 * Pre-processing stub. Executes on first chunk.
	 *
	 * Override this for pre-processing checks, e.g. when you want
	 * to determine whether upload must proceed after certain
	 * pre-conditions are met.
	 *
	 * @return bool True on success.
	 */
	protected function pre_processing() {
		return true;
	}

	/**
	 * Chunk processing. Executes on every chunk.
	 *
	 * Use this for chunk-specific processing, e.g. fingerprinting.
	 *
	 * @return bool True on success.
	 */
	protected function chunk_processing() {
		return true;
	}

	/**
	 * Post-processing stub. Executes on last chunk.
	 *
	 * Override this for fast post-processing such as pulling and/or
	 * stripping EXIF tags. For longer processing, use destdir
	 * and process from there to avoid script timeout, while keeping
	 * return of this method always true. Also useful if you want to
	 * integrate ChunkUpload as a part of generic zapcore routing
	 * handler.
	 *
	 * @return bool True on success.
	 *
	 */
	protected function post_processing() {
		return true;
	}

	/**
	 * Wrap JSON response.
	 *
	 * This is different from typical zapcore JSON response since it's
	 * related to upload end status. I/O or upload error will emit HTTP
	 * status 503.
	 */
	private function json(array $resp) {
		$Err = new ChunkUploadError;
		$errno = $resp[0];
		$data = isset($resp[1]) ? $resp[1] : null;

		$http_code = 200;
		if ($errno !== 0) {
			$http_code = 403;
			// @codeCoverageIgnoreStart
			if ($errno === $Err::EDIO || $this->upload_error)
				$http_code = 503;
			// @codeCoverageIgnoreEnd
		}
		if (!$this->intercept_response($errno, $data, $http_code))
			return;
		self::$core->print_json($errno, $data, $http_code);
	}

	/**
	 * JSON response interceptor.
	 *
	 * Override this if you want to stop uploader from printing
	 * out JSON response and halt. Useful when you're integrating
	 * ChunkUpload with other router. Parameters are ready to pass to
	 * Router::print_json().
	 *
	 * @param int $errno Response error number.
	 * @param array $data Response data. Typically null on failure.
	 * @param int $http_errno HTTP status code.
	 * @return bool If true, send JSON response and halt.
	 *
	 * @cond
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @endcond
	 */
	protected function intercept_response(
		int $errno, array $data=null, int $http_errno=200
	) {
		return true;
	}

	/**
	 * Safely unlink file.
	 *
	 * @codeCoverageIgnore
	 */
	private function unlink(string $file) {
		if (@unlink($file))
			return true;
		self::$logger->error(
			"Chupload: Cannot delete file: '$file'.");
		return false;
	}

	/**
	 * Verify request.
	 *
	 * On success, this sets ChunkUpload::request.
	 *
	 * @return int $errno 0 on success, certain number from class
	 *     constants on error.
	 */
	private function check_request() {
		$Err = new ChunkUploadError;
		$log = self::$logger;
		$args = $this->args;

		$post = $args['post'];
		$files = $args['files'];

		if (!$files || !isset($files[$this->post_prefix . 'blob'])) {
			# file field incomplete
			$log->warning("Chupload: chunk not received.");
			return $Err::EREQ_NO_CHUNK;
		}

		$keys = ['name', 'size', 'index'];
		$vals = [];
		$pfx = $this->post_prefix;

		foreach($keys as $key) {
			if (!isset($post[$pfx . $key])) {
				# post field incomplete
				$log->warning(
					"Chupload: '$key' form data not received.");
				return $Err::EREQ_DATA_INCOMPLETE;
			}
			$vals[$key] = $post[$pfx . $key];
		}

		$blob = $files[$pfx . 'blob'];
		if ($blob['error'] !== 0) {
			$this->upload_error = true;
			$log->error("Chupload: upload error: {$blob['error']}.");
			return $blob['error'];
		}
		$vals['blob'] = $blob;

		$this->request = $vals;
		return 0;
	}

	/**
	 * Verifiy new chunk out of Chupload::request.
	 *
	 * On success, this sets ChunkUpload::chunk_data.
	 *
	 * @return int $errno 0 on success, certain number from class
	 *     constants on error.
	 */
	private function check_constraints() {
		$Err = new ChunkUploadError;
		$logger = self::$logger;
		$request = $this->request;

		$size = $index = $blob = null;
		extract($request);

		$size = intval($size);
		if ($size < 1) {
			# size violation
			$logger->warning("Chupload: invalid filesize.");
			return $Err::ECST_FSZ_INVALID;
		}
		$index = intval($index);
		if ($index < 0) {
			# index undersized
			$logger->warning("Chupload: invalid chunk index.");
			return $Err::ECST_CID_UNDERSIZED;
		}
		if ($size > $this->max_filesize) {
			# max size violation
			$logger->warning("Chupload: max filesize violation.");
			return $Err::ECST_FSZ_OVERSIZED;
		}
		if ($index * $this->chunk_size > $this->max_filesize) {
			# index oversized
			$logger->warning("Chupload: chunk index violation.");
			return $Err::ECST_CID_OVERSIZED;
		}
		$chunk_path = $blob['tmp_name'];
		if (filesize($chunk_path) > $this->chunk_size) {
			# chunk oversized
			$logger->warning("Chupload: invalid chunk index.");
			$this->unlink($chunk_path);
			return $Err::ECST_MCH_OVERSIZED;
		}

		$max_chunk_float = $size / $this->chunk_size;
		$max_chunk = floor($max_chunk_float);
		if ($max_chunk_float == $max_chunk)
			$max_chunk--;
		$max_chunk = intval($max_chunk);

		$chunk = file_get_contents($chunk_path);

		$basename = $this->get_basename();
		$tempname = $this->tempdir . '/' . $basename;
		$destname = $this->destdir . '/' . $basename;

		# always overwrite old file with the same name, hence
		# make sure get_basename() guarantee uniqueness
		// @codeCoverageIgnoreStart
		if (file_exists($destname) && !$this->unlink($destname))
			return $Err::EDIO;
		// @codeCoverageIgnoreEnd

		$this->chunk_data = [
			'size' => $size,
			'index' => $index,
			'chunk_path' => $chunk_path,
			'max_chunk' => $max_chunk,
			'chunk' => $chunk,
			'basename' => $basename,
			'tempname' => $tempname,
			'destname' => $destname,
		];

		return 0;
	}

	/**
	 * Append new chunk to packed chunks.
	 *
	 * Unless it's on latest chunk, there's always unsigned short
	 * integer appended at the end, representing index. The only
	 * failure is due to I/O error.
	 *
	 * @return int $errno 0 on success, certain number from class
	 *     constants on error.
	 */
	private function pack_chunk() {
		$Err = new ChunkUploadError;

		$index = $chunk = $chunk_path = null;
		extract($this->chunk_data);

		# truncate or append
		$fhn = @fopen($tempname, ($index === 0 ? 'wb' : 'ab'));
		if (false === $fhn) {
			// @codeCoverageIgnoreStart
			self::$logger->error(
				"Chupload: cannot open temp file: '$tempname'.");
			return $Err::EDIO;
			// @codeCoverageIgnoreEnd
		}
		# write to temp blob
		fwrite($fhn, $chunk);
		# append index
		if ($index < $max_chunk && $max_chunk >= 1)
			fwrite($fhn, pack('v', $index));
		fclose($fhn);
		# remove chunk
		$this->unlink($chunk_path);

		return 0;
	}

	/**
	 * Merge packed chunks to destination.
	 *
	 * @return int $errno 0 on success, certain number from class
	 *     constants on error.
	 */
	private function merge_chunks() {
		$Err = new ChunkUploadError;
		$tempname = $destname = $max_chunk = null;
		extract($this->chunk_data);
		if (
			false === ($fhi = @fopen($tempname, 'rb')) ||
			false === ($fho = @fopen($destname, 'wb'))
		) {
			// @codeCoverageIgnoreStart
			$errmsg = sprintf(
				"Cannot open files for merging: '%s' -> '%s'.",
				$tempname, $destname);
			self::$logger->error("Chupload: $errmsg");
			return $Err::EDIO;
			// @codeCoverageIgnoreEnd
		}
		if ($max_chunk === 0) {
			# single and last chunk, no tail
			$chunk = fread($fhi, $this->chunk_size);
			fwrite($fho, $chunk);
			return 0;
		}

		# head chunks, with tail
		$total = 0;
		for ($i=0; $i<$max_chunk; $i++) {
			$chunk = fread($fhi, $this->chunk_size);
			$total += $this->chunk_size;
			fwrite($fho, $chunk);
			$tail = fread($fhi, 2);
			$i_unpack = unpack('vint', $tail)['int'];
			if ($i !== $i_unpack)
				return $Err::ECST_MCH_UNORDERED;
		}

		# last chunk, no tail
		$chunk = fread($fhi, $this->chunk_size);
		fwrite($fho, $chunk);
		return 0;
	}

	/**
	 * Finalize merging and execute post-processor.
	 *
	 * @return int $errno 0 on success, certain number from class
	 *     constants on error.
	 */
	private function finalize() {
		$log = self::$logger;
		$tempname = $destname = null;
		extract($this->chunk_data);

		$merge_status = $this->merge_chunks();
		$this->unlink($tempname);
		if ($merge_status !== 0) {
			$this->unlink($destname);
			$log->warning("Chupload: broken chunk.");
			return $merge_status;
		}

		if (!$this->post_processing()) {
			if (file_exists($destname))
				$this->unlink($destname);
			$log->error("Chupload: post-processing failed.");
			return ChunkUploadError::ECST_POSTPROC_FAIL;
		}
		return 0;
	}

	/**
	 * Uploader.
	 *
	 * @param dict $args Router arguments.
	 */
	public function upload(array $args) {
		$Err = new ChunkUploadError;
		$this->args = $args;

		# check request
		if (0 !== $check = $this->check_request())
			return $this->json([$check]);

		# check constraints
		if (0 !== $check = $this->check_constraints())
			return $this->json([$check]);

		# pack chunk
		if (0 !== $check = $this->pack_chunk())
			// @codeCoverageIgnoreStart
			return $this->json([$check]);
			// @codeCoverageIgnoreEnd

		$basename = $index = $max_chunk = null;
		extract($this->chunk_data);

		// pre-processing
		if ($index === 0 && !$this->pre_processing())
			return $this->json([$Err::ECST_PREPROC_FAIL]);

		// chunk processing
		if (!$this->chunk_processing())
			return $this->json([$Err::ECST_CHUNKPROC_FAIL]);

		// merge chunks on finish and do post-processing
		if ($max_chunk === $index && 0 !== $check = $this->finalize())
			return $this->json([$check]);

		// success
		self::$logger->info(
			"Chupload: file successfully uploaded: '$basename'.");
		return $this->json([0, [
			'path' => $basename,
			'index'  => $index,
		]]);
	}

	/* getters */

	/**
	 * Retrieve router args.
	 *
	 * @return array Dict of standard arguments accepted by
	 *     Router::route callback.
	 */
	public function get_args() {
		if (!$this->args)
			throw new ChunkUploadError("Uploader not initialized.");
		return $this->args;
	}

	/**
	 * Retrieve request.
	 *
	 * @return array Dict of request data with keys:
	 *     - `(string)name`, filename
	 *     - `(int)size`, filesize
	 *     - `(int)index`, chunk index
	 *     - `(int)error`, builtin upload error code, UPLOAD_ERR_OK
	 *       on success
	 *     - `(string)blob`, uploaded chunk in string
	 * @see https://archive.fo/x0nXY
	 */
	public function get_request() {
		if (!$this->request)
			throw new ChunkUploadError("Request not initialized.");
		return $this->request;
	}

	/**
	 * Retrieve chunk data.
	 *
	 * @return array Dict of chunk data with keys:
	 *     - `(int)size`, total filesize
	 *     - `(int)index`, chunk index
	 *     - `(string)chunk_path`, chunk absolute path
	 *     - `(int)max_chunk`, max chunk
	 *     - `(string)chunk`, chunk blob as string
	 *     - `(string)basename`, file basename
	 *     - `(string)tempname`, file absolute tempname
	 *     - `(string)destname`, file absolute destination
	 */
	public function get_chunk_data() {
		if (!$this->chunk_data)
			throw new ChunkUploadError("Chunk data not set.");
		return $this->chunk_data;
	}

	/**
	 * Retrieve private post_prefix property.
	 */
	public function get_post_prefix() {
		return $this->post_prefix;
	}

	/**
	 * Retrieve private chunk_size property.
	 */
	public function get_chunk_size() {
		return $this->chunk_size;
	}

	/**
	 * Retrieve private max_filesize property.
	 */
	public function get_max_filesize() {
		return $this->max_filesize;
	}

}
