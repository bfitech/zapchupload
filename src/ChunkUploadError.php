<?php


namespace BFITech\ZapChupload;


/**
 * Chupload exception.
 *
 * Errors generally invokes HTTP status 403 except on
 * I/O error that is assigned status 503.
 */
class ChunkUploadError extends \Exception {

	/* initialization errors */

	/** Invalid class constant overrides. */
	const EINI_CONST_INVALID = 0x0100;
	/** Invalid _POST data prefix. */
	const EINI_PREFIX_INVALID = 0x0101;
	/** Chunk size too small. */
	const EINI_CHUNK_TOO_SMALL = 0x0102;
	/** Chunk size too big. */
	const EINI_CHUNK_TOO_BIG = 0x0103;
	/** Chunk size too big. */
	const EINI_MAX_FILESIZE_TOO_SMALL = 0x0104;
	/** Temporary or destination directory not set. */
	const EINI_DIRS_NOT_SET = 0x0105;
	/** Temporary and destination directory are identical. */
	const EINI_DIRS_IDENTICAL = 0x0106;
	/** Temporary or destination can't be created. */
	const EINI_DIRS_NOT_CREATED = 0x0107;
	/**
	 * Cannot retrieve request-related properties without
	 * invoking ChunkUpload::upload().
	 */
	const EINI_PROPERTY_EMPTY = 0x0108;

	/* request errors */

	/** No chunk received. */
	const EREQ_NO_CHUNK = 0x0200;
	/** Incomplete data. */
	const EREQ_DATA_INCOMPLETE = 0x0201;

	/* constraint violations */

	/** Invalid filesize. */
	const ECST_FSZ_INVALID = 0x0300;
	/** Chunk index < 0. */
	const ECST_CID_UNDERSIZED = 0x0301;
	/** Excessive filesize. */
	const ECST_FSZ_OVERSIZED = 0x0302;
	/** Chunk index too big. */
	const ECST_CID_OVERSIZED = 0x0303;
	/** Chunk index invalid. */
	const ECST_CID_INVALID = 0x0304;
	/** Merged chunk exceeds max filesize. */
	const ECST_MCH_OVERSIZED = 0x0306;
	/** Messed up chunk order. */
	const ECST_MCH_UNORDERED = 0x0307;
	/** Post-proc failed. */
	const ECST_PREPROC_FAIL = 0x0308;
	/** Post-proc failed. */
	const ECST_CHUNKPROC_FAIL = 0x0309;
	/** Post-proc failed. */
	const ECST_POSTPROC_FAIL = 0x0310;

	/* upload errors */

	/* Upload errors are identical to builtin UPLOAD_ERR_*. */

	/* I/O errors */

	/** Cannot delete file. */
	const EDIO_DELETE_FAIL = 0x0500;
	/** Cannot merge packed chunks. */
	const EDIO_MERGE_FAIL = 0x0501;
	/** Cannot write to file. */
	const EDIO_WRITE_FAIL = 0x0502;
	/** Resource busy. */
	const EDIO_BUSY = 0x0503;

	/** Errno. */
	public $code = 0;
	/** Errmsg. */
	public $message = null;

	/**
	 * Constructor.
	 *
	 * @param int $code Errno. See the class constants.
	 * @param string $message Errmsg.
	 */
	public function __construct(int $code=null, string $message=null) {
		if ($code)
			$this->code = $code;
		if ($message)
			$this->message = $message;
	}

}
