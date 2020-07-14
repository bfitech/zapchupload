
/* global m */
/* eslint no-undef: "error" */

class ChuploadError {
	constructor(errno, errmsg) {
		this.errno = errno;
		this.errmsg = errmsg;
	}
}

const E_UPLOAD_STARTED = 0x0100;
const E_UNFINISHED = 0x0101;

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

		// calculate chunk size
		this.chunkMax = Math.floor(this.file.size / this.chunkSz);
		this.chunkIndex = 0;

		this.uploadChunk();
	}

	uploadChunk() {
		const bgn = this.chunkSz * this.chunkIndex;
		const end = bgn + this.chunkSz;
		const blob = this.file.slice(bgn, end);

		blob.arrayBuffer().then(msg => {
			crypto.subtle.digest('SHA-256', msg).then(buf => {
				const sum = Array.from(new Uint8Array(buf)).
					map(b => b.toString(16).padStart(2, '0')).join('');
				this.send(blob, sum);
			});
		});
	}

	send(blob, sum) {
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
			this.progress = ((this.chunkIndex / this.chunkMax) * 100)
				.toString().replace(/\.([0-9]{2}).+/, '.$1');
			this.cbChunkOk(resp);

			// cancelled
			if (this.cancelled)
				return;

			// progress
			this.cbProgress(this.progress);

			// continue to next chunk
			this.uploadChunk();
		}).catch(resp => {
			this.cbError(resp.response);
		});
	}

	cancel() {
		this.cancelled = true;
	}
}


const app = {
	progress: 0,
	file: null,
	path: null,
	errno: 0,

	attr: {},

	uploader: null,

	reset() {
		this.progress = 0;
		this.file = null;
		this.path = null;
	},

	getConfig() {
		m.request(
			'./config'
		).then(resp => {
			this.config = resp.data;
		}).catch(() => {});
	},

	cbFileOk(resp) {
		this.progress = 0;
		this.file = null;
		this.path = resp.data.path;
		this.attr = resp.data;
		this.fileList();
	},
	cbError(resp) {
		// FIXME: using this.error is always received as 0 by eleError
		app.errno = resp.errno;
		this.reset();
	},
	cbProgress(progress) {
		this.progress = Number(progress);
	},
	upload() {
		const cnf = this.config;
		try {
			this.uploader = new Chupload(
				this.file, './upload',
				cnf.post_prefix, cnf.chunk_size,
				null,
				resp => this.cbFileOk(resp),
				resp => this.cbError(resp),
				progress => this.cbProgress(progress)
			);
			this.uploader.start();
		} catch(err) {
			this.errno = err.errno;
		}
	},
	cancel() {
		if (!this.uploader)
			return;
		this.uploader.cancel();
		this.uploader = null;
	},

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
	},
	elePicker(upl) {
		if (!this.file) {
			return m('a', {
				onclick() {
					app.errno = 0;
					upl.dom.click();
				},
			}, 'pick a file');
		}
		return m('span', this.file.name);
	},

	eleProgress() {
		if (!this.progress)
			return;
		return [m('progress', {
			max: 100,
			value: this.progress,
		}, this.progress + '%')];
	},

	btnReset() {
		if (!this.file)
			return;
		const self = this;
		return m('button', {
			onclick() {
				self.reset();
			},
		}, 'reset');
	},
	btnUpload(upl) {
		if (!this.file)
			return;
		const self = this;
		return m('button', {
			onclick() {
				self.upload(upl);
			},
		}, 'upload');
	},
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
	},

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
	},

	eleError() {
		if (!app.errno)
			return;
		return [
			m('span', 'ERROR with errno: 0x' +
				app.errno.toString(16).padStart(4, '0') + '.'),
		];
	},

	list: [],
	fileList() {
		m.request('./list').then(resp => {
			this.list = resp.data;
		}).catch(() => {});
	},
	fileRemove(ele) {
		m.request({
			method: 'DELETE',
			url: './remove',
			body: ele,
			serialize(data) {
				return data;
			},
		}).then(() => {}).catch(() => {}).finally(() => {
			this.fileList();
		});
	},
	fileEle(ele) {
		const self = this;
		return m('p', [
			m('a', {
				title: 'delete',
				onclick() {
					self.fileRemove(ele);
				},
			}, '\u2716'),
			m('a', {
				href: './download?fname=' + ele,
				target: '_blank',
			}, ele),
		]);
	},

	oninit() {
		this.getConfig();
		this.fileList();
	},

	tab: 'upload',
	viewTab() {
		const self = this;
		return [
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
						self.reset();
					},
					class: self.tab === 'list' ? 'active' : '',
				}, 'list'),
				m('hr'),
			]),
		];
	},
	viewUpload() {
		if (this.tab !== 'upload')
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
	},
	viewList() {
		if (this.tab !== 'list')
			return;
		return [
			m('div.list', this.list.map(ele => this.fileEle(ele))),
		];
	},
	view() {
		if (!this.config)
			return;
		return m('div', [
			this.viewTab(),
			m('div', this.viewUpload(), this.viewList())
		]);
	},
};


// root
m.mount(document.querySelector('#box'), app);
