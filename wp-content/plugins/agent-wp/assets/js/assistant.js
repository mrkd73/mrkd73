(function () {
	'use strict';

	var cfg = window.agentWpAssistant || {};
	var i18n = cfg.i18n || {};
	var root = document.getElementById('wpa-assistant-root');
	if (!root) return;

	var open = false;
	var tab = 'ideas';
	var sending = false;
	var suggestions = [];
	var pageSuggestions = [];
	var briefing = null;
	var pageCtx = cfg.page || {};
	var viewMode = 'briefing';
	var filterStatus = 'open';
	var historyLoaded = false;
	var lastOpenCount = parseInt(cfg.openCount, 10) || 0;
	var baseTitle = document.title;

	root.hidden = false;
	root.classList.toggle('wpa-as--right', cfg.fabSide === 'right');
	root.innerHTML =
		'<button type="button" class="wpa-as__fab" id="wpa-as-fab" aria-label="' +
		esc(i18n.title || 'دستیار') +
		'">' +
		'<svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><path fill="currentColor" d="M12 3a9 9 0 0 0-9 9v7a2 2 0 0 0 2 2h2v-8H5a7 7 0 0 1 14 0h-2v8h2a2 2 0 0 0 2-2v-7a9 9 0 0 0-9-9zm-3 11v2h2v-2H9zm4 0v2h2v-2h-2z"/></svg>' +
		'<span class="wpa-as__badge" id="wpa-as-badge" hidden>0</span>' +
		'</button>' +
		'<div class="wpa-as__panel" id="wpa-as-panel" hidden>' +
		'<header class="wpa-as__head">' +
		'<div class="wpa-as__head-main">' +
		'<h2 class="wpa-as__brand">' +
		esc(i18n.title || 'دستیار') +
		'</h2>' +
		'<p class="wpa-as__tagline">' +
		esc(i18n.subtitle || 'همراه رشد سایت شما') +
		'</p>' +
		'<div class="wpa-as__status"><span class="wpa-as__dot" aria-hidden="true"></span><span id="wpa-as-status-text">' +
		esc(cfg.lastScanLabel || i18n.online || 'مشاور آنلاین') +
		'</span></div>' +
		'</div>' +
		'<div class="wpa-as__head-actions">' +
		'<button type="button" class="wpa-as__icon-btn" id="wpa-as-min" title="' +
		esc(i18n.minimize || 'جمع') +
		'">–</button>' +
		'<button type="button" class="wpa-as__icon-btn" id="wpa-as-copy" title="' +
		esc(i18n.copyDigest || 'کپی خلاصه') +
		'">⎘</button>' +
		'</div>' +
		'</header>' +
		'<div class="wpa-as__tabs">' +
		'<button type="button" class="wpa-as__tab is-active" data-tab="ideas">' +
		esc(i18n.ideasTab || 'خلاصه') +
		'</button>' +
		'<button type="button" class="wpa-as__tab" data-tab="chat">' +
		esc(i18n.chatTab || 'گفتگو') +
		'</button>' +
		'</div>' +
		'<div class="wpa-as__body">' +
		'<div class="wpa-as__pane" id="wpa-as-ideas-pane">' +
		'<div class="wpa-as__toolbar">' +
		'<button type="button" class="wpa-as__chip is-active" data-filter="open">' +
		esc(i18n.filterOpen || 'امروز') +
		'</button>' +
		'<button type="button" class="wpa-as__chip" data-filter="done">' +
		esc(i18n.filterDone || 'آرشیو') +
		'</button>' +
		'<button type="button" class="wpa-as__chip" id="wpa-as-scan">' +
		esc(i18n.scan || 'بررسی تازه') +
		'</button>' +
		'</div>' +
		'<div id="wpa-as-ideas"></div>' +
		'</div>' +
		'<div class="wpa-as__pane" id="wpa-as-chat-pane" hidden>' +
		'<div id="wpa-as-messages"></div>' +
		'</div>' +
		'</div>' +
		'<div class="wpa-as__composer" id="wpa-as-composer" hidden>' +
		'<textarea class="wpa-as__input" id="wpa-as-input" rows="1" placeholder="' +
		esc(i18n.placeholder || 'پیام') +
		'"></textarea>' +
		'<button type="button" class="wpa-as__send" id="wpa-as-send" aria-label="ارسال">' +
		'<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M3 20.5v-17L21 12 3 20.5zm2.5-3.3L14.1 12 5.5 6.8v3.7L11 12l-5.5 1.5v3.7z"/></svg>' +
		'</button>' +
		'</div>' +
		'</div>' +
		'<div class="wpa-as__toast" id="wpa-as-toast" hidden></div>';

	var fab = document.getElementById('wpa-as-fab');
	var panel = document.getElementById('wpa-as-panel');
	var badge = document.getElementById('wpa-as-badge');
	var messagesEl = document.getElementById('wpa-as-messages');
	var ideasEl = document.getElementById('wpa-as-ideas');
	var chatPane = document.getElementById('wpa-as-chat-pane');
	var ideasPane = document.getElementById('wpa-as-ideas-pane');
	var composer = document.getElementById('wpa-as-composer');
	var input = document.getElementById('wpa-as-input');
	var sendBtn = document.getElementById('wpa-as-send');
	var scanBtn = document.getElementById('wpa-as-scan');
	var copyBtn = document.getElementById('wpa-as-copy');
	var minBtn = document.getElementById('wpa-as-min');
	var statusText = document.getElementById('wpa-as-status-text');
	var toastEl = document.getElementById('wpa-as-toast');
	var filterCategory = '';
	var searchQ = '';
	var expandedId = null;

	try {
		if (localStorage.getItem('wpa_as_min') === '1') {
			root.classList.add('is-minimized');
		}
	} catch (e) {}

	function esc(s) {
		return String(s || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/"/g, '&quot;');
	}

	function api(action, data) {
		var body = new window.FormData();
		body.append('action', action);
		body.append('nonce', cfg.nonce || '');
		if (data) {
			Object.keys(data).forEach(function (k) {
				body.append(k, data[k]);
			});
		}
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) {
			return r.json();
		});
	}

	function pagePayload(extra) {
		var out = {
			page_url: pageCtx.url || window.location.href || '',
			page_screen: pageCtx.screen || '',
			page_post_id: String(pageCtx.postId || 0),
			page_is_admin: pageCtx.isAdmin || cfg.isAdmin ? '1' : '0',
			page_title: pageCtx.title || document.title || '',
			page_comment_id: String(pageCtx.commentId || 0),
			page_post_type: pageCtx.postType || '',
			page_option: pageCtx.optionPage || '',
			page_is_new: pageCtx.isNew ? '1' : '0',
		};
		if (extra) {
			Object.keys(extra).forEach(function (k) {
				out[k] = extra[k];
			});
		}
		return out;
	}

	function toast(msg) {
		if (!toastEl) return;
		toastEl.hidden = false;
		toastEl.textContent = msg;
		window.clearTimeout(toast._t);
		toast._t = window.setTimeout(function () {
			toastEl.hidden = true;
		}, 3200);
	}

	function setScanLabel(label) {
		if (statusText && label) statusText.textContent = label;
	}

	function copyText(text) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(text);
		}
		return new Promise(function (resolve, reject) {
			var ta = document.createElement('textarea');
			ta.value = text;
			document.body.appendChild(ta);
			ta.select();
			try {
				document.execCommand('copy');
				resolve();
			} catch (err) {
				reject(err);
			}
			ta.remove();
		});
	}

	function updateDocTitle(n) {
		n = parseInt(n, 10) || 0;
		document.title = n > 0 ? '(' + n + ') ' + baseTitle : baseTitle;
	}

	function setBadge(n) {
		n = parseInt(n, 10) || 0;
		if (n > lastOpenCount && lastOpenCount >= 0) {
			fab.classList.add('is-pulse');
			window.setTimeout(function () {
				fab.classList.remove('is-pulse');
			}, 1800);
			if (cfg.sound !== false && cfg.sound !== 0 && cfg.sound !== '0') {
				try {
					var ctx = new (window.AudioContext || window.webkitAudioContext)();
					var o = ctx.createOscillator();
					var g = ctx.createGain();
					o.connect(g);
					g.connect(ctx.destination);
					o.frequency.value = 880;
					g.gain.value = 0.03;
					o.start();
					o.stop(ctx.currentTime + 0.08);
				} catch (err) {}
			}
			if (cfg.notify && window.Notification && Notification.permission === 'granted') {
				try {
					new Notification(i18n.title || 'دستیار', {
						body: n + ' پیشنهاد باز',
						silent: true,
					});
				} catch (err2) {}
			}
		}
		lastOpenCount = n;
		if (n > 0) {
			badge.hidden = false;
			badge.textContent = String(n > 99 ? '99+' : n);
		} else {
			badge.hidden = true;
		}
		updateDocTitle(n);
	}

	function formatTime(iso) {
		var d = iso ? new Date(String(iso).replace(' ', 'T')) : new Date();
		if (isNaN(d.getTime())) d = new Date();
		return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
	}

	function addMsg(text, mine, opts) {
		opts = opts || {};
		var div = document.createElement('div');
		div.className = 'wpa-as__msg' + (mine ? ' is-me' : '');
		div.textContent = text;
		var meta = document.createElement('span');
		meta.className = 'wpa-as__msg-meta';
		var bits = [formatTime(opts.createdAt)];
		if (opts.route && opts.route.modelName) {
			bits.push(opts.route.auto ? 'خودکار · ' + opts.route.modelName : opts.route.modelName);
		} else if (opts.route && opts.route.label) {
			bits.push(opts.route.label);
		}
		meta.textContent = bits.join(' · ');
		div.appendChild(meta);
		messagesEl.appendChild(div);
		chatPane.scrollTop = chatPane.scrollHeight;
		return div;
	}

	function showTyping(on) {
		var el = document.getElementById('wpa-as-typing');
		if (!on) {
			if (el) el.remove();
			return;
		}
		if (el) return;
		el = document.createElement('div');
		el.id = 'wpa-as-typing';
		el.className = 'wpa-as__typing';
		el.innerHTML = '<span></span><span></span><span></span>';
		messagesEl.appendChild(el);
		chatPane.scrollTop = chatPane.scrollHeight;
	}

	function toolLabel(id) {
		var labels = {
			wp_content: 'محتوا',
			post_meta: 'متا',
			rest: 'REST',
			update_option: 'تنظیمات',
			write_file: 'فایل',
			trash_content: 'حذف',
			media: 'رسانه',
			menus: 'منو',
			discover: 'کشف',
		};
		return labels[id] || id || 'ابزار';
	}

	function appendTools(tools) {
		if (!tools || !tools.length) return;
		tools.forEach(function (tool) {
			var card = document.createElement('div');
			card.className = 'wpa-as__tool';
			var needsConfirm = !!(tool.data && tool.data.needsConfirm && tool.data.confirmToken);
			var head = document.createElement('div');
			head.className = 'wpa-as__tool-head';
			head.innerHTML =
				'<strong>' +
				esc(toolLabel(tool.id)) +
				'</strong><span class="wpa-as__tool-st ' +
				(needsConfirm ? 'is-wait' : tool.ok ? 'is-ok' : 'is-fail') +
				'">' +
				esc(
					needsConfirm
						? i18n.awaitConfirm || 'در انتظار تأیید'
						: tool.ok
							? i18n.toolOk || 'موفق'
							: i18n.toolFail || 'ناموفق'
				) +
				'</span>';
			var body = document.createElement('div');
			body.className = 'wpa-as__tool-body';
			body.textContent = tool.message || '';
			card.appendChild(head);
			card.appendChild(body);
			if (needsConfirm) {
				var ttl = parseInt(cfg.pendingTtl, 10) || 1800;
				var ttlEl = document.createElement('div');
				ttlEl.className = 'wpa-as__tool-ttl';
				ttlEl.textContent = (i18n.ttlLeft || 'مانده') + ': ~' + Math.round(ttl / 60) + ' د';
				card.appendChild(ttlEl);
				if (tool.data && tool.data.warning) {
					var warnEl = document.createElement('div');
					warnEl.className = 'wpa-as__tool-warn';
					warnEl.textContent = tool.data.warning;
					card.appendChild(warnEl);
				}
				var actions = document.createElement('div');
				actions.className = 'wpa-as__tool-actions';
				var conf = document.createElement('button');
				conf.type = 'button';
				conf.className = 'wpa-as__chip wpa-as__chip--primary';
				conf.textContent =
					tool.data && tool.data.highRisk
						? i18n.confirmRisk || 'متوجه شدم — تأیید'
						: i18n.confirm || 'تأیید اجرا';
				conf.addEventListener('click', function () {
					if (tool.data && tool.data.highRisk) {
						var w =
							(tool.data && tool.data.warning) ||
							(i18n.riskConfirm || 'تغییر کد قالب/افزونه — ادامه؟');
						if (!window.confirm(w)) return;
					}
					conf.disabled = true;
					api('agent_wp_confirm_pending', { token: String(tool.data.confirmToken) }).then(function (json) {
						if (!json || !json.success) {
							conf.disabled = false;
							body.textContent =
								(json && json.data && json.data.message) || i18n.confirmFail || 'تأیید ناموفق';
							return;
						}
						head.querySelector('.wpa-as__tool-st').textContent = i18n.toolOk || 'موفق';
						head.querySelector('.wpa-as__tool-st').className = 'wpa-as__tool-st is-ok';
						body.textContent = (json.data && json.data.message) || i18n.toolOk || 'انجام شد';
						actions.innerHTML = '';
						loadSuggestions();
					});
				});
				var cancel = document.createElement('button');
				cancel.type = 'button';
				cancel.className = 'wpa-as__chip';
				cancel.textContent = i18n.cancel || 'لغو';
				cancel.addEventListener('click', function () {
					api('agent_wp_cancel_pending', { token: String(tool.data.confirmToken) }).then(function () {
						head.querySelector('.wpa-as__tool-st').textContent = i18n.cancelled || 'لغو شد';
						head.querySelector('.wpa-as__tool-st').className = 'wpa-as__tool-st is-fail';
						actions.innerHTML = '';
					});
				});
				actions.appendChild(conf);
				actions.appendChild(cancel);
				card.appendChild(actions);
			}
			messagesEl.appendChild(card);
		});
		chatPane.scrollTop = chatPane.scrollHeight;
	}

	var catLabel = {
		content: 'محتوا',
		design: 'دیزاین',
		seo: 'سئو',
		technical: 'فنی',
		growth: 'رشد',
		cleanup: 'پاکسازی',
		foresight: 'آینده‌نگری',
	};

	function doSuggestion(s) {
		setTab('chat');
		input.value =
			'این را انجام بده. فقط خلاصه بگو بعد از اجرا. [suggestion:' +
			s.id +
			']\n' +
			s.title;
		send();
	}

	function doPageSuggestion(s) {
		var hasModal = typeof window.agentWpEditorAssistOpen === 'function';
		if (s.editorAction && hasModal) {
			window.agentWpEditorAssistOpen(s.editorAction, {
				postId: s.postId || pageCtx.postId || 0,
				commentId: s.commentId || pageCtx.commentId || 0,
				prompt: s.promptHint || '',
			});
			return;
		}
		// ابزار ویرایشگر لود نشده — برو به صفحه ویرایش و بعد از لود مودال را باز کن
		if (s.editorAction && s.editUrl) {
			try {
				var u = new URL(s.editUrl, window.location.origin);
				u.searchParams.set('agent_wp_assist', s.editorAction);
				if (s.promptHint) u.searchParams.set('agent_wp_prompt', s.promptHint);
				window.location.assign(u.toString());
			} catch (e) {
				window.location.assign(s.editUrl);
			}
			return;
		}
		setTab('chat');
		input.value = s.chatPrompt || ('انجام بده: ' + s.title + (s.body ? '\n' + s.body : ''));
		send();
	}

	function askAbout(s, more) {
		setTab('chat');
		input.value = more
			? 'جزئیات بیشتر و قدم‌های دقیق برای این پیشنهاد: ' + s.title
			: 'درباره این پیشنهاد کوتاه راهنمایی بده: ' + s.title;
		input.focus();
	}

	function bindPageSuggestionActions(actions, s) {
		var doIt = document.createElement('button');
		doIt.type = 'button';
		doIt.className = 'wpa-as__chip wpa-as__chip--primary';
		doIt.textContent = s.editorAction
			? i18n.openEditor || 'باز کردن ابزار'
			: i18n.doIt || 'انجام بده';
		doIt.addEventListener('click', function () {
			doPageSuggestion(s);
		});
		var ask = document.createElement('button');
		ask.type = 'button';
		ask.className = 'wpa-as__chip';
		ask.textContent = i18n.talk || 'گفتگو';
		ask.addEventListener('click', function () {
			askAbout(s, false);
		});
		actions.appendChild(doIt);
		actions.appendChild(ask);
		if (s.editUrl && !s.editorAction) {
			var edit = document.createElement('a');
			edit.href = s.editUrl;
			edit.className = 'wpa-as__chip';
			edit.textContent = i18n.edit || 'ویرایش';
			actions.appendChild(edit);
		}
	}

	function renderPageSuggestionRow(s) {
		var card = document.createElement('article');
		card.className = 'wpa-as__card wpa-as__card--page';
		card.innerHTML =
			'<div class="wpa-as__card-cat">' +
			esc(catLabel[s.category] || s.category || 'صفحه') +
			'</div>' +
			'<h3 class="wpa-as__card-title">' +
			esc(s.title) +
			'</h3>' +
			(s.body ? '<p class="wpa-as__card-body">' + esc(s.body) + '</p>' : '') +
			'<div class="wpa-as__card-actions"></div>';
		bindPageSuggestionActions(card.querySelector('.wpa-as__card-actions'), s);
		return card;
	}

	function appendPageSuggestions(container) {
		if (!pageSuggestions || !pageSuggestions.length) return;
		var head = document.createElement('div');
		head.className = 'wpa-as__page-head';
		head.innerHTML =
			'<p class="wpa-as__page-label">' +
			esc(i18n.pageSuggestions || 'برای همین صفحه') +
			'</p>';
		container.appendChild(head);
		pageSuggestions.slice(0, 3).forEach(function (s) {
			container.appendChild(renderPageSuggestionRow(s));
		});
	}

	function bindSuggestionActions(actions, s) {
		if (filterStatus !== 'open') {
			var reopen = document.createElement('button');
			reopen.type = 'button';
			reopen.className = 'wpa-as__chip wpa-as__chip--primary';
			reopen.textContent = i18n.reopen || 'باز کردن دوباره';
			reopen.addEventListener('click', function () {
				api('agent_wp_assistant_suggestion_status', { id: String(s.id), status: 'open' }).then(
					function (json) {
						if (json && json.success) {
							setBadge(json.data.openCount);
							filterStatus = 'open';
							filterCategory = '';
							syncFilterChips();
							loadSuggestions();
						}
					}
				);
			});
			actions.appendChild(reopen);
			return;
		}

		var doIt = document.createElement('button');
		doIt.type = 'button';
		doIt.className = 'wpa-as__chip wpa-as__chip--primary';
		doIt.textContent = i18n.doIt || 'انجام بده';
		doIt.addEventListener('click', function () {
			doSuggestion(s);
		});
		var ask = document.createElement('button');
		ask.type = 'button';
		ask.className = 'wpa-as__chip';
		ask.textContent = i18n.talk || 'گفتگو';
		ask.addEventListener('click', function () {
			askAbout(s, false);
		});
		var more = document.createElement('button');
		more.type = 'button';
		more.className = 'wpa-as__chip';
		more.textContent = i18n.askMore || 'جزئیات';
		more.addEventListener('click', function () {
			if (expandedId === s.id) {
				expandedId = null;
				renderIdeas();
				return;
			}
			expandedId = s.id;
			renderIdeas();
		});
		var done = document.createElement('button');
		done.type = 'button';
		done.className = 'wpa-as__chip';
		done.textContent = i18n.done || 'انجام شد';
		done.addEventListener('click', function () {
			api('agent_wp_assistant_suggestion_status', { id: String(s.id), status: 'done' }).then(function (json) {
				if (json && json.success) {
					setBadge(json.data.openCount);
					loadSuggestions();
				}
			});
		});
		var snooze = document.createElement('button');
		snooze.type = 'button';
		snooze.className = 'wpa-as__chip';
		snooze.textContent = i18n.snooze || 'بعداً';
		snooze.addEventListener('click', function () {
			api('agent_wp_assistant_snooze', { id: String(s.id), days: '1' }).then(function (json) {
				if (json && json.success) {
					setBadge(json.data.openCount);
					loadSuggestions();
					toast(json.data.message || 'اسنوز شد');
				}
			});
		});
		actions.appendChild(doIt);
		actions.appendChild(ask);
		actions.appendChild(more);
		if (s.editUrl) {
			var edit = document.createElement('a');
			edit.href = s.editUrl;
			edit.rel = 'noopener noreferrer';
			edit.className = 'wpa-as__chip';
			edit.textContent = i18n.edit || 'ویرایش';
			edit.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				if (edit.getAttribute('data-wpa-opening') === '1') return;
				edit.setAttribute('data-wpa-opening', '1');
				window.setTimeout(function () {
					edit.removeAttribute('data-wpa-opening');
				}, 900);
				window.location.assign(s.editUrl);
			});
			actions.appendChild(edit);
		}
		actions.appendChild(snooze);
		actions.appendChild(done);
	}

	function renderSuggestionRow(s, compact) {
		var card = document.createElement('article');
		card.className = 'wpa-as__card' + (expandedId === s.id ? ' is-open' : '');
		if (s.status && s.status !== 'open') {
			card.classList.add('is-' + s.status);
		}
		var showBody = !compact || expandedId === s.id;
		card.innerHTML =
			'<div class="wpa-as__card-cat">' +
			esc(catLabel[s.category] || s.category) +
			'</div>' +
			'<h3 class="wpa-as__card-title">' +
			esc(s.title) +
			'</h3>' +
			(showBody && s.body
				? '<p class="wpa-as__card-body">' + esc(s.body) + '</p>'
				: '') +
			'<div class="wpa-as__card-actions"></div>';
		bindSuggestionActions(card.querySelector('.wpa-as__card-actions'), s);
		return card;
	}

	function renderBriefing() {
		ideasEl.innerHTML = '';
		appendPageSuggestions(ideasEl);

		if (!briefing || !briefing.total) {
			var calm = document.createElement('div');
			calm.className = 'wpa-as__brief';
			calm.innerHTML =
				'<p class="wpa-as__brief-head">' +
				esc((briefing && briefing.headline) || i18n.calmEmpty || 'همه‌چیز آرام است') +
				'</p>' +
				'<p class="wpa-as__brief-note">' +
				esc(i18n.calmEmpty || 'الان مورد فوری نیست.') +
				'</p>';
			ideasEl.appendChild(calm);
			return;
		}

		var wrap = document.createElement('div');
		wrap.className = 'wpa-as__brief';
		wrap.innerHTML =
			'<p class="wpa-as__brief-head">' +
			esc(briefing.headline || '') +
			'</p>' +
			'<p class="wpa-as__brief-note">' +
			esc(i18n.startHere || 'از اینجا شروع کن') +
			' — ' +
			esc('جزئیات را فقط اگر خواستی باز کن.') +
			'</p>';
		ideasEl.appendChild(wrap);

		(briefing.categories || []).forEach(function (c) {
			var row = document.createElement('button');
			row.type = 'button';
			row.className = 'wpa-as__cat';
			row.innerHTML =
				'<span class="wpa-as__cat-label">' +
				esc(c.label) +
				'</span>' +
				'<span class="wpa-as__cat-meta">' +
				esc(String(c.count)) +
				'</span>' +
				'<span class="wpa-as__cat-hint">' +
				esc((c.top && c.top.title) || '') +
				'</span>';
			row.addEventListener('click', function () {
				filterCategory = c.id;
				viewMode = 'list';
				loadSuggestions();
			});
			ideasEl.appendChild(row);
		});

		if (briefing.top && briefing.top.length) {
			var tip = document.createElement('p');
			tip.className = 'wpa-as__brief-tip';
			tip.textContent = 'اولویت من: ' + briefing.top[0].title;
			ideasEl.appendChild(tip);
		}
	}

	function renderIdeas() {
		if (viewMode === 'briefing' && filterStatus === 'open' && !filterCategory) {
			renderBriefing();
			return;
		}

		ideasEl.innerHTML = '';
		if (filterStatus === 'open' && !filterCategory) {
			appendPageSuggestions(ideasEl);
		}
		if (filterCategory && filterStatus === 'open') {
			var back = document.createElement('button');
			back.type = 'button';
			back.className = 'wpa-as__back';
			back.textContent = '← ' + (i18n.back || 'بازگشت به خلاصه');
			back.addEventListener('click', function () {
				filterCategory = '';
				expandedId = null;
				viewMode = 'briefing';
				loadSuggestions();
			});
			ideasEl.appendChild(back);
			var head = document.createElement('p');
			head.className = 'wpa-as__brief-head';
			head.textContent = catLabel[filterCategory] || filterCategory;
			ideasEl.appendChild(head);
		}

		if (!suggestions.length) {
			var empty = document.createElement('div');
			empty.className = 'wpa-as__empty';
			empty.textContent = i18n.empty || 'خالی';
			ideasEl.appendChild(empty);
			return;
		}

		var list = suggestions.slice(0, filterStatus === 'open' ? 5 : 8);
		list.forEach(function (s) {
			ideasEl.appendChild(renderSuggestionRow(s, true));
		});
		if (suggestions.length > list.length) {
			var moreNote = document.createElement('p');
			moreNote.className = 'wpa-as__brief-tip';
			moreNote.textContent =
				(i18n.more || 'بیشتر') +
				': در گفتگو بگو «بیشتر بگو» یا نام دسته را بپرس.';
			ideasEl.appendChild(moreNote);
		}
	}

	function syncFilterChips() {
		Array.prototype.forEach.call(root.querySelectorAll('[data-filter]'), function (btn) {
			btn.classList.toggle('is-active', btn.getAttribute('data-filter') === filterStatus);
		});
	}

	function loadSuggestions() {
		return api(
			'agent_wp_assistant_suggestions',
			pagePayload({
				status: filterStatus,
				q: searchQ,
				category: filterCategory,
			})
		).then(function (json) {
			if (!json || !json.success) return;
			suggestions = json.data.suggestions || [];
			pageSuggestions = json.data.pageSuggestions || [];
			briefing = json.data.briefing || null;
			if (json.data.pageContext) {
				pageCtx = json.data.pageContext;
				if (pageCtx.label) setScanLabel(pageCtx.label);
			}
			viewMode = json.data.mode === 'briefing' && !filterCategory ? 'briefing' : 'list';
			setBadge(json.data.openCount);
			renderIdeas();
		});
	}

	function loadHistory() {
		return api('agent_wp_assistant_history')
			.then(function (json) {
				messagesEl.innerHTML = '';
				if (!json || !json.success) {
					addMsg(i18n.welcome || 'سلام!', false);
					return;
				}
				var msgs = json.data.messages || [];
				if (!msgs.length) {
					addMsg((i18n.welcome || 'سلام!') + ' — «' + (cfg.siteName || '') + '»', false);
					historyLoaded = true;
					return;
				}
				msgs.forEach(function (m) {
					var mine = m.role === 'user';
					var route = m.meta && m.meta.route ? m.meta.route : null;
					addMsg(m.content || '', mine, { createdAt: m.createdAt, route: route });
					if (!mine && m.meta && m.meta.tools) {
						appendTools(m.meta.tools);
					}
				});
				historyLoaded = true;
			})
			.catch(function () {
				addMsg(i18n.historyFail || 'خطا', false);
			});
	}

	function setTab(name) {
		tab = name;
		Array.prototype.forEach.call(root.querySelectorAll('.wpa-as__tab'), function (btn) {
			btn.classList.toggle('is-active', btn.getAttribute('data-tab') === name);
		});
		chatPane.hidden = name !== 'chat';
		ideasPane.hidden = name !== 'ideas';
		composer.hidden = name !== 'chat';
		if (name === 'ideas') loadSuggestions();
		if (name === 'chat' && !historyLoaded) loadHistory();
	}

	function setOpen(next) {
		open = !!next;
		panel.hidden = !open;
		fab.classList.toggle('is-open', open);
		if (open) {
			if (tab === 'ideas') loadSuggestions();
			else if (!historyLoaded) loadHistory();
			if (tab === 'chat') input.focus();
		}
	}

	function toggle() {
		setOpen(!open);
	}

	function preferredImageModel() {
		try {
			return localStorage.getItem('agent_wp_preferred_image_model') || '';
		} catch (e) {
			return '';
		}
	}

	function send() {
		var text = (input.value || '').trim();
		if (!text || sending) return;
		sending = true;
		sendBtn.disabled = true;
		input.value = '';
		addMsg(text, true);
		showTyping(true);
		var payload = pagePayload({ content: text });
		var pref = preferredImageModel();
		if (pref) payload.preferred_image_model = pref;
		api('agent_wp_assistant_chat', payload)
			.then(function (json) {
				sending = false;
				sendBtn.disabled = false;
				showTyping(false);
				if (!json || !json.success) {
					addMsg((json && json.data && json.data.message) || i18n.sendFail || 'خطا', false);
					return;
				}
				var reply =
					(json.data.assistantMessage && json.data.assistantMessage.content) || '';
				var route =
					json.data.route ||
					(json.data.assistantMessage &&
						json.data.assistantMessage.meta &&
						json.data.assistantMessage.meta.route);
				if (reply) addMsg(reply, false, { route: route });
				appendTools(json.data.tools || []);
				if (json.data.suggestions) {
					suggestions = json.data.suggestions;
					setBadge(json.data.openCount);
				}
				if (tab === 'ideas') loadSuggestions();
				if (json.data.closedSuggestions) {
					addMsg(
						(i18n.autoDone || 'پیشنهاد مرتبط به‌عنوان انجام‌شده علامت خورد.') +
							' (' +
							json.data.closedSuggestions +
							')',
						false
					);
				}
			})
			.catch(function () {
				sending = false;
				sendBtn.disabled = false;
				showTyping(false);
				addMsg(i18n.sendFail || 'خطا', false);
			});
	}

	fab.addEventListener('click', function (e) {
		e.stopPropagation();
		toggle();
	});
	panel.addEventListener('click', function (e) {
		e.stopPropagation();
	});
	document.addEventListener('click', function () {
		if (open) setOpen(false);
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && open) setOpen(false);
		if (e.altKey && !e.ctrlKey && !e.metaKey && (e.key === 'a' || e.key === 'A' || e.code === 'KeyA')) {
			var tag = (e.target && e.target.tagName) || '';
			if (tag === 'INPUT' || tag === 'TEXTAREA' || (e.target && e.target.isContentEditable)) {
				return;
			}
			e.preventDefault();
			setOpen(!open);
		}
	});

	// نوار ادمین → باز کردن ویجت
	document.addEventListener('click', function (e) {
		var a = e.target && e.target.closest ? e.target.closest('#wp-admin-bar-agent-wp-assistant a') : null;
		if (!a) return;
		e.preventDefault();
		e.stopPropagation();
		setOpen(true);
		setTab('ideas');
	});

	Array.prototype.forEach.call(root.querySelectorAll('.wpa-as__tab'), function (btn) {
		btn.addEventListener('click', function () {
			setTab(btn.getAttribute('data-tab'));
		});
	});
	Array.prototype.forEach.call(root.querySelectorAll('[data-filter]'), function (btn) {
		btn.addEventListener('click', function () {
			filterStatus = btn.getAttribute('data-filter') || 'open';
			filterCategory = '';
			expandedId = null;
			viewMode = filterStatus === 'open' ? 'briefing' : 'list';
			syncFilterChips();
			loadSuggestions();
		});
	});
	sendBtn.addEventListener('click', send);
	input.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			send();
		}
	});
	if (scanBtn) {
		scanBtn.addEventListener('click', function () {
			scanBtn.disabled = true;
			api('agent_wp_assistant_scan')
				.then(function (json) {
					scanBtn.disabled = false;
					if (json && json.success) {
						filterStatus = 'open';
						filterCategory = '';
						expandedId = null;
						syncFilterChips();
						setBadge(json.data.openCount);
						if (json.data.lastScanLabel) setScanLabel(json.data.lastScanLabel);
						toast(
							(i18n.scanToast || 'بررسی تازه تمام شد') +
								' · ' +
								(json.data.openCount || 0) +
								' موضوع'
						);
						loadSuggestions();
					}
				})
				.catch(function () {
					scanBtn.disabled = false;
				});
		});
	}
	if (copyBtn) {
		copyBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			api('agent_wp_assistant_digest').then(function (json) {
				if (!json || !json.success) return;
				copyText(json.data.digest || '').then(function () {
					toast(i18n.copied || 'کپی شد');
				});
			});
		});
	}
	if (minBtn) {
		minBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			root.classList.toggle('is-minimized');
			try {
				localStorage.setItem('wpa_as_min', root.classList.contains('is-minimized') ? '1' : '0');
			} catch (err) {}
		});
	}
	if (cfg.notify && window.Notification && Notification.permission === 'default') {
		try {
			Notification.requestPermission();
		} catch (err3) {}
	}

	setBadge(cfg.openCount || 0);
})();
