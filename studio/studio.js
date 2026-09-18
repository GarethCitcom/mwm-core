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
		if (name === 'calendar') { return '<svg width="18" height="18" viewBox="0 0 16 16" ' + p + '><rect x="2" y="3" width="12" height="11" rx="2"></rect><line x1="2" y1="6.5" x2="14" y2="6.5"></line><line x1="5.5" y1="1.5" x2="5.5" y2="4"></line><line x1="10.5" y1="1.5" x2="10.5" y2="4"></line></svg>'; }
		if (name === 'tick') { return '<svg width="24" height="24" viewBox="0 0 16 16" fill="none" stroke="#FFFFFF" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3.5 8.5l3 3 6-7"></path></svg>'; }
		return '';
	}
	var STEP_NAMES = ['Video', 'Where it goes', 'Practice', 'Check & publish'];

	/* ---------------------------------------------------------------- state */
	function freshLesson() {
		return { id: 0, step: 1, yt: '', video: null, videoError: '', finding: false, level: 'gcse-higher', topic: 'algebra', subtopic: '', ws: null, ans: null, quizText: '', quizResult: null, quizImages: {}, copied: false, publishing: false, published: null, editingTitle: '', publishError: '' };
	}
	function freshPaper(keep) {
		var now = new Date();
		return { id: 0, board: keep.board || 'edexcel', season: keep.season || (now.getMonth() >= 5 ? 'June' : 'November'), year: keep.year || (now.getMonth() >= 5 ? now.getFullYear() : now.getFullYear() - 1), tier: keep.tier || 'higher', paper: '1', qp: null, ms: null, worksheets: [], wsPdfs: [], published: null, publishing: false, error: '', editingTitle: '' };
	}
	function freshWorksheet() {
		return { id: 0, title: '', level: 'gcse-foundation', topic: 'number', subtopic: '', pdf: null, ans: null, desc: '', published: null, publishing: false, error: '', editingTitle: '' };
	}
	var S = {
		view: B.view || 'dash',
		playlists: B.playlists, sync: B.sync, synced: false, syncing: false, recent: B.recent,
		content: B.content, kindFilter: 'All', issueFilter: '', confirmId: null, lastDeleted: null,
		lesson: freshLesson(),
		pp: freshPaper({}),
		ws: freshWorksheet(),
		ed: { paper: 'Paper 1 (non-calculator)', date: '', session: 'morning', level: 'gcse-higher', board: 'edexcel', checked: false, saving: false, error: '', dateConfirm: null },
		dates: B.dates
	};

	/* ---------------------------------------------------------------- views */
	function viewDash() {
		var cards = [
			{ icon: 'play', title: 'Add a new lesson', text: 'Paste a YouTube link and we’ll do the rest — about two minutes.', view: 'lesson', primary: true },
			{ icon: 'worksheet', title: 'Add a worksheet', text: 'A worksheet on its own, with its own page to send students to.', view: 'worksheets' },
			{ icon: 'download', title: 'Upload past papers', text: 'Add a paper and its mark scheme as PDFs.', view: 'papers' }
		];
		var status = S.synced ? 'Checked just now' : (S.sync && S.sync.last_relative ? S.sync.last_relative : 'Not checked yet');
		var syncLabel = S.syncing ? 'Checking…' : (S.synced ? '✓ All up to date' : 'Check for new videos now');
		return '<h1 class="st-h1">Hi ' + esc(B.user.name) + '</h1>' +
			'<p class="st-intro">Everything here goes straight onto the website, step by step. You can’t break anything — every change can be undone.</p>' +
			'<div class="st-cards">' + cards.map(function (c) {
				return '<div class="st-card"><span class="st-card__icon">' + icon(c.icon) + '</span><h2>' + esc(c.title) + '</h2><p>' + esc(c.text) + '</p>' +
					'<button type="button" class="mwm-btn ' + (c.primary ? 'mwm-btn--primary' : 'mwm-btn--secondary') + '" data-go="' + c.view + '">Start</button></div>';
			}).join('') + '</div>' +
			'<div class="st-tintbox"><div class="st-tintbox__head"><h2>Quick Maths &amp; Gaming look after themselves</h2><span class="mwm-meta">' + esc(status) + '</span></div>' +
			'<p>Anything you add to these YouTube playlists appears on the site overnight, automatically. There’s nothing for you to do here.</p>' +
			'<div class="st-list">' + S.playlists.map(function (pl) { return '<div class="st-list__row"><span class="st-list__name">' + esc(pl.name) + '</span><span class="st-list__meta">' + esc(pl.meta) + '</span></div>'; }).join('') +
			'<div class="st-list__foot"><button type="button" class="mwm-linkbtn mwm-linkbtn--sm" data-sync' + (S.syncing ? ' disabled' : '') + '>' + esc(syncLabel) + '</button>' + (S.sync && S.sync.error && !S.synced ? '<p class="st-error" style="margin-top:8px">' + esc(S.sync.error) + '</p>' : '') + '</div></div></div>' +
			'<div class="st-card st-card--small"><span class="st-card__icon">' + icon('calendar') + '</span><div class="st-card__text"><h2>Update exam dates</h2><p>Once a year, when the boards confirm them — verified dates appear on the exam calendar.</p></div><button type="button" class="mwm-linkbtn mwm-linkbtn--sm" data-go="dates">Update dates<span aria-hidden="true">→</span></button></div>' +
			'<h2 class="st-h2">Recently added</h2>' +
			'<div class="st-list st-list--12">' + (S.recent.length ? S.recent.map(function (r) { return '<div class="st-list__row"><span class="st-list__name" style="flex:1">' + esc(r.title) + '</span><span class="st-list__sub" style="margin:0;white-space:nowrap">' + esc(r.added) + '</span>' + tag('Live', 'mwm-tag--live') + '</div>'; }).join('') : '<div class="st-list__row"><span class="mwm-meta">Nothing added yet — your first lesson will show here.</span></div>') + '</div>' +
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
				html += '<div class="st-found"><span class="st-found__thumb" style="background-image:url(' + esc(v.thumbnail) + ')"></span><div><div class="st-found__title">' + esc(v.title) + '</div><div class="st-found__meta">' + esc(meta || 'Length will show once published') + '</div>' +
					(v.existing_id && v.existing_id !== L.id ? '<div class="st-error" style="margin-top:4px">This video is already on the site. <button type="button" class="mwm-linkbtn mwm-linkbtn--sm" data-edit-lesson="' + v.existing_id + '">Edit that lesson instead</button></div>' : '<div class="st-found__ok">✓ Found it — that’s the one?</div>') + '</div></div>';
				if (v.warning) { html += '<p class="st-note">' + esc(v.warning) + '</p>'; }
			}
			html += '</div>';
		}
		if (L.step === 2) {
			var topic = B.topics.filter(function (t) { return t.slug === L.topic; })[0];
			html += '<div class="st-panel"><h2>Where does it belong?</h2><p class="st-panel__sub">Not sure? Pick the closest — you can change it any time.</p>' +
				'<div class="st-label">Level</div><div class="st-chips">' + B.levels.map(function (l) { return chip(l.name, L.level === l.slug, { level: l.slug }); }).join('') + '</div>' +
				'<div class="st-label st-label--24">Topic</div><div class="st-chips">' + B.topics.map(function (t) { return chip(t.name, L.topic === t.slug, { topic: t.slug }); }).join('') + '</div>';
			html += '<div class="st-label st-label--24">Subtopic <span style="font-weight:400;color:var(--muted)">(optional)</span></div>' + picker('subtopic', topic, L.subtopic) +
				'<p class="st-note st-note--8">Can’t find the right one? Type a new name and choose “Create” — it’s added under ' + esc(topic ? topic.name : 'the topic') + ' straight away.</p>';
			html += '</div>';
		}
		if (L.step === 3) {
			html += '<div class="st-panel"><h2>Add the practice bits (optional)</h2><p class="st-panel__sub">Skip anything that isn’t ready — the site never shows an empty button, just a friendly “not yet” note.</p>' +
				fileRow('Worksheet PDF', L.ws ? '✓ ' + L.ws.filename + ' added — it gets its own worksheet page too' : 'No worksheet yet — students will see a friendly note instead of a button.', !!L.ws, 'ws', 'st-filerow--first') +
				fileRow('Worked answers PDF', L.ans ? '✓ ' + L.ans.filename + ' added' : 'Optional — appears behind “Reveal answers”.', !!L.ans, 'ans', '') +
				'<div class="st-quiz"><div class="st-filerow__label">Quiz <span>(optional)</span></div><div class="st-quiz__sub">Ask ChatGPT to write one for you: copy the instructions below, swap in your topic, then paste back what it gives you.</div>' +
				'<div class="st-prompt"><div class="st-prompt__head"><span class="st-prompt__title">Instructions for ChatGPT</span><button type="button" class="st-copy" data-copy>' + (L.copied ? '✓ Copied' : 'Copy') + '</button></div><div class="st-prompt__text">' + esc(B.prompt) + '</div></div>' +
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
					'<div class="st-preview"><span class="st-preview__thumb" style="background-image:url(' + esc(L.video ? L.video.thumbnail : '') + ')"></span><div class="st-preview__body"><div class="mwm-tags">' + tag(levelName(L.level), 'mwm-tag--higher') + tag(topicName(L.subtopic || L.topic), 'mwm-tag--outline') + '</div>' +
					'<div class="st-preview__title">' + esc(L.video ? L.video.title : L.editingTitle) + '</div><div class="st-preview__meta">' + esc([mins].concat(extras).filter(Boolean).join(' · ')) + '</div></div></div>' +
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
			{ key: 'level', label: 'Level needs checking', tag: 'Check level' },
			{ key: 'no_worksheet', label: 'Missing worksheet', tag: 'No worksheet' },
			{ key: 'no_answers', label: 'Missing worked answers', tag: 'No answers' },
			{ key: 'no_quiz', label: 'No quiz yet', tag: 'No quiz' },
			{ key: 'video', label: 'Video missing or not playable', tag: 'Video problem' }
		],
		Worksheets: [
			{ key: 'unlinked', label: 'Not linked to a lesson', tag: 'No lesson' }
		]
	};
	function issueTag(key) {
		var all = ISSUES.Lessons.concat(ISSUES.Worksheets);
		var i = all.filter(function (x) { return x.key === key; })[0];
		return i ? i.tag : key;
	}
	function viewContent() {
		var kinds = ['All', 'Lessons', 'Worksheets', 'Past papers', 'Exam dates', 'Quizzes'];
		var map = { 'Lessons': 'Lesson', 'Worksheets': 'Worksheet', 'Past papers': 'Past paper', 'Exam dates': 'Exam date', 'Quizzes': 'Quiz' };
		var ofKind = S.content.filter(function (it) { return S.kindFilter === 'All' || map[S.kindFilter] === it.kind; });
		var issues = ISSUES[S.kindFilter] || [];
		var rows = S.issueFilter ? ofKind.filter(function (it) { return (it.flags || []).indexOf(S.issueFilter) > -1; }) : ofKind;
		var issuesHtml = '';
		if (issues.length) {
			var fine = ofKind.filter(function (it) { return !(it.flags || []).length; }).length;
			issuesHtml = '<div class="st-issues"><span class="st-issues__label">Needs attention</span><div class="st-chips st-chips--flush">' +
				issues.map(function (i) {
					var n = ofKind.filter(function (it) { return (it.flags || []).indexOf(i.key) > -1; }).length;
					return chip(i.label + ' · ' + n, S.issueFilter === i.key, { issue: i.key }, n ? '' : 'is-empty');
				}).join('') + '</div>' +
				'<div class="st-issues__meta">' + (S.issueFilter ? 'Showing ' + rows.length + ' of ' + ofKind.length + ' · <button type="button" class="mwm-linkbtn mwm-linkbtn--sm" data-issue="">Show all</button>' : fine + ' of ' + ofKind.length + ' have nothing outstanding') + '</div></div>';
		}
		return '<a href="' + esc(B.site + 'studio/') + '" class="st-back" data-go="dash"><span aria-hidden="true">←</span>Back to your dashboard</a>' +
			'<h1 class="st-h1 st-h1--after-back">Your content</h1>' +
			'<p class="st-intro">Everything that’s on the site. Edit anything, or remove it — there’s an undo if you change your mind.</p>' +
			'<div class="st-chips" style="margin-top:24px">' + kinds.map(function (k) { return chip(k, S.kindFilter === k, { kind: k }); }).join('') + '</div>' +
			issuesHtml +
			(S.lastDeleted ? '<div class="st-undo"><span>“' + esc(S.lastDeleted.item.title) + '” has been removed from the site.</span><button type="button" data-undo>Undo</button></div>' : '') +
			'<div class="st-list st-list--20">' + (rows.length ? rows.map(function (r) {
				var actions = S.confirmId === r.id
					? '<span class="st-confirm-text">Remove from the site?</span><button type="button" class="st-smallbtn st-smallbtn--danger" data-remove="' + r.id + '">Yes, remove</button><button type="button" class="st-smallbtn st-smallbtn--keep" data-keep>Keep it</button>'
					: '<button type="button" class="st-smallbtn" data-edit="' + r.id + '">Edit</button><button type="button" class="st-smallbtn st-smallbtn--quiet" data-ask-remove="' + r.id + '">Remove</button>';
				var flags = (r.flags || []).map(function (f) { return tag(issueTag(f), 'mwm-tag--warn mwm-tag--sm' + (S.issueFilter === f ? ' is-on' : '')); }).join('');
				return '<div class="st-list__row st-list__row--16"><div class="st-list__main"><div class="st-list__name">' + esc(r.title) + '</div><div class="st-list__sub">' + esc(r.meta) + '</div>' + (flags ? '<div class="st-list__flags">' + flags + '</div>' : '') + '</div>' + tag(r.kind, 'mwm-tag--outline mwm-tag--sm') + actions + '</div>';
			}).join('') : '<div class="st-list__row"><span class="mwm-meta">' + (S.issueFilter ? 'Nothing needs attention here — nice.' : 'Nothing here yet.') + '</span></div>') + '</div>' +
			'<p class="st-note st-note--16">Removing a lesson never deletes your video — it stays safe on YouTube.</p>';
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
			'<div class="st-picker" data-ppws><div class="st-picker__control"><input type="text" class="st-input st-picker__input" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-label="Search worksheets" placeholder="Search worksheets by name, e.g. “ratio”…" autocomplete="off" data-ppws-input><span class="st-picker__caret" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="7" cy="7" r="4.5"/><path d="M10.5 10.5L14 14"/></svg></span></div><div class="st-picker__list" role="listbox" hidden data-ppws-list></div></div>' +
			'<div class="st-label st-label--28">4 · Publish</div>' +
			(P.qp || P.id ? '<button type="button" class="mwm-btn mwm-btn--primary mwm-btn--lg st-publish st-publish--12" data-publish-pp' + (P.publishing ? ' disabled' : '') + '>' + (P.publishing ? 'Publishing…' : (P.id ? 'Publish changes' : 'Publish this paper')) + '</button>' : '<p class="st-note st-note--12">Add the question paper PDF above and the publish button appears here.</p>') +
			(P.error ? '<p class="st-error">' + esc(P.error) + '</p>' : '') + '</div>';
		return html;
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

	function render() {
		var html;
		switch (S.view) {
			case 'lesson': html = viewLesson(); break;
			case 'content': html = viewContent(); break;
			case 'worksheets': html = viewWorksheet(); break;
			case 'papers': html = viewPapers(); break;
			case 'dates': html = viewDates(); break;
			default: html = viewDash();
		}
		root.innerHTML = html;
		document.querySelectorAll('[data-studio-nav] [data-view]').forEach(function (a) {
			// Past papers and exam dates live off the dashboard, so keep Dashboard lit while on them.
			var on = a.getAttribute('data-view') === S.view || (a.getAttribute('data-view') === 'dash' && (S.view === 'papers' || S.view === 'dates'));
			a.classList.toggle('is-current', on);
			if (on) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); }
		});
		try { history.replaceState(null, '', B.site + 'studio/' + (S.view === 'dash' ? '' : S.view + '/')); } catch (e) {}
	}
	function go(view) { S.view = view; window.scrollTo(0, 0); render(); }

	/* ---------------------------------------------------------------- actions */
	function findVideo(url) {
		var L = S.lesson;
		L.yt = url; L.videoError = ''; L.video = null; L.finding = true; render();
		api('studio/video?url=' + encodeURIComponent(url)).then(function (v) {
			L.video = v; L.finding = false; render();
		}).catch(function (e) { L.videoError = e.message; L.finding = false; render(); });
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
		var body = { id: L.id, youtube_url: L.video ? L.video.id : L.yt, title: L.video ? L.video.title : '', level: L.level, topic: L.subtopic || L.topic, worksheet: L.ws ? L.ws.id : 0, answers: L.ans ? L.ans.id : 0, seconds: L.video ? L.video.seconds : undefined, thumbnail: L.video ? L.video.thumbnail : undefined };
		var quiz = quizPayload();
		if (quiz !== undefined) { body.quiz = quiz; }
		api('studio/lessons', { method: 'POST', body: body }).then(function (card) {
			L.publishing = false; L.published = card; refreshContent(); render();
		}).catch(function (e) { L.publishing = false; L.publishError = e.message; render(); });
	}
	function refreshContent() {
		api('studio/content').then(function (rows) { S.content = rows; S.recent = rows.slice(0, 3); S.dates = rows.filter(function (r) { return r.kind === 'Exam date'; }); if (S.view === 'content' || S.view === 'dash' || S.view === 'dates') { render(); } }).catch(function () {});
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
				L.id = c.id; L.editingTitle = c.title; L.yt = c.youtube_url; L.level = c.level_slug || 'gcse-higher';
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
		} else {
			go('dates');
		}
	}

	/* ---------------------------------------------------------------- events */
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
	/* Worksheet search for past papers: queries the public worksheets endpoint as you type. */
	var wsSearchTimer = 0, wsSearchSeq = 0;
	function wsSearch(box, q) {
		var list = box.querySelector('[data-ppws-list]');
		var mine = ++wsSearchSeq;
		q = q.trim();
		if (!q) { list.setAttribute('hidden', ''); box.classList.remove('is-open'); return; }
		list.innerHTML = '<div class="st-picker__empty">Searching…</div>'; list.removeAttribute('hidden'); box.classList.add('is-open');
		api('worksheets?per_page=8&search=' + encodeURIComponent(q)).then(function (res) {
			if (mine !== wsSearchSeq) { return; }
			var linked = S.pp.worksheets.map(function (w) { return w.id; });
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
		if (e.target.matches('[data-yt]')) { S.lesson.yt = e.target.value; }
		if (e.target.matches('[data-quiz-text]')) { S.lesson.quizText = e.target.value; S.lesson.quizResult = null; }
		if (e.target.matches('[data-ed="date"]')) { S.ed.date = e.target.value; S.ed.error = ''; render(); }
		if (e.target.matches('[data-ws-title]')) { S.ws.title = e.target.value; S.ws.error = ''; var t = root.querySelector('.st-preview__title'); if (t) { t.textContent = S.ws.title || 'Untitled worksheet'; } }
		if (e.target.matches('[data-ws-desc]')) { S.ws.desc = e.target.value; }
	});
	root.addEventListener('change', function (e) {
		var el = e.target, L = S.lesson;
		if (el.matches('[data-pdf]')) {
			var key = el.getAttribute('data-pdf'), file = el.files && el.files[0];
			if (!file) { return; }
			var target = key === 'qp' || key === 'ms' || key === 'ppws' ? S.pp : (key === 'wspdf' || key === 'wsans' ? S.ws : L);
			var prop = key === 'wspdf' ? 'pdf' : (key === 'wsans' ? 'ans' : key);
			root.classList.add('st-busy');
			upload(file, 'pdf').then(function (info) { root.classList.remove('st-busy'); if (key === 'ppws') { S.pp.wsPdfs.push(info); } else { target[prop] = info; } if (target !== L) { target.error = ''; } render(); })
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
		else if (el.matches('[data-ed="paper"]')) { S.ed.paper = el.value; }
		else if (el.matches('[data-ed="checked"]')) { S.ed.checked = el.checked; S.ed.error = ''; render(); }
	});
	root.addEventListener('click', function (e) {
		var el, L = S.lesson, P = S.pp, E = S.ed;
		if ((el = e.target.closest('[data-go]'))) { e.preventDefault(); if (el.getAttribute('data-go') === 'lesson' && L.published) { S.lesson = freshLesson(); } if (el.getAttribute('data-go') === 'worksheets' && S.ws.published) { S.ws = freshWorksheet(); } go(el.getAttribute('data-go')); return; }
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
		// Lesson wizard
		if ((el = e.target.closest('[data-level]'))) { L.level = el.getAttribute('data-level'); render(); return; }
		if ((el = e.target.closest('[data-topic]'))) { L.topic = el.getAttribute('data-topic'); L.subtopic = ''; render(); return; }
		if ((el = e.target.closest('[data-copy]'))) {
			try { navigator.clipboard.writeText(B.prompt); } catch (err) {}
			L.copied = true; render(); return;
		}
		if ((el = e.target.closest('[data-check-quiz]'))) { checkQuiz(root.querySelector('[data-quiz-text]').value); return; }
		if ((el = e.target.closest('[data-edit-lesson]'))) { editItem(Number(el.getAttribute('data-edit-lesson'))); return; }
		if ((el = e.target.closest('[data-back]'))) { L.step = Math.max(1, L.step - 1); window.scrollTo(0, 0); render(); return; }
		if ((el = e.target.closest('[data-next]'))) { L.step = Math.min(4, L.step + 1); window.scrollTo(0, 0); render(); return; }
		if ((el = e.target.closest('[data-publish]'))) { publishLesson(); return; }
		if ((el = e.target.closest('[data-reset-lesson]'))) { S.lesson = freshLesson(); render(); return; }
		// Content
		if ((el = e.target.closest('[data-kind]'))) { S.kindFilter = el.getAttribute('data-kind'); S.issueFilter = ''; S.confirmId = null; render(); return; }
		if ((el = e.target.closest('[data-issue]'))) { var iss = el.getAttribute('data-issue'); S.issueFilter = S.issueFilter === iss ? '' : iss; S.confirmId = null; render(); return; }
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
		if ((el = e.target.closest('[data-ppboard]'))) { P.board = el.getAttribute('data-ppboard'); render(); return; }
		if ((el = e.target.closest('[data-pp-unlink]'))) { var uid = Number(el.getAttribute('data-pp-unlink')); P.worksheets = P.worksheets.filter(function (w) { return w.id !== uid; }); render(); return; }
		if ((el = e.target.closest('[data-pp-unpdf]'))) { P.wsPdfs.splice(Number(el.getAttribute('data-pp-unpdf')), 1); render(); return; }
		if ((el = e.target.closest('[data-ppws-pick]'))) {
			var wid = Number(el.getAttribute('data-ppws-pick'));
			if (!P.worksheets.some(function (w) { return w.id === wid; })) { P.worksheets.push({ id: wid, name: el.getAttribute('data-ppws-name') }); }
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
	document.addEventListener('click', function (e) {
		var a = e.target.closest('[data-studio-nav] [data-view]');
		if (a) { e.preventDefault(); if (a.getAttribute('data-view') === 'lesson' && S.lesson.published) { S.lesson = freshLesson(); } if (a.getAttribute('data-view') === 'worksheets' && S.ws.published) { S.ws = freshWorksheet(); } go(a.getAttribute('data-view')); }
	});

	if (B.editId) { editItem(B.editId); } else { render(); }
})();
