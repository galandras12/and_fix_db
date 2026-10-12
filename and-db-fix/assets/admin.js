(function () {
	'use strict';
	var C = window.ADBOPT_CFG, root = document.getElementById('adbopt-app');
	if (!root || !C) { return; }

	var state = {
		tab: 'overview', data: null, tasks: null, sel: {},
		sortKey: 'total', sortDir: -1, group: 'none', filter: '', busy: {}
	};
	var nf = new Intl.NumberFormat('hu-HU', { maximumFractionDigits: 1 });

	/* ---------- segédek ---------- */
	function esc(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function fmtBytes(n) {
		var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
		n = Number(n) || 0;
		while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
		return nf.format(Math.round(n * 100) / 100) + ' ' + u[i];
	}
	function fmtNum(n) { return new Intl.NumberFormat('hu-HU').format(n); }

	function api(op, payload) {
		var fd = new FormData();
		fd.append('action', 'adbopt');
		fd.append('nonce', C.nonce);
		fd.append('op', op);
		fd.append('payload', JSON.stringify(payload || {}));
		return fetch(C.ajax, { method: 'POST', credentials: 'same-origin', body: fd })
			.then(function (r) { return r.text(); })
			.then(function (t) {
				var j;
				try { j = JSON.parse(t); } catch (e) { throw new Error('Váratlan szerverválasz (időtúllépés vagy PHP hiba).'); }
				if (!j.success) { throw new Error((j.data && j.data.message) || 'Ismeretlen hiba'); }
				return j.data;
			});
	}

	function toast(msg, type) {
		var box = document.getElementById('adbopt-toasts');
		if (!box) { box = document.createElement('div'); box.id = 'adbopt-toasts'; document.body.appendChild(box); }
		var t = document.createElement('div');
		t.className = 'ad-toast ' + (type || '');
		t.textContent = msg;
		box.appendChild(t);
		setTimeout(function () { t.remove(); }, type === 'err' ? 8000 : 4500);
	}

	function setBusy(btn, on, label) {
		if (!btn) { return; }
		if (on) {
			btn._orig = btn.innerHTML;
			btn.classList.add('ad-busy');
			btn.disabled = true;
			btn.innerHTML = '<span class="ad-spin"></span>' + esc(label || 'Folyamatban…');
		} else {
			btn.classList.remove('ad-busy');
			btn.disabled = false;
			if (btn._orig != null) { btn.innerHTML = btn._orig; }
		}
	}

	function progress(id, text, pct) {
		var el = document.getElementById(id);
		if (!el) { return; }
		el.classList.add('on');
		el.classList.toggle('indet', pct == null);
		el.querySelector('.ad-pt').textContent = text;
		el.querySelector('.ad-pb > i').style.width = pct == null ? '' : Math.max(2, Math.min(100, pct)) + '%';
	}
	function progressEnd(id) {
		var el = document.getElementById(id);
		if (el) { el.classList.remove('on'); }
	}
	function progressBox(id) {
		return '<div class="ad-progress" id="' + id + '"><div class="ad-pt"></div><div class="ad-pb"><i></i></div></div>';
	}

	function keepScroll(fn) {
		var y = window.scrollY;
		fn();
		window.scrollTo(0, y);
	}

	/* ---------- betöltés ---------- */
	function loadOverview() {
		return api('overview').then(function (d) {
			state.data = d;
			keepScroll(function () { renderHead(); renderTab(); });
		}).catch(function (e) { toast(e.message, 'err'); });
	}
	function loadTasks() {
		return api('tasks').then(function (d) {
			var first = !state.tasks;
			state.tasks = d.tasks;
			if (first) {
				d.tasks.forEach(function (t) { state.sel[t.id] = t.safe && t.count > 0; });
			}
			if (state.tab === 'clean' || state.tab === 'settings') { keepScroll(renderTab); }
		}).catch(function (e) { toast(e.message, 'err'); });
	}

	/* ---------- váz ---------- */
	function skeleton() {
		root.innerHTML = '<h1>And DB fix <span class="ad-ver">v' + esc(C.version) + '</span></h1>' +
			'<div id="ad-head"></div>' +
			'<div class="ad-tabs" id="ad-tabs"></div>' +
			'<div id="ad-body"><div class="ad-card">Betöltés…</div></div>';
		renderTabs();
	}
	var TABS = [
		['overview', 'Áttekintés'], ['options', 'Opciók'], ['clean', 'Takarítás'],
		['backup', 'Mentés'], ['settings', 'Beállítások']
	];
	function renderTabs() {
		document.getElementById('ad-tabs').innerHTML = TABS.map(function (t) {
			return '<button type="button" class="ad-tab' + (state.tab === t[0] ? ' on' : '') + '" data-act="tab" data-tab="' + t[0] + '">' + t[1] + '</button>';
		}).join('');
	}

	function renderHead() {
		var d = state.data, st = d.status, el = document.getElementById('ad-head');
		var lim = st.limit ? fmtBytes(st.limit) : 'nincs limit';
		var pct = st.pct == null ? 0 : Math.min(100, st.pct);
		el.innerHTML = '<div class="ad-status l' + st.level + '">' +
			'<div class="ad-big">' + fmtBytes(d.total) + ' / ' + esc(lim) + (st.pct != null ? ' &middot; ' + nf.format(st.pct) + '%' : '') + '</div>' +
			'<div class="ad-msg">' + esc(st.message) + '</div>' +
			(st.pct != null ? '<div class="ad-bar"><i style="width:' + pct + '%"></i></div>' : '') +
			'<div style="margin-top:8px;font-size:12px;opacity:.9">Adatbázis: ' + esc(d.db_name) + ' &middot; a tárhely az összes tábla méretét számolja</div></div>';
	}

	function renderTab() {
		renderTabs();
		var body = document.getElementById('ad-body');
		if (!state.data) { return; }
		var fn = { overview: tabOverview, options: tabOptions, clean: tabClean, backup: tabBackup, settings: tabSettings }[state.tab];
		body.innerHTML = fn();
		if (state.tab === 'overview') { renderTableBody(); }
	}

	/* ---------- Áttekintés ---------- */
	var COLS = [
		['name', 'Tábla', false], ['owner', 'Forrás', false], ['engine', 'Motor', false],
		['rows', 'Sorok (~)', true], ['data', 'Adat', true], ['index', 'Index', true],
		['total', 'Összesen', true], ['free', 'Overhead', true]
	];

	function tabOverview() {
		var d = state.data;
		var owners = {};
		d.tables.forEach(function (t) { owners[t.owner] = (owners[t.owner] || 0) + t.total; });
		var list = Object.keys(owners).map(function (k) { return [k, owners[k]]; }).sort(function (a, b) { return b[1] - a[1]; }).slice(0, 12);
		var max = list.length ? list[0][1] : 1;
		var ownerRows = list.map(function (o) {
			var pct = d.total ? (o[1] / d.total * 100) : 0;
			return '<tr><td>' + esc(o[0]) + '</td><td class="num">' + fmtBytes(o[1]) + '</td><td class="num">' + nf.format(pct) + '%</td>' +
				'<td style="width:35%"><span class="ad-minibar" style="width:' + Math.max(2, o[1] / max * 100) + '%"></span></td></tr>';
		}).join('');

		return '<div class="ad-card"><h2>Méret plugin / forrás szerint</h2>' +
			'<div class="ad-scroll"><table class="ad-t"><thead><tr><th>Forrás</th><th class="num">Méret</th><th class="num">Arány</th><th></th></tr></thead><tbody>' + ownerRows + '</tbody></table></div></div>' +

			'<div class="ad-card"><h2>Táblák mérete</h2>' +
			'<div class="ad-mut">Kattints egy oszlopfejlécre a rendezéshez (méret, sorok száma, név, forrás stb.). A csoportosítással a táblákat forrás vagy motor szerint összevonva láthatod, részösszegekkel.</div>' +
			'<div class="ad-tools"><label>Csoportosítás: <select data-change="group">' +
			[['none', 'Nincs'], ['owner', 'Forrás (plugin)'], ['engine', 'Tárolómotor']].map(function (g) {
				return '<option value="' + g[0] + '"' + (state.group === g[0] ? ' selected' : '') + '>' + g[1] + '</option>';
			}).join('') + '</select></label>' +
			'<input type="search" placeholder="Szűrés névre / forrásra…" value="' + esc(state.filter) + '" data-input="filter">' +
			'<span class="ad-mut">' + d.tables.length + ' tábla</span></div>' +
			progressBox('ad-p-drop') +
			'<div class="ad-scroll" id="ad-tablewrap"></div>' +
			'<p class="ad-mut">A „Tábla törlése” csak a WordPress maghoz nem tartozó, azonos előtagú táblákon jelenik meg. Olyan plugin táblájára használd, ami már nincs telepítve, és előtte készíts mentést.</p></div>';
	}

	function sortedRows(rows) {
		var k = state.sortKey, dir = state.sortDir;
		return rows.slice().sort(function (a, b) {
			var x = a[k], y = b[k];
			if (typeof x === 'string') { return dir * x.localeCompare(y, 'hu'); }
			return dir * (x - y);
		});
	}

	function tableRow(t, total) {
		var hot = total && t.total / total > 0.25;
		return '<tr' + (hot ? ' class="hot"' : '') + '><td><code>' + esc(t.name) + '</code></td><td>' + esc(t.owner) + '</td><td>' + esc(t.engine) + '</td>' +
			'<td class="num">' + fmtNum(t.rows) + '</td><td class="num">' + fmtBytes(t.data) + '</td><td class="num">' + fmtBytes(t.index) + '</td>' +
			'<td class="num"><strong>' + fmtBytes(t.total) + '</strong></td><td class="num">' + fmtBytes(t.free) + '</td><td>' +
			(t.drop ? '<button type="button" class="button button-small button-link-delete" data-act="drop" data-table="' + esc(t.name) + '">Tábla törlése</button>' : '') +
			'</td></tr>';
	}

	function renderTableBody() {
		var wrap = document.getElementById('ad-tablewrap');
		if (!wrap || !state.data) { return; }
		var d = state.data, f = state.filter.trim().toLowerCase();
		var rows = d.tables.filter(function (t) {
			return !f || t.name.toLowerCase().indexOf(f) >= 0 || t.owner.toLowerCase().indexOf(f) >= 0;
		});
		var head = '<tr>' + COLS.map(function (c) {
			var arrow = state.sortKey === c[0] ? (state.sortDir < 0 ? ' ▼' : ' ▲') : '';
			return '<th class="sortable' + (c[2] ? ' num' : '') + '" data-act="sort" data-key="' + c[0] + '">' + c[1] + arrow + '</th>';
		}).join('') + '<th></th></tr>';
		var out = '';
		if (state.group === 'none') {
			out = sortedRows(rows).map(function (t) { return tableRow(t, d.total); }).join('');
		} else {
			var groups = {};
			rows.forEach(function (t) { var g = t[state.group] || '—'; (groups[g] = groups[g] || []).push(t); });
			Object.keys(groups).map(function (g) {
				var sum = groups[g].reduce(function (a, t) { return a + t.total; }, 0);
				return [g, sum];
			}).sort(function (a, b) { return b[1] - a[1]; }).forEach(function (g) {
				out += '<tr class="grp"><td colspan="9">' + esc(g[0]) + ' &middot; ' + groups[g[0]].length + ' tábla &middot; ' + fmtBytes(g[1]) +
					' (' + nf.format(d.total ? g[1] / d.total * 100 : 0) + '%)</td></tr>';
				out += sortedRows(groups[g[0]]).map(function (t) { return tableRow(t, d.total); }).join('');
			});
		}
		wrap.innerHTML = '<table class="ad-t"><thead>' + head + '</thead><tbody>' + (out || '<tr><td colspan="9">Nincs találat.</td></tr>') + '</tbody></table>';
	}

	/* ---------- Opciók ---------- */
	function tabOptions() {
		var d = state.data;
		var rows = d.options.map(function (o) {
			return '<tr><td><code>' + esc(o.name.length > 70 ? o.name.slice(0, 70) + '…' : o.name) + '</code></td><td>' + esc(o.owner) + '</td>' +
				'<td class="num"><strong>' + fmtBytes(o.len) + '</strong></td><td>' + (o.auto ? 'igen' : 'nem') + '</td><td>' +
				(o.can_off ? '<button type="button" class="button button-small" data-act="noauto" data-opt="' + esc(o.name) + '">Autoload ki</button>' : '') + '</td></tr>';
		}).join('');
		return '<div class="ad-card"><h2>Legnagyobb opciók (wp_options)</h2>' +
			'<p>Autoload opciók: <strong>' + fmtNum(d.autoload.count) + ' db, ' + fmtBytes(d.autoload.size) + '</strong>. Ha ez 1–2 MB fölött van, minden oldalbetöltést lassít.</p>' +
			'<div class="ad-scroll"><table class="ad-t"><thead><tr><th>Opció neve</th><th>Valószínű forrás</th><th class="num">Méret</th><th>Autoload</th><th></th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
			'<p class="ad-mut">Az „Autoload ki” az opciót nem törli, csak nem tölti be minden oldalnál.</p></div>';
	}

	/* ---------- Takarítás ---------- */
	function tabClean() {
		if (!state.tasks) { return '<div class="ad-card">Betöltés…</div>'; }
		var rows = state.tasks.map(function (t) {
			return '<tr><td style="width:30px"><input type="checkbox" data-change="sel" data-id="' + esc(t.id) + '"' + (state.sel[t.id] ? ' checked' : '') + '></td>' +
				'<td><strong>' + esc(t.label) + '</strong><div class="ad-mut">' + esc(t.desc) + '</div></td><td class="num">' + fmtNum(t.count) + ' db</td></tr>';
		}).join('');
		return '<div class="ad-card"><h2>Felesleges adatok törlése</h2>' +
			'<p><strong>Törlés előtt készíts mentést</strong> (Mentés fül). A törlés nem visszavonható. Az előre bejelöltek biztonságosak; a revíziók, a kuka és az összes tranziens törlése tudatos döntés.</p>' +
			'<div class="ad-scroll"><table class="ad-t"><thead><tr><th></th><th>Mit</th><th class="num">Találat</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
			progressBox('ad-p-clean') +
			'<p><button type="button" class="button button-primary" data-act="clean">Kijelöltek törlése</button></p></div>' +

			'<div class="ad-card"><h2>Táblák optimalizálása (OPTIMIZE TABLE)</h2>' +
			'<p>Törlés után ez adja vissza ténylegesen a helyet a lemeznek.</p>' + progressBox('ad-p-opt') +
			'<p><button type="button" class="button" data-act="optimize" data-scope="overhead">Csak a töredezett táblák</button> ' +
			'<button type="button" class="button" data-act="optimize" data-scope="all">Minden tábla</button></p></div>';
	}

	function doClean(btn) {
		var ids = state.tasks.filter(function (t) { return state.sel[t.id]; }).map(function (t) { return t.id; });
		if (!ids.length) { toast('Nem jelöltél ki semmit.'); return; }
		if (!window.confirm('Biztosan törlöd a kijelölt elemeket? Van mentésed?')) { return; }
		setBusy(btn, true, 'Törlés…');
		var initial = {}, totalDeleted = 0, round = 0;
		state.tasks.forEach(function (t) { if (ids.indexOf(t.id) >= 0) { initial[t.id] = t.count; } });
		var initSum = ids.reduce(function (a, id) { return a + initial[id]; }, 0) || 1;
		progress('ad-p-clean', 'Törlés indul…', 0);

		function step(list) {
			round++;
			return api('clean', { tasks: list }).then(function (r) {
				var remSum = 0, next = [];
				list.forEach(function (id) {
					totalDeleted += r.deleted[id] || 0;
					remSum += r.remaining[id] || 0;
					if ((r.remaining[id] || 0) > 0 && (r.deleted[id] || 0) > 0) { next.push(id); }
					state.tasks.forEach(function (t) { if (t.id === id) { t.count = r.remaining[id]; } });
				});
				var allRem = ids.reduce(function (a, id) {
					var t = state.tasks.filter(function (x) { return x.id === id; })[0];
					return a + (t ? t.count : 0);
				}, 0);
				progress('ad-p-clean', fmtNum(totalDeleted) + ' elem törölve · hátra: ' + fmtNum(allRem), (1 - allRem / initSum) * 100);
				if (next.length && round < 500) { return step(next); }
				return allRem;
			});
		}
		step(ids).then(function (rem) {
			toast(fmtNum(totalDeleted) + ' elem törölve.' + (rem > 0 ? ' Maradt még ' + fmtNum(rem) + ' (nem törölhető tovább vagy hiba).' : ''), 'ok');
		}).catch(function (e) { toast(e.message, 'err'); })
			.then(function () {
				setBusy(btn, false);
				progressEnd('ad-p-clean');
				return Promise.all([loadOverview(), loadTasks()]);
			});
	}

	function doOptimize(btn) {
		var scope = btn.getAttribute('data-scope');
		var list = state.data.tables.filter(function (t) { return scope === 'all' || t.free >= 1048576; }).map(function (t) { return t.name; });
		if (!list.length) { toast('Nincs optimalizálandó tábla.'); return; }
		setBusy(btn, true, 'Optimalizálás…');
		var i = 0;
		function next() {
			if (i >= list.length) { return Promise.resolve(); }
			progress('ad-p-opt', (i + 1) + '/' + list.length + ' · ' + list[i], i / list.length * 100);
			return api('optimize_one', { table: list[i] }).then(function () { i++; return next(); });
		}
		next().then(function () { toast(list.length + ' tábla optimalizálva.', 'ok'); })
			.catch(function (e) { toast(e.message, 'err'); })
			.then(function () { setBusy(btn, false); progressEnd('ad-p-opt'); return loadOverview(); });
	}

	/* ---------- Mentés ---------- */
	function tabBackup() {
		return '<div class="ad-card"><h2>Biztonsági mentés</h2>' +
			'<p>SQL-mentés letöltése az adatbázis <code>' + esc(state.data.prefix) + '</code> előtagú tábláiról. A fájlnév az oldal címét és a dátum-időt tartalmazza. ' +
			'Visszatöltés: phpMyAdmin → Importálás (a .sql.gz fájlt is kicsomagolás nélkül elfogadja).</p>' +
			'<p><button type="button" class="button button-primary" data-act="backup" data-gz="0">Mentés letöltése (.sql)</button> ' +
			(state.data.gzip ? '<button type="button" class="button" data-act="backup" data-gz="1">Tömörített mentés (.sql.gz, kisebb)</button>' : '') + '</p>' +
			'<p class="ad-mut">Nagy adatbázisnál a letöltés elindulása után a mentés a háttérben készül; a gomb addig pörög, amíg a böngésző meg nem kapja a fájlt.</p></div>';
	}

	function doBackup(btn) {
		var token = 'b' + Date.now();
		var f = document.createElement('form');
		f.method = 'POST'; f.action = C.backupUrl; f.style.display = 'none';
		[['action', 'adbopt_backup'], ['_wpnonce', C.backupNonce], ['gz', btn.getAttribute('data-gz')], ['token', token]].forEach(function (p) {
			var i = document.createElement('input'); i.type = 'hidden'; i.name = p[0]; i.value = p[1]; f.appendChild(i);
		});
		document.body.appendChild(f);
		setBusy(btn, true, 'Mentés készül…');
		f.submit();
		var tries = 0, timer = setInterval(function () {
			tries++;
			if (document.cookie.indexOf('adbopt_dl=' + token) >= 0 || tries > 1200) {
				clearInterval(timer);
				setBusy(btn, false);
				f.remove();
				document.cookie = 'adbopt_dl=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
				toast('A mentés letöltése elindult.', 'ok');
			}
		}, 500);
	}

	/* ---------- Beállítások ---------- */
	function sw(id, checked, label, hint) {
		return '<div class="ad-row"><div class="ad-lbl">' + label + (hint ? '<small>' + hint + '</small>' : '') + '</div>' +
			'<label class="sw"><input type="checkbox" id="' + id + '"' + (checked ? ' checked' : '') + '><span class="sl"></span></label></div>';
	}

	function tabSettings() {
		var d = state.data, s = d.settings;
		var taskRows = (state.tasks || []).filter(function (t) { return t.safe; }).map(function (t) {
			return sw('st-task-' + t.id, s.tasks.indexOf(t.id) >= 0, esc(t.label), '');
		}).join('');
		var last = d.last_run ? 'Utolsó futás: ' + esc(d.last_run) + (s.last_result ? ' – ' + esc(s.last_result) : '') : 'Még nem futott.';
		return '<div class="ad-grid">' +
			'<div class="ad-card"><h2>Adatbázis-limit</h2>' +
			'<p>A tárhelyszolgáltatód által megengedett adatbázisméret. A fejléc színe ez alapján változik: zöld 70% alatt, citromsárga 70–80%, narancs 80–95%, piros 95% fölött (figyelmeztetéssel). <strong>0 = nincs limit.</strong></p>' +
			'<p><input type="number" min="0" step="1" class="ad-num" id="st-limit" value="' + esc(s.limit_mb) + '"> MB</p></div>' +

			'<div class="ad-card"><h2>Automatikus heti takarítás</h2>' +
			sw('st-enabled', s.enabled, '<strong>Heti automatikus takarítás</strong>', d.next_clean ? 'Következő futás: ' + esc(d.next_clean) : '') +
			'<div class="ad-sub">' + taskRows + sw('st-optimize', s.optimize, 'Utána a töredezett táblák optimalizálása', '') + '</div>' +
			'<p class="ad-mut">' + last + '</p></div>' +

			'<div class="ad-card" style="grid-column:1/-1"><h2>Email értesítések</h2>' +
			'<p>Címzettek (vesszővel vagy új sorral elválasztva):</p>' +
			'<textarea class="ad-em" id="st-emails" placeholder="nev@pelda.hu, masik@pelda.hu">' + esc(s.emails) + '</textarea>' +
			sw('st-mail_monthly', s.mail_monthly, '<strong>Havi jelentés</strong> minden hónap első napján', 'A limit kihasználtsága és hogy az előző hónapban melyik plugin/szolgáltatás mennyivel növelte az adatbázist (a napi mérések alapján; az első teljes hónapos adat a bekapcsolás utáni hónapban lesz).') +
			sw('st-mail_70', s.mail_70, 'Figyelmeztetés 70% fölött', 'Egyszer küld, amikor az adatbázis átlépi a 70%-ot.') +
			sw('st-mail_80', s.mail_80, 'Figyelmeztetés 80% fölött', 'Egyszer küld, amikor az adatbázis átlépi a 80%-ot.') +
			sw('st-mail_95', s.mail_95, 'Ismétlődő figyelmeztetés 95% fölött', 'Amíg 95% felett van, újra és újra küld.') +
			'<div class="ad-sub"><p>Ismétlés időköze: <input type="number" min="1" max="720" class="ad-num" id="st-hours" value="' + esc(s.mail_95_hours) + '"> óra</p></div>' +
			'<p class="ad-mut">Az ellenőrzés óránként fut (WP-Cron), ezért csak akkor, ha az oldalt látogatják. Az email küldés a WordPress levélküldését (wp_mail) használja.</p>' +
			'<p><button type="button" class="button" data-act="test">Teszt email küldése</button></p></div></div>' +

			'<p><button type="button" class="button button-primary button-large" data-act="save">Beállítások mentése</button></p>';
	}

	function doSave(btn) {
		var g = function (id) { return document.getElementById(id); };
		var tasks = state.tasks ? [] : state.data.settings.tasks.slice();
		(state.tasks || []).forEach(function (t) { var e = g('st-task-' + t.id); if (e && e.checked) { tasks.push(t.id); } });
		var payload = {
			limit_mb: g('st-limit').value, enabled: g('st-enabled').checked, optimize: g('st-optimize').checked, tasks: tasks,
			emails: g('st-emails').value, mail_monthly: g('st-mail_monthly').checked, mail_70: g('st-mail_70').checked,
			mail_80: g('st-mail_80').checked, mail_95: g('st-mail_95').checked, mail_95_hours: g('st-hours').value
		};
		setBusy(btn, true, 'Mentés…');
		return api('save_settings', payload).then(function () {
			toast('Beállítások elmentve.', 'ok');
			return loadOverview();
		}).catch(function (e) { toast(e.message, 'err'); }).then(function () { setBusy(btn, false); });
	}

	/* ---------- események ---------- */
	root.addEventListener('click', function (ev) {
		var el = ev.target.closest('[data-act]');
		if (!el || !root.contains(el)) { return; }
		var act = el.getAttribute('data-act');
		if (act === 'tab') {
			state.tab = el.getAttribute('data-tab');
			keepScroll(renderTab);
			if (state.tab === 'clean' || state.tab === 'settings') { if (!state.tasks) { loadTasks(); } }
		} else if (act === 'sort') {
			var k = el.getAttribute('data-key');
			if (state.sortKey === k) { state.sortDir *= -1; } else { state.sortKey = k; state.sortDir = (k === 'name' || k === 'owner' || k === 'engine') ? 1 : -1; }
			renderTableBody();
		} else if (act === 'drop') {
			var name = el.getAttribute('data-table');
			if (!window.confirm('VÉGLEGESEN törlöd a(z) ' + name + ' táblát?\nCsak akkor tedd, ha a hozzá tartozó plugin már nincs telepítve, és van mentésed!')) { return; }
			setBusy(el, true, 'Törlés…');
			api('droptable', { table: name }).then(function () { toast('Tábla törölve: ' + name, 'ok'); return loadOverview(); })
				.catch(function (e) { toast(e.message, 'err'); setBusy(el, false); });
		} else if (act === 'noauto') {
			var opt = el.getAttribute('data-opt');
			if (!window.confirm('Kikapcsolod az autoload-ot ennél az opciónál? Az opció megmarad, csak nem töltődik be minden oldalnál.')) { return; }
			setBusy(el, true, '…');
			api('noautoload', { option: opt }).then(function () { toast('Autoload kikapcsolva: ' + opt, 'ok'); return loadOverview(); })
				.catch(function (e) { toast(e.message, 'err'); setBusy(el, false); });
		} else if (act === 'clean') { doClean(el);
		} else if (act === 'optimize') { doOptimize(el);
		} else if (act === 'backup') { doBackup(el);
		} else if (act === 'save') { doSave(el);
		} else if (act === 'test') {
			setBusy(el, true, 'Küldés…');
			doSave(null).then(function () { return api('send_test'); })
				.then(function (r) { toast('Teszt email elküldve: ' + r.sent_to.join(', '), 'ok'); })
				.catch(function (e) { toast(e.message, 'err'); })
				.then(function () { setBusy(el, false); });
		}
	});

	root.addEventListener('change', function (ev) {
		var el = ev.target, what = el.getAttribute('data-change');
		if (what === 'group') { state.group = el.value; renderTableBody(); }
		else if (what === 'sel') { state.sel[el.getAttribute('data-id')] = el.checked; }
	});
	root.addEventListener('input', function (ev) {
		if (ev.target.getAttribute('data-input') === 'filter') { state.filter = ev.target.value; renderTableBody(); }
	});

	/* ---------- indítás ---------- */
	skeleton();
	loadOverview();
	loadTasks();
})();
