<?php


namespace BFITech\ZapChupload;


/**
 * Chupload exception.
 */
class ChunkUploadError extends \Exception {

	/** Request error. */
	const EREQ = 0x02;
	/** Constraint violation. */
	const ECST = 0x03;
	/** Upload error. Detailed errnos are bultin UPLOAD_ERR_* */
	const EUPL = 0x04;
	/** Disk IO error. */
	const EDIO = 0x05;

	/** No chunk received. */
	const EREQ_NO_CHUNK = 0x00;
	/** Incomplete data. */
	const EREQ_DATA_INCOMPLETE = 0x01;

	/** Invalid filesize. */
	const ECST_FSZ_INVALID = 0x00;
	/** Chunk index < 0. */
	const ECST_CID_UNDERSIZED = 0x01;
	/** Excessive filesize. */
	const ECST_FSZ_OVERSIZED = 0x02;
	/** Chunk index too big. */
	const ECST_CID_OVERSIZED = 0x03;
	/** Chunk index too big. */
	const ECST_CID_INVALID = 0x04;
	/** Chunk fingerprint invalid. */
	const ECST_FGP_INVALID = 0x05;
	/** Merged chunk exceeds max filesize. */
	const ECST_MCH_OVERSIZED = 0x06;
	/** Messed up chunk order. */
	const ECST_MCH_UNORDERED = 0x07;
	/** Post-proc failed. */
	const ECST_POSTPROC_FAIL = 0x08;


}
