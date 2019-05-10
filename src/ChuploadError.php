<?php


namespace BFITech\ZapChupload;


/**
 * Chupload exception.
 *
 * Errors generally invokes HTTP status 403 except on
 * I/O error that is assigned status 503.
 */
class ChunkUploadError extends \Exception {

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
	/** Chunk index too big. */
	const ECST_CID_INVALID = 0x0304;
	/** Chunk fingerprint invalid. */
	const ECST_FGP_INVALID = 0x0305;
	/** Merged chunk exceeds max filesize. */
	const ECST_MCH_OVERSIZED = 0x0306;
	/** Messed up chunk order. */
	const ECST_MCH_UNORDERED = 0x0307;
	/** Post-proc failed. */
	const ECST_POSTPROC_FAIL = 0x0308;

	/* upload error */

	/* Upload errors are identical to builtin UPLOAD_ERR_*. */

	/* I/O error */

	/** Disk I/O error. */
	const EDIO = 0x0500;

}
