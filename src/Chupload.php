<?php


namespace BFITech\ZapChupload;

use BFITech\ZapCore\Router;
use BFITech\ZapCore\Logger;


/**
 * Chupload class.
 */
class ChunkUpload {

	/** Core instance. */
	public static $core = null;
	/** Logger instance. */
	public static $logger = null;

	// make sure these are the same with client
	private $post_prefix;
	private $chunk_size;
	private $max_filesize;

	private $with_fingerprint = false;

	private $tempdir = null;
	private $destdir = null;

	/**
	 * Constructor.
	 *
	 * @param object $core A core instance.
	 * @param string $tempdir Temporary upload directory.
	 * @param string $destdir Destination directory.
	 * @param string|null $post_prefix POST data prefix.
	 * @param int|null $chunk_size Chunk size, defaults to 2M.
	 * @param int|null $max_filesize Maximum filesize, defaults
	 *     to 10M.
	 * @param bool $with_fingerprint If true, fingerprint will
	 *     be verified by $this->check_fingerprint() after each
	 *     chunk upload is complete.
	 * @param object|null $logger An instance of logging service.
	 *     If left as null, default logger is used, implying
	 *     error level and usage of STDERR.
	 */
	public function __construct(
		Router $core, $tempdir, $destdir,
		$post_prefix=null, $chunk_size=null, $max_filesize=null,
		$with_fingerprint=false, Logger $logger=null
	) {
		self::$core = $core;

		if (!$logger)
			$logger = new Logger();
		self::$logger = $logger;

		// defaults
		$this->post_prefix = '__chupload_';
		$this->chunk_size = 1024 * 100;
		$this->max_filesize = 1024 * 1024 * 10;

		if ($post_prefix !== null)
			$this->post_prefix = (string)$post_prefix;

		$this->with_fingerprint = (bool)$with_fingerprint;

		if ($chunk_size) {
			$chunk_size = (int)$chunk_size;
			if ($chunk_size > 1024 * 1024 * 2) {
				$logger->warning(
					"Chupload: chunk size > 2M. Default 100k is used.");
				$chunk_size = null;
			} elseif ($chunk_size < 1024) {
				$logger->warning(
					"Chupload: chunk size < 1k. Default 100k is used.");
				$chunk_size = null;
			} else {
				$logger->debug(
					"Chupload: using chunk size: $chunk_size.");
			}
		}
		if ($chunk_size)
			$this->chunk_size = $chunk_size;

		if ($max_filesize) {
			$max_filesize = (int)$max_filesize;
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

		if (!$tempdir || !$destdir) {
			$errmsg = sprintf('%s not set.',
				(!$tempdir ? 'Temporary' : 'Destination'));
			$logger->error("Chupload: $errmsg");
			throw new ChunkUploadError($errmsg);
		}

		if ($tempdir == $destdir) {
			$errmsg = "Temp and destination dirs shan't be the same.";
			$logger->error("Chupload: $errmsg");
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
				$logger->error("Chupload: $errmsg");
				throw new ChunkUploadError($errmsg);
			}
			$this->$dname = $dpath;
		}
	}

	/**
	 * Default fingerprint check.
	 *
	 * This default method only returns true.
	 *
	 * @param string $fingerprint A fingerprint generated by client.
	 * @param string $chunk_received Received chunk.
	 * @return bool True if fingerprint matches, false otherwise.
	 *     Unmatched fingerprint will halt remaining chunk uploads.
	 * @codeCoverageIgnore
	 *
	 * @manonly
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @endmanonly
	 */
	protected function check_fingerprint(
		$fingerprint, $chunk_received
	) {
		return true;
	}

	/**
	 * Default basename generator.
	 *
	 * @param string $path Path to a file.
	 */
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
	 * @return bool|string False for failed post-processing, new
	 *     destination path otherwise, which can be the same with
	 *     input path. Changing path must be wrapped inside this
	 *     method.
	 */
	protected function post_processing($path) {
		return $path;
	}

	/**
	 * Print JSON.
	 *
	 * This is different from typical core JSON since it's related to
	 * upload end status.
	 *
	 * @param array $resp Array with values: 0 := `errno`, 1 := `data`.
	 */
	public static function json($resp) {
		$Err = new ChunkUploadError;
		$errno = $resp[0];
		$data = isset($resp[1]) ? $resp[1] : [];

		$http_code = 200;
		if (in_array($errno, [
			$Err::EREQ,
			$Err::ECST,
		])) {
			$http_code = 403;
		} elseif (in_array($errno, [
			$Err::EUPL,
			$Err::EDIO,
		])) {
			$http_code = 503;
		}
		self::$core->print_json($errno, $data, $http_code);
	}

	/**
	 * Safely unlink file.
	 *
	 * @codeCoverageIgnore
	 */
	private function unlink($file) {
		if (@unlink($file))
			return true;
		self::$logger->error(
			"Chupload: Cannot delete file: '$file'.");
		return false;
	}

	/**
	 * Verify request.
	 */
	private function upload_check_request($args) {
		$Err = new ChunkUploadError;
		$logger = self::$logger;

		$post = $args['post'];
		$files = $args['files'];

		if (!$files || !isset($files[$this->post_prefix . 'blob'])) {
			# file field incomplete
			$logger->warning("Chupload: chunk not received.");
			return [$Err::EREQ, [$Err::EREQ_NO_CHUNK]];
		}

		$keys = ['name', 'size', 'index'];
		if ($this->with_fingerprint)
			$keys[] = 'fingerprint';
		$vals = [];
		$pfx = $this->post_prefix;

		foreach($keys as $key) {
			if (!isset($post[$pfx . $key])) {
				# post field incomplete
				$logger->warning(
					"Chupload: '$key' form data not received.");
				return [$Err::EREQ, [$Err::EREQ_DATA_INCOMPLETE]];
			}
			$vals[$key] = $post[$pfx . $key];
		}

		$blob = $files[$pfx . 'blob'];
		if ($blob['error'] !== 0) {
			# generic upload error
			$logger->error("Chupload: upload error: {$blob['error']}.");
			return [$Err::EUPL, [$blob['error']]];
		}
		$vals['blob'] = $blob;

		return [0, $vals];
	}

	/**
	 * Verifiy new chunk.
	 */
	private function upload_check_constraints($request) {
		$Err = new ChunkUploadError;
		$logger = self::$logger;

		$name = $size = $index = $blob = null;
		extract($request);

		$size = intval($size);
		if ($size < 1) {
			# size violation
			$logger->warning("Chupload: invalid filesize.");
			return [$Err::ECST, [$Err::ECST_FSZ_INVALID]];
		}
		$index = intval($index);
		if ($index < 0) {
			# index undersized
			$logger->warning("Chupload: invalid chunk index.");
			return [$Err::ECST, [$Err::ECST_CID_UNDERSIZED]];
		}
		if ($size > $this->max_filesize) {
			# max size violation
			$logger->warning("Chupload: max filesize violation.");
			return [$Err::ECST, [$Err::ECST_FSZ_OVERSIZED]];
		}
		if ($index * $this->chunk_size > $this->max_filesize) {
			# index oversized
			$logger->warning("Chupload: chunk index violation.");
			return [$Err::ECST, [$Err::ECST_CID_OVERSIZED]];
		}
		$chunk_path = $blob['tmp_name'];
		if (filesize($chunk_path) > $this->chunk_size) {
			# chunk oversized
			$logger->warning("Chupload: invalid chunk index.");
			$this->unlink($chunk_path);
			return [$Err::ECST, [$Err::ECST_MCH_OVERSIZED]];
		}

		$max_chunk_s = $size / $this->chunk_size;
		$max_chunk = floor($max_chunk_s);
		if ($max_chunk_s == $max_chunk)
			$max_chunk--;

		$chunk = file_get_contents($chunk_path);

		$basename = $this->get_basename($name);
		$tempname = $this->tempdir . '/' . $basename;
		$destname = $this->destdir . '/' . $basename;

		# always overwrite old file with the same name, hence
		# make sure get_basename() guarantee uniqueness
		// @codeCoverageIgnoreStart
		if (file_exists($destname) && !$this->unlink($destname))
			return [$Err::EDIO, []];
		// @codeCoverageIgnoreEnd

		return [0, [
			'size' => $size,
			'index' => $index,
			'chunk_path' => $chunk_path,
			'max_chunk' => $max_chunk,
			'chunk' => $chunk,
			'basename' => $basename,
			'tempname' => $tempname,
			'destname' => $destname,
		]];
	}

	/**
	 * Append new chunk to packed chunks.
	 */
	private function upload_pack_chunk($constraints) {
		$Err = new ChunkUploadError;

		$index = $chunk = $chunk_path = null;
		extract($constraints);

		# truncate or append
		$fhn = @fopen($tempname, ($index === 0 ? 'wb' : 'ab'));
		if (false === $fhn) {
			// @codeCoverageIgnoreStart
			self::$logger->error(
				"Chupload: cannot open temp file: '%s'.", $tempname);
			return [$Err::EDIO, []];
			// @codeCoverageIgnoreEnd
		}
		# write to temp blob
		fwrite($fhn, $chunk);
		# append index
		if ($index < $max_chunk && $max_chunk > 1)
			fwrite($fhn, pack('v', $index));
		fclose($fhn);
		# remove chunk
		$this->unlink($chunk_path);

		return [0];
	}

	/**
	 * Merge packed chunks to destination.
	 */
	private function upload_merge_chunks(
		$tempname, $destname, $max_chunk
	) {
		$Err = new ChunkUploadError;
		if (
			false === ($fhi = @fopen($tempname, 'rb')) ||
			false === ($fho = @fopen($destname, 'wb'))
		) {
			// @codeCoverageIgnoreStart
			$errmsg = sprintf(
				"Cannot open files for merging: '%s' -> '%s'.",
				$tempname, $destname);
			self::$logger->error("Chupload: $errmsg");
			return [$Err::EDIO, []];
			// @codeCoverageIgnoreEnd
		}
		if ($max_chunk == 0) {
			# single or last chunk
			$chunk = fread($fhi, $this->chunk_size);
			if (filesize($tempname) > $this->chunk_size)
				return [$Err::ECST, [$Err::ECST_MCH_OVERSIZED]];
			fwrite($fho, $chunk);
			return [0];
		}
		$total = 0;
		for ($i=0; $i<$max_chunk; $i++) {
			$chunk = fread($fhi, $this->chunk_size);
			$total += $this->chunk_size;
			if ($total > $this->max_filesize)
				return [$Err::ECST, [$Err::ECST_FSZ_INVALID]];
			fwrite($fho, $chunk);
			$tail = fread($fhi, 2);
			if (strlen($tail) < 2)
				return [$Err::ECST, [$Err::ECST_MCH_UNORDERED]];
			$i_unpack = unpack('vint', $tail)['int'];
			if ($i !== $i_unpack)
				return [$Err::ECST, [$Err::ECST_MCH_UNORDERED]];
		}
		$chunk = fread($fhi, $this->chunk_size);
		fwrite($fho, $chunk);
		return [0];
	}

	/**
	 * Uploader.
	 *
	 * @param dict $args ZapCore router arguments.
	 */
	public function upload($args) {
		$Err = new ChunkUploadError;
		$logger = self::$logger;

		$request = $this->upload_check_request($args);
		if ($request[0] !== 0)
			return self::json($request);

		$chunk = $fingerprint = null;
		extract($request[1]);

		$constraints = $this->upload_check_constraints($request[1]);
		if ($constraints[0] !== 0)
			return self::json($constraints);
		extract($constraints[1]);

		$packed = $this->upload_pack_chunk($constraints[1]);
		if ($packed[0] !== 0)
			return self::json($packed);

		// fingerprint check
		if (
			$this->with_fingerprint &&
			!$this->check_fingerprint($fingerprint, $chunk)
		) {
			# fingerprint violation
			$logger->warning("Chupload: fingerprint doesn't match.");
			$this->unlink($tempname);
			return self::json([$Err::ECST, [$Err::ECST_FGP_INVALID]]);
		}

		// merge chunks on finish
		if ($max_chunk == $index) {

			$merge_status = $this->upload_merge_chunks(
				$tempname, $destname, $max_chunk);
			if ($merge_status[0] !== 0) {
				$this->unlink($destname);
				$this->unlink($tempname);
				$logger->warning("Chupload: broken chunk.");
				return self::json($merge_status);
			}
			$this->unlink($tempname);

			$postproc_destname = $this->post_processing($destname);
			if (!$postproc_destname) {
				if (file_exists($destname))
					$this->unlink($destname);
				$logger->error("Chupload: post-processing failed.");
				return self::json(
					[$Err::ECST, [$Err::ECST_POSTPROC_FAIL]]);
			}

		}

		// success
		$logger->info(
			"Chupload: file successfully uploaded: '$basename'.");
		return self::json([0, [
			'path' => $basename,
			'index'  => $index,
		]]);
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
