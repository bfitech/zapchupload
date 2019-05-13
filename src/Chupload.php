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
 */
class ChunkUpload {

	/**
	 * Default chunk size.
	 *
	 * On overriding, exception will be thrown if this condition are
	 * not met:<br>
	 * > ChunkUpload::CHUNK_SIZE_MIN <
	 * > ChunkUpload::CHUNK_SIZE_DEFAULT <
	 * > ChunkUpload::CHUNK_SIZE_MAX
	 */
	const CHUNK_SIZE_DEFAULT = 1024 * 100;
	/**
	 * Minimum chunk size.
	 *
	 * If this is too small, network overhead will slow down the
	 * upload tremendously.
	 **/
	const CHUNK_SIZE_MIN = 1024 * 10;
	/**
	 * Maximum chunk size.
	 *
	 * If this is too big, it will defeat the purpose of chunk
	 * uploading and most likely will hit `upload_max_size` on typical
	 * PHP setup.
	 **/
	const CHUNK_SIZE_MAX = 1024 * 1024 * 20;

	/** Router instance. */
	public static $core = null;
	/** Logger instance. */
	public static $logger = null;

	// Use ChunkUpload::get_config to retrieve values of these.
	/** Default _POST prefix. */
	private $post_prefix = '__chupload_';
	/** Default chunk size. */
	private $chunk_size = self::CHUNK_SIZE_DEFAULT;
	/** Default max filesize. */
	private $max_filesize = self::CHUNK_SIZE_MAX;

	/** Initial temporary directory. */
	private $tempdir = null;
	/** Initial destination directory. */
	private $destdir = null;

	/** Collected HTTP variables. */
	private $args = [];
	/** Verified request. */
	private $request = [];
	/** Verified chunk data. */
	private $chunk_data = [];

	/** Internal indicator that there has been upload error. */
	private $upload_error = false;

	/**
	 * Constructor.
	 *
	 * @param Router $core Router instance. Use
	 *     BFITech\\ZapCoreDev\\RouterDev instance for testing.
	 * @param string $tempdir Temporary upload directory.
	 * @param string $destdir Destination directory.
	 * @param string $post_prefix \_POST data prefix. Defaults to
	 *     '__chupload_'.
	 * @param int $chunk_size Chunk size. Defaults to
	 *     ChunkUpload::CHUNK_SIZE_DEFAULT. Make sure
	 *     this is below PHP `upload_max_filesize`.
	 * @param int $max_filesize Maximum filesize. Defaults to
	 *     ChunkUpload::CHUNK_SIZE_MAX. Not affected by
	 *     `upload_max_filesize`.
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
		self::$logger = $logger ?? new Logger;

		$this->prepare_prefix($post_prefix);
		$this->prepare_sizes($chunk_size, $max_filesize);
		$this->prepare_dir($tempdir, $destdir);
	}

	/**
	 * Exception and error logger wrapper.
	 */
	private function throw_error(int $code, string $message) {
		self::$logger->error("Chupload: $message");
		throw new ChunkUploadError($code, $message);
	}

	/**
	 * Verify _POST data prefix.
	 */
	private function prepare_prefix(string $post_prefix=null) {
		$log = self::$logger;
		if (!$post_prefix) {
			$log->debug(sprintf(
				"Chupload: using post prefix: '%s'.",
				$this->post_prefix));
			return;
		}
		$pattern = '/^[a-z_]([a-z0-9_\-]+)?$/i';
		if (!preg_match($pattern, $post_prefix)) {
			$this->throw_error(
				ChunkUploadError::EINI_PREFIX_INVALID,
				"Prefix must satisfy regex: '$pattern'.");
		}
		$log->debug(
			"Chupload: using post prefix: '$post_prefix'.");
		$this->post_prefix = $post_prefix;
	}

	/**
	 * Verify chunk size and max filesize.
	 */
	private function prepare_sizes(
		int $chunk_size=null, int $max_filesize=null
	) {
		$Err = new ChunkUploadError;
		$log = self::$logger;

		$min = static::CHUNK_SIZE_MIN;
		$def = static::CHUNK_SIZE_DEFAULT;
		$max = static::CHUNK_SIZE_MAX;

		if (!($min < $def && $def < $max))
			$this->throw_error($Err::EINI_CONST_INVALID,
				"Invalid chunk parameter overrides.");

		if ($chunk_size) {
			if ($chunk_size < $min) {
				$msg = sprintf(
					"Chunk size too small: %d < %d.",
					$chunk_size, $min);
				$this->throw_error($Err::EINI_CHUNK_TOO_SMALL, $msg);
			}
			if ($chunk_size > $max) {
				$msg = sprintf(
					"Chunk size too big: %d > %d.",
					$chunk_size, $max);
				$this->throw_error($Err::EINI_CHUNK_TOO_BIG, $msg);
			}
			$log->debug(
				"Chupload: using chunk size: $chunk_size.");
			$this->chunk_size = $chunk_size;
		}

		if ($max_filesize) {
			if ($max_filesize < $this->chunk_size) {
				$msg = sprintf(
					"Chupload max filesize too small: %d < %d. ",
					$max_filesize, $this->chunk_size);
				$this->throw_error(
					$Err::EINI_MAX_FILESIZE_TOO_SMALL, $msg);
			}
			$log->debug(
				"Chupload: using chunk size: $chunk_size.");
			$this->max_filesize = $max_filesize;
		}

	}

	/**
	 * Prepare temporary and destination directories.
	 */
	private function prepare_dir(
		string $tempdir=null, string $destdir=null
	) {
		$Err = new ChunkUploadError;

		if (!$tempdir || !$destdir) {
			$msg = sprintf('%s directory not set.',
				(!$tempdir ? 'Temporary' : 'Destination'));
			$this->throw_error($Err::EINI_DIRS_NOT_SET, $msg);
		}

		if ($tempdir == $destdir) {
			$msg = "Temporary and destination dirs mustn't " .
				"be the same.";
			$this->throw_error($Err::EINI_DIRS_IDENTICAL, $msg);
		}

		foreach ([
			'tempdir' => $tempdir,
			'destdir' => $destdir,
		] as $dname => $dpath) {
			if (!is_dir($dpath) && !@mkdir($dpath, 0755)) {
				$msg = sprintf(
					"Cannot create %s directory: '%s.'",
					$dname, $dpath);
				$this->throw_error($Err::EINI_DIRS_NOT_CREATED, $msg);
			}
			$this->$dname = $dpath;
		}

		self::$logger->debug(sprintf(
			"Chupload: using tempdir: '%s', destdir: '%s'.",
			$tempdir, $destdir));
	}

	/**
	 * Default basename generator.
	 *
	 * Basename sent by `_POST` data under the key 'name' is used.
	 * Override this if you want to rename uploaded file according to
	 * certain rule.
	 *
	 * Due to its simplicity, this default implementation may
	 * cause unordered packed chunks in the case of simultaneous
	 * uploads of the same basename. One way to prevent
	 * this is by using a shortlived random cookie for each request
	 * and use ChunkUpload::post_processing to rename the file.
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
	 * integrate ChunkUpload as a part of generic Router::route
	 * callback.
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
			if (
				$this->upload_error ||
				in_array($errno, [
					$Err::EDIO_DELETE_FAIL,
					$Err::EDIO_MERGE_FAIL,
					$Err::EDIO_WRITE_FAIL,
				])
			)
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
	 * Router::print_json.
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
	 * @return int $errno 0 on success, certain errno otherwise.
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
	 * @return int $errno 0 on success, certain errno otherwise.
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

		# always overwrite old file with the same destname, hence
		# make sure get_basename() guarantee uniqueness
		// @codeCoverageIgnoreStart
		if (file_exists($destname) && !$this->unlink($destname))
			return $Err::EDIO_DELETE_FAIL;
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
	 * @return int $errno 0 on success, certain errno otherwise.
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
			return $Err::EDIO_WRITE_FAIL;
			// @codeCoverageIgnoreEnd
		}
		if (!flock($fhn, LOCK_EX | LOCK_NB)) {
			// @codeCoverageIgnoreStart
			self::$logger->error(
				"Chupload: temporary file busy: '$tempname'.");
			return $Err::EDIO_BUSY;
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
	 * Palallel uploads with the same destname cause unordered chunks.
	 * Try it with `chupload-client.py` provided by the tutorial:
	 *
	 * @code
	 * $ parallel -N0 ./chupload-client.py file.dat ::: 1 2
	 * @endcode
	 *
	 * @return int $errno 0 on success, certain errno otherwise.
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
			return $Err::EDIO_MERGE_FAIL;
			// @codeCoverageIgnoreEnd
		}
		if (
			!flock($fhi, LOCK_SH) ||
			!flock($fho, LOCK_EX | LOCK_NB)
		) {
			// @codeCoverageIgnoreStart
			$errmsg = sprintf(
				"Resource busy when merging: '%s' -> '%s'.",
				$tempname, $destname);
			self::$logger->error("Chupload: $errmsg");
			return $Err::EDIO_BUSY;
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
			// @codeCoverageIgnoreStart
			# missin tail, may happen on parallel uploads with the same
			# destname
			if (strlen($tail) < 2)
				return $Err::ECST_MCH_UNORDERED;
			// @codeCoverageIgnoreEnd
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
	 * @return int $errno 0 on success, certain errno otherwise.
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
	 * Do not call this outside Router::route context. See class example
	 * for usage.
	 *
	 * Unless there is an intercept by ChunkUpload::intercept_response,
	 * this will always send JSON dict response with keys: `errno` and
	 * `data`. `errno` is non-zero integer and `data` is null on
	 * failure. On success, `errno` is always zero and `data` is a dict
	 * with keys:
	 *     - `(int)index`: successfully-uploaded chunk index
	 *     - `(string)path`: basename after processed by
	 *       ChunkUpload::get_basename
	 *     - `(bool)done`: false on mid-processing, true on last chunk
	 *
	 * @param dict $args Router::route callback arguments of \_POST
	 *     request with these prefixed keys:
	 *     - `(dict)post`
	 *       - `(int)index`: chunk index starting from 0 at
	 *                       first chunk
	 *       - `(int)size`: total filesize
	 *       - `(string)name`: file basename
	 *     - `(dict)files`
	 *       - `(string)blob`: chunk data as string
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

		$resp = [
			'index'  => $index,
			'path' => $basename,
			'done' => false,
		];
		if ($max_chunk === $index) {
			// merge chunks on finish and do post-processing
			if (0 !== $check = $this->finalize())
				return $this->json([$check]);
			$resp['done'] = true;
		}

		// success
		self::$logger->info(
			"Chupload: file successfully uploaded: '$basename'.");
		return $this->json([0, $resp]);
	}

	/* getters */

	/**
	 * Retrieve router args.
	 *
	 * @return array Dict of standard arguments accepted by
	 *     Router::route callback.
	 * @see ChunkUpload::upload.
	 */
	public function get_args() {
		if (!$this->args)
			throw new ChunkUploadError(
				ChunkUploadError::EINI_PROPERTY_EMPTY,
				"Uploader not initialized.");
		return $this->args;
	}

	/**
	 * Retrieve request.
	 *
	 * @return array Dict of request data with keys:
	 *     - `(string)name`: filename
	 *     - `(int)size`: filesize
	 *     - `(int)index`: chunk index
	 *     - `(int)error`: builtin upload error code, `UPLOAD_ERR_OK`
	 *       on success
	 *     - `(string)blob`: uploaded chunk in string
	 * @see https://archive.fo/x0nXY
	 */
	public function get_request() {
		if (!$this->request)
			throw new ChunkUploadError(
				ChunkUploadError::EINI_PROPERTY_EMPTY,
				"Request not initialized.");
		return $this->request;
	}

	/**
	 * Retrieve chunk data.
	 *
	 * @return array Dict of chunk data with keys:
	 *     - `(int)size`: total filesize
	 *     - `(int)index`: chunk index
	 *     - `(string)chunk_path`: chunk absolute path
	 *     - `(int)max_chunk`: max chunk
	 *     - `(string)chunk`: chunk blob as string
	 *     - `(string)basename`: file basename
	 *     - `(string)tempname`: file absolute tempname
	 *     - `(string)destname`: file absolute destination
	 */
	public function get_chunk_data() {
		if (!$this->chunk_data)
			throw new ChunkUploadError(
				ChunkUploadError::EINI_PROPERTY_EMPTY,
				"Chunk data not set.");
		return $this->chunk_data;
	}

	/**
	 * Retrieve verified properties.
	 *
	 * Very useful when you want to set up client parameters
	 * programmatically.
	 *
	 * @return array Dict containing keys:
	 *     - `(string)post_prefix`: verified \_POST data prefix
	 *     - `(int)chunk_size`: verified cunk size
	 *     - `(int)max_filesize`: verified max filesize
	 */
	public function get_config() {
		return [
			'post_prefix' => $this->post_prefix,
			'chunk_size' => $this->chunk_size,
			'max_filesize' => $this->max_filesize,
		];
	}

}
