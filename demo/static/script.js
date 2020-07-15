
/* global m */


const E_UPLOAD_STARTED = 0x0100;
const E_UNFINISHED = 0x0101;


class ChuploadError {
	constructor(errno, errmsg) {
		this.errno = errno;
		this.errmsg = errmsg;
	}
}


class Chupload {
	constructor(
		file, url,
		pfx, chunkSz,
		cbChunkOk, cbFileOk, cbError, cbProgress
	) {
		this.file = file;

		this.url = url;
		this.postPrefix = pfx;
		this.chunkSz = chunkSz;

		this.cbChunkOk = cbChunkOk || (() => {});
		this.cbFileOk = cbFileOk || (() => {});
		this.cbError = cbError || (() => {});
		this.cbProgress = cbProgress || (() => {});

		this.chunkIndex = -1;
		this.chunkMax = -1;

		this.progress = -1;
		this.started = false;
		this.cancelled = false;
	}

	start() {
		if (this.started)
			throw new ChuploadError(
				E_UPLOAD_STARTED, "upload already started");

		this.chunkMax = Math.floor(this.file.size / this.chunkSz);
		this.chunkIndex = 0;

		this.send();
	}

	async send() {
		const bgn = this.chunkSz * this.chunkIndex;
		const end = bgn + this.chunkSz;
		const blob = this.file.slice(bgn, end);

		const abuf = await blob.arrayBuffer();
		const ubuf = await crypto.subtle.digest('SHA-256', abuf);
		const sum = Array.from(new Uint8Array(ubuf)).
			map(b => b.toString(16).padStart(2, '0')).join('');

		const form = new FormData();
		const pfx = this.postPrefix;
		form.append(pfx + 'name', this.file.name);
		form.append(pfx + 'size', this.file.size);
		form.append(pfx + 'index', this.chunkIndex);
		form.append(pfx + 'blob', blob);
		form.append(pfx + 'sum', sum);

		m.request({
			method: 'POST',
			url: this.url,
			body: form,
		}).then(resp => {
			// check last chunk
			if (this.chunkIndex >= this.chunkMax) {
				if (!resp.data || !resp.data.done)
					throw new ChuploadError(
						E_UNFINISHED, "server failed to finish");
				this.progress = -1;
				this.cbFileOk(resp);
				return;
			}

			// increment index
			this.chunkIndex++;

			// successful chunk upload
			this.progress = (this.chunkIndex / this.chunkMax) * 100;
			this.cbChunkOk(resp);

			// cancelled
			if (this.cancelled)
				return;

			// progress
			this.cbProgress(this.progress);

			// continue to next chunk
			this.send();
		}).catch(resp => {
			this.cbError(resp.response);
		});
	}

	cancel() {
		this.cancelled = true;
	}
}


class Upload {
	file = null;
	progress = 0;
	path = null;
	attr = {};

	errno = 0;

	chupload = null;
	download = null;

	constructor(app, download) {
		this.app = app;
		this.download = download;
	}

	reset() {
		this.progress = 0;
		this.file = null;
		this.path = null;
	}

	// callbacks
	cbFileOk(resp) {
		this.progress = 0;
		this.file = null;
		this.path = resp.data.path;
		this.attr = resp.data;
		this.download.load();
	}
	cbError(resp) {
		this.errno = resp.errno;
		this.reset();
	}
	cbProgress(progress) {
		this.progress = progress;
	}

	// handlers
	upload() {
		const cnf = this.app.config;
		try {
			this.chupload = new Chupload(
				this.file, './upload',
				cnf.post_prefix, cnf.chunk_size,
				null,
				resp => this.cbFileOk(resp),
				resp => this.cbError(resp),
				progress => this.cbProgress(progress)
			);
			this.chupload.start();
		} catch(err) {
			this.errno = err.errno;
		}
	}
	cancel() {
		if (!this.chupload)
			return;
		this.chupload.cancel();
		this.chupload = null;
	}

	// controls
	eleFile() {
		const self = this;
		return m('input', {
			type: 'file',
			onchange() {
				if (!this.files)
					return;
				self.reset();
				self.file = this.files[0];
			},
		});
	}
	elePicker(upl) {
		const self = this;
		if (!this.file) {
			return m('a', {
				onclick() {
					self.errno = 0;
					upl.dom.click();
				},
			}, 'pick a file');
		}
		return m('span', this.file.name);
	}
	eleProgress() {
		if (!this.progress)
			return;
		return [m('progress', {
			max: 100,
			value: this.progress,
		}, this.progress + '%')];
	}
	elePath() {
		if (!this.path)
			return;
		const attr = this.attr;
		return [
			m('p', [
				m('strong', 'OK: '),
				m('a', {
					href: './download/?fname=' + this.path,
					target: '_blank',
				}, this.path),
			]),
			m('hr'),
			m('p', [m('strong', '• SIZE: '), attr.size]),
			m('p', [m('strong', '• TYPE: '), attr.content_type]),
		];
	}
	eleError() {
		if (!this.errno)
			return;
		return [
			m('span', 'ERROR with errno: 0x' +
				this.errno.toString(16).padStart(4, '0') + '.'),
		];
	}

	// buttons
	btnReset() {
		if (!this.file)
			return;
		const self = this;
		return m('button', {
			onclick() {
				self.reset();
			},
		}, 'reset');
	}
	btnUpload(upl) {
		if (!this.file)
			return;
		const self = this;
		return m('button', {
			onclick() {
				self.upload(upl);
			},
		}, 'upload');
	}
	btnCancel() {
		if (!this.progress || this.progress === 100)
			return;
		const self = this;
		return m('button', {
			onclick() {
				self.cancel();
				self.reset();
			},
		}, 'cancel');
	}

	view() {
		if (this.app.tab !== 'upload')
			return;
		const inp = this.eleFile();
		return [
			m('div.upload', [
				inp,
				this.elePicker(inp),
				m('hr'),
				this.btnReset(),
				this.btnUpload(inp),
				this.btnCancel(),
				this.elePath(),
				this.eleError(),
			]),
			m('div', this.eleProgress()),
		];
	}
}

class Download {
	app = null;
	list = [];

	constructor(app) {
		this.app = app;
		this.load();
	}

	remove(ele) {
		m.request({
			method: 'DELETE',
			url: './remove',
			body: ele,
			serialize(data) {
				return data;
			},
		}).then(() => {}).catch(() => {}).finally(() => {
			this.load();
		});
	}

	load() {
		m.request('./list').then(resp => {
			this.list = resp.data;
		}).catch(() => {});
	}

	fileEle(ele) {
		const self = this;
		return m('p', [
			m('a', {
				title: 'delete',
				onclick() {
					self.remove(ele);
				},
			}, '\u2716'),
			m('a', {
				href: './download?fname=' + ele,
				target: '_blank',
			}, ele),
		]);
	}
	view() {
		if (this.app.tab !== 'list')
			return;
		return [
			m('div.list', this.list.map(ele => this.fileEle(ele))),
		];
	}
}


class App {
	uploader = null;
	download = null;
	tab = 'upload';

	oninit() {
		m.request(
			'./config'
		).then(resp => {
			this.config = resp.data;
		}).catch(() => {}).finally(() => {
			this.download = new Download(this);
			this.upload = new Upload(this, this.download);
		});
	}

	view() {
		if (!this.config)
			return;
		const self = this;
		return m('div', [
			m('div.tab', [
				m('span', {
					onclick() {
						self.tab = 'upload';
					},
					class: self.tab === 'upload' ? 'active' : '',
				}, 'upload'),
				m('span', {
					onclick() {
						self.tab = 'list';
						self.upload.reset();
						self.upload.errno = 0;
					},
					class: self.tab === 'list' ? 'active' : '',
				}, 'list'),
				m('hr'),
			]),
			m('div', this.upload.view(), this.download.view())
		]);
	}
}


m.mount(document.querySelector('#box'), new App);
