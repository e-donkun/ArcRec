/*
ArcRec 管理画面の共通スクリプト。
ianseo 本体のカスタムモジュール「スマホモード」の sp.js と同じ部品(エスケープ、通知、ボトムシート、検索の絞り込み)。
POST には CSRF トークン(LITE_CSRF)を付ける。
*/

/** HTML エスケープ */
function spEsc(s) {
	return String(s === null || s === undefined ? '' : s)
		.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/** 本体ページの URL（例: spUrl('Partecipants/UpdateTargetNo.php', {Ses: 1})） */
function spUrl(path, params) {
	let url = path;
	if (params) {
		const q = new URLSearchParams();
		Object.keys(params).forEach(k => {
			const v = params[k];
			if (Array.isArray(v)) {
				v.forEach(x => q.append(k, x));
			} else if (v !== undefined && v !== null) {
				q.append(k, v);
			}
		});
		const qs = q.toString();
		if (qs) url += (url.indexOf('?') >= 0 ? '&' : '?') + qs;
	}
	return url;
}

/** GET して文字列を返す */
async function spGetText(path, params) {
	const res = await fetch(spUrl(path, params), {credentials: 'same-origin', cache: 'no-store'});
	if (!res.ok) throw new Error('HTTP ' + res.status);
	return res.text();
}

/** GET して JSON を返す */
async function spGetJson(path, params) {
	const txt = await spGetText(path, params);
	try {
		return JSON.parse(txt);
	} catch (e) {
		throw new Error('応答を読み取れませんでした');
	}
}

/** POST（フォーム形式）して JSON を返す */
async function spPostJson(path, params) {
	const body = new URLSearchParams();
	Object.keys(params || {}).forEach(k => {
		const v = params[k];
		if (Array.isArray(v)) v.forEach(x => body.append(k, x)); else body.append(k, v);
	});
	body.append('_csrf', typeof LITE_CSRF !== 'undefined' ? LITE_CSRF : '');
	const res = await fetch(spUrl(path), {
		method: 'POST',
		credentials: 'same-origin',
		cache: 'no-store',
		headers: {'Content-Type': 'application/x-www-form-urlencoded'},
		body: body.toString(),
	});
	if (!res.ok) throw new Error('HTTP ' + res.status);
	const txt = await res.text();
	try {
		return JSON.parse(txt);
	} catch (e) {
		throw new Error('応答を読み取れませんでした');
	}
}

/** 画面下部に短いメッセージを表示する */
let spToastTimer = null;
function spToast(msg, isError) {
	const t = document.getElementById('sp-toast');
	if (!t) return;
	t.textContent = msg;
	t.classList.toggle('error', !!isError);
	t.classList.add('show');
	clearTimeout(spToastTimer);
	spToastTimer = setTimeout(() => t.classList.remove('show'), isError ? 3500 : 1600);
}

/** ボトムシート（編集用の画面） */
const spSheet = {
	bg: null,
	el: null,
	onClose: null,
	init() {
		if (this.el) return;
		this.bg = document.createElement('div');
		this.bg.className = 'sp-sheet-bg';
		this.bg.addEventListener('click', () => this.close());
		this.el = document.createElement('div');
		this.el.className = 'sp-sheet';
		this.el.setAttribute('role', 'dialog');
		document.body.appendChild(this.bg);
		document.body.appendChild(this.el);
	},
	open(html, onClose) {
		this.init();
		this.el.innerHTML = html;
		this.onClose = onClose || null;
		this.bg.classList.add('open');
		requestAnimationFrame(() => this.el.classList.add('open'));
		return this.el;
	},
	close() {
		if (!this.el) return;
		this.el.classList.remove('open');
		this.bg.classList.remove('open');
		const cb = this.onClose;
		this.onClose = null;
		if (cb) cb();
	},
};

/** ブラウザに小さな設定を保存する（使えないときは無視） */
function spStore(key, value) {
	try {
		if (value === undefined) return localStorage.getItem('lite_' + key);
		localStorage.setItem('lite_' + key, value);
	} catch (e) {
		return null;
	}
}

/** 検索用に文字列を正規化する（全角英数→半角、大文字→小文字） */
function spNorm(s) {
	return String(s || '')
		.replace(/[Ａ-Ｚａ-ｚ０-９]/g, c => String.fromCharCode(c.charCodeAt(0) - 0xFEE0))
		.replace(/　/g, ' ')
		.toLowerCase();
}

/** カードの一覧を検索語で絞り込む（カードの data-search を見る） */
function spFilterCards(listEl, text, extraFilter) {
	const words = spNorm(text).split(/\s+/).filter(w => w);
	let shown = 0;
	listEl.querySelectorAll('[data-search]').forEach(card => {
		const hay = card.getAttribute('data-search');
		let ok = words.every(w => hay.indexOf(w) >= 0);
		if (ok && extraFilter) ok = extraFilter(card);
		card.classList.toggle('sp-hide', !ok);
		if (ok) shown++;
	});
	return shown;
}
