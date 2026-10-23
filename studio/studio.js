/* Studio — Kym's front-end publishing area. Vanilla JS, talks to the mwm/v1 REST API. */
(function () {
	'use strict';
	var B = window.MWM_STUDIO;
	var root = document.getElementById('mwm-studio');
	if (!B || !root) { return; }

	/* ---------------------------------------------------------------- helpers */
	function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
	function api(path, opts) {
		opts = opts || {};
		var init = { method: opts.method || 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': B.nonce } };
		if (opts.body instanceof FormData) { init.body = opts.body; }
		else if (opts.body !== undefined) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
		return fetch(B.rest + path, init).then(function (r) {
			return r.json().catch(function () { return {}; }).then(function (data) {
				if (!r.ok) { var e = new Error(data && data.message ? data.message : 'Something went wrong. Try again in a moment.'); e.data = data; throw e; }
				return data;
			});
		});
	}
	function toast(msg) {
		var t = document.createElement('div'); t.className = 'st-toast'; t.setAttribute('role', 'status'); t.textContent = msg;
		document.body.appendChild(t); setTimeout(function () { t.remove(); }, 2600);
	}
	function chip(label, on, data, cls) {
		var d = Object.keys(data).map(function (k) { return ' data-' + k + '="' + esc(data[k]) + '"'; }).join('');
		return '<button type="button" class="mwm-chip mwm-chip--md' + (on ? ' is-on' : '') + (cls ? ' ' + cls : '') + '" aria-pressed="' + (on ? 'true' : 'false') + '"' + d + '>' + esc(label) + '</button>';
	}
	function tag(text, cls) { return '<span class="mwm-tag ' + cls + '">' + esc(text) + '</span>'; }
	/* Video thumbnail that opens a larger version in a popup — Kym reads a lot from the thumbnail alone. */
	function thumbBtn(url, cls, title, link) {
		if (!url) { return '<span class="' + cls + '"></span>'; }
		return '<button type="button" class="' + cls + ' st-thumbbtn" style="background-image:url(' + esc(url) + ')" data-zoom="' + esc(url) + '" data-zoom-title="' + esc(title || '') + '" data-zoom-link="' + esc(link || '') + '" title="Click to see it bigger" aria-label="See the thumbnail bigger"><span class="st-thumbbtn__hint" aria-hidden="true">' + icon('search') + '</span></button>';
	}
	function watchUrl(ytId) { return ytId ? 'https://www.youtube.com/watch?v=' + encodeURIComponent(ytId) : ''; }
	function zoomOpen(url, title, link) {
		zoomClose();
		var box = document.createElement('div');
		box.className = 'st-zoom'; box.setAttribute('role', 'dialog'); box.setAttribute('aria-modal', 'true'); box.setAttribute('aria-label', 'Video thumbnail');
		box.innerHTML = '<div class="st-zoom__card"><img class="st-zoom__img" src="' + esc(url) + '" alt="' + esc(title || 'Video thumbnail') + '">' +
			(title || link ? '<div class="st-zoom__foot">' + (title ? '<span class="st-zoom__title">' + esc(title) + '</span>' : '') + (link ? '<a class="st-zoom__link" href="' + esc(link) + '" target="_blank" rel="noopener">' + icon('play') + 'Play on YouTube</a>' : '') + '</div>' : '') +
			'<button type="button" class="st-zoom__close" data-zoom-close aria-label="Close">×</button></div>';
		box.addEventListener('click', function (e) { if (!e.target.closest('.st-zoom__img, .st-zoom__link')) { zoomClose(); } });
		document.body.appendChild(box);
		document.body.classList.add('st-zoom-open');
		box.querySelector('[data-zoom-close]').focus();
	}
	function zoomClose() {
		var z = document.querySelector('.st-zoom');
		if (z) { z.remove(); document.body.classList.remove('st-zoom-open'); }
	}
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && document.querySelector('.st-zoom')) { e.preventDefault(); zoomClose(); } });
	/* Searchable subtopic picker: type to filter, pick one, or create a new one under the chosen topic. */
	function picker(key, topic, value) {
		var sel = topic ? topic.subtopics.filter(function (s) { return s.slug === value; })[0] : null;
		return '<div class="st-picker" data-picker="' + key + '" data-picker-topic="' + esc(topic ? topic.slug : '') + '" data-picker-selected="' + esc(sel ? sel.name : '') + '">' +
			'<div class="st-picker__control">' +
			'<input type="text" class="st-input st-picker__input" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-label="Subtopic" placeholder="' + (topic && topic.subtopics.length ? 'Search subtopics, or type a new one…' : 'No subtopics yet — type one to create it') + '" value="' + esc(sel ? sel.name : '') + '" autocomplete="off" data-picker-input>' +
			(sel ? '<button type="button" class="st-picker__clear" aria-label="Clear subtopic" data-picker-clear>✕</button>' : '<span class="st-picker__caret" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6.5l4 4 4-4"/></svg></span>') +
			'</div><div class="st-picker__list" role="listbox" hidden data-picker-list></div></div>';
	}
	function pickerOptions(topic, q, selected) {
		var ql = q.trim().toLowerCase();
		var items = topic ? topic.subtopics.filter(function (s) { return !ql || s.name.toLowerCase().indexOf(ql) > -1; }) : [];
		var html = items.map(function (s) { return '<button type="button" class="st-picker__opt' + (s.slug === selected ? ' is-on' : '') + '" role="option" aria-selected="' + (s.slug === selected ? 'true' : 'false') + '" data-picker-pick="' + esc(s.slug) + '">' + esc(s.name) + '</button>'; }).join('');
		var exact = topic && topic.subtopics.some(function (s) { return s.name.toLowerCase() === ql; });
		if (ql && !exact) { html += '<button type="button" class="st-picker__opt st-picker__opt--new" role="option" data-picker-create="' + esc(q.trim()) + '"><span class="st-picker__plus">+</span> Create “' + esc(q.trim()) + '”</button>'; }
		if (!html) { html = '<div class="st-picker__empty">No subtopics yet — type a name to create one.</div>'; }
		return html;
	}
	function pickerState(key) { return key === 'wssubtopic' ? S.ws : S.lesson; }
	function pickerPaint(box, q) {
		var topic = B.topics.filter(function (t) { return t.slug === box.getAttribute('data-picker-topic'); })[0];
		var state = pickerState(box.getAttribute('data-picker'));
		box.querySelector('[data-picker-list]').innerHTML = pickerOptions(topic, q, state.subtopic);
	}
	function pickerOpen(box, open) {
		box.querySelector('[data-picker-list]').toggleAttribute('hidden', !open);
		box.querySelector('[data-picker-input]').setAttribute('aria-expanded', open ? 'true' : 'false');
		box.classList.toggle('is-open', open);
	}
	function pickerCreate(box, name) {
		var key = box.getAttribute('data-picker'), topicSlug = box.getAttribute('data-picker-topic');
		var topic = B.topics.filter(function (t) { return t.slug === topicSlug; })[0];
		if (!topic) { return; }
		box.classList.add('is-busy');
		api('studio/subtopics', { method: 'POST', body: { topic: topicSlug, name: name } }).then(function (s) {
			if (!topic.subtopics.some(function (x) { return x.slug === s.slug; })) { topic.subtopics.push({ id: s.id, slug: s.slug, name: s.name, order: s.order }); }
			pickerState(key).subtopic = s.slug; render(); toast('Subtopic “' + s.name + '” added');
		}).catch(function (err) { box.classList.remove('is-busy'); toast(err.message); });
	}
	function levelName(slug) { var l = B.levels.filter(function (x) { return x.slug === slug; })[0]; return l ? l.name : ''; }
	/* A lesson can suit more than one level. Slugs in the site's order (Foundation, Higher, A-level), no repeats. */
	function sortLevels(slugs) { return B.levels.map(function (l) { return l.slug; }).filter(function (s) { return slugs.indexOf(s) > -1; }); }
	/* "GCSE Foundation & Higher", "GCSE Higher & A-level": same wording as mwm_levels_label() on the server. */
	function levelsLabel(slugs) {
		var prev = '', names = sortLevels(slugs || []).map(function (s) {
			var n = levelName(s), short = s.indexOf('gcse-') === 0 && prev.indexOf('gcse-') === 0;
			prev = s;
			return short ? n.replace(/^GCSE /, '') : n;
		});
		return names.length < 2 ? (names[0] || '') : names.slice(0, -1).join(', ') + ' & ' + names[names.length - 1];
	}
	/* Levels on a content row; older rows only carry level_slug. */
	function rowLevels(r) { return r.levels && r.levels.length ? r.levels : (r.level_slug ? [r.level_slug] : []); }
	function topicName(slug) {
		for (var i = 0; i < B.topics.length; i++) {
			if (B.topics[i].slug === slug) { return B.topics[i].name; }
			var s = B.topics[i].subtopics.filter(function (x) { return x.slug === slug; })[0];
			if (s) { return s.name; }
		}
		return '';
	}
	function icon(name) {
		var p = 'fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
		if (name === 'play') { return '<svg width="18" height="18" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M7 4.5l9 5.5-9 5.5z"></path></svg>'; }
		if (name === 'worksheet') { return '<svg width="18" height="18" viewBox="0 0 16 16" ' + p + '><rect x="3" y="2" width="10" height="12" rx="1.5"></rect><path d="M5.5 6h5M5.5 9h5"></path></svg>'; }
		if (name === 'download') { return '<svg width="18" height="18" viewBox="0 0 16 16" ' + p + '><path d="M8 2v8M4.5 6.5L8 10l3.5-3.5"></path><line x1="3" y1="13" x2="13" y2="13"></line></svg>'; }
		if (name === 'route') { return '<svg width="18" height="18" viewBox="0 0 16 16" ' + p + '><circle cx="3.5" cy="3.5" r="1.5"></circle><circle cx="12.5" cy="12.5" r="1.5"></circle><path d="M5 3.5h4a2.5 2.5 0 0 1 0 5H7a2.5 2.5 0 0 0 0 5h4"></path></svg>'; }
		if (name === 'calendar') { return '<svg width="18" height="18" viewBox="0 0 16 16" ' + p + '><rect x="2" y="3" width="12" height="11" rx="2"></rect><line x1="2" y1="6.5" x2="14" y2="6.5"></line><line x1="5.5" y1="1.5" x2="5.5" y2="4"></line><line x1="10.5" y1="1.5" x2="10.5" y2="4"></line></svg>'; }
		if (name === 'star') { return '<svg width="18" height="18" viewBox="0 0 16 16" ' + p + '><path d="M8 1.8l1.9 3.9 4.3.6-3.1 3 .7 4.3L8 11.6l-3.8 2 .7-4.3-3.1-3 4.3-.6z"></path></svg>'; }
		if (name === 'chart') { return '<svg width="18" height="18" viewBox="0 0 16 16" ' + p + '><path d="M2 13.5h12"></path><path d="M4 11V8M7.3 11V4.5M10.6 11V6.5M13.9 11V3"></path></svg>'; }
		if (name === 'up') { return '<svg width="14" height="14" viewBox="0 0 16 16" ' + p + '><path d="M8 13V3M3.5 7.5L8 3l4.5 4.5"></path></svg>'; }
		if (name === 'down') { return '<svg width="14" height="14" viewBox="0 0 16 16" ' + p + '><path d="M8 3v10M3.5 8.5L8 13l4.5-4.5"></path></svg>'; }
		if (name === 'paper') { return '<svg width="18" height="18" viewBox="0 0 16 16" ' + p + '><path d="M4 2h5.5L13 5.5V14H4z"></path><path d="M9.5 2v3.5H13"></path><path d="M6 8.5h4M6 11h4"></path></svg>'; }
		if (name === 'quiz') { return '<svg width="18" height="18" viewBox="0 0 16 16" ' + p + '><circle cx="8" cy="8" r="6"></circle><path d="M6.2 6.3a1.8 1.8 0 1 1 2.6 1.6c-.5.3-.8.6-.8 1.2"></path><circle cx="8" cy="11.3" r="0.4" fill="currentColor"></circle></svg>'; }
		if (name === 'search') { return '<svg width="16" height="16" viewBox="0 0 16 16" ' + p + '><circle cx="7" cy="7" r="4.5"></circle><path d="M10.5 10.5L14 14M7 5v4M5 7h4"></path></svg>'; }
		if (name === 'tick') { return '<svg width="24" height="24" viewBox="0 0 16 16" fill="none" stroke="#FFFFFF" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7"></path></svg>'; }
		return '';
	}
	var STEP_NAMES = ['Video', 'Where it goes', 'Practice', 'Check & publish'];

	/* ---------------------------------------------------------------- state */
	function freshLesson() {
		return { id: 0, step: 1, yt: '', video: null, videoError: '', finding: false, title: '', levels: ['gcse-higher'], topic: 'algebra', subtopic: '', ws: null, ans: null, quizText: '', quizResult: null, quizImages: {}, copied: false, publishing: false, published: null, editingTitle: '', publishError: '' };
	}
	function freshPaper(keep) {
		var now = new Date();
		return { id: 0, board: keep.board || 'edexcel', season: keep.season || (now.getMonth() >= 5 ? 'June' : 'November'), year: keep.year || (now.getMonth() >= 5 ? now.getFullYear() : now.getFullYear() - 1), tier: keep.tier || 'higher', paper: '1', qp: null, ms: null, worksheets: [], wsPdfs: [], published: null, publishing: false, error: '', editingTitle: '' };
	}
	function freshPredicted(keep) {
		var now = new Date();
		// Predicted papers are for the coming exam season: default to next year from September onwards.
		return { id: 0, board: keep.board || 'edexcel', level: keep.level || 'gcse-higher', year: keep.year || (now.getMonth() >= 8 ? now.getFullYear() + 1 : now.getFullYear()), name: '', calculator: true, qp: null, sol: null, worksheets: [], wsPdfs: [], published: null, publishing: false, error: '', editingTitle: '' };
	}
	function freshWorksheet() {
		return { id: 0, title: '', level: 'gcse-foundation', topic: 'number', subtopic: '', pdf: null, ans: null, desc: '', published: null, publishing: false, error: '', editingTitle: '' };
	}
	var S = {
		view: B.view || 'dash',
		playlists: B.playlists, sync: B.sync, synced: false, syncing: false, recent: B.recent,
		content: B.content, kindFilter: 'All', issueFilter: '', levelFilter: '', topicFilter: '', boardFilter: '', search: '', confirmId: null, lastDeleted: null, levelBusy: null,
		lesson: freshLesson(),
		pp: freshPaper({}),
		pr: freshPredicted({}),
		pathways: (B.pathways && B.pathways.levels) || {},
		pw: { level: 'gcse-higher', topic: '', data: null, subtopics: [], rows: [], orphans: [], loading: false, saving: false, error: '', saved: '', add: '', paste: '', copied: '', plans: (B.pathways && B.pathways.plans) || {}, planPaste: '', planSaving: false, planSaved: false },
		dismissed: B.dismissed || {},
		suggestions: B.suggestions || { items: [], hidden: [], total: 0, scanned_label: '' },
		sg: { filter: 'all', showHidden: false, confirmAll: false, busy: '', scanning: false, error: '' },
		ws: freshWorksheet(),
		ed: { paper: 'Paper 1 (non-calculator)', date: '', session: 'morning', level: 'gcse-higher', board: 'edexcel', checked: false, saving: false, error: '', dateConfirm: null },
		dates: B.dates,
		home: homeState(B.home),
		stats: { days: 28, data: {}, loading: false, error: '' }
	};
	function homeState(h) {
		h = h || {};
		return { hero: h.hero || null, heroTitle: h.hero_title || '', featured: (h.featured || []).slice(), autoHero: h.auto_hero || null, autoFeatured: h.auto_featured || [], max: h.max || 6, saving: false, saved: false, dirty: false, error: '', found: {} };
	}

	/* ---------------------------------------------------------------- views */
	function viewDash() {
		var cards = [
			{ icon: 'play', title: 'Add a new lesson', text: 'Paste a YouTube link and we’ll do the rest — about two minutes.', view: 'lesson', primary: true },
			{ icon: 'worksheet', title: 'Add a worksheet', text: 'A worksheet on its own, with its own page to send students to.', view: 'worksheets' },
			{ icon: 'route', title: 'Revision pathways', text: 'One per level and topic, built from your lessons. Put the subtopics in order and new lessons slot in by themselves.', view: 'pathways', label: 'Edit pathways' },
			{ icon: 'download', title: 'Upload past papers', text: 'Add a paper and its mark scheme as PDFs.', view: 'papers' },
			{ icon: 'paper', title: 'Upload predicted papers', text: 'Your own paper for the next exam, with worked solutions.', view: 'predicted' },
			{ icon: 'star', title: 'Home page', text: 'Choose the big lesson at the top of the home page (with a shorter title if you like) and the six lessons underneath.', view: 'home', label: 'Choose lessons' }
		];
		var status = S.synced ? 'Checked just now' : (S.sync && S.sync.last_relative ? S.sync.last_relative : 'Not checked yet');
		var syncLabel = S.syncing ? 'Checking…' : (S.synced ? '✓ All up to date' : 'Check for new videos now');
		return '<h1 class="st-h1">Hi ' + esc(B.user.name) + '</h1>' +
			'<p class="st-intro">Everything here goes straight onto the website, step by step. You can’t break anything — every change can be undone.</p>' +
			notifsHtml() +
			'<div class="st-cards">' + cards.map(function (c) {
				return '<div class="st-card"><span class="st-card__icon">' + icon(c.icon) + '</span><h2>' + esc(c.title) + '</h2><p>' + esc(c.text) + '</p>' +
					'<button type="button" class="mwm-btn ' + (c.primary ? 'mwm-btn--primary' : 'mwm-btn--secondary') + '" data-go="' + c.view + '">' + esc(c.label || 'Start') + '</button></div>';
			}).join('') + '</div>' +
			'<div class="st-card st-card--small"><span class="st-card__icon">' + icon('play') + '</span><div class="st-card__text"><h2>Suggested from YouTube</h2><p>' + esc((S.suggestions.items || []).length ? (S.suggestions.items.length === 1 ? '1 video on your channel isn’t on the site yet.' : S.suggestions.items.length + ' videos on your channel aren’t on the site yet.') : 'We check your channel overnight for videos that aren’t on the site.') + '</p></div><button type="button" class="mwm-linkbtn mwm-linkbtn--sm" data-go="suggestions">See suggestions<span aria-hidden="true">→</span></button></div>' +
			(B.stats ? '<div class="st-card st-card--small"><span class="st-card__icon">' + icon('chart') + '</span><div class="st-card__text"><h2>Site stats</h2><p>How many people visited, what they looked at and what they searched on Google.</p></div><button type="button" class="mwm-linkbtn mwm-linkbtn--sm" data-go="stats">See your stats<span aria-hidden="true">→</span></button></div>' : '') +
			'<div class="st-card st-card--small"><span class="st-card__icon">' + icon('calendar') + '</span><div class="st-card__text"><h2>Update exam dates</h2><p>Once a year, when the boards confirm them — verified dates appear on the exam calendar.</p></div><button type="button" class="mwm-linkbtn mwm-linkbtn--sm" data-go="dates">Update dates<span aria-hidden="true">→</span></button></div>' +
			'<div class="st-tintbox"><div class="st-tintbox__head"><h2>Quick Maths &amp; Gaming look after themselves</h2><span class="mwm-meta">' + esc(status) + '</span></div>' +
			'<p>Anything you add to these YouTube playlists appears on the site overnight, automatically. There’s nothing for you to do here.</p>' +
			'<div class="st-list">' + S.playlists.map(function (pl) { return '<div class="st-list__row"><span class="st-list__name">' + esc(pl.name) + '</span><span class="st-list__meta">' + esc(pl.meta) + '</span></div>'; }).join('') +
			'<div class="st-list__foot"><button type="button" class="mwm-linkbtn mwm-linkbtn--sm" data-sync' + (S.syncing ? ' disabled' : '') + '>' + esc(syncLabel) + '</button>' + (S.sync && S.sync.error && !S.synced ? '<p class="st-error" style="margin-top:8px">' + esc(S.sync.error) + '</p>' : '') + '</div></div></div>' +
			'<h2 class="st-h2">Recently added</h2>' +
			'<div class="st-list st-list--12">' + (S.recent.length ? S.recent.map(function (r) { return '<div class="st-list__row"><span class="st-list__name" style="flex:1">' + esc(r.title) + '</span><span class="st-list__sub" style="margin:0;white-space:nowrap">' + esc(r.added) + '</span>' + (r.status === 'draft' ? tag('Draft', 'mwm-tag--warn') : tag('Live', 'mwm-tag--live')) + '</div>'; }).join('') : '<div class="st-list__row"><span class="mwm-meta">Nothing added yet — your first lesson will show here.</span></div>') + '</div>' +
			'<button type="button" class="mwm-linkbtn st-more" data-go="content">See and edit everything you’ve added<span aria-hidden="true">→</span></button>';
	}

	function stepPills(step) {
		return '<div class="st-steps">' + STEP_NAMES.map(function (n, i) {
			var cls = step === i + 1 ? ' is-on' : (step > i + 1 ? ' is-done' : '');
			return '<span class="st-step' + cls + '"' + (step === i + 1 ? ' aria-current="step"' : '') + '>' + (i + 1) + ' · ' + n + '</span>';
		}).join('') + '</div>';
	}

	function viewLesson() {
		var L = S.lesson;
		var html = '<a href="' + esc(B.site + 'studio/') + '" class="st-back" data-go="dash"><span aria-hidden="true">←</span>Back to your dashboard</a>' +
			'<h1 class="st-h1 st-h1--after-back">' + (L.id ? 'Edit a lesson' : 'Add a new lesson') + '</h1>';
		if (L.id) { html += '<div class="st-banner">You’re editing <strong>' + esc(L.editingTitle) + '</strong> — change what you need, then publish again. Nothing changes on the site until you do.</div>'; }
		html += stepPills(L.step);

		if (L.step === 1) {
			html += '<div class="st-panel"><h2>Paste the YouTube link</h2><p class="st-panel__sub">Copy the link from YouTube (the Share button), paste it here, and we’ll fill in the title, length and thumbnail for you.</p>' +
				'<form class="st-inputrow" data-find-form><input type="url" class="st-input" value="' + esc(L.yt) + '" placeholder="https://youtu.be/…" aria-label="YouTube link" data-yt><button type="submit" class="mwm-btn mwm-btn--primary mwm-btn--sm"' + (L.finding ? ' disabled' : '') + '>' + (L.finding ? 'Looking…' : 'Find my video') + '</button></form>';
			if (L.videoError) { html += '<p class="st-error">' + esc(L.videoError) + '</p>'; }
			if (L.video) {
				var v = L.video;
				var meta = [v.duration_label, v.published_label ? 'uploaded ' + v.published_label : ''].filter(Boolean).join(' · ');
				html += '<div class="st-found">' + thumbBtn(v.thumbnail, 'st-found__thumb', v.title, watchUrl(v.id)) + '<div><div class="st-found__title">' + esc(v.title) + '</div><div class="st-found__meta">' + esc(meta || 'Length will show once published') + '</div>' +
					(v.existing_id && v.existing_id !== L.id ? '<div class="st-error" style="margin-top:4px">This video is already on the site. <button type="button" class="mwm-linkbtn mwm-linkbtn--sm" data-edit-lesson="' + v.existing_id + '">Edit that lesson instead</button></div>' : '<div class="st-found__ok">✓ Found it — that’s the one?</div>') + '</div></div>';
				if (v.warning) { html += '<p class="st-note">' + esc(v.warning) + '</p>'; }
			}
			html += '</div>';
		}
		if (L.step === 2) {
			var topic = B.topics.filter(function (t) { return t.slug === L.topic; })[0];
			if (L.video) {
				html += '<div class="st-found st-found--compact">' + thumbBtn(L.video.thumbnail, 'st-found__thumb', L.video.title, watchUrl(L.video.id)) + '<div><div class="st-found__title">' + esc(L.video.title) + '</div><div class="st-found__meta">' + esc([L.video.duration_label, L.video.published_label ? 'uploaded ' + L.video.published_label : ''].filter(Boolean).join(' · ') || 'On YouTube') + '</div></div></div>';
			}
			html += '<div class="st-panel"><h2>What’s it called?</h2><p class="st-panel__sub">Students see this as the lesson title. It starts as the YouTube title — shorten it or tidy it up if you like.</p>' +
				'<div class="st-inputrow" style="margin-top:12px"><input type="text" class="st-input" value="' + esc(lessonTitle()) + '" placeholder="e.g. Solving quadratics by factorising" aria-label="Lesson title" maxlength="140" data-lesson-title></div>' +
				(L.video && L.title && L.title !== L.video.title ? '<p class="st-note st-note--8">On YouTube it’s “' + esc(L.video.title) + '” — <button type="button" class="mwm-linkbtn mwm-linkbtn--sm" data-title-reset>use that instead</button></p>' : '') + '</div>';
			html += '<div class="st-panel"><h2>Where does it belong?</h2><p class="st-panel__sub">Not sure? Pick the closest — you can change it any time.</p>' +
				'<div class="st-label">Level <span style="font-weight:400;color:var(--muted)">(tick more than one if it suits both)</span></div><div class="st-chips">' + B.levels.map(function (l) { return chip(l.name, L.levels.indexOf(l.slug) > -1, { level: l.slug }); }).join('') + '</div>' +
				'<div class="st-label st-label--24">Topic</div><div class="st-chips">' + B.topics.map(function (t) { return chip(t.name, L.topic === t.slug, { topic: t.slug }); }).join('') + '</div>';
			html += '<div class="st-label st-label--24">Subtopic <span style="font-weight:400;color:var(--muted)">(optional)</span></div>' + picker('subtopic', topic, L.subtopic) +
				'<p class="st-note st-note--8">Can’t find the right one? Type a new name and choose “Create” — it’s added under ' + esc(topic ? topic.name : 'the topic') + ' straight away.</p>';
			html += '</div>';
		}
		if (L.step === 3) {
			html += '<div class="st-panel"><h2>Add the practice bits (optional)</h2><p class="st-panel__sub">Skip anything that isn’t ready — the site never shows an empty button, just a friendly “not yet” note.</p>' +
				fileRow('Worksheet PDF', L.ws ? '✓ ' + L.ws.filename + ' added — it gets its own worksheet page too' : 'No worksheet yet — students will see a friendly note instead of a button.', !!L.ws, 'ws', 'st-filerow--first') +
				fileRow('Worked answers PDF', L.ans ? '✓ ' + L.ans.filename + ' added' : 'Optional — appears behind “Reveal answers”.', !!L.ans, 'ans', '') +
				'<div class="st-quiz"><div class="st-filerow__label">Quiz <span>(optional)</span></div><div class="st-quiz__sub">Ask ChatGPT to write one for you: copy the instructions below — they’re already filled in for this lesson — then paste back what it gives you.</div>' +
				'<div class="st-prompt"><div class="st-prompt__head"><span class="st-prompt__title">Instructions for ChatGPT</span><button type="button" class="st-copy" data-copy>' + (L.copied ? '✓ Copied' : 'Copy') + '</button></div><div class="st-prompt__text">' + esc(quizPrompt()) + '</div></div>' +
				'<textarea class="st-textarea" rows="5" placeholder="Paste ChatGPT’s answer here — it starts with { and ends with }" aria-label="Quiz JSON" data-quiz-text>' + esc(L.quizText) + '</textarea>' +
				'<div class="st-actions"><button type="button" class="mwm-btn mwm-btn--primary st-btn-check" data-check-quiz>Check my quiz</button><label class="st-textlink"><input type="file" accept=".json,application/json" data-quiz-file>…or upload the .json file</label></div>';
			if (L.quizResult && L.quizResult.ok) {
				html += '<div class="st-quizok"><div class="st-quizok__title">✓ Quiz added — ' + esc(L.quizResult.summary) + '</div><div class="st-quizok__list">' + L.quizResult.questions.map(function (q, i) {
					var row = '<div><div class="st-quizok__row"><span>' + (i + 1) + '. ' + esc(q.q) + '</span><span class="st-quizok__kind">' + esc(L.quizResult.kinds[i]) + '</span></div>';
					if (q.type === 'choice-image') {
						var img = L.quizImages[i];
						row += '<div class="st-imgrow"><div class="st-imgrow__text">' + (q.image ? '<div>ChatGPT suggests: “' + esc(q.image) + '”</div>' : '') + '<div>' + (img ? '✓ ' + esc(img.filename) + ' attached' : 'Attach the picture — save ChatGPT’s image if it’s right, or use a screenshot from your video. Double-check any picture ChatGPT draws.') + '</div></div>' +
							'<label class="st-filebtn st-filebtn--sm"><input type="file" accept="image/*" data-quiz-img="' + i + '">Choose image</label></div>';
					}
					return row + '</div>';
				}).join('') + '</div></div>';
			}
			if (L.quizResult && !L.quizResult.ok) { html += '<p class="st-error">' + esc(L.quizResult.error) + '</p>'; }
			html += '</div></div>';
		}
		if (L.step === 4) {
			if (L.published) {
				html += '<div class="st-success"><span class="st-success__icon">' + icon('tick') + '</span><h2>It’s live!</h2><p>Your lesson is on the site now — in Browse, and in the pathway if the topic belongs to one.</p>' +
					'<div class="st-success__actions"><a href="' + esc(L.published.url) + '" class="mwm-btn mwm-btn--primary">See the lesson</a><button type="button" class="mwm-btn mwm-btn--secondary" data-reset-lesson>Add another</button></div></div>';
			} else {
				var extras = [L.ws ? 'Worksheet' : '', L.ans ? 'Answers' : '', L.quizResult && L.quizResult.ok ? 'Quiz (' + L.quizResult.questions.length + ' questions)' : ''].filter(Boolean);
				var mins = L.video && L.video.minutes_label ? L.video.minutes_label : '';
				html += '<div class="st-panel"><h2>Check it, then publish</h2><p class="st-panel__sub">This is exactly how students will see it.</p>' +
					'<div class="st-preview">' + thumbBtn(L.video ? L.video.thumbnail : '', 'st-preview__thumb', L.video ? L.video.title : '', L.video ? watchUrl(L.video.id) : '') + '<div class="st-preview__body"><div class="mwm-tags">' + tag(levelsLabel(L.levels), 'mwm-tag--higher') + tag(topicName(L.subtopic || L.topic), 'mwm-tag--outline') + '</div>' +
					'<div class="st-preview__title">' + esc(lessonTitle()) + '</div><div class="st-preview__meta">' + esc([mins].concat(extras).filter(Boolean).join(' · ')) + '</div></div></div>' +
					'<button type="button" class="mwm-btn mwm-btn--primary mwm-btn--lg st-publish" data-publish' + (L.publishing ? ' disabled' : '') + '>' + (L.publishing ? 'Publishing…' : (L.id ? 'Publish changes' : 'Publish lesson')) + '</button>' +
					(L.publishError ? '<p class="st-error">' + esc(L.publishError) + '</p>' : '') +
					'<p class="st-note">It goes live straight away — and you can take it down again just as quickly.</p></div>';
			}
		}
		if (!(L.step === 4 && L.published)) {
			var canNext = L.step < 4 && (L.step !== 1 || (L.video && (!L.video.existing_id || L.video.existing_id === L.id)));
			html += '<div class="st-nav">' + (L.step > 1 ? '<button type="button" class="mwm-btn mwm-btn--quiet" data-back>Back</button>' : '<span></span>') +
				(canNext ? '<button type="button" class="mwm-btn mwm-btn--primary" data-next>' + (L.step === 3 ? 'Check it over' : 'Next') + '</button>' : '') + '</div>';
		}
		return html;
	}

	function fileRow(label, status, ok, key, cls) {
		return '<div class="st-filerow ' + cls + '"><div><div class="st-filerow__label">' + label + '</div><div class="st-filerow__status' + (ok ? ' is-ok' : '') + '">' + esc(status) + '</div></div><label class="st-filebtn"><input type="file" accept="application/pdf" data-pdf="' + key + '">Choose PDF</label></div>';
	}

	/* "Needs attention" filters per content kind; keys match the `flags` the REST API puts on each row. */
	var ISSUES = {
		Lessons: [
			{ key: 'draft', label: 'Draft — not on the site yet', tag: 'Draft' },
			{ key: 'level', label: 'Level needs checking', tag: 'Check level' },
			{ key: 'no_worksheet', label: 'Missing worksheet', tag: 'No worksheet' },
			{ key: 'no_answers', label: 'Missing worked answers', tag: 'No answers' },
			{ key: 'no_quiz', label: 'No quiz yet', tag: 'No quiz' },
			{ key: 'video', label: 'Video missing or not playable', tag: 'Video problem' }
		],
		Worksheets: [
			{ key: 'unlinked', label: 'Not linked to a lesson', tag: 'No lesson' }
		],
	};
	/* Dashboard notifications: one per issue type, most urgent first. text(n) is the sentence Kym sees. */
	var NOTIFS = [
		{ key: 'suggested', view: 'suggestions', count: function () { return (S.suggestions.items || []).length; }, text: function (n) { return n === 1 ? '1 video on your YouTube channel isn’t on the site yet' : n + ' videos on your YouTube channel aren’t on the site yet'; } },
		{ key: 'video', kind: 'Lessons', urgent: true, text: function (n) { return n === 1 ? '1 lesson has a video that’s missing or won’t play' : n + ' lessons have a video that’s missing or won’t play'; } },
		{ key: 'draft', kind: 'Lessons', text: function (n) { return n === 1 ? '1 draft lesson is waiting to be finished and published' : n + ' draft lessons are waiting to be finished and published'; } },
		{ key: 'level', kind: 'Lessons', text: function (n) { return n === 1 ? '1 lesson needs its level checking' : n + ' lessons need their level checking'; } },
		{ key: 'no_worksheet', kind: 'Lessons', text: function (n) { return n === 1 ? '1 lesson has no worksheet' : n + ' lessons have no worksheet'; } },
		{ key: 'no_answers', kind: 'Lessons', text: function (n) { return n === 1 ? '1 lesson worksheet has no worked answers' : n + ' lesson worksheets have no worked answers'; } },
		{ key: 'unlinked', kind: 'Worksheets', text: function (n) { return n === 1 ? '1 worksheet isn’t linked to a lesson' : n + ' worksheets aren’t linked to a lesson'; } },
		{ key: 'no_quiz', kind: 'Lessons', text: function (n) { return n === 1 ? '1 lesson has no quiz' : n + ' lessons have no quiz'; } },
		{ key: 'unplaced', view: 'pathways', count: function () { return pwCount('unplaced'); }, text: function (n) { return n === 1 ? '1 subtopic needs placing in its revision pathway' : n + ' subtopics need placing in their revision pathways'; } },
		{ key: 'orphan', view: 'pathways', count: function () { return pwCount('orphans'); }, text: function (n) { return n === 1 ? '1 lesson has no subtopic, so it sits at the end of its pathway' : n + ' lessons have no subtopic, so they sit at the end of their pathways'; } }
	];
	var KIND_OF = { Lessons: 'Lesson', Worksheets: 'Worksheet' };
	function notifCounts() {
		return NOTIFS.map(function (n) {
			var count = n.count ? n.count() : S.content.filter(function (it) { return it.kind === KIND_OF[n.kind] && (it.flags || []).indexOf(n.key) > -1; }).length;
			var at = S.dismissed[n.key];
			return { def: n, count: count, ignored: typeof at === 'number' && count <= at };
		}).filter(function (x) { return x.count > 0; });
	}
	function notifsHtml() {
		var all = notifCounts();
		var live = all.filter(function (x) { return !x.ignored; });
		var ignored = all.length - live.length;
		var html = '<div class="st-notes"><div class="st-notes__head"><h2>Needs attention</h2>' + (ignored ? '<button type="button" class="mwm-linkbtn mwm-linkbtn--sm" data-notif-restore>' + ignored + (ignored === 1 ? ' ignored · show it' : ' ignored · show them') + '</button>' : '') + '</div>';
		if (!live.length) {
			html += '<p class="st-notes__empty">' + (all.length ? 'Nothing new — everything outstanding is ignored for now.' : 'Nothing needs your attention right now.') + '</p>';
		} else {
			html += live.map(function (x) {
				return '<div class="st-notes__row' + (x.def.urgent ? ' is-urgent' : '') + '"><span class="st-notes__dot" aria-hidden="true"></span><span class="st-notes__text">' + esc(x.def.text(x.count)) + '</span>' +
					'<span class="st-notes__actions"><button type="button" class="st-smallbtn" data-notif-go="' + x.def.key + '">Show them</button><button type="button" class="st-smallbtn st-smallbtn--quiet" data-notif-ignore="' + x.def.key + '" data-count="' + x.count + '" title="Hide this until more turn up">Ignore</button></span></div>';
			}).join('');
		}
		return html + '</div>';
	}
	function issueTag(key) {
		var all = ISSUES.Lessons.concat(ISSUES.Worksheets, ISSUES.Pathways);
		var i = all.filter(function (x) { return x.key === key; })[0];
		return i ? i.tag : key;
	}
	/* Level / topic / board / search filters on the content list. Rows without the field (e.g. a quiz with no lesson) drop out when that filter is set. */
	function rowMatches(r, skip) {
		if (skip !== 'level' && S.levelFilter && rowLevels(r).indexOf(S.levelFilter) < 0) { return false; }
		if (skip !== 'topic' && S.topicFilter && r.topic_slug !== S.topicFilter) { return false; }
		if (skip !== 'board' && S.boardFilter && (r.boards ? r.boards.indexOf(S.boardFilter) < 0 : r.board !== S.boardFilter)) { return false; }
		if (skip !== 'search' && S.search) {
			var q = S.search.toLowerCase();
			if ((r.title + ' ' + (r.meta || '')).toLowerCase().indexOf(q) < 0) { return false; }
		}
		return true;
	}
	function filtersOn() { return !!(S.levelFilter || S.topicFilter || S.boardFilter || S.search); }
	function filterBar(ofKind) {
		var count = function (skip, test) { return ofKind.filter(function (r) { return rowMatches(r, skip) && test(r); }).length; };
		var hasTopics = ofKind.some(function (r) { return r.topic_slug; });
		var hasBoards = ofKind.some(function (r) { return r.board || (r.boards && r.boards.length); });
		var html = '<div class="st-filters">';
		html += '<div class="st-filters__group"><span class="st-filters__label">Level</span><div class="st-chips st-chips--flush">' +
			chip('All', !S.levelFilter, { flevel: '' }, 'st-fchip') +
			B.levels.map(function (l) { var n = count('level', function (r) { return rowLevels(r).indexOf(l.slug) > -1; }); return chip(l.name.replace(/^GCSE /, '') + ' · ' + n, S.levelFilter === l.slug, { flevel: l.slug }, 'st-fchip' + (n ? '' : ' is-empty')); }).join('') + '</div></div>';
		if (hasTopics) {
			html += '<div class="st-filters__group"><label class="st-filters__label" for="st-ftopic">Topic</label><select id="st-ftopic" class="st-select" data-ftopic><option value="">All topics</option>' +
				B.topics.map(function (t) { var n = count('topic', function (r) { return r.topic_slug === t.slug; }); return '<option value="' + esc(t.slug) + '"' + (S.topicFilter === t.slug ? ' selected' : '') + (n ? '' : ' disabled') + '>' + esc(t.name) + ' (' + n + ')</option>'; }).join('') + '</select></div>';
		}
		if (hasBoards) {
			html += '<div class="st-filters__group"><span class="st-filters__label">Board</span><div class="st-chips st-chips--flush">' + chip('All', !S.boardFilter, { fboard: '' }, 'st-fchip') +
				Object.keys(B.boards).map(function (b) { var n = count('board', function (r) { return r.boards ? r.boards.indexOf(b) > -1 : r.board === b; }); return chip(B.boards[b] + ' · ' + n, S.boardFilter === b, { fboard: b }, 'st-fchip' + (n ? '' : ' is-empty')); }).join('') + '</div></div>';
		}
		html += '<div class="st-filters__group st-filters__group--grow"><label class="st-filters__label" for="st-fsearch">Search</label><input type="search" id="st-fsearch" class="st-input st-input--sm" placeholder="Type part of a title or topic…" value="' + esc(S.search) + '" data-fsearch></div>';
		return html + '</div>';
	}

	function viewContent() {
		var kinds = ['All', 'Lessons', 'Worksheets', 'Past papers', 'Predicted papers', 'Exam dates', 'Quizzes'];
		var map = { 'Lessons': 'Lesson', 'Worksheets': 'Worksheet', 'Past papers': 'Past paper', 'Predicted papers': 'Predicted paper', 'Exam dates': 'Exam date', 'Quizzes': 'Quiz' };
		var ofKind = S.content.filter(function (it) { return S.kindFilter === 'All' || map[S.kindFilter] === it.kind; });
		// A topic or board filter left over from another kind would silently empty the list, so drop it when the control isn't shown.
		if (S.topicFilter && !ofKind.some(function (r) { return r.topic_slug; })) { S.topicFilter = ''; }
		if (S.boardFilter && !ofKind.some(function (r) { return r.board || (r.boards && r.boards.length); })) { S.boardFilter = ''; }
		var base = ofKind.filter(function (r) { return rowMatches(r); });
		var issues = ISSUES[S.kindFilter] || [];
		var rows = S.issueFilter ? base.filter(function (it) { return (it.flags || []).indexOf(S.issueFilter) > -1; }) : base;
		var issuesHtml = '';
		if (issues.length) {
			var fine = base.filter(function (it) { return !(it.flags || []).length; }).length;
			issuesHtml = '<div class="st-issues"><span class="st-issues__label">Needs attention</span><div class="st-chips st-chips--flush">' +
				issues.map(function (i) {
					var n = base.filter(function (it) { return (it.flags || []).indexOf(i.key) > -1; }).length;
					return chip(i.label + ' · ' + n, S.issueFilter === i.key, { issue: i.key }, n ? '' : 'is-empty');
				}).join('') + '</div>' +
				'<div class="st-issues__meta">' + (S.issueFilter ? 'Showing ' + rows.length + ' of ' + base.length + ' · <button type="button" class="mwm-linkbtn mwm-linkbtn--sm" data-issue="">Show all</button>' : fine + ' of ' + base.length + ' have nothing outstanding') + '</div></div>';
		}
		var showing = filtersOn() ? '<div class="st-filters__meta">Showing ' + rows.length + ' of ' + ofKind.length + (S.kindFilter === 'All' ? ' items' : ' ' + S.kindFilter.toLowerCase()) + ' · <button type="button" class="mwm-linkbtn mwm-linkbtn--sm" data-fclear>Clear filters</button></div>' : '';
		return '<a href="' + esc(B.site + 'studio/') + '" class="st-back" data-go="dash"><span aria-hidden="true">←</span>Back to your dashboard</a>' +
			'<h1 class="st-h1 st-h1--after-back">Your content</h1>' +
			'<p class="st-intro">Everything that’s on the site. Edit anything, or remove it — there’s an undo if you change your mind.</p>' +
			'<div class="st-chips" style="margin-top:24px">' + kinds.map(function (k) { return chip(k, S.kindFilter === k, { kind: k }); }).join('') + '</div>' +
			filterBar(ofKind) + showing +
			issuesHtml +
			(S.lastDeleted ? '<div class="st-undo"><span>“' + esc(S.lastDeleted.item.title) + '” has been removed from the site.</span><button type="button" data-undo>Undo</button></div>' : '') +
			'<div class="st-list st-list--20">' + (rows.length ? rows.map(function (r) {
				var actions = S.confirmId === r.id
					? '<span class="st-confirm-text">Remove from the site?</span><button type="button" class="st-smallbtn st-smallbtn--danger" data-remove="' + r.id + '">Yes, remove</button><button type="button" class="st-smallbtn st-smallbtn--keep" data-keep>Keep it</button>'
					: '<button type="button" class="st-smallbtn" data-edit="' + r.id + '">Edit</button><button type="button" class="st-smallbtn st-smallbtn--quiet" data-ask-remove="' + r.id + '">Remove</button>';
				var flags = (r.flags || []).map(function (f) { return tag(issueTag(f), 'mwm-tag--warn mwm-tag--sm' + (S.issueFilter === f ? ' is-on' : '')); }).join('');
				var rl = rowLevels(r), flagged = (r.flags || []).indexOf('level') > -1;
				var quick = r.kind === 'Lesson' ? '<div class="st-qlevels' + (S.levelBusy === r.id ? ' is-busy' : '') + '" role="group" aria-label="Levels"><span class="st-qlevels__label">Level</span>' + B.levels.map(function (l) {
					var on = rl.indexOf(l.slug) > -1, check = on && flagged;
					var tip = check ? 'Click to confirm this level' : (on ? (rl.length > 1 ? 'Click to take this level off' : '') : 'Click to add this level');
					return '<button type="button" class="st-qlevel' + (on ? ' is-on' : '') + (check ? ' is-check' : '') + (on && rl.length === 1 && !check ? ' is-only' : '') + '" aria-pressed="' + (on ? 'true' : 'false') + '" data-qlevel="' + esc(l.slug) + '" data-id="' + r.id + '"' + (S.levelBusy === r.id ? ' disabled' : '') + (tip ? ' title="' + tip + '"' : '') + '>' + esc(l.name.replace(/^GCSE /, '')) + (check ? ' ✓' : '') + '</button>';
				}).join('') + (!rl.length ? '<span class="st-qlevels__hint">not set yet</span>' : (flagged ? '<span class="st-qlevels__hint">click to confirm, or pick the right one</span>' : '')) + '</div>' : '';
				return '<div class="st-list__row st-list__row--16">' + listMedia(r) + '<div class="st-list__main"><div class="st-list__name">' + esc(r.title) + '</div><div class="st-list__sub">' + esc(r.meta) + '</div>' + (flags ? '<div class="st-list__flags">' + flags + '</div>' : '') + quick + '</div>' + tag(r.kind, 'mwm-tag--outline mwm-tag--sm') + actions + '</div>';
			}).join('') : '<div class="st-list__row"><span class="mwm-meta">' + (S.issueFilter ? 'Nothing needs attention here — nice.' : (filtersOn() ? 'Nothing matches those filters — try clearing one.' : 'Nothing here yet.')) + '</span></div>') + '</div>' +
			'<p class="st-note st-note--16">Removing a lesson never deletes your video — it stays safe on YouTube.</p>';
	}

	/* Left-hand cell on a content row: the video thumbnail (click to enlarge, play badge to YouTube) or an icon for everything else. */
	var KIND_ICON = { 'Lesson': 'play', 'Worksheet': 'worksheet', 'Past paper': 'paper', 'Predicted paper': 'paper', 'Exam date': 'calendar', 'Quiz': 'quiz' };
	function listMedia(r) {
		if (r.kind === 'Lesson' && r.thumb) {
			var link = watchUrl(r.youtube_id);
			return '<div class="st-list__media">' + thumbBtn(r.thumb, 'st-list__thumb', r.title, link) +
				(link ? '<a class="st-list__play" href="' + esc(link) + '" target="_blank" rel="noopener" title="Play on YouTube" aria-label="Play on YouTube">' + icon('play') + '</a>' : '') + '</div>';
		}
		return '<div class="st-list__media"><span class="st-list__icon" aria-hidden="true">' + icon(KIND_ICON[r.kind] || 'paper') + '</span></div>';
	}

	function viewWorksheet() {
		var W = S.ws;
		var html = '<a href="' + esc(B.site + 'studio/') + '" class="st-back" data-go="dash"><span aria-hidden="true">←</span>Back to your dashboard</a>' +
			'<h1 class="st-h1 st-h1--after-back">' + (W.id ? 'Edit a worksheet' : 'Add a worksheet') + '</h1>';
		if (W.id) { html += '<div class="st-banner">You’re editing <strong>' + esc(W.editingTitle) + '</strong> — change what you need, then publish again.</div>'; }
		if (W.published) {
			html += '<div class="st-success"><span class="st-success__icon">' + icon('tick') + '</span><h2>It’s live!</h2><p>The worksheet has its own page now — share the link with students, or find it under Worksheets.</p>' +
				'<div class="st-success__actions"><a href="' + esc(W.published.url) + '" class="mwm-btn mwm-btn--primary">See the worksheet</a><button type="button" class="mwm-btn mwm-btn--secondary" data-reset-ws>Add another</button></div>' +
				'<p class="st-note st-note--16" style="text-align:center">Link to give out: <a href="' + esc(W.published.url) + '">' + esc(W.published.url) + '</a></p></div>';
			return html;
		}
		var topic = B.topics.filter(function (t) { return t.slug === W.topic; })[0];
		html += '<p class="st-intro">For a worksheet that isn’t part of a lesson. If it goes with a video, add it through “Add a lesson” instead and it gets linked up for you.</p>' +
			'<div class="st-panel"><h2>What’s it called?</h2><p class="st-panel__sub">Students see this as the page title, so keep it plain — “Sharing in a ratio” rather than a file name.</p>' +
			'<div class="st-inputrow"><input type="text" class="st-input" value="' + esc(W.title) + '" placeholder="e.g. Sharing in a ratio" aria-label="Worksheet name" data-ws-title></div>' +
			'<div class="st-label st-label--24">Level</div><div class="st-chips">' + B.levels.map(function (l) { return chip(l.name, W.level === l.slug, { wslevel: l.slug }); }).join('') + '</div>' +
			'<div class="st-label st-label--24">Topic</div><div class="st-chips">' + B.topics.map(function (t) { return chip(t.name, W.topic === t.slug, { wstopic: t.slug }); }).join('') + '</div>';
		html += '<div class="st-label st-label--24">Subtopic <span style="font-weight:400;color:var(--muted)">(optional)</span></div>' + picker('wssubtopic', topic, W.subtopic) +
			'<p class="st-note st-note--8">Can’t find the right one? Type a new name and choose “Create”.</p>';
		html += '</div>' +
			'<div class="st-panel"><h2>The PDFs</h2><p class="st-panel__sub">The worksheet itself, and the worked answers if you have them — answers sit behind “Reveal answers” on the page.</p>' +
			'<div class="st-filerow st-filerow--first"><div><div class="st-filerow__label">Worksheet PDF</div><div class="st-filerow__status' + (W.pdf ? ' is-ok' : '') + '">' + esc(W.pdf ? '✓ ' + W.pdf.filename + ' added' : 'Needed before you can publish.') + '</div></div><label class="st-filebtn"><input type="file" accept="application/pdf" data-pdf="wspdf">Choose PDF</label></div>' +
			'<div class="st-filerow"><div><div class="st-filerow__label">Worked answers PDF <span>(optional)</span></div><div class="st-filerow__status' + (W.ans ? ' is-ok' : '') + '">' + esc(W.ans ? '✓ ' + W.ans.filename + ' added' : 'Optional — appears behind “Reveal answers”.') + '</div></div><label class="st-filebtn"><input type="file" accept="application/pdf" data-pdf="wsans">Choose PDF</label></div>' +
			'<div class="st-label st-label--24">A line about it <span style="font-weight:400;color:var(--muted)">(optional)</span></div>' +
			'<textarea class="st-textarea" rows="3" style="font-family:inherit;font-size:14px" placeholder="e.g. Ten exam-style questions on sharing in a ratio, with a tricky one at the end." aria-label="Description" data-ws-desc>' + esc(W.desc) + '</textarea></div>' +
			'<div class="st-panel"><h2>Check it, then publish</h2>' +
			'<div class="st-preview"><span class="st-preview__thumb mwm-thumb--doc" style="display:flex;align-items:center;justify-content:center"><span class="mwm-thumb__doc">' + icon('worksheet') + '<span>PDF</span></span></span><div class="st-preview__body"><div class="mwm-tags">' + tag(levelName(W.level), 'mwm-tag--higher') + tag(topicName(W.subtopic || W.topic), 'mwm-tag--outline') + '</div>' +
			'<div class="st-preview__title">' + esc(W.title || 'Untitled worksheet') + '</div><div class="st-preview__meta">' + esc([W.pdf ? W.pdf.label : 'No PDF yet', W.ans ? 'Answers' : ''].filter(Boolean).join(' · ')) + '</div></div></div>' +
			(W.pdf || W.id ? '<button type="button" class="mwm-btn mwm-btn--primary mwm-btn--lg st-publish" data-publish-ws' + (W.publishing ? ' disabled' : '') + '>' + (W.publishing ? 'Publishing…' : (W.id ? 'Publish changes' : 'Publish worksheet')) + '</button>' : '<p class="st-note st-note--16">Add the worksheet PDF above and the publish button appears here.</p>') +
			(W.error ? '<p class="st-error">' + esc(W.error) + '</p>' : '') +
			'<p class="st-note">It goes live straight away — and you can take it down again just as quickly.</p></div>';
		return html;
	}

	function viewPapers() {
		var P = S.pp;
		var html = '<a href="' + esc(B.site + 'studio/') + '" class="st-back" data-go="dash"><span aria-hidden="true">←</span>Back to your dashboard</a>' +
			'<h1 class="st-h1 st-h1--after-back">' + (P.id ? 'Edit a past paper' : 'Upload a past paper') + '</h1>';
		if (P.id) { html += '<div class="st-banner">You’re editing <strong>' + esc(P.editingTitle) + '</strong> — change what you need, then publish again.</div>'; }
		if (P.published) {
			html += '<div class="st-success"><span class="st-success__icon">' + icon('tick') + '</span><h2>Published!</h2><p>' + esc(P.published.summary) + '</p>' +
				'<div class="st-success__actions"><a href="' + esc(B.urls.pastPapers) + '" class="mwm-btn mwm-btn--primary">See the past papers page</a><button type="button" class="mwm-btn mwm-btn--secondary" data-reset-pp>Upload another</button></div></div>';
			return html;
		}
		var thisYear = new Date().getFullYear();
		var years = [];
		for (var y = thisYear + 1; y >= 2015; y--) { years.push(y); }
		html += '<div class="st-panel"><div class="st-label" style="margin-top:0">1 · Which exam is it from?</div>' +
			'<div class="st-chips">' + Object.keys(B.boards).map(function (b) { return chip(B.boards[b], P.board === b, { ppboard: b }); }).join('') + '</div>' +
			'<div class="st-fields" style="margin-top:16px"><label class="st-field">Exam month<select class="mwm-select" data-pp="season">' + ['June', 'November'].map(function (s) { return '<option value="' + s + '"' + (P.season === s ? ' selected' : '') + '>' + s + '</option>'; }).join('') + '</select></label>' +
			'<label class="st-field">Year<select class="mwm-select" data-pp="year">' + years.map(function (yy) { return '<option value="' + yy + '"' + (Number(P.year) === yy ? ' selected' : '') + '>' + yy + '</option>'; }).join('') + '</select></label></div>' +
			'<div class="st-groups"><div><div class="st-label" style="margin-top:0">Tier</div><div class="st-chips">' + chip('Foundation', P.tier === 'foundation', { tier: 'foundation' }) + chip('Higher', P.tier === 'higher', { tier: 'higher' }) + '</div></div>' +
			'<div><div class="st-label" style="margin-top:0">Paper</div><div class="st-chips">' + ['1', '2', '3'].map(function (n) { return chip('Paper ' + n, P.paper === n, { paper: n }); }).join('') + '</div></div></div>' +
			'<div class="st-label st-label--28">2 · Add the PDFs</div>' +
			'<div class="st-filerow st-filerow--8"><div><div class="st-filerow__label">Question paper</div><div class="st-filerow__status' + (P.qp ? ' is-ok' : '') + '">' + esc(P.qp ? '✓ ' + P.qp.filename + ' added' : 'The paper itself, as students sat it.') + '</div></div><label class="st-filebtn"><input type="file" accept="application/pdf" data-pdf="qp">Choose PDF</label></div>' +
			'<div class="st-filerow"><div><div class="st-filerow__label">Mark scheme <span>(can come later)</span></div><div class="st-filerow__status' + (P.ms ? ' is-ok' : '') + '">' + esc(P.ms ? '✓ ' + P.ms.filename + ' added' : 'Until it’s added, the site shows “Mark scheme coming soon”.') + '</div></div><label class="st-filebtn"><input type="file" accept="application/pdf" data-pdf="ms">Choose PDF</label></div>' +
			'<div class="st-label st-label--28">3 · Practise what came up <span style="font-weight:400;color:var(--muted)">(optional)</span></div>' +
			'<p class="st-panel__sub" style="margin-top:4px">Worksheets shown under this paper. Upload a revision worksheet made for it, or link worksheets that are already on the site.</p>' +
			'<div class="st-chips st-chips--12" data-pp-links>' + P.worksheets.map(function (w) { return '<span class="mwm-chip mwm-chip--md is-on st-chip--static">' + esc(w.name) + '<button type="button" class="st-chip__x" aria-label="Unlink ' + esc(w.name) + '" data-pp-unlink="' + w.id + '">✕</button></span>'; }).join('') +
			P.wsPdfs.map(function (f, i) { return '<span class="mwm-chip mwm-chip--md is-on st-chip--static">New: ' + esc(f.filename) + '<button type="button" class="st-chip__x" aria-label="Remove ' + esc(f.filename) + '" data-pp-unpdf="' + i + '">✕</button></span>'; }).join('') +
			(!P.worksheets.length && !P.wsPdfs.length ? '<span class="mwm-meta">No worksheets linked yet.</span>' : '') + '</div>' +
			'<div class="st-filerow st-filerow--8"><div><div class="st-filerow__label">Upload a revision worksheet PDF</div><div class="st-filerow__status">It gets its own worksheet page, named after this paper.</div></div><label class="st-filebtn"><input type="file" accept="application/pdf" data-pdf="ppws">Choose PDF</label></div>' +
			'<div class="st-label st-label--12">Or link an existing worksheet</div>' +
			wsPicker('pp') +
			'<div class="st-label st-label--28">4 · Publish</div>' +
			(P.qp || P.id ? '<button type="button" class="mwm-btn mwm-btn--primary mwm-btn--lg st-publish st-publish--12" data-publish-pp' + (P.publishing ? ' disabled' : '') + '>' + (P.publishing ? 'Publishing…' : (P.id ? 'Publish changes' : 'Publish this paper')) + '</button>' : '<p class="st-note st-note--12">Add the question paper PDF above and the publish button appears here.</p>') +
			(P.error ? '<p class="st-error">' + esc(P.error) + '</p>' : '') + '</div>';
		return html;
	}

	/* Search-as-you-type worksheet picker shared by the past and predicted paper forms. `form` says which state it feeds. */
	function wsPicker(form) {
		return '<div class="st-picker" data-ppws="' + form + '"><div class="st-picker__control"><input type="text" class="st-input st-picker__input" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-label="Search worksheets" placeholder="Search worksheets by name, e.g. “ratio”…" autocomplete="off" data-ppws-input><span class="st-picker__caret" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5L14 14"/></svg></span></div><div class="st-picker__list" role="listbox" hidden data-ppws-list></div></div>';
	}

	function viewPredicted() {
		var P = S.pr;
		var html = '<a href="' + esc(B.site + 'studio/') + '" class="st-back" data-go="dash"><span aria-hidden="true">←</span>Back to your dashboard</a>' +
			'<h1 class="st-h1 st-h1--after-back">' + (P.id ? 'Edit a predicted paper' : 'Upload a predicted paper') + '</h1>';
		if (P.id) { html += '<div class="st-banner">You’re editing <strong>' + esc(P.editingTitle) + '</strong>. Change what you need, then publish again.</div>'; }
		if (P.published) {
			html += '<div class="st-success"><span class="st-success__icon">' + icon('tick') + '</span><h2>Published!</h2><p>' + esc(P.published.summary) + '</p>' +
				'<div class="st-success__actions"><a href="' + esc(B.urls.predictedPapers) + '" class="mwm-btn mwm-btn--primary">See the predicted papers page</a><button type="button" class="mwm-btn mwm-btn--secondary" data-reset-pr>Upload another</button></div></div>';
			return html;
		}
		var thisYear = new Date().getFullYear();
		var years = [];
		for (var y = thisYear + 2; y >= thisYear - 1; y--) { years.push(y); }
		html += '<p class="st-intro">A paper you’ve written for the next exam, with worked solutions. It sits beside the past papers; when the exam has been and gone, remove it from “Your content”.</p>' +
			'<div class="st-panel"><div class="st-label" style="margin-top:0">1 · Which exam is it for?</div>' +
			'<div class="st-chips">' + Object.keys(B.boards).map(function (b) { return chip(B.boards[b], P.board === b, { prboard: b }); }).join('') + '</div>' +
			'<div class="st-label st-label--24">Level</div><div class="st-chips">' + B.levels.map(function (l) { return chip(l.name, P.level === l.slug, { prlevel: l.slug }); }).join('') + '</div>' +
			'<div class="st-fields" style="margin-top:16px"><label class="st-field">Exam year<select class="mwm-select" data-pr="year">' + years.map(function (yy) { return '<option value="' + yy + '"' + (Number(P.year) === yy ? ' selected' : '') + '>' + yy + '</option>'; }).join('') + '</select></label></div>' +
			'<div class="st-label st-label--24">What’s the paper called?</div><p class="st-panel__sub" style="margin-top:4px">Students see this on the row. There are no series or paper-number filters for predicted papers, so say it all here.</p>' +
			'<div class="st-inputrow"><input type="text" class="st-input" value="' + esc(P.name) + '" placeholder="e.g. Paper 1 Pure Mathematics" aria-label="Paper name" data-pr-name></div>' +
			'<label class="st-check" style="margin-top:12px"><input type="checkbox"' + (P.calculator ? ' checked' : '') + ' data-pr-calc>Calculator paper</label>' +
			'<div class="st-label st-label--28">2 · Add the PDFs</div>' +
			'<div class="st-filerow st-filerow--8"><div><div class="st-filerow__label">Question paper</div><div class="st-filerow__status' + (P.qp ? ' is-ok' : '') + '">' + esc(P.qp ? '✓ ' + P.qp.filename + ' added' : 'The paper for students to sit.') + '</div></div><label class="st-filebtn"><input type="file" accept="application/pdf" data-pdf="prqp">Choose PDF</label></div>' +
			'<div class="st-filerow"><div><div class="st-filerow__label">Worked solutions <span>(can come later)</span></div><div class="st-filerow__status' + (P.sol ? ' is-ok' : '') + '">' + esc(P.sol ? '✓ ' + P.sol.filename + ' added' : 'Until it’s added, the site shows “Worked solutions coming soon”.') + '</div></div><label class="st-filebtn"><input type="file" accept="application/pdf" data-pdf="prsol">Choose PDF</label></div>' +
			'<div class="st-label st-label--28">3 · Practise what comes up <span style="font-weight:400;color:var(--muted)">(optional)</span></div>' +
			'<p class="st-panel__sub" style="margin-top:4px">Worksheets shown under this paper. Upload a worksheet made for it, or link worksheets that are already on the site.</p>' +
			'<div class="st-chips st-chips--12" data-pr-links>' + P.worksheets.map(function (w) { return '<span class="mwm-chip mwm-chip--md is-on st-chip--static">' + esc(w.name) + '<button type="button" class="st-chip__x" aria-label="Unlink ' + esc(w.name) + '" data-pr-unlink="' + w.id + '">✕</button></span>'; }).join('') +
			P.wsPdfs.map(function (f, i) { return '<span class="mwm-chip mwm-chip--md is-on st-chip--static">New: ' + esc(f.filename) + '<button type="button" class="st-chip__x" aria-label="Remove ' + esc(f.filename) + '" data-pr-unpdf="' + i + '">✕</button></span>'; }).join('') +
			(!P.worksheets.length && !P.wsPdfs.length ? '<span class="mwm-meta">No worksheets linked yet.</span>' : '') + '</div>' +
			'<div class="st-filerow st-filerow--8"><div><div class="st-filerow__label">Upload a worksheet PDF</div><div class="st-filerow__status">It gets its own worksheet page, named after this paper.</div></div><label class="st-filebtn"><input type="file" accept="application/pdf" data-pdf="prws">Choose PDF</label></div>' +
			'<div class="st-label st-label--12">Or link an existing worksheet</div>' +
			wsPicker('pr') +
			'<div class="st-label st-label--28">4 · Publish</div>' +
			'<p class="st-note">The page carries a note that this is your own practice material, not an official ' + esc(B.boards[P.board] || 'exam board') + ' paper.</p>' +
			(P.qp || P.id ? '<button type="button" class="mwm-btn mwm-btn--primary mwm-btn--lg st-publish st-publish--12" data-publish-pr' + (P.publishing ? ' disabled' : '') + '>' + (P.publishing ? 'Publishing…' : (P.id ? 'Publish changes' : 'Publish this paper')) + '</button>' : '<p class="st-note st-note--12">Add the question paper PDF above and the publish button appears here.</p>') +
			(P.error ? '<p class="st-error">' + esc(P.error) + '</p>' : '') + '</div>';
		return html;
	}

	/* ---------------------------------------------------------------- revision pathways */
	/* A pathway is one level + one topic: the topic's subtopics in revision order. Lessons slot in by their subtopic,
	   so Kym only curates the order (and a "needs first" note). Rows here: { subtopic: id (0 = new), name, note, lessons, placed }. */
	function pwOverview(level) { return (S.pathways && S.pathways[level]) || []; }
	function pwCount(field) {
		var n = 0;
		Object.keys(S.pathways || {}).forEach(function (l) { pwOverview(l).forEach(function (t) { n += t[field] || 0; }); });
		return n;
	}
	function pwOpen(level, topic) {
		var P = S.pw;
		P.level = level; P.topic = topic; P.loading = true; P.error = ''; P.saved = ''; P.add = ''; P.paste = ''; P.copied = ''; render();
		api('studio/pathways?level=' + encodeURIComponent(level) + '&topic=' + encodeURIComponent(topic)).then(function (d) {
			P.loading = false; pwLoad(d); render(); window.scrollTo(0, 0);
		}).catch(function (err) { P.loading = false; P.topic = ''; toast(err.message); render(); });
	}
	function pwLoad(d) {
		var P = S.pw;
		P.data = d.pathway; P.subtopics = d.subtopics || [];
		P.rows = d.pathway.rows.filter(function (r) { return !r.needs_subtopic; }).map(function (r) { return { subtopic: r.subtopic_id, name: r.name, note: r.note || '', lessons: r.lessons || [], placed: r.placed }; });
		P.orphans = d.pathway.rows.filter(function (r) { return r.needs_subtopic; }).map(function (r) { return r.lessons[0]; });
		P.hidden = (d.pathway.hidden || []).map(function (h) { return { subtopic: h.subtopic_id, name: h.name, lessons: h.lessons || [] }; });
	}
	/* One subtopic per line, optional " | note" after it. Numbering and bullets are ignored. */
	function pwParse(text) {
		return String(text || '').split(/\r?\n/).map(function (line) {
			var clean = line.replace(/^\s*(?:[-*•]|\d+[.)])\s*/, '').trim();
			if (!clean) { return null; }
			var parts = clean.split('|');
			return { name: parts[0].trim(), note: parts.slice(1).join('|').trim() };
		}).filter(Boolean);
	}
	function pwAddRow(name, note, id) {
		var P = S.pw, key = name.trim().toLowerCase();
		if (!key) { return false; }
		var existing = P.rows.filter(function (r) { return r.name.toLowerCase() === key || (id && r.subtopic === id); })[0];
		if (existing) { if (note) { existing.note = note; } return false; }
		var known = P.subtopics.filter(function (s) { return s.id === id || s.name.toLowerCase() === key; })[0];
		P.rows.push({ subtopic: known ? known.id : 0, name: known ? known.name : name.trim(), note: note || '', lessons: [], placed: true });
		return true;
	}
	function pwSave() {
		var P = S.pw;
		if (!P.rows.length) { P.error = 'Add at least one subtopic first.'; render(); return; }
		P.saving = true; P.error = ''; P.saved = ''; render();
		api('studio/pathways', { method: 'POST', body: { level: P.level, topic: P.topic, rows: P.rows.map(function (r) { return { subtopic: r.subtopic, name: r.name, note: r.note }; }), hidden: (P.hidden || []).map(function (h) { return h.subtopic; }) } }).then(function (d) {
			P.saving = false; pwLoad(d);
			if (d.overviews) { S.pathways = d.overviews.levels || S.pathways; P.plans = d.overviews.plans || P.plans; }
			P.saved = 'Saved. It’s live on the Revision page now.' + (d.created ? ' ' + d.created + (d.created === 1 ? ' new subtopic was created.' : ' new subtopics were created.') : '');
			render();
		}).catch(function (err) { P.saving = false; P.error = err.message; render(); });
	}
	function pwPlanSave() {
		var P = S.pw, level = P.level;
		P.planSaving = true; P.planSaved = false; render();
		api('studio/revision-plan', { method: 'POST', body: { level: level, plan: P.plans[level] || [] } }).then(function (d) {
			P.planSaving = false; P.planSaved = true; P.plans[level] = d.plan || [];
			if (d.overviews) { P.plans = d.overviews.plans || P.plans; }
			render();
		}).catch(function (err) { P.planSaving = false; toast(err.message); render(); });
	}
	function pwLessonLine(r) {
		if (!r.lessons.length) { return 'No lesson yet · students see “Coming soon”'; }
		return r.lessons.map(function (l) { return l.title; }).join(' · ');
	}
	/* The format every prompt asks ChatGPT for, so the answer pastes straight back in. */
	var PW_FORMAT = 'Reply with one subtopic per line, in order, and nothing else: no numbers, bullets, headings or explanations. If a subtopic needs something learned first, add it after a vertical bar, like this:\nSolving quadratics by factorising | needs: expanding double brackets';
	function pwPrompts() {
		var P = S.pw, lvl = levelName(P.level), topic = P.data ? P.data.topic_name : '', isA = P.level === 'a-level';
		var qual = isA ? 'A-level' : 'GCSE';
		var list = P.rows.length ? P.rows.map(function (r, i) { return (i + 1) + '. ' + r.name + (r.lessons.length ? '' : ' (no lesson yet)'); }).join('\n') : '(none yet)';
		var intro = 'I run a free maths revision website for UK students. ';
		return [
			{ key: 'order', title: 'Put my subtopics in the best order', then: 'Then paste the reply into the box below and press “Use this order”.', text: intro + 'These are my ' + lvl + ' ' + topic + ' subtopics, in the order they are in now:\n\n' + list + '\n\nPut them in the best order for a student to revise them, building from the basics up so each one only needs what came before it. Keep my wording for each subtopic exactly as it is and do not add or remove any. ' + PW_FORMAT },
			{ key: 'gaps', title: 'What subtopics am I missing?', then: 'Then paste the reply below and press “Add to the end”, and move each new one into place.', text: intro + 'For ' + lvl + ' ' + topic + ' I have these subtopics:\n\n' + list + '\n\nCompare them with the ' + qual + ' maths specifications for Edexcel, AQA and OCR' + (isA ? '' : (P.level === 'gcse-foundation' ? ' (Foundation tier only)' : ' (Higher tier, including Higher-only content)')) + '. List only the subtopics I am missing, using short names in the same style as mine, in the order a student should learn them. ' + PW_FORMAT },
			{ key: 'notes', title: 'Suggest “needs first” notes', then: 'Then paste the reply below and press “Use this order”: the notes are added to the matching subtopics.', text: intro + 'These are my ' + lvl + ' ' + topic + ' subtopics in revision order:\n\n' + list + '\n\nFor each one, say in a few words what a student should already know before starting it, if anything (for example “needs: fractions”). Keep every subtopic, in this order, with my wording. ' + PW_FORMAT + '\nLeave the part after the bar empty when nothing is needed first.' },
			{ key: 'full', title: 'Write the whole list from scratch', then: 'Then paste the reply below and press “Use this order”. Anything already here that has lessons but isn’t in the reply drops to the end as “needs placing”.', text: intro + 'Write the complete list of ' + lvl + ' ' + topic + ' subtopics a student needs for the ' + qual + ' maths exam (Edexcel, AQA and OCR' + (isA ? '' : (P.level === 'gcse-foundation' ? ', Foundation tier only' : ', Higher tier')) + '), in the order to revise them, building from the basics up. Use short names a student would recognise, such as “Solving linear equations” or “Sharing in a ratio”, one idea per line, between 10 and 30 lines. ' + PW_FORMAT }
		];
	}
	function pwPlanPrompt(level) {
		var lvl = levelName(level);
		var exams = (S.dates || []).filter(function (d) { return d.level_slug === level && d.data && d.data.date; }).map(function (d) { return d.data.full + ' (' + d.data.board_name + ', ' + d.data.paper + ')'; });
		var topics = pwOverview(level).map(function (t) { return t.topic_name; }).join(', ');
		return 'I run a free maths revision website for UK students. Draft a week-by-week revision plan for a ' + lvl + ' student. The topics are: ' + (topics || 'Number, Algebra, Ratio & Proportion, Geometry & Measures, Probability, Statistics') + '. ' +
			(exams.length ? 'The exams are on: ' + exams.join('; ') + '. Start about ten weeks before the first exam and ' : 'Assume the exams are in May and June. Start in early March and ') +
			'finish the week before the first paper, with the last two weeks on past papers and weak spots. Each week should focus on one or two topics and spend longer on the bigger ones (Number and Algebra).\n' +
			'Reply with one line per week and nothing else, in this exact format:\n2027-03-01 | Number: fractions, percentages and ratio | Number\n' +
			'The first part is the Monday of that week as YYYY-MM-DD, the second is the focus for the week in a few words, the third is a one or two word label for a calendar.';
	}
	function promptBox(key, title, text, copied, then) {
		return '<div class="st-prompt"><div class="st-prompt__head"><span class="st-prompt__title">' + esc(title) + '</span><button type="button" class="st-copy" data-pw-copy="' + esc(key) + '">' + (copied === key ? '✓ Copied' : 'Copy') + '</button></div>' +
			(then ? '<p class="st-prompt__then">' + esc(then) + '</p>' : '') +
			'<details class="st-prompt__details"><summary>Show the prompt</summary><pre class="st-prompt__text">' + esc(text) + '</pre></details></div>';
	}
	function pwPlanPanel(level) {
		var P = S.pw, plan = P.plans[level] || [];
		return '<div class="st-panel"><h2>Revision plan · ' + esc(levelName(level)) + ' <span style="font-weight:400;color:var(--muted)">(optional)</span></h2>' +
			'<p class="st-panel__sub">A suggested week-by-week focus, shown on the exam calendar as shaded weeks. One plan per level, whichever exam board.</p>' +
			(plan.length ? '<div class="st-pw-plan">' + plan.map(function (w, i) {
				return '<div class="st-pw-plan__row"><label class="st-field">Week commencing<input type="date" class="st-date" value="' + esc(w.week) + '" data-plan="week" data-i="' + i + '"></label>' +
					'<label class="st-field st-field--grow">Focus<input type="text" class="st-input st-input--sm" value="' + esc(w.focus) + '" placeholder="e.g. Number: fractions and percentages" data-plan="focus" data-i="' + i + '"></label>' +
					'<label class="st-field">Calendar label<input type="text" class="st-input st-input--sm" value="' + esc(w.short) + '" placeholder="e.g. Number" data-plan="short" data-i="' + i + '"></label>' +
					'<button type="button" class="st-iconbtn st-iconbtn--danger" aria-label="Remove week" data-plan-del="' + i + '">✕</button></div>';
			}).join('') + '</div>' : '<p class="st-note st-note--8">No plan yet for this level.</p>') +
			'<button type="button" class="mwm-linkbtn mwm-linkbtn--sm st-pw-add" data-plan-add>+ Add a week</button>' +
			'<div class="st-label st-label--24">Or paste a plan</div><p class="st-panel__sub" style="margin-top:4px">One week per line: <code>2027-03-01 | Number: fractions and ratio | Number</code> (Monday’s date, the focus, a short label). Pasting replaces the plan above.</p>' +
			'<textarea class="st-textarea st-textarea--paste" rows="4" aria-label="Paste a plan" placeholder="2027-03-01 | Number: fractions and ratio | Number&#10;2027-03-08 | Algebra: equations and sequences | Algebra" data-plan-paste>' + esc(P.planPaste) + '</textarea>' +
			'<div class="st-actions"><button type="button" class="mwm-btn mwm-btn--secondary mwm-btn--sm" data-plan-paste-apply' + (P.planPaste.trim() ? '' : ' disabled') + '>Use this plan</button></div>' +
			promptBox('plan', 'Ask ChatGPT to draft a plan', pwPlanPrompt(level), P.copied) +
			'<div class="st-actions" style="margin-top:20px"><button type="button" class="mwm-btn mwm-btn--primary" data-plan-save' + (P.planSaving ? ' disabled' : '') + '>' + (P.planSaving ? 'Saving…' : 'Save the plan') + '</button>' + (P.planSaved ? '<span class="st-saved">✓ Saved</span>' : '') + '</div></div>';
	}
	function viewPathways() {
		var P = S.pw;
		var html = '<a href="' + esc(B.site + 'studio/') + '" class="st-back" data-go="dash"><span aria-hidden="true">←</span>Back to your dashboard</a>';
		if (!P.topic) {
			var list = pwOverview(P.level);
			return html + '<h1 class="st-h1 st-h1--after-back">Revision pathways</h1>' +
				'<p class="st-intro">There’s one pathway for every level and topic, built from your lessons: put each topic’s subtopics in the order to revise them and every lesson slots in under its subtopic as soon as it’s published. A subtopic with no lesson yet shows as “Coming soon”, so you can plan the whole list first and record it later.</p>' +
				'<div class="st-label" style="margin-top:24px">Level</div><div class="st-chips">' + B.levels.map(function (l) { return chip(l.name, P.level === l.slug, { pwlevel: l.slug }); }).join('') + '</div>' +
				'<div class="st-list st-list--20">' + (list.length ? list.map(function (t) {
					var flags = '';
					if (t.unplaced) { flags += tag(t.unplaced + (t.unplaced === 1 ? ' subtopic needs placing' : ' subtopics need placing'), 'mwm-tag--warn mwm-tag--sm'); }
					if (t.orphans) { flags += tag(t.orphans + (t.orphans === 1 ? ' lesson has no subtopic' : ' lessons have no subtopic'), 'mwm-tag--warn mwm-tag--sm'); }
					var sub = t.total ? [t.total + (t.total === 1 ? ' subtopic' : ' subtopics'), t.with_lessons + ' with lessons', t.coming_soon ? t.coming_soon + ' coming soon' : ''].filter(Boolean).join(' · ') : 'Nothing here yet: open it and add the subtopics.';
					return '<div class="st-list__row st-list__row--16"><div class="st-list__media"><span class="st-list__icon" aria-hidden="true">' + icon('route') + '</span></div><div class="st-list__main"><div class="st-list__name">' + esc(t.topic_name) + '</div><div class="st-list__sub">' + esc(sub) + '</div>' + (flags ? '<div class="st-list__flags">' + flags + '</div>' : '') + '</div>' +
						'<a href="' + esc(t.url) + '" class="st-smallbtn st-smallbtn--quiet" target="_blank" rel="noopener">View</a><button type="button" class="st-smallbtn" data-pw-open="' + esc(t.topic) + '">' + (t.total ? 'Edit' : 'Set up') + '</button></div>';
				}).join('') : '<div class="st-list__row"><span class="mwm-meta">No topics are set up for this level yet.</span></div>') + '</div>' +
				pwPlanPanel(P.level);
		}
		html += '<button type="button" class="st-back" data-pw-list><span aria-hidden="true">←</span>All pathways</button>';
		if (P.loading || !P.data) { return html + '<h1 class="st-h1 st-h1--after-back">Loading…</h1>'; }
		var D = P.data;
		html += '<h1 class="st-h1 st-h1--after-back">' + esc(D.topic_name) + ' <span class="st-h1__muted">· ' + esc(D.level_name) + '</span></h1>' +
			'<p class="st-intro">Students see these subtopics in this order, each with its lessons underneath. Use the arrows to reorder, add a “needs first” note where it helps, and publish. <a href="' + esc(D.url) + '" target="_blank" rel="noopener">See it on the site</a>.</p>';
		html += '<div class="st-panel"><h2>1 · Subtopics, in the order to revise them</h2>';
		if (!P.rows.length) { html += '<p class="st-note st-note--8">Nothing here yet. Add subtopics below, or paste a list.</p>'; }
		html += P.rows.map(function (r, i) {
			var tools = (r.placed ? '' : '<button type="button" class="st-smallbtn st-pw-place" data-pw-place="' + i + '" title="It’s in the right spot">✓ Place here</button>') +
				'<button type="button" class="st-iconbtn" aria-label="Move up" data-pw-move="up" data-i="' + i + '"' + (i === 0 ? ' disabled' : '') + '>↑</button><button type="button" class="st-iconbtn" aria-label="Move down" data-pw-move="down" data-i="' + i + '"' + (i === P.rows.length - 1 ? ' disabled' : '') + '>↓</button>' +
				'<button type="button" class="st-iconbtn st-iconbtn--danger" aria-label="Remove from this pathway" title="' + (r.lessons.length ? 'Take it out of this pathway. Its lessons stay on Learn Maths and you can put it back below.' : 'Take it out of this pathway (the subtopic itself stays)') + '" data-pw-remove="' + i + '">✕</button>';
			return '<div class="st-pw-row' + (r.placed ? '' : ' is-unplaced') + (r.subtopic ? '' : ' is-new') + '"><span class="st-pw-row__step">' + (i + 1 < 10 ? '0' + (i + 1) : i + 1) + '</span>' +
				'<div class="st-pw-row__main"><div class="st-pw-row__name">' + esc(r.name) + (r.placed ? '' : ' ' + tag('Needs placing', 'mwm-tag--warn mwm-tag--sm')) + (r.subtopic ? '' : ' ' + tag('New', 'mwm-tag--outline mwm-tag--sm')) + '</div>' +
				'<div class="st-pw-row__lessons' + (r.lessons.length ? '' : ' is-soon') + '">' + esc(pwLessonLine(r)) + '</div>' +
				'<input type="text" class="st-input st-input--sm st-pw-row__note" value="' + esc(r.note) + '" placeholder="Needs first (optional), e.g. needs: fractions" aria-label="Needs-first note" data-pw-note="' + i + '"></div>' +
				'<div class="st-pw-row__tools">' + tools + '</div></div>';
		}).join('');
		if (P.rows.some(function (r) { return !r.placed; })) { html += '<p class="st-note st-note--12">“Needs placing” subtopics have lessons but no position yet, so students see them at the end. Move each one to where it belongs, or press “Place here” if it’s already right, then publish.</p>'; }
		html += '</div>';
		if (P.hidden && P.hidden.length) {
			html += '<div class="st-panel"><h2>Hidden from this pathway</h2><p class="st-panel__sub">These have lessons at this level but you’ve taken them out of the ' + esc(D.level_name) + ' ' + esc(D.topic_name) + ' pathway. The lessons still show on Learn Maths.</p><div class="st-list st-list--12">' +
				P.hidden.map(function (h, i) { return '<div class="st-list__row"><div class="st-list__main"><div class="st-list__name">' + esc(h.name) + '</div><div class="st-list__sub">' + esc(h.lessons.map(function (l) { return l.title; }).join(' · ')) + '</div></div><button type="button" class="st-smallbtn" data-pw-unhide="' + i + '">Put back</button></div>'; }).join('') + '</div></div>';
		}
		if (P.orphans.length) {
			html += '<div class="st-panel"><h2>Lessons with no subtopic</h2><p class="st-panel__sub">These sit at the end of the pathway until they have a subtopic. Open each lesson and pick one.</p><div class="st-list st-list--12">' +
				P.orphans.map(function (l) { return '<div class="st-list__row"><span class="st-list__name">' + esc(l.title) + '</span><button type="button" class="st-smallbtn" data-edit="' + l.id + '">Edit lesson</button></div>'; }).join('') + '</div></div>';
		}
		var spare = P.subtopics.filter(function (st) { return !P.rows.some(function (r) { return r.subtopic === st.id || r.name.toLowerCase() === st.name.toLowerCase(); }); });
		html += '<div class="st-panel"><h2>2 · Add subtopics</h2>' +
			'<div class="st-inputrow"><input type="text" class="st-input" value="' + esc(P.add) + '" placeholder="e.g. Solving quadratics by factorising" aria-label="New subtopic" data-pw-add-input><button type="button" class="mwm-btn mwm-btn--secondary" data-pw-add' + (P.add.trim() ? '' : ' disabled') + '>Add</button></div>' +
			(spare.length ? '<div class="st-label st-label--16">Already on the site but not in this pathway</div><div class="st-chips st-chips--12">' + spare.map(function (st) { return chip(st.name, false, { 'pw-pick': st.id }); }).join('') + '</div>' : '') + '</div>';
		html += '<div class="st-panel"><h2>3 · Paste a list <span style="font-weight:400;color:var(--muted)">(from ChatGPT, or anywhere)</span></h2>' +
			'<p class="st-panel__sub">Need a list? Copy one of these prompts into ChatGPT. Each already includes your current subtopics and asks for a reply in the right format: one subtopic per line, in order, with an optional “needs first” note after a vertical bar.</p>' +
			pwPrompts().map(function (pr) { return promptBox(pr.key, pr.title, pr.text, P.copied, pr.then); }).join('') +
			'<div class="st-label st-label--24" id="st-pw-paste-label">Paste the reply here</div><p class="st-panel__sub" style="margin-top:4px">Or type your own list: one subtopic per line, e.g. <code>Solving quadratics | needs: factorising</code>. Names that already exist are matched; new ones are created when you publish.</p>' +
			'<textarea class="st-textarea st-textarea--paste" rows="8" aria-labelledby="st-pw-paste-label" placeholder="Expanding brackets&#10;Factorising&#10;Solving linear equations | needs: collecting like terms" data-pw-paste>' + esc(P.paste) + '</textarea>' +
			'<div class="st-actions"><button type="button" class="mwm-btn mwm-btn--primary mwm-btn--sm" data-pw-paste-apply="replace"' + (P.paste.trim() ? '' : ' disabled') + '>Use this order</button><button type="button" class="mwm-btn mwm-btn--secondary mwm-btn--sm" data-pw-paste-apply="append"' + (P.paste.trim() ? '' : ' disabled') + '>Add to the end</button></div>' +
			'<p class="st-note st-note--8">“Use this order” rebuilds section 1 in the order you pasted (anything with lessons that you left out drops to the end as “needs placing”). “Add to the end” keeps what’s there and adds the new ones after it. Either way, nothing is saved until you publish.</p></div>';
		html += '<div class="st-panel"><h2>4 · Publish</h2>' +
			'<button type="button" class="mwm-btn mwm-btn--primary mwm-btn--lg st-publish st-publish--12" data-publish-pw' + (P.saving ? ' disabled' : '') + '>' + (P.saving ? 'Publishing…' : 'Publish this order') + '</button>' +
			(P.error ? '<p class="st-error">' + esc(P.error) + '</p>' : '') + (P.saved ? '<p class="st-note st-note--12 st-saved">✓ ' + esc(P.saved) + '</p>' : '') +
			'<p class="st-note st-note--12">It goes live straight away on the Revision page. Lessons you add later appear under their subtopic by themselves.</p></div>';
		return html;
	}

	/* Home page picks: hero lesson (+ short title) and the featured grid. */
	function lessonPicker(target, placeholder) {
		return '<div class="st-picker st-picker--inline" data-lpick-box="' + target + '"><div class="st-picker__control"><input type="text" class="st-input st-picker__input" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-label="' + esc(placeholder) + '" placeholder="' + esc(placeholder) + '" autocomplete="off" data-lpick-input="' + target + '"><span class="st-picker__caret" aria-hidden="true">' + icon('search') + '</span></div><div class="st-picker__list" role="listbox" hidden data-lpick-list></div></div>';
	}
	function lpickSearch(box, q) {
		var list = box.querySelector('[data-lpick-list]'), target = box.getAttribute('data-lpick-box'), H = S.home;
		var mine = ++wsSearchSeq;
		q = q.trim();
		if (!q) { list.setAttribute('hidden', ''); box.classList.remove('is-open'); return; }
		list.innerHTML = '<div class="st-picker__empty">Searching…</div>'; list.removeAttribute('hidden'); box.classList.add('is-open');
		api('lessons?per_page=8&format=lesson&search=' + encodeURIComponent(q)).then(function (res) {
			if (mine !== wsSearchSeq) { return; }
			var taken = H.featured.map(function (c) { return c.id; });
			var items = (res.items || []).filter(function (l) { return target !== 'featured' || taken.indexOf(l.id) < 0; });
			items.forEach(function (l) { H.found[l.id] = l; });
			list.innerHTML = items.length
				? items.map(function (l) { return '<button type="button" class="st-picker__opt" role="option" data-lpick-choose="' + l.id + '" data-target="' + target + '">' + (l.thumb ? '<span class="st-picker__thumb" style="background-image:url(' + esc(l.thumb) + ')"></span>' : '') + esc(l.title) + '<span class="st-picker__hint">' + esc([l.level, l.subtopic || l.topic].filter(Boolean).join(' · ')) + '</span></button>'; }).join('')
				: '<div class="st-picker__empty">No lessons match “' + esc(q) + '”.</div>';
		}).catch(function () { list.innerHTML = '<div class="st-picker__empty">Couldn’t search just now — try again.</div>'; });
	}
	function homeCard(c, title) {
		return '<div class="st-preview st-preview--home">' + thumbBtn(c.thumb, 'st-preview__thumb', c.title, watchUrl(c.youtube_id)) + '<div class="st-preview__body"><div class="mwm-tags">' + (c.level ? tag(c.level, 'mwm-tag--higher') : '') + (c.subtopic || c.topic ? tag(c.subtopic || c.topic, 'mwm-tag--outline') : '') + '</div>' +
			'<div class="st-preview__title">' + esc(title || c.title) + '</div><div class="st-preview__meta">' + esc([c.duration, c.worksheet ? 'Worksheet' : '', c.quiz ? 'Quiz' : ''].filter(Boolean).join(' · ')) + '</div></div></div>';
	}
	function viewHome() {
		var H = S.home;
		var html = '<a href="' + esc(B.site + 'studio/') + '" class="st-back" data-go="dash"><span aria-hidden="true">←</span>Back to your dashboard</a>' +
			'<h1 class="st-h1 st-h1--after-back">Home page</h1>' +
			'<p class="st-intro">Pick what visitors see first. Leave either section empty and the site shows your newest lessons automatically.</p>';

		// Hero
		html += '<div class="st-panel st-panel--24"><h2>The big lesson at the top</h2><p class="st-panel__sub">Shown large next to the welcome text, with a play button.</p>';
		if (H.hero) {
			html += '<div class="st-home"><div class="st-home__card">' + homeCard(H.hero, H.heroTitle) + '</div><div class="st-home__side">' +
				'<div class="st-home__current">' + esc(H.hero.title) + '</div>' +
				'<label class="st-label" for="st-hero-title">Short title <span style="font-weight:400;color:var(--muted)">(optional)</span></label>' +
				'<input type="text" id="st-hero-title" class="st-input st-input--sm" maxlength="80" value="' + esc(H.heroTitle) + '" placeholder="e.g. Volume of a cuboid" data-home-title>' +
				'<p class="st-note st-note--8">Long YouTube titles get squashed on the home page card. Type a shorter version here and it’s used there instead — the lesson itself keeps its full name.</p>' +
				'<div class="st-home__actions"><button type="button" class="mwm-linkbtn mwm-linkbtn--sm" data-home-hero-clear>Use the newest lesson instead</button></div>' +
				'</div></div>';
			html += '<div class="st-label st-label--24">Swap it for a different lesson</div>' + lessonPicker('hero', 'Search your lessons by name…');
		} else {
			html += '<div class="st-home"><div class="st-home__card">' + (H.autoHero ? homeCard(H.autoHero) : '<p class="mwm-meta">No lessons yet.</p>') + '</div><div class="st-home__side"><div class="st-home__auto">' + tag('Automatic', 'mwm-tag--outline mwm-tag--sm') + '<p class="st-note st-note--8">Nothing chosen, so the home page shows your newest lesson. Pick one below to fix it in place.</p></div></div></div>';
			html += '<div class="st-label st-label--24">Choose a lesson</div>' + lessonPicker('hero', 'Search your lessons by name…');
		}
		html += '</div>';

		// Featured grid
		html += '<div class="st-panel st-panel--24"><h2>“Find your next lightbulb moment”</h2><p class="st-panel__sub">The grid of ' + H.max + ' lessons further down the home page. Put them in the order you want them shown.</p>';
		if (H.featured.length) {
			html += '<div class="st-list st-list--12">' + H.featured.map(function (c, i) {
				return '<div class="st-list__row st-list__row--16">' + listMedia({ kind: 'Lesson', thumb: c.thumb, title: c.title, youtube_id: c.youtube_id }) +
					'<div class="st-list__main"><div class="st-list__name">' + (i + 1) + '. ' + esc(c.title) + '</div><div class="st-list__sub">' + esc([c.level, c.subtopic || c.topic, c.duration].filter(Boolean).join(' · ')) + '</div></div>' +
					'<div class="st-home__rowbtns"><button type="button" class="st-smallbtn st-smallbtn--xs st-smallbtn--keep" data-home-move="' + i + '" data-dir="-1" aria-label="Move up"' + (i === 0 ? ' disabled' : '') + '>' + icon('up') + '</button>' +
					'<button type="button" class="st-smallbtn st-smallbtn--xs st-smallbtn--keep" data-home-move="' + i + '" data-dir="1" aria-label="Move down"' + (i === H.featured.length - 1 ? ' disabled' : '') + '>' + icon('down') + '</button>' +
					'<button type="button" class="st-smallbtn st-smallbtn--xs st-smallbtn--quiet" data-home-remove="' + i + '">Remove</button></div></div>';
			}).join('') + '</div>';
			if (H.featured.length < H.max) { html += '<div class="st-label st-label--24">Add another (' + (H.max - H.featured.length) + ' more)</div>' + lessonPicker('featured', 'Search your lessons by name…'); }
			else { html += '<p class="st-note st-note--16">That’s all ' + H.max + ' — remove one to add another.</p>'; }
		} else {
			html += '<div class="st-home__auto" style="margin-top:12px">' + tag('Automatic', 'mwm-tag--outline mwm-tag--sm') + '<p class="st-note st-note--8">Nothing chosen, so the grid shows your ' + H.max + ' newest lessons' + (H.autoFeatured.length ? ': ' + H.autoFeatured.map(function (c) { return c.title; }).join(', ') : '') + '.</p></div>';
			html += '<div class="st-label st-label--24">Choose the first lesson</div>' + lessonPicker('featured', 'Search your lessons by name…');
		}
		html += '</div>';

		html += '<div class="st-nav st-nav--home"><a href="' + esc(B.site) + '" target="_blank" rel="noopener" class="mwm-linkbtn mwm-linkbtn--sm">See the home page<span aria-hidden="true">→</span></a>' +
			'<div><span class="st-home__status">' + (H.saving ? 'Saving…' : (H.dirty ? 'Not saved yet' : (H.saved ? '✓ Saved — it’s live on the home page' : ''))) + '</span>' +
			'<button type="button" class="mwm-btn mwm-btn--primary" data-home-save' + (H.saving || !H.dirty ? ' disabled' : '') + '>Save home page</button></div></div>' +
			(H.error ? '<p class="st-error">' + esc(H.error) + '</p>' : '');
		return html;
	}
	function homeSave() {
		var H = S.home;
		H.saving = true; H.error = ''; render();
		api('studio/home', { method: 'POST', body: { hero_lesson: H.hero ? H.hero.id : 0, hero_title: H.heroTitle, featured: H.featured.map(function (c) { return c.id; }) } }).then(function (d) {
			S.home = homeState(d); S.home.saved = true; toast('Saved — the home page shows it now.'); render();
		}).catch(function (err) { H.saving = false; H.error = err.message; render(); });
	}

	function viewDates() {
		var E = S.ed;
		var ready = !!(E.date && E.checked);
		var boardName = B.boards[E.board] || 'Edexcel';
		return '<a href="' + esc(B.site + 'studio/') + '" class="st-back" data-go="dash"><span aria-hidden="true">←</span>Back to your dashboard</a>' +
			'<h1 class="st-h1 st-h1--after-back">Exam dates</h1>' +
			'<p class="st-intro">Only dates you’ve checked against the official timetable go on the site — students see them on the exam calendar.</p>' +
			'<div class="st-panel st-panel--24"><div class="st-fields">' +
			'<label class="st-field">Paper<select class="mwm-select" data-ed="paper">' + ['Paper 1 (non-calculator)', 'Paper 2 (calculator)', 'Paper 3 (calculator)'].map(function (p) { return '<option value="' + esc(p) + '"' + (E.paper === p ? ' selected' : '') + '>' + esc(p) + '</option>'; }).join('') + '</select></label>' +
			'<label class="st-field">Date<input type="date" class="st-date" value="' + esc(E.date) + '" data-ed="date"></label>' +
			'<div class="st-field">Session<div class="st-chips">' + chip('Morning', E.session === 'morning', { session: 'morning' }) + chip('Afternoon', E.session === 'afternoon', { session: 'afternoon' }) + '</div></div></div>' +
			'<div class="st-fields" style="margin-top:20px"><div class="st-field">Level<div class="st-chips">' + B.levels.map(function (l) { return chip(l.name, E.level === l.slug, { edlevel: l.slug }); }).join('') + '</div></div>' +
			'<div class="st-field">Exam board<div class="st-chips">' + Object.keys(B.boards).map(function (b) { return chip(B.boards[b], E.board === b, { edboard: b }); }).join('') + '</div></div></div>' +
			'<label class="st-check"><input type="checkbox"' + (E.checked ? ' checked' : '') + ' data-ed="checked">I’ve checked this against the official ' + esc(boardName) + ' timetable</label>' +
			(ready ? '<button type="button" class="mwm-btn mwm-btn--primary" style="margin-top:20px" data-save-date' + (E.saving ? ' disabled' : '') + '>' + (E.saving ? 'Saving…' : 'Save this date') + '</button>' : '<p class="st-note st-note--16">Pick a date and tick the box above, then you can save.</p>') +
			(E.error ? '<p class="st-error">' + esc(E.error) + '</p>' : '') + '</div>' +
			'<h2 class="st-h2 st-h2--32">On the site now</h2>' +
			'<div class="st-list st-list--12">' + (S.dates.length ? S.dates.map(function (d, i) {
				var confirm = E.dateConfirm === d.id;
				return '<div class="st-list__row"><span class="st-list__name" style="flex:1;min-width:200px">' + esc(d.title) + '</span><span class="st-list__sub" style="margin:0;white-space:nowrap">' + esc(d.meta) + '</span>' + tag('On the calendar', 'mwm-tag--live') +
					'<button type="button" class="st-smallbtn st-smallbtn--quiet st-smallbtn--xs' + (confirm ? ' is-confirm' : '') + '" data-remove-date="' + d.id + '">' + (confirm ? 'Really remove?' : 'Remove') + '</button></div>';
			}).join('') : '<div class="st-list__row"><span class="mwm-meta">No dates on the site yet.</span></div>') + '</div>';
	}

	/* ---------------------------------------------------------------- site stats (Google, via Site Kit) */
	function fmtNum(n) { return Math.round(n || 0).toLocaleString('en-GB'); }
	function fmtDuration(s) {
		s = Math.round(s || 0);
		if (s < 60) { return s + 's'; }
		return Math.floor(s / 60) + 'm ' + (s % 60 < 10 ? '0' : '') + (s % 60) + 's';
	}
	function fmtDay(ymd, opts) { var p = ymd.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]).toLocaleDateString('en-GB', opts || { weekday: 'short', day: 'numeric', month: 'short' }); }
	function statsAgo(ts) {
		var mins = Math.max(0, Math.round((Date.now() / 1000 - ts) / 60));
		return mins < 1 ? 'just now' : (mins === 1 ? '1 minute ago' : (mins < 60 ? mins + ' minutes ago' : 'about an hour ago'));
	}
	/* "↑ 12% on the 28 days before": the arrow and words carry the direction, so no red/green. */
	function statsDelta(t, days) {
		if (!t) { return ''; }
		var than = ' on the ' + days + ' days before';
		if (!t.prev) { return t.now ? 'New' + than.replace(' on', ' compared with') : ''; }
		var pct = Math.round((t.now - t.prev) / t.prev * 100);
		if (pct === 0) { return 'About the same as the ' + days + ' days before'; }
		return (pct > 0 ? '↑ ' : '↓ ') + Math.abs(pct) + '% ' + (pct > 0 ? 'up' : 'down') + than;
	}
	function statsTile(label, value, delta, note) {
		return '<div class="st-tile"><div class="st-tile__label">' + esc(label) + '</div><div class="st-tile__value">' + esc(value) + '</div>' +
			'<div class="st-tile__delta">' + esc(delta || note || '') + '</div></div>';
	}
	function niceMax(v) {
		if (v <= 4) { return 4; }
		var step = Math.pow(10, Math.floor(Math.log10(v))), n = v / step;
		return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 2.5 ? 2.5 : n <= 5 ? 5 : 10) * step;
	}
	/* Single-series area chart of daily visitors. The SVG stretches to the width; labels, crosshair and dot are HTML so they stay crisp. */
	function statsChart(series) {
		if (!series || series.length < 2) { return '<p class="st-note st-note--16">Not enough days yet to draw a chart.</p>'; }
		var n = series.length, max = niceMax(Math.max.apply(null, series.map(function (p) { return p.users; })));
		var pts = series.map(function (p, i) { return (i / (n - 1) * 1000).toFixed(1) + ',' + (200 - p.users / max * 200).toFixed(1); });
		var ticks = [max, max / 2, 0];
		var xs = [0, Math.floor((n - 1) / 2), n - 1];
		return '<div class="st-chart" data-st-chart>' +
			'<div class="st-chart__y" aria-hidden="true">' + ticks.map(function (t) { return '<span>' + fmtNum(t) + '</span>'; }).join('') + '</div>' +
			'<div class="st-chart__plot">' +
			'<svg viewBox="0 0 1000 200" preserveAspectRatio="none" role="img" aria-label="Visitors per day, ' + esc(fmtDay(series[0].date)) + ' to ' + esc(fmtDay(series[n - 1].date)) + '">' +
			ticks.map(function (t) { var y = (200 - t / max * 200).toFixed(1); return '<line x1="0" x2="1000" y1="' + y + '" y2="' + y + '" class="st-chart__grid' + (t === 0 ? ' is-base' : '') + '" vector-effect="non-scaling-stroke"/>'; }).join('') +
			'<path class="st-chart__area" d="M0,200 L' + pts.join(' L') + ' L1000,200 Z"/>' +
			'<polyline class="st-chart__line" points="' + pts.join(' ') + '" vector-effect="non-scaling-stroke"/></svg>' +
			'<span class="st-chart__cross" hidden></span><span class="st-chart__dot" hidden></span><div class="st-chart__tip" role="status" hidden></div>' +
			'</div>' +
			'<div class="st-chart__x" aria-hidden="true">' + xs.map(function (i) { return '<span>' + esc(fmtDay(series[i].date, { day: 'numeric', month: 'short' })) + '</span>'; }).join('') + '</div>' +
			'</div>' +
			'<details class="st-details"><summary>Show every day as a table</summary><table class="st-table"><thead><tr><th scope="col">Day</th><th scope="col">Visitors</th><th scope="col">Page views</th></tr></thead><tbody>' +
			series.slice().reverse().map(function (p) { return '<tr><td>' + esc(fmtDay(p.date)) + '</td><td>' + fmtNum(p.users) + '</td><td>' + fmtNum(p.views) + '</td></tr>'; }).join('') +
			'</tbody></table></details>';
	}
	function statsChartHover(box, clientX) {
		var D = S.stats.data[S.stats.days], series = D && D.analytics && D.analytics.series;
		if (!series || series.length < 2) { return; }
		var plot = box.querySelector('.st-chart__plot'), r = plot.getBoundingClientRect(), n = series.length;
		var i = Math.max(0, Math.min(n - 1, Math.round((clientX - r.left) / r.width * (n - 1))));
		var p = series[i], max = niceMax(Math.max.apply(null, series.map(function (s) { return s.users; })));
		var left = i / (n - 1) * 100, top = (1 - p.users / max) * 100;
		var cross = plot.querySelector('.st-chart__cross'), dot = plot.querySelector('.st-chart__dot'), tip = plot.querySelector('.st-chart__tip');
		cross.hidden = dot.hidden = tip.hidden = false;
		cross.style.left = dot.style.left = left + '%';
		dot.style.top = top + '%';
		tip.innerHTML = '<strong>' + esc(fmtDay(p.date)) + '</strong><span>' + fmtNum(p.users) + (p.users === 1 ? ' visitor' : ' visitors') + '</span><span>' + fmtNum(p.views) + (p.views === 1 ? ' page view' : ' page views') + '</span>';
		// Keep the tooltip inside the chart: flip it to the left of the line past the halfway point.
		tip.classList.toggle('is-left', left > 55);
		tip.style.left = left + '%';
	}
	function statsChartLeave(box) {
		box.querySelectorAll('.st-chart__cross, .st-chart__dot, .st-chart__tip').forEach(function (el) { el.hidden = true; });
	}
	function statsBars(items, unit) {
		var top = Math.max.apply(null, items.map(function (x) { return x.share; })) || 1;
		return '<div class="st-bars">' + items.map(function (x) {
			return '<div class="st-bars__row"><div class="st-bars__head"><span>' + esc(x.label) + '</span><span class="st-bars__val">' + Math.round(x.share) + '%</span></div>' +
				'<span class="st-bar" title="' + esc(fmtNum(x.value) + ' ' + unit) + '"><span style="width:' + (x.share / top * 100).toFixed(1) + '%"></span></span></div>';
		}).join('') + '</div>';
	}
	function statsLoad(days, refresh) {
		var T = S.stats;
		T.loading = true; T.error = '';
		api('studio/stats?days=' + days + (refresh ? '&refresh=1' : '')).then(function (d) {
			T.data[days] = d; T.loading = false;
			if (S.view === 'stats') { render(); }
			if (refresh) { toast('Stats updated'); }
		}).catch(function (err) {
			T.loading = false; T.error = err.message;
			if (S.view === 'stats') { render(); }
		});
	}
	function viewStats() {
		var T = S.stats, D = T.data[T.days];
		if (!D && !T.loading && !T.error) { statsLoad(T.days); }
		var html = '<a href="' + esc(B.site + 'studio/') + '" class="st-back" data-go="dash"><span aria-hidden="true">←</span>Back to your dashboard</a>' +
			'<h1 class="st-h1 st-h1--after-back">Site stats</h1>' +
			'<p class="st-intro">How many people visit the site, what they look at and how they find it. The numbers come from Google and are a day behind.</p>' +
			'<div class="st-chips st-stats__range" role="group" aria-label="Time period">' + [7, 28, 90].map(function (d) { return chip('Last ' + d + ' days', T.days === d, { statsdays: d }); }).join('') +
			(D && D.label ? '<span class="mwm-meta st-stats__label">' + esc(D.label) + '</span>' : '') + '</div>';

		if (!D) {
			if (T.error) {
				return html + '<div class="st-panel st-panel--24"><h2>We couldn’t get your stats</h2><p class="st-panel__sub">' + esc(T.error) + '</p><button type="button" class="mwm-btn mwm-btn--secondary mwm-btn--sm" style="margin-top:16px" data-stats-retry>Try again</button></div>';
			}
			return html + '<div class="st-panel st-panel--24 st-stats__loading"><p class="st-panel__sub" style="margin:0">Fetching your numbers from Google…</p></div>';
		}
		if (D.status !== 'ok') {
			return html + '<div class="st-panel st-panel--24"><h2>No stats to show yet</h2><p class="st-panel__sub">' + esc(D.message) + '</p></div>';
		}

		var A = D.analytics || {}, G = D.search || {}, t = A.totals || {}, g = G.totals || {};
		if (A.error) {
			html += '<div class="st-banner">' + esc(A.error) + (A.detail ? '<br><small>' + esc(A.detail) + '</small>' : '') + '</div>';
		} else {
			html += '<div class="st-tiles">' +
				statsTile('Visitors', fmtNum(t.users.now), statsDelta(t.users, D.days)) +
				statsTile('Page views', fmtNum(t.views.now), statsDelta(t.views, D.days)) +
				statsTile('Average visit', fmtDuration(t.duration.now), statsDelta(t.duration, D.days)) +
				(G.error ? statsTile('Visits', fmtNum(t.sessions.now), statsDelta(t.sessions, D.days)) : statsTile('Clicks from Google', fmtNum(g.clicks.now), statsDelta(g.clicks, D.days))) +
				'</div>';
			html += '<div class="st-panel st-panel--24"><h2>Visitors each day</h2><p class="st-panel__sub">Hover over the chart to see a day’s numbers.</p>' + statsChart(A.series) + '</div>';

			html += '<h2 class="st-h2 st-h2--32">Most viewed</h2>';
			if (A.pages && A.pages.length) {
				var topViews = A.pages[0].views || 1;
				html += '<div class="st-list st-list--12">' + A.pages.map(function (p, i) {
					return '<div class="st-list__row st-stats__page"><span class="st-stats__rank">' + (i + 1) + '</span>' +
						'<div class="st-list__main"><a class="st-list__name st-stats__link" href="' + esc(p.url) + '" target="_blank" rel="noopener">' + esc(p.title) + '</a>' + (p.kind ? ' ' + tag(p.kind, 'mwm-tag--outline mwm-tag--sm') : '') +
						'<span class="st-bar st-bar--thin"><span style="width:' + (p.views / topViews * 100).toFixed(1) + '%"></span></span></div>' +
						'<span class="st-list__meta">' + fmtNum(p.views) + (p.views === 1 ? ' view' : ' views') + '</span></div>';
				}).join('') + '</div>';
			} else {
				html += '<div class="st-list st-list--12"><div class="st-list__row"><span class="mwm-meta">' + (A.pages ? 'No page views in this period yet.' : 'Couldn’t load the list of pages just now.') + '</span></div></div>';
			}

			html += '<div class="st-stats__pair">';
			html += '<div class="st-panel st-panel--24"><h2>How people find the site</h2><p class="st-panel__sub">Share of visits.</p>' +
				(A.sources && A.sources.length ? statsBars(A.sources, 'visits') : '<p class="st-note st-note--16">' + (A.sources ? 'No visits in this period yet.' : 'Couldn’t load this just now.') + '</p>') + '</div>';
			html += '<div class="st-panel st-panel--24"><h2>What they use</h2><p class="st-panel__sub">Share of visitors.</p>' +
				(A.devices && A.devices.length ? statsBars(A.devices, 'visitors') : '<p class="st-note st-note--16">' + (A.devices ? 'No visitors in this period yet.' : 'Couldn’t load this just now.') + '</p>') + '</div>';
			html += '</div>';
		}

		html += '<h2 class="st-h2 st-h2--32">What people searched on Google</h2>';
		if (G.error) {
			html += '<div class="st-banner">' + esc(G.error) + (G.detail ? '<br><small>' + esc(G.detail) + '</small>' : '') + '</div>';
		} else {
			html += '<p class="st-intro" style="margin-top:6px">' + fmtNum(g.impressions.now) + ' times the site showed up in Google results, and ' + fmtNum(g.clicks.now) + (g.clicks.now === 1 ? ' click' : ' clicks') + ' through to it.</p>';
			if (G.queries && G.queries.length) {
				html += '<div class="st-list st-list--12">' + G.queries.map(function (q) {
					return '<div class="st-list__row"><div class="st-list__main"><div class="st-list__name">' + esc(q.query) + '</div>' +
						'<div class="st-list__sub">Shown ' + fmtNum(q.impressions) + (q.impressions === 1 ? ' time' : ' times') + (q.position ? ' · usually around #' + Math.max(1, Math.round(q.position)) + ' on Google' : '') + '</div></div>' +
						'<span class="st-list__meta">' + fmtNum(q.clicks) + (q.clicks === 1 ? ' click' : ' clicks') + '</span></div>';
				}).join('') + '</div>';
			} else {
				html += '<div class="st-list st-list--12"><div class="st-list__row"><span class="mwm-meta">' + (G.queries ? 'No searches recorded in this period yet. Google search data can take a few days to arrive.' : 'Couldn’t load search terms just now.') + '</span></div></div>';
			}
		}

		html += '<div class="st-stats__foot"><span class="mwm-meta">Updated ' + esc(statsAgo(D.generated)) + ' · </span><button type="button" class="mwm-linkbtn mwm-linkbtn--sm" data-stats-refresh' + (T.loading ? ' disabled' : '') + '>' + (T.loading ? 'Refreshing…' : 'Refresh') + '</button>' +
			'<a class="mwm-linkbtn mwm-linkbtn--sm st-stats__full" href="' + esc(D.dashboard) + '" target="_blank" rel="noopener">Open the full Site Kit dashboard<span aria-hidden="true">→</span></a></div>';
		return html;
	}

	function render() {
		var html;
		switch (S.view) {
			case 'lesson': html = viewLesson(); break;
			case 'content': html = viewContent(); break;
			case 'worksheets': html = viewWorksheet(); break;
			case 'papers': html = viewPapers(); break;
			case 'predicted': html = viewPredicted(); break;
			case 'dates': html = viewDates(); break;
			case 'home': html = viewHome(); break;
			case 'pathways': html = viewPathways(); break;
			case 'suggestions': html = viewSuggestions(); break;
			case 'stats': html = viewStats(); break;
			default: html = viewDash();
		}
		root.innerHTML = html;
		document.querySelectorAll('[data-studio-nav] [data-view]').forEach(function (a) {
			// Past papers, predicted papers, exam dates, pathways and suggestions live off the dashboard, so keep Dashboard lit while on them.
			var on = a.getAttribute('data-view') === S.view || (a.getAttribute('data-view') === 'dash' && (S.view === 'papers' || S.view === 'predicted' || S.view === 'dates' || S.view === 'pathways' || S.view === 'suggestions' || S.view === 'home'));
			a.classList.toggle('is-current', on);
			if (on) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
		});
		try { history.replaceState(null, '', B.site + 'studio/' + (S.view === 'dash' ? '' : S.view + '/')); } catch (e) {}
	}
	function go(view) { S.view = view; window.scrollTo(0, 0); render(); }

	/* ---------------------------------------------------------------- actions */
	/* The lesson title as it will be saved: Kym's edit, else the YouTube title, else the title of the lesson being edited. */
	function lessonTitle() {
		var L = S.lesson;
		return (L.title || '').trim() || (L.video ? L.video.title : '') || L.editingTitle || '';
	}
	/* The ChatGPT quiz prompt, with the first line filled in from what Kym chose in steps 1–2. */
	function quizPrompt() {
		var L = S.lesson;
		var lines = B.prompt.split('\n');
		var level = levelsLabel(L.levels) || 'GCSE';
		var onlyA = L.levels.length && L.levels.every(function (l) { return l === 'a-level'; });
		var topic = topicName(L.topic);
		var sub = L.subtopic ? topicName(L.subtopic) : '';
		var title = lessonTitle();
		var where = topic ? ' (topic: ' + topic + (sub ? ' · ' + sub : '') + ')' : '';
		lines[0] = 'Write a 5-question ' + (onlyA ? 'A-level' : (L.levels.indexOf('a-level') > -1 ? 'GCSE and A-level' : 'GCSE')) + ' maths quiz for ' + level + ' students' +
			(title ? ' based on my lesson “' + title + '”' + where + '. Only test what that lesson covers.' : (topic ? ' about ' + topic + (sub ? ' · ' + sub : '') + '.' : ' about [YOUR TOPIC].'));
		return lines.join('\n');
	}
	function findVideo(url, then) {
		var L = S.lesson;
		L.yt = url; L.videoError = ''; L.video = null; L.finding = true; render();
		api('studio/video?url=' + encodeURIComponent(url)).then(function (v) {
			L.video = v; L.finding = false; L.title = ''; /* a new video: the title follows it until Kym edits it */ if (then) { then(v); } render();
		}).catch(function (e) { L.videoError = e.message; L.finding = false; render(); });
	}

	/* ---------------------------------------------------------------- suggested lessons (YouTube channel scan) */
	function viewSuggestions() {
		var G = S.sg, D = S.suggestions;
		var items = D.items || [], hidden = D.hidden || [];
		var shown = G.filter === 'lessons' ? items.filter(function (v) { return !v.is_short; }) : (G.filter === 'shorts' ? items.filter(function (v) { return v.is_short; }) : items);
		var longCount = items.filter(function (v) { return !v.is_short; }).length;
		function row(v, isHidden) {
			var guess = [v.level_name, v.topic_name].filter(Boolean).join(' · ');
			var meta = [v.duration_label, v.published_label ? 'uploaded ' + v.published_label : '', v.unlisted ? 'unlisted on YouTube' : ''].filter(Boolean).join(' · ');
			var actions = isHidden
				? '<button type="button" class="st-smallbtn st-smallbtn--quiet" data-sg-restore="' + esc(v.id) + '">Bring back</button>'
				: (v.is_short
					? '<button type="button" class="st-smallbtn" data-sg-short="' + esc(v.id) + '">Add to Quick Maths</button>'
					: '<button type="button" class="st-smallbtn" data-sg-wizard="' + esc(v.id) + '">Add as a lesson</button>') +
					'<button type="button" class="st-smallbtn st-smallbtn--quiet" data-sg-ignore="' + esc(v.id) + '" title="Never suggest this video again">Not for the site</button>';
			return '<div class="st-sg' + (G.busy === v.id ? ' is-busy' : '') + '"><a href="' + esc(v.url) + '" target="_blank" rel="noopener" class="st-sg__thumb" style="background-image:url(' + esc(v.thumbnail) + ')" aria-label="Watch on YouTube"><span class="st-sg__len">' + esc(v.duration_label) + '</span></a>' +
				'<div class="st-sg__body"><div class="st-sg__title">' + esc(v.title) + '</div><div class="st-sg__meta">' + esc(meta) + '</div>' +
				(guess ? '<div class="st-sg__guess">Looks like <strong>' + esc(guess) + '</strong> — you can change it</div>' : '<div class="st-sg__guess">Couldn’t guess the level or topic — you’ll pick them</div>') + '</div>' +
				'<div class="st-sg__actions">' + actions + '</div></div>';
		}
		var html = '<a href="' + esc(B.site + 'studio/') + '" class="st-back" data-go="dash"><span aria-hidden="true">←</span>Back to your dashboard</a>' +
			'<h1 class="st-h1 st-h1--after-back">Suggested from YouTube</h1>' +
			'<p class="st-intro">Videos on your channel that aren’t on the website yet. Add the ones you want; “Not for the site” hides a video for good (you can bring it back from the hidden list).</p>' +
			'<div class="st-sg-bar"><span class="mwm-meta">' + esc(D.scanned_label || 'Not scanned yet') + (D.total ? ' · ' + D.total + ' videos on the channel' : '') + '</span>' +
			'<button type="button" class="mwm-linkbtn mwm-linkbtn--sm" data-sg-scan' + (G.scanning ? ' disabled' : '') + '>' + (G.scanning ? 'Scanning…' : 'Scan the channel now') + '</button></div>' +
			(G.error ? '<p class="st-error">' + esc(G.error) + '</p>' : '');
		if (!items.length && !hidden.length) {
			return html + '<div class="st-list st-list--20"><div class="st-list__row"><span class="mwm-meta">' + (D.total ? 'Everything on your channel is on the site — nice.' : 'Scan the channel to see what’s not on the site yet.') + '</span></div></div>';
		}
		html += '<div class="st-chips st-chips--12">' + chip('All · ' + items.length, G.filter === 'all', { sgfilter: 'all' }) + chip('Lessons · ' + longCount, G.filter === 'lessons', { sgfilter: 'lessons' }) + chip('Shorts · ' + (items.length - longCount), G.filter === 'shorts', { sgfilter: 'shorts' }) + '</div>';
		if (longCount > 1) {
			html += '<div class="st-sg-bulk">' + (G.confirmAll
				? '<span>Create ' + longCount + ' draft lessons? They won’t show on the site until you open each one and publish it.</span><button type="button" class="st-smallbtn" data-sg-addall>Yes, create the drafts</button><button type="button" class="st-smallbtn st-smallbtn--keep" data-sg-cancelall>Not now</button>'
				: '<span>In a hurry? Add all ' + longCount + ' longer videos as <strong>draft</strong> lessons, then finish them one by one from Your content.</span><button type="button" class="st-smallbtn st-smallbtn--quiet" data-sg-askall>Add all as drafts</button>') + '</div>';
		}
		html += '<div class="st-sg-list">' + (shown.length ? shown.map(function (v) { return row(v, false); }).join('') : '<div class="st-list__row"><span class="mwm-meta">Nothing here.</span></div>') + '</div>';
		if (hidden.length) {
			html += '<div class="st-sg-hidden"><button type="button" class="mwm-linkbtn mwm-linkbtn--sm" data-sg-togglehidden>' + (G.showHidden ? 'Hide the hidden videos' : hidden.length + (hidden.length === 1 ? ' video hidden · show it' : ' videos hidden · show them')) + '</button>' +
				(G.showHidden ? '<div class="st-sg-list">' + hidden.map(function (v) { return row(v, true); }).join('') + '</div>' : '') + '</div>';
		}
		return html;
	}
	function sgApply(promise, doneMsg) {
		var G = S.sg;
		return promise.then(function (d) {
			G.busy = ''; G.scanning = false; G.error = ''; G.confirmAll = false;
			S.suggestions = { scanned_at: d.scanned_at, scanned_label: d.scanned_label, channel: d.channel, total: d.total, items: d.items, hidden: d.hidden };
			if (doneMsg) { toast(typeof doneMsg === 'function' ? doneMsg(d) : doneMsg); }
			render();
		}).catch(function (err) { G.busy = ''; G.scanning = false; G.error = err.message; render(); });
	}
	function sgOpenWizard(v) {
		var L = freshLesson();
		var url = 'https://youtu.be/' + v.id;
		L.yt = url;
		if (v.level) { L.levels = [v.level]; }
		if (v.topic) { L.topic = v.topic; L.subtopic = v.subtopic || ''; }
		S.lesson = L;
		go('lesson');
		findVideo(url, function () { L.step = 2; window.scrollTo(0, 0); });
	}
	function upload(file, kind) {
		var fd = new FormData(); fd.append('file', file);
		return api('studio/upload?kind=' + kind, { method: 'POST', body: fd });
	}
	function checkQuiz(text) {
		var L = S.lesson;
		L.quizText = text;
		if (!text.trim()) { L.quizResult = null; render(); return; }
		api('studio/quiz/validate', { method: 'POST', body: { json: text } }).then(function (r) { L.quizResult = r; L.quizImages = {}; render(); })
			.catch(function (e) { L.quizResult = { ok: false, error: e.message }; render(); });
	}
	function quizPayload() {
		var L = S.lesson;
		if (!L.quizResult || !L.quizResult.ok) { return L.quizText.trim() ? undefined : ''; }
		var qs = L.quizResult.questions.map(function (q, i) {
			var c = Object.assign({}, q);
			if (L.quizImages[i]) { c.imageUrl = L.quizImages[i].url; }
			return c;
		});
		return JSON.stringify({ questions: qs });
	}
	function publishLesson() {
		var L = S.lesson;
		L.publishing = true; L.publishError = ''; render();
		var body = { id: L.id, youtube_url: L.video ? L.video.id : L.yt, title: lessonTitle(), levels: L.levels, level: L.levels[0], topic: L.subtopic || L.topic, worksheet: L.ws ? L.ws.id : 0, answers: L.ans ? L.ans.id : 0, seconds: L.video ? L.video.seconds : undefined, thumbnail: L.video ? L.video.thumbnail : undefined };
		var quiz = quizPayload();
		if (quiz !== undefined) { body.quiz = quiz; }
		api('studio/lessons', { method: 'POST', body: body }).then(function (card) {
			L.publishing = false; L.published = card; refreshContent(); render();
		}).catch(function (e) { L.publishing = false; L.publishError = e.message; render(); });
	}
	function refreshContent() {
		api('studio/content').then(function (rows) { S.content = rows; S.recent = rows.slice(0, 3); S.dates = rows.filter(function (r) { return r.kind === 'Exam date'; }); if (S.view === 'content' || S.view === 'dash' || S.view === 'dates') { render(); } }).catch(function () {});
		refreshPathways();
	}
	/* Pathway overviews change whenever a lesson or subtopic does; cheap to re-fetch (they're cached server-side). */
	function refreshPathways() {
		api('studio/pathways').then(function (d) { S.pathways = d.levels || {}; S.pw.plans = d.plans || {}; if (S.view === 'dash' || (S.view === 'pathways' && !S.pw.topic)) { render(); } }).catch(function () {});
	}
	function editItem(id) {
		var it = S.content.filter(function (r) { return r.id === id; })[0];
		if (!it) { return; }
		if (it.kind === 'Worksheet' && !it.lesson_id) {
			root.classList.add('st-busy');
			api('studio/worksheets/' + it.id).then(function (w) {
				root.classList.remove('st-busy');
				var W = freshWorksheet();
				W.id = w.id; W.editingTitle = w.title; W.title = w.title; W.level = w.level_slug || 'gcse-foundation';
				W.topic = w.topic_slug || 'number'; W.subtopic = w.subtopic_slug || ''; W.desc = w.description || '';
				W.pdf = w.pdf ? { id: w.pdf.id, filename: w.pdf.name, label: w.pdf.label, keep: true } : null;
				W.ans = w.answers ? { id: w.answers.id, filename: w.answers.name, keep: true } : null;
				S.ws = W;
				go('worksheets');
			}).catch(function (e) { root.classList.remove('st-busy'); toast(e.message); });
			return;
		}
		if (it.kind === 'Lesson' || it.kind === 'Quiz' || it.kind === 'Worksheet') {
			var lessonId = it.kind === 'Lesson' ? it.id : it.lesson_id;
			root.classList.add('st-busy');
			api('studio/lessons/' + lessonId).then(function (c) {
				root.classList.remove('st-busy');
				var L = freshLesson();
				L.id = c.id; L.editingTitle = c.title; L.title = c.title; L.yt = c.youtube_url; L.levels = c.levels && c.levels.length ? c.levels.slice() : [c.level_slug || 'gcse-higher'];
				L.topic = c.topic_slug || 'number'; L.subtopic = c.subtopic_slug || '';
				L.video = { id: c.youtube_id, title: c.title, thumbnail: c.thumb, seconds: c.seconds, duration_label: '', minutes_label: c.duration, published_label: c.published_label ? '' : '', existing_id: c.id };
				L.ws = c.worksheet ? { id: c.worksheet.id, filename: c.worksheet.name, url: c.worksheet.url } : null;
				L.ans = c.answers ? { id: c.answers.id, filename: c.answers.name, url: c.answers.url } : null;
				L.quizText = c.quiz_json || '';
				L.step = it.kind === 'Lesson' ? 2 : 3;
				S.lesson = L;
				go('lesson');
				if (L.quizText) { checkQuiz(L.quizText); }
			}).catch(function (e) { root.classList.remove('st-busy'); toast(e.message); });
		} else if (it.kind === 'Past paper') {
			var d = it.data;
			S.pp = { id: it.id, board: d.board || 'edexcel', season: d.season, year: d.year, tier: d.tier, paper: String(d.paper), qp: d.qp ? { id: 0, filename: 'current question paper', keep: true } : null, ms: d.ms ? { id: 0, filename: 'current mark scheme', keep: true } : null, worksheets: (d.worksheets || []).map(function (w) { return { id: w.id, name: w.name }; }), wsPdfs: [], published: null, publishing: false, error: '', editingTitle: it.title };
			go('papers');
		} else if (it.kind === 'Predicted paper') {
			var pd = it.data;
			S.pr = { id: it.id, board: pd.board || 'edexcel', level: pd.level || 'gcse-higher', year: pd.year, name: pd.title || '', calculator: !!pd.calculator, qp: pd.qp ? { id: 0, filename: 'current question paper', keep: true } : null, sol: pd.solutions ? { id: 0, filename: 'current worked solutions', keep: true } : null, worksheets: (pd.worksheets || []).map(function (w) { return { id: w.id, name: w.name }; }), wsPdfs: [], published: null, publishing: false, error: '', editingTitle: it.title };
			go('predicted');
		} else {
			go('dates');
		}
	}

	/* ---------------------------------------------------------------- events */
	root.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && e.target.matches('[data-pw-add-input]')) { e.preventDefault(); var ab = root.querySelector('[data-pw-add]'); if (ab && !ab.disabled) { ab.click(); } }
	});
	root.addEventListener('submit', function (e) {
		var f = e.target.closest('[data-find-form]');
		if (f) { e.preventDefault(); var v = f.querySelector('[data-yt]').value.trim(); if (v) { findVideo(v); } }
	});
	/* Subtopic picker: filter as you type, keyboard navigation, close on blur. */
	root.addEventListener('focusin', function (e) {
		if (e.target.matches('[data-picker-input]')) {
			var box = e.target.closest('[data-picker]');
			var q = e.target.value === box.getAttribute('data-picker-selected') ? '' : e.target.value;
			pickerPaint(box, q); pickerOpen(box, true);
		}
	});
	root.addEventListener('focusout', function (e) {
		var lpbox = e.target.closest && e.target.closest('[data-lpick-box]');
		if (lpbox && !(e.relatedTarget && lpbox.contains(e.relatedTarget))) { lpbox.querySelector('[data-lpick-list]').setAttribute('hidden', ''); lpbox.classList.remove('is-open'); return; }
		var wbox = e.target.closest && e.target.closest('[data-ppws]');
		if (wbox && !(e.relatedTarget && wbox.contains(e.relatedTarget))) { wbox.querySelector('[data-ppws-list]').setAttribute('hidden', ''); wbox.classList.remove('is-open'); return; }
		var box = e.target.closest && e.target.closest('[data-picker]');
		if (!box || (e.relatedTarget && box.contains(e.relatedTarget))) { return; }
		var input = box.querySelector('[data-picker-input]');
		input.value = box.getAttribute('data-picker-selected');
		pickerOpen(box, false);
	});
	root.addEventListener('keydown', function (e) {
		var box = e.target.closest && e.target.closest('[data-picker]');
		if (!box) { return; }
		var opts = Array.prototype.slice.call(box.querySelectorAll('.st-picker__opt'));
		var i = opts.indexOf(e.target);
		if (e.key === 'Escape') { e.preventDefault(); box.querySelector('[data-picker-input]').value = box.getAttribute('data-picker-selected'); pickerOpen(box, false); return; }
		if (e.key === 'ArrowDown') { e.preventDefault(); pickerOpen(box, true); if (opts[i + 1]) { opts[i + 1].focus(); } return; }
		if (e.key === 'ArrowUp') { e.preventDefault(); if (i > 0) { opts[i - 1].focus(); } else { box.querySelector('[data-picker-input]').focus(); } return; }
		if (e.key === 'Enter' && e.target.matches('[data-picker-input]')) {
			e.preventDefault();
			var q = e.target.value.trim();
			var visible = opts.filter(function (o) { return o.hasAttribute('data-picker-pick'); });
			if (visible.length === 1 || (visible[0] && visible[0].textContent.trim().toLowerCase() === q.toLowerCase())) { visible[0].click(); }
			else if (q) { pickerCreate(box, q); }
		}
	});
	/* Worksheet search for past and predicted papers: queries the public worksheets endpoint as you type. */
	var wsSearchTimer = 0, wsSearchSeq = 0, searchTimer = 0;
	function paperForm(box) { return box && box.getAttribute('data-ppws') === 'pr' ? S.pr : S.pp; }
	function wsSearch(box, q) {
		var list = box.querySelector('[data-ppws-list]');
		var mine = ++wsSearchSeq;
		q = q.trim();
		if (!q) { list.setAttribute('hidden', ''); box.classList.remove('is-open'); return; }
		list.innerHTML = '<div class="st-picker__empty">Searching…</div>'; list.removeAttribute('hidden'); box.classList.add('is-open');
		api('worksheets?per_page=8&search=' + encodeURIComponent(q)).then(function (res) {
			if (mine !== wsSearchSeq) { return; }
			var linked = paperForm(box).worksheets.map(function (w) { return w.id; });
			var items = (res.items || []).filter(function (w) { return linked.indexOf(w.id) === -1; });
			list.innerHTML = items.length
				? items.map(function (w) { return '<button type="button" class="st-picker__opt" role="option" data-ppws-pick="' + w.id + '" data-ppws-name="' + esc(w.title) + '">' + esc(w.title) + '<span class="st-picker__hint">' + esc([w.level, w.topic].filter(Boolean).join(' · ')) + '</span></button>'; }).join('')
				: '<div class="st-picker__empty">No worksheets match “' + esc(q) + '”.</div>';
		}).catch(function () { list.innerHTML = '<div class="st-picker__empty">Couldn’t search just now — try again.</div>'; });
	}
	root.addEventListener('input', function (e) {
		if (e.target.matches('[data-ppws-input]')) {
			var wb = e.target.closest('[data-ppws]'), wq = e.target.value;
			clearTimeout(wsSearchTimer); wsSearchTimer = setTimeout(function () { wsSearch(wb, wq); }, 250);
			return;
		}
		if (e.target.matches('[data-picker-input]')) { var pb = e.target.closest('[data-picker]'); pickerPaint(pb, e.target.value); pickerOpen(pb, true); return; }
		if (e.target.matches('[data-lpick-input]')) {
			var lpb = e.target.closest('[data-lpick-box]'), lpq = e.target.value;
			clearTimeout(wsSearchTimer); wsSearchTimer = setTimeout(function () { lpickSearch(lpb, lpq); }, 250);
			return;
		}
		if (e.target.matches('[data-home-title]')) { S.home.heroTitle = e.target.value; S.home.dirty = true; S.home.saved = false; var hs = root.querySelector('.st-home__status'); if (hs) { hs.textContent = 'Not saved yet'; } var hb = root.querySelector('[data-home-save]'); if (hb) { hb.disabled = false; } var ht = root.querySelector('.st-preview--home .st-preview__title'); if (ht) { ht.textContent = S.home.heroTitle || S.home.hero.title; } }
		if (e.target.matches('[data-fsearch]')) {
			S.search = e.target.value;
			clearTimeout(searchTimer); searchTimer = setTimeout(function () { render(); var si = root.querySelector('[data-fsearch]'); if (si) { si.focus(); si.setSelectionRange(si.value.length, si.value.length); } }, 200);
		}
		if (e.target.matches('[data-yt]')) { S.lesson.yt = e.target.value; }
		if (e.target.matches('[data-lesson-title]')) { S.lesson.title = e.target.value; }
		if (e.target.matches('[data-quiz-text]')) { S.lesson.quizText = e.target.value; S.lesson.quizResult = null; }
		if (e.target.matches('[data-ed="date"]')) { S.ed.date = e.target.value; S.ed.error = ''; render(); }
		// Pathway editor: text inputs update state without re-rendering, so typing keeps focus.
		if (e.target.matches('[data-pw-note]')) { S.pw.rows[Number(e.target.getAttribute('data-pw-note'))].note = e.target.value; S.pw.saved = ''; }
		if (e.target.matches('[data-pw-add-input]')) { S.pw.add = e.target.value; var ab = root.querySelector('[data-pw-add]'); if (ab) { ab.disabled = !e.target.value.trim(); } }
		if (e.target.matches('[data-pw-paste]')) { S.pw.paste = e.target.value; root.querySelectorAll('[data-pw-paste-apply]').forEach(function (b) { b.disabled = !e.target.value.trim(); }); }
		if (e.target.matches('[data-plan]')) { (S.pw.plans[S.pw.level] || [])[Number(e.target.getAttribute('data-i'))][e.target.getAttribute('data-plan')] = e.target.value; S.pw.planSaved = false; }
		if (e.target.matches('[data-plan-paste]')) { S.pw.planPaste = e.target.value; var pb = root.querySelector('[data-plan-paste-apply]'); if (pb) { pb.disabled = !e.target.value.trim(); } }
		if (e.target.matches('[data-ws-title]')) { S.ws.title = e.target.value; S.ws.error = ''; var t = root.querySelector('.st-preview__title'); if (t) { t.textContent = S.ws.title || 'Untitled worksheet'; } }
		if (e.target.matches('[data-ws-desc]')) { S.ws.desc = e.target.value; }
	});
	root.addEventListener('change', function (e) {
		var el = e.target, L = S.lesson;
		if (el.matches('[data-ftopic]')) { S.topicFilter = el.value; S.confirmId = null; render(); return; }
		if (el.matches('[data-pdf]')) {
			var key = el.getAttribute('data-pdf'), file = el.files && el.files[0];
			if (!file) { return; }
			var target = key === 'qp' || key === 'ms' || key === 'ppws' ? S.pp : (key === 'prqp' || key === 'prsol' || key === 'prws' ? S.pr : (key === 'wspdf' || key === 'wsans' ? S.ws : L));
			var prop = { wspdf: 'pdf', wsans: 'ans', prqp: 'qp', prsol: 'sol' }[key] || key;
			root.classList.add('st-busy');
			upload(file, 'pdf').then(function (info) { root.classList.remove('st-busy'); if (key === 'ppws' || key === 'prws') { target.wsPdfs.push(info); } else { target[prop] = info; } if (target !== L) { target.error = ''; } render(); })
				.catch(function (err) { root.classList.remove('st-busy'); if (target !== L) { target.error = err.message; } else { toast(err.message); } render(); });
		} else if (el.matches('[data-quiz-file]')) {
			var qf = el.files && el.files[0];
			if (!qf) { return; }
			var reader = new FileReader();
			reader.onload = function () { checkQuiz(String(reader.result)); };
			reader.readAsText(qf);
		} else if (el.matches('[data-quiz-img]')) {
			var idx = Number(el.getAttribute('data-quiz-img')), img = el.files && el.files[0];
			if (!img) { return; }
			root.classList.add('st-busy');
			upload(img, 'image').then(function (info) { root.classList.remove('st-busy'); L.quizImages[idx] = info; render(); })
				.catch(function (err) { root.classList.remove('st-busy'); toast(err.message); });
		} else if (el.matches('[data-pp]')) { S.pp[el.getAttribute('data-pp')] = el.value; }
		else if (el.matches('[data-pr]')) { S.pr[el.getAttribute('data-pr')] = el.value; }
		else if (el.matches('[data-pr-calc]')) { S.pr.calculator = el.checked; }
		else if (el.matches('[data-ed="paper"]')) { S.ed.paper = el.value; }
		else if (el.matches('[data-ed="checked"]')) { S.ed.checked = el.checked; S.ed.error = ''; render(); }
	});
	root.addEventListener('click', function (e) {
		var el, L = S.lesson, P = S.pp, E = S.ed;
		if ((el = e.target.closest('[data-go]'))) { e.preventDefault(); if (el.getAttribute('data-go') === 'lesson' && L.published) { S.lesson = freshLesson(); } if (el.getAttribute('data-go') === 'worksheets' && S.ws.published) { S.ws = freshWorksheet(); } go(el.getAttribute('data-go')); return; }
		if ((el = e.target.closest('[data-statsdays]'))) { S.stats.days = Number(el.getAttribute('data-statsdays')); S.stats.error = ''; render(); return; }
		if ((el = e.target.closest('[data-stats-retry]'))) { S.stats.error = ''; render(); return; }
		if ((el = e.target.closest('[data-stats-refresh]'))) { statsLoad(S.stats.days, true); render(); return; }
		if ((el = e.target.closest('[data-sync]'))) {
			S.syncing = true; render();
			api('studio/sync', { method: 'POST' }).then(function (r) { S.syncing = false; S.synced = r.ok; S.playlists = r.playlists; S.sync = r.state; if (!r.ok) { toast(r.error || 'The playlists couldn’t be checked just now.'); } render(); })
				.catch(function (err) { S.syncing = false; toast(err.message); render(); });
			return;
		}
		// Subtopic picker (lesson wizard + standalone worksheet)
		if ((el = e.target.closest('[data-picker-pick]'))) { var pbox = el.closest('[data-picker]'); pickerState(pbox.getAttribute('data-picker')).subtopic = el.getAttribute('data-picker-pick'); render(); return; }
		if ((el = e.target.closest('[data-picker-create]'))) { pickerCreate(el.closest('[data-picker]'), el.getAttribute('data-picker-create')); return; }
		if ((el = e.target.closest('[data-picker-clear]'))) { pickerState(el.closest('[data-picker]').getAttribute('data-picker')).subtopic = ''; render(); return; }
		if ((el = e.target.closest('[data-zoom]'))) { zoomOpen(el.getAttribute('data-zoom'), el.getAttribute('data-zoom-title'), el.getAttribute('data-zoom-link')); return; }
		// Lesson wizard
		if ((el = e.target.closest('[data-title-reset]'))) { L.title = L.video ? L.video.title : ''; render(); return; }
		if ((el = e.target.closest('[data-level]'))) {
			var lv = el.getAttribute('data-level');
			if (L.levels.indexOf(lv) < 0) { L.levels = sortLevels(L.levels.concat([lv])); }
			else if (L.levels.length > 1) { L.levels = L.levels.filter(function (x) { return x !== lv; }); }
			else { toast('Every lesson needs at least one level.'); return; }
			render(); return;
		}
		if ((el = e.target.closest('[data-topic]'))) { L.topic = el.getAttribute('data-topic'); L.subtopic = ''; render(); return; }
		if ((el = e.target.closest('[data-copy]'))) {
			try { navigator.clipboard.writeText(quizPrompt()); } catch (err) {}
			L.copied = true; render(); return;
		}
		if ((el = e.target.closest('[data-check-quiz]'))) { checkQuiz(root.querySelector('[data-quiz-text]').value); return; }
		if ((el = e.target.closest('[data-edit-lesson]'))) { editItem(Number(el.getAttribute('data-edit-lesson'))); return; }
		if ((el = e.target.closest('[data-back]'))) { L.step = Math.max(1, L.step - 1); window.scrollTo(0, 0); render(); return; }
		if ((el = e.target.closest('[data-next]'))) { L.step = Math.min(4, L.step + 1); window.scrollTo(0, 0); render(); return; }
		if ((el = e.target.closest('[data-publish]'))) { publishLesson(); return; }
		if ((el = e.target.closest('[data-reset-lesson]'))) { S.lesson = freshLesson(); render(); return; }
		// Home page picks
		var H = S.home;
		if ((el = e.target.closest('[data-lpick-choose]'))) {
			var chosen = H.found[Number(el.getAttribute('data-lpick-choose'))];
			if (!chosen) { return; }
			if (el.getAttribute('data-target') === 'hero') { H.hero = chosen; H.heroTitle = ''; }
			else if (H.featured.length < H.max && !H.featured.some(function (c) { return c.id === chosen.id; })) { H.featured.push(chosen); }
			H.dirty = true; H.saved = false; render(); return;
		}
		if ((el = e.target.closest('[data-home-hero-clear]'))) { H.hero = null; H.heroTitle = ''; H.dirty = true; H.saved = false; render(); return; }
		if ((el = e.target.closest('[data-home-remove]'))) { H.featured.splice(Number(el.getAttribute('data-home-remove')), 1); H.dirty = true; H.saved = false; render(); return; }
		if ((el = e.target.closest('[data-home-move]'))) {
			var mi = Number(el.getAttribute('data-home-move')), mj = mi + Number(el.getAttribute('data-dir'));
			if (mj < 0 || mj >= H.featured.length) { return; }
			var mv = H.featured[mi]; H.featured[mi] = H.featured[mj]; H.featured[mj] = mv; H.dirty = true; H.saved = false; render(); return;
		}
		if ((el = e.target.closest('[data-home-save]'))) { homeSave(); return; }
		// Content
		if ((el = e.target.closest('[data-kind]'))) { S.kindFilter = el.getAttribute('data-kind'); S.issueFilter = ''; S.confirmId = null; render(); return; }
		if ((el = e.target.closest('[data-flevel]'))) { S.levelFilter = el.getAttribute('data-flevel'); S.confirmId = null; render(); return; }
		if ((el = e.target.closest('[data-fboard]'))) { S.boardFilter = el.getAttribute('data-fboard'); S.confirmId = null; render(); return; }
		if ((el = e.target.closest('[data-fclear]'))) { S.levelFilter = ''; S.topicFilter = ''; S.boardFilter = ''; S.search = ''; S.confirmId = null; render(); return; }
		if ((el = e.target.closest('[data-issue]'))) { var iss = el.getAttribute('data-issue'); S.issueFilter = S.issueFilter === iss ? '' : iss; S.confirmId = null; render(); return; }
		if ((el = e.target.closest('[data-qlevel]'))) {
			var qid = Number(el.getAttribute('data-id')), qlevel = el.getAttribute('data-qlevel');
			var qrow = S.content.filter(function (x) { return x.id === qid; })[0];
			if (!qrow || S.levelBusy) { return; }
			var have = rowLevels(qrow), isOn = have.indexOf(qlevel) > -1;
			var needsCheck = (qrow.flags || []).indexOf('level') > -1;
			// A guessed level (flagged for checking): clicking it confirms it, clicking another swaps the guess for that one.
			// Otherwise each button adds or takes off its level, but a lesson always keeps at least one.
			var next, msg;
			if (needsCheck) {
				next = isOn ? have : [qlevel];
				msg = isOn ? 'confirmed' : 'changed';
			} else if (!isOn) {
				next = sortLevels(have.concat([qlevel]));
				msg = 'added';
			} else if (have.length > 1) {
				next = have.filter(function (x) { return x !== qlevel; });
				msg = 'removed';
			} else {
				toast('Every lesson needs at least one level. Add another before taking this one off.');
				return;
			}
			S.levelBusy = qid; render();
			api('studio/content/' + qid + '/level', { method: 'POST', body: { levels: next } }).then(function (d) {
				qrow.levels = d.levels || [d.level]; qrow.level_slug = d.level; qrow.flags = (qrow.flags || []).filter(function (f) { return f !== 'level'; }); S.levelBusy = null;
				toast({ confirmed: d.level_name + ' confirmed', changed: 'Level changed to ' + d.level_name, added: levelName(qlevel) + ' added. Now ' + d.level_name, removed: levelName(qlevel) + ' taken off. Now ' + d.level_name }[msg]);
				render(); refreshContent();
			}).catch(function (err) { S.levelBusy = null; toast(err.message); render(); });
			return;
		}
		if ((el = e.target.closest('[data-edit]'))) { editItem(Number(el.getAttribute('data-edit'))); return; }
		if ((el = e.target.closest('[data-ask-remove]'))) { S.confirmId = Number(el.getAttribute('data-ask-remove')); render(); return; }
		if ((el = e.target.closest('[data-keep]'))) { S.confirmId = null; render(); return; }
		if ((el = e.target.closest('[data-remove]'))) {
			var id = Number(el.getAttribute('data-remove'));
			var index = S.content.findIndex(function (x) { return x.id === id; });
			var item = S.content[index];
			S.content = S.content.filter(function (x) { return x.id !== id; }); S.confirmId = null; S.lastDeleted = { item: item, index: index }; render();
			api('studio/content/' + id, { method: 'DELETE' }).catch(function (err) { toast(err.message); refreshContent(); });
			return;
		}
		if ((el = e.target.closest('[data-undo]'))) {
			var ld = S.lastDeleted;
			if (!ld) { return; }
			S.content.splice(Math.min(ld.index, S.content.length), 0, ld.item); S.lastDeleted = null; render();
			api('studio/content/' + ld.item.id + '/restore', { method: 'POST' }).then(refreshContent).catch(function (err) { toast(err.message); });
			return;
		}
		// Standalone worksheet
		var W = S.ws;
		if ((el = e.target.closest('[data-wslevel]'))) { W.level = el.getAttribute('data-wslevel'); render(); return; }
		if ((el = e.target.closest('[data-wstopic]'))) { W.topic = el.getAttribute('data-wstopic'); W.subtopic = ''; render(); return; }
		if ((el = e.target.closest('[data-publish-ws]'))) {
			W.title = (root.querySelector('[data-ws-title]') || { value: W.title }).value.trim();
			W.desc = (root.querySelector('[data-ws-desc]') || { value: W.desc }).value;
			if (!W.title) { W.error = 'Give the worksheet a name first — that’s what students will see.'; render(); root.querySelector('[data-ws-title]').focus(); return; }
			W.publishing = true; W.error = ''; render();
			var wbody = { id: W.id, title: W.title, level: W.level, topic: W.subtopic || W.topic, description: W.desc, answers: W.ans ? W.ans.id : 0 };
			if (W.pdf && !W.pdf.keep) { wbody.pdf = W.pdf.id; }
			api('studio/worksheets', { method: 'POST', body: wbody }).then(function (d) { W.publishing = false; W.published = d; refreshContent(); render(); })
				.catch(function (err) { W.publishing = false; W.error = err.message; render(); });
			return;
		}
		if ((el = e.target.closest('[data-reset-ws]'))) { S.ws = freshWorksheet(); render(); return; }
		// Past papers
		// Suggested lessons
		var G = S.sg;
		if ((el = e.target.closest('[data-sg-scan]'))) { G.scanning = true; G.error = ''; render(); sgApply(api('studio/suggestions/scan', { method: 'POST' }), function (d) { return d.items.length ? d.items.length + ' videos aren’t on the site yet' : 'Everything on the channel is on the site'; }); return; }
		if ((el = e.target.closest('[data-sgfilter]'))) { G.filter = el.getAttribute('data-sgfilter'); render(); return; }
		if ((el = e.target.closest('[data-sg-ignore]'))) { var ig = el.getAttribute('data-sg-ignore'); G.busy = ig; render(); sgApply(api('studio/suggestions/ignore', { method: 'POST', body: { id: ig } }), 'Hidden — it won’t be suggested again'); return; }
		if ((el = e.target.closest('[data-sg-restore]'))) { var rs = el.getAttribute('data-sg-restore'); G.busy = rs; render(); sgApply(api('studio/suggestions/ignore', { method: 'POST', body: { id: rs, undo: true } }), 'Back in the suggestions'); return; }
		if ((el = e.target.closest('[data-sg-short]'))) { var sh = el.getAttribute('data-sg-short'); G.busy = sh; render(); sgApply(api('studio/suggestions/add', { method: 'POST', body: { id: sh, as: 'short' } }), 'Added to Quick Maths — it’s live').then(refreshContent); return; }
		if ((el = e.target.closest('[data-sg-wizard]'))) { var wv = (S.suggestions.items || []).filter(function (v) { return v.id === el.getAttribute('data-sg-wizard'); })[0]; if (wv) { sgOpenWizard(wv); } return; }
		if ((el = e.target.closest('[data-sg-askall]'))) { G.confirmAll = true; render(); return; }
		if ((el = e.target.closest('[data-sg-cancelall]'))) { G.confirmAll = false; render(); return; }
		if ((el = e.target.closest('[data-sg-addall]'))) { G.scanning = true; render(); sgApply(api('studio/suggestions/add', { method: 'POST', body: { all: true } }), function (d) { return d.created + (d.created === 1 ? ' draft lesson created' : ' draft lessons created') + ' — find them under Your content'; }).then(refreshContent); return; }
		if ((el = e.target.closest('[data-sg-togglehidden]'))) { G.showHidden = !G.showHidden; render(); return; }
		// Dashboard notifications
		if ((el = e.target.closest('[data-notif-go]'))) {
			var nk = el.getAttribute('data-notif-go'), ndef = NOTIFS.filter(function (n) { return n.key === nk; })[0];
			if (ndef && ndef.view) { go(ndef.view); return; }
			S.kindFilter = ndef ? ndef.kind : 'All'; S.issueFilter = nk; S.confirmId = null; go('content'); return;
		}
		if ((el = e.target.closest('[data-notif-ignore]'))) {
			var ik = el.getAttribute('data-notif-ignore'), ic = Number(el.getAttribute('data-count'));
			S.dismissed[ik] = ic; render();
			api('studio/notifications', { method: 'POST', body: { key: ik, count: ic } }).catch(function (err) { toast(err.message); });
			return;
		}
		if ((el = e.target.closest('[data-notif-restore]'))) {
			S.dismissed = {}; render();
			api('studio/notifications', { method: 'POST', body: { reset: true } }).catch(function (err) { toast(err.message); });
			return;
		}
		// Revision pathways
		var PW = S.pw;
		if ((el = e.target.closest('[data-pwlevel]'))) { PW.level = el.getAttribute('data-pwlevel'); PW.topic = ''; PW.data = null; PW.planSaved = false; PW.copied = ''; render(); return; }
		if ((el = e.target.closest('[data-pw-open]'))) { pwOpen(PW.level, el.getAttribute('data-pw-open')); return; }
		if ((el = e.target.closest('[data-pw-list]'))) { PW.topic = ''; PW.data = null; PW.copied = ''; render(); window.scrollTo(0, 0); return; }
		if ((el = e.target.closest('[data-pw-move]'))) {
			var mi = Number(el.getAttribute('data-i')), mj = mi + (el.getAttribute('data-pw-move') === 'up' ? -1 : 1);
			if (PW.rows[mj]) { var tmp = PW.rows[mi]; PW.rows[mi] = PW.rows[mj]; PW.rows[mj] = tmp; tmp.placed = true; PW.saved = ''; render(); }
			return;
		}
		if ((el = e.target.closest('[data-pw-place]'))) { PW.rows[Number(el.getAttribute('data-pw-place'))].placed = true; PW.saved = ''; render(); return; }
		if ((el = e.target.closest('[data-pw-remove]'))) {
			var gone = PW.rows.splice(Number(el.getAttribute('data-pw-remove')), 1)[0];
			if (gone && gone.lessons.length && gone.subtopic) { PW.hidden = (PW.hidden || []).concat([{ subtopic: gone.subtopic, name: gone.name, lessons: gone.lessons }]); toast('Taken out. Its lessons stay on Learn Maths; put it back any time.'); }
			PW.saved = ''; render(); return;
		}
		if ((el = e.target.closest('[data-pw-unhide]'))) {
			var back = PW.hidden.splice(Number(el.getAttribute('data-pw-unhide')), 1)[0];
			if (back) { PW.rows.push({ subtopic: back.subtopic, name: back.name, note: '', lessons: back.lessons, placed: false }); }
			PW.saved = ''; render(); return;
		}
		if ((el = e.target.closest('[data-pw-add]'))) { if (pwAddRow(PW.add, '', 0)) { PW.add = ''; PW.saved = ''; } else { toast('That subtopic is already in the list.'); } render(); var ai = root.querySelector('[data-pw-add-input]'); if (ai) { ai.focus(); } return; }
		if ((el = e.target.closest('[data-pw-pick]'))) { var pk = Number(el.getAttribute('data-pw-pick')), pst = PW.subtopics.filter(function (s) { return s.id === pk; })[0]; if (pst) { pwAddRow(pst.name, '', pst.id); PW.saved = ''; render(); } return; }
		if ((el = e.target.closest('[data-pw-paste-apply]'))) {
			var lines = pwParse(PW.paste);
			if (!lines.length) { return; }
			if (el.getAttribute('data-pw-paste-apply') === 'replace') {
				// Pasted order first; anything with lessons that wasn't mentioned keeps its lessons and drops to the end, flagged.
				var kept = PW.rows.filter(function (r) { return r.lessons.length; }).map(function (r) { r.placed = false; return r; });
				PW.rows = [];
				lines.forEach(function (ln) {
					var had = kept.filter(function (r) { return r.name.toLowerCase() === ln.name.toLowerCase(); })[0];
					if (had) { had.placed = true; if (ln.note) { had.note = ln.note; } PW.rows.push(had); kept = kept.filter(function (r) { return r !== had; }); }
					else { pwAddRow(ln.name, ln.note, 0); }
				});
				PW.rows = PW.rows.concat(kept);
			} else {
				lines.forEach(function (ln) { pwAddRow(ln.name, ln.note, 0); });
			}
			PW.paste = ''; PW.saved = ''; render(); return;
		}
		if ((el = e.target.closest('[data-pw-copy]'))) {
			var ck = el.getAttribute('data-pw-copy'), ctext = ck === 'plan' ? pwPlanPrompt(PW.level) : (pwPrompts().filter(function (pr) { return pr.key === ck; })[0] || {}).text;
			try { navigator.clipboard.writeText(ctext || ''); } catch (err) {}
			PW.copied = ck; toast('Copied. Paste it into ChatGPT.'); render(); return;
		}
		if ((el = e.target.closest('[data-publish-pw]'))) { pwSave(); return; }
		if ((el = e.target.closest('[data-plan-add]'))) {
			var plist = PW.plans[PW.level] = PW.plans[PW.level] || [], plast = plist[plist.length - 1], pnext = '';
			if (plast && plast.week) { var pdt = new Date(plast.week); pdt.setDate(pdt.getDate() + 7); pnext = pdt.toISOString().slice(0, 10); }
			plist.push({ week: pnext, focus: '', short: '' }); PW.planSaved = false; render(); return;
		}
		if ((el = e.target.closest('[data-plan-del]'))) { (PW.plans[PW.level] || []).splice(Number(el.getAttribute('data-plan-del')), 1); PW.planSaved = false; render(); return; }
		if ((el = e.target.closest('[data-plan-paste-apply]'))) {
			var prows = String(PW.planPaste || '').split(/\r?\n/).map(function (line) {
				var parts = line.replace(/^\s*(?:[-*•]|\d+[.)])\s*/, '').split('|').map(function (x) { return x.trim(); });
				return parts[0] && parts[1] ? { week: parts[0], focus: parts[1], short: parts[2] || '' } : null;
			}).filter(Boolean);
			if (!prows.length) { toast('No weeks found. Each line needs a date, a bar and a focus.'); return; }
			PW.plans[PW.level] = prows; PW.planPaste = ''; PW.planSaved = false; render(); return;
		}
		if ((el = e.target.closest('[data-plan-save]'))) { pwPlanSave(); return; }
		if ((el = e.target.closest('[data-ppboard]'))) { P.board = el.getAttribute('data-ppboard'); render(); return; }
		if ((el = e.target.closest('[data-pp-unlink]'))) { var uid = Number(el.getAttribute('data-pp-unlink')); P.worksheets = P.worksheets.filter(function (w) { return w.id !== uid; }); render(); return; }
		if ((el = e.target.closest('[data-pp-unpdf]'))) { P.wsPdfs.splice(Number(el.getAttribute('data-pp-unpdf')), 1); render(); return; }
		if ((el = e.target.closest('[data-ppws-pick]'))) {
			var wid = Number(el.getAttribute('data-ppws-pick')), form = paperForm(el.closest('[data-ppws]'));
			if (!form.worksheets.some(function (w) { return w.id === wid; })) { form.worksheets.push({ id: wid, name: el.getAttribute('data-ppws-name') }); }
			render(); return;
		}
		if ((el = e.target.closest('[data-tier]'))) { P.tier = el.getAttribute('data-tier'); render(); return; }
		if ((el = e.target.closest('[data-paper]'))) { P.paper = el.getAttribute('data-paper'); render(); return; }
		if ((el = e.target.closest('[data-publish-pp]'))) {
			P.publishing = true; P.error = ''; render();
			var body = { id: P.id, board: P.board, tier: P.tier, season: P.season, year: Number(P.year), paper: Number(P.paper), worksheets: P.worksheets.map(function (w) { return w.id; }), worksheet_pdfs: P.wsPdfs.map(function (f) { return f.id; }) };
			if (P.qp && !P.qp.keep) { body.question_paper = P.qp.id; }
			if (!P.ms) { body.mark_scheme = 0; } else if (!P.ms.keep) { body.mark_scheme = P.ms.id; }
			api('studio/past-papers', { method: 'POST', body: body }).then(function (d) {
				P.publishing = false;
				var nws = (d.worksheets || []).length;
				P.published = { summary: B.boards[P.board] + ' · ' + P.season + ' ' + P.year + ' · ' + (P.tier === 'higher' ? 'Higher' : 'Foundation') + ' · Paper ' + P.paper + (d.ms ? ' with its mark scheme' : ' — mark scheme still to come, the site says so for you') + (nws ? ' · ' + nws + (nws === 1 ? ' worksheet linked' : ' worksheets linked') : '') };
				refreshContent(); render();
			}).catch(function (err) { P.publishing = false; P.error = err.message; render(); });
			return;
		}
		if ((el = e.target.closest('[data-reset-pp]'))) { S.pp = freshPaper({ board: P.board, tier: P.tier, season: P.season, year: P.year }); render(); return; }
		// Predicted papers
		var R = S.pr;
		if ((el = e.target.closest('[data-prboard]'))) { R.board = el.getAttribute('data-prboard'); render(); return; }
		if ((el = e.target.closest('[data-prlevel]'))) { R.level = el.getAttribute('data-prlevel'); render(); return; }
		if ((el = e.target.closest('[data-pr-unlink]'))) { var ruid = Number(el.getAttribute('data-pr-unlink')); R.worksheets = R.worksheets.filter(function (w) { return w.id !== ruid; }); render(); return; }
		if ((el = e.target.closest('[data-pr-unpdf]'))) { R.wsPdfs.splice(Number(el.getAttribute('data-pr-unpdf')), 1); render(); return; }
		if ((el = e.target.closest('[data-publish-pr]'))) {
			if (!R.name.trim()) { R.error = 'Give the paper a name students will recognise, e.g. “Paper 1 Pure Mathematics”.'; render(); return; }
			R.publishing = true; R.error = ''; render();
			var rbody = { id: R.id, board: R.board, level: R.level, year: Number(R.year), name: R.name.trim(), calculator: R.calculator, worksheets: R.worksheets.map(function (w) { return w.id; }), worksheet_pdfs: R.wsPdfs.map(function (f) { return f.id; }) };
			if (R.qp && !R.qp.keep) { rbody.question_paper = R.qp.id; }
			if (!R.sol) { rbody.worked_solutions = 0; } else if (!R.sol.keep) { rbody.worked_solutions = R.sol.id; }
			api('studio/predicted-papers', { method: 'POST', body: rbody }).then(function (d) {
				R.publishing = false;
				var rws = (d.worksheets || []).length;
				R.published = { summary: B.boards[R.board] + ' · Predicted ' + R.year + ' · ' + levelName(R.level) + ' · ' + R.name.trim() + (d.solutions ? ' with worked solutions' : '. Worked solutions still to come; the site says so for you') + (rws ? ' · ' + rws + (rws === 1 ? ' worksheet linked' : ' worksheets linked') : '') };
				refreshContent(); render();
			}).catch(function (err) { R.publishing = false; R.error = err.message; render(); });
			return;
		}
		if ((el = e.target.closest('[data-reset-pr]'))) { S.pr = freshPredicted({ board: R.board, level: R.level, year: R.year }); render(); return; }
		// Exam dates
		if ((el = e.target.closest('[data-session]'))) { E.session = el.getAttribute('data-session'); render(); return; }
		if ((el = e.target.closest('[data-edlevel]'))) { E.level = el.getAttribute('data-edlevel'); render(); return; }
		if ((el = e.target.closest('[data-edboard]'))) { E.board = el.getAttribute('data-edboard'); render(); return; }
		if ((el = e.target.closest('[data-save-date]'))) {
			E.saving = true; E.error = ''; render();
			api('studio/exam-dates', { method: 'POST', body: { paper: E.paper, date: E.date, session: E.session, level: E.level, board: E.board, verified: true } }).then(function () {
				E.saving = false; E.date = ''; E.checked = false; toast('Saved — it’s on the calendar now.'); refreshContent(); render();
			}).catch(function (err) { E.saving = false; E.error = err.message; render(); });
			return;
		}
		if ((el = e.target.closest('[data-remove-date]'))) {
			var did = Number(el.getAttribute('data-remove-date'));
			if (E.dateConfirm === did) {
				S.dates = S.dates.filter(function (d) { return d.id !== did; }); E.dateConfirm = null; render();
				api('studio/content/' + did, { method: 'DELETE' }).then(refreshContent).catch(function (err) { toast(err.message); refreshContent(); });
			} else { E.dateConfirm = did; render(); }
			return;
		}
	});
	root.addEventListener('pointermove', function (e) {
		var box = e.target.closest('[data-st-chart]');
		if (box) { statsChartHover(box, e.clientX); }
	});
	root.addEventListener('pointerout', function (e) {
		var box = e.target.closest('[data-st-chart]');
		if (box && !box.contains(e.relatedTarget)) { statsChartLeave(box); }
	});
	document.addEventListener('click', function (e) {
		var a = e.target.closest('[data-studio-nav] [data-view]');
		if (a) { e.preventDefault(); if (a.getAttribute('data-view') === 'lesson' && S.lesson.published) { S.lesson = freshLesson(); } if (a.getAttribute('data-view') === 'worksheets' && S.ws.published) { S.ws = freshWorksheet(); } go(a.getAttribute('data-view')); }
	});

	if (B.editId) { editItem(B.editId); } else { render(); }
})();
