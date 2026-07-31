/**
 * پاپ‌آپ ایجنت در ویرایش نوشته / دیدگاه
 */
(function () {
	'use strict';

	var cfg = window.agentWpEditorAssist || {};
	var i18n = cfg.i18n || {};
	if (!cfg.ajaxUrl) return;

	var modal = null;
	var currentAction = '';
	var currentPostId = 0;
	var currentCommentId = 0;
	var pendingToken = '';
	var lastTrigger = null;
	var runMode = 'run'; // run | confirm

	function esc(s) {
		return String(s || '')
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/"/g, '&quot;');
	}

	function preferredImageModel() {
		try {
			return localStorage.getItem('agent_wp_preferred_image_model') || '';
		} catch (e) {
			return '';
		}
	}

	function api(action, data) {
		var body = new window.FormData();
		body.append('action', action);
		body.append('nonce', cfg.nonce || '');
		if (data) {
			Object.keys(data).forEach(function (k) {
				if (data[k] !== undefined && data[k] !== null) body.append(k, data[k]);
			});
		}
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) {
			return r.json();
		});
	}

	function resetRunButton() {
		var run = document.getElementById('wpa-ed-run');
		if (!run) return;
		runMode = 'run';
		pendingToken = '';
		run.disabled = false;
		run.textContent = i18n.run || 'اجرا';
	}

	function ensureModal() {
		if (modal) return modal;
		modal = document.createElement('div');
		modal.className = 'wpa-ed-modal';
		modal.hidden = true;
		modal.innerHTML =
			'<div class="wpa-ed-modal__backdrop" data-wpa-ed-close></div>' +
			'<div class="wpa-ed-modal__panel" role="dialog" aria-modal="true" aria-labelledby="wpa-ed-modal-title">' +
			'<header class="wpa-ed-modal__head"><h2 id="wpa-ed-modal-title"></h2>' +
			'<button type="button" class="wpa-ed-modal__x" data-wpa-ed-close aria-label="' +
			esc(i18n.close || 'بستن') +
			'">×</button></header>' +
			'<label class="wpa-ed-modal__label" for="wpa-ed-prompt">' +
			esc(i18n.promptLabel || 'پرامپت') +
			'</label>' +
			'<textarea id="wpa-ed-prompt" class="wpa-ed-modal__input" rows="4" placeholder="' +
			esc(i18n.promptPh || '') +
			'"></textarea>' +
			'<p class="wpa-ed-modal__status" id="wpa-ed-status" hidden></p>' +
			'<div class="wpa-ed-modal__preview" id="wpa-ed-preview" hidden></div>' +
			'<footer class="wpa-ed-modal__foot">' +
			'<button type="button" class="button" data-wpa-ed-close id="wpa-ed-cancel">' +
			esc(i18n.cancel || 'انصراف') +
			'</button>' +
			'<button type="button" class="button button-primary" id="wpa-ed-run">' +
			esc(i18n.run || 'اجرا') +
			'</button>' +
			'</footer></div>';
		document.body.appendChild(modal);
		modal.addEventListener('click', function (e) {
			if (e.target && e.target.getAttribute('data-wpa-ed-close') !== null) closeModal(true);
		});
		document.getElementById('wpa-ed-run').addEventListener('click', onPrimaryClick);
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && modal && !modal.hidden) {
				e.preventDefault();
				closeModal(true);
			}
		});
		return modal;
	}

	function defaultPrompt(action) {
		var t = cfg.title || '';
		if (action === 'featured') {
			return t ? 'تصویر شاخص جذاب برای: ' + t : 'تصویر شاخص حرفه‌ای برای این مطلب';
		}
		if (action === 'tags') return 'برچسب‌های کوتاه و مرتبط با موضوع';
		if (action === 'excerpt') return 'خلاصه ۲–۳ جمله‌ای جذاب و واضح';
		if (action === 'content_block') return 'یک پاراگراف مقدمهٔ مفید و خوانا';
		if (action === 'comment_reply') return 'پاسخ مودبانه، کوتاه و حرفه‌ای';
		return '';
	}

	function actionTitle(action) {
		var map = {
			featured: i18n.featuredBtn || 'تصویر شاخص',
			tags: i18n.tagsBtn || 'برچسب',
			excerpt: i18n.excerptBtn || 'خلاصه',
			content_block: i18n.contentBtn || 'بلوک متن',
			comment_reply: i18n.replyBtn || 'پاسخ دیدگاه',
		};
		return map[action] || i18n.modalTitle || 'ایجنت';
	}

	function openModal(action, opts) {
		opts = opts || {};
		currentAction = action;
		currentPostId = opts.postId || cfg.postId || 0;
		currentCommentId = opts.commentId || cfg.commentId || 0;
		ensureModal();
		resetRunButton();
		document.getElementById('wpa-ed-modal-title').textContent = actionTitle(action);
		document.getElementById('wpa-ed-prompt').value = opts.prompt || defaultPrompt(action);
		var st = document.getElementById('wpa-ed-status');
		st.hidden = true;
		st.textContent = '';
		st.className = 'wpa-ed-modal__status';
		var prev = document.getElementById('wpa-ed-preview');
		prev.hidden = true;
		prev.innerHTML = '';
		modal.hidden = false;
		document.getElementById('wpa-ed-prompt').focus();
	}

	function closeModal(cancelPending) {
		if (cancelPending && pendingToken) {
			api('agent_wp_cancel_pending', { token: pendingToken });
			pendingToken = '';
		}
		if (modal) modal.hidden = true;
		resetRunButton();
		if (lastTrigger && typeof lastTrigger.focus === 'function') {
			try {
				lastTrigger.focus();
			} catch (e) {}
		}
		lastTrigger = null;
	}

	function setStatus(msg, isErr) {
		var st = document.getElementById('wpa-ed-status');
		st.hidden = false;
		st.textContent = msg || '';
		st.className = 'wpa-ed-modal__status' + (isErr ? ' is-err' : ' is-ok');
	}

	function applyFeaturedUi(data) {
		if (data.thumbHtml) {
			var wrap = document.getElementById('postimagediv');
			if (wrap) {
				var inside = wrap.querySelector('.inside');
				if (inside) {
					inside.innerHTML = data.thumbHtml;
				}
			}
		}
		if (data.attachmentId && window.wp && wp.data && wp.data.dispatch) {
			try {
				var ed = wp.data.dispatch('core/editor');
				if (ed && ed.editPost) {
					ed.editPost({ featured_media: parseInt(data.attachmentId, 10) });
				}
			} catch (e) {}
		}
	}

	function applyTagsUi(data) {
		var tags = data.tags || [];
		var termIds = data.termIds || [];
		if (!tags.length && !termIds.length) return;

		var tax = data.taxonomy || 'post_tag';
		var input = document.getElementById('new-tag-' + tax) || document.getElementById('new-tag-post_tag');
		if (input && tags.length) {
			input.value = tags.join(', ');
			var btn = input.parentNode && input.parentNode.querySelector('.tagadd');
			if (btn) btn.click();
		}

		if (termIds.length && window.wp && wp.data && wp.data.dispatch) {
			try {
				var ed = wp.data.dispatch('core/editor');
				if (ed && ed.editPost) {
					if (tax === 'post_tag') {
						ed.editPost({ tags: termIds });
					} else {
						ed.editPost({ categories: termIds });
					}
				}
			} catch (e) {}
		}
	}

	function applyExcerptUi(excerpt) {
		var el = document.getElementById('excerpt');
		if (el) el.value = excerpt;
		if (window.wp && wp.data && wp.data.dispatch) {
			try {
				wp.data.dispatch('core/editor').editPost({ excerpt: excerpt });
			} catch (e) {}
		}
	}

	function insertBlockInEditor(plainText) {
		if (!plainText || !window.wp || !wp.blocks || !wp.data) return false;
		try {
			var block = wp.blocks.createBlock('core/paragraph', { content: plainText });
			var insert = wp.data.dispatch('core/block-editor').insertBlocks;
			if (!insert) return false;
			insert(block, 0);
			return true;
		} catch (e) {
			return false;
		}
	}

	function applyReplyUi(reply) {
		var replyBox = document.getElementById('replycontent');
		if (replyBox) {
			replyBox.value = reply;
			replyBox.dispatchEvent(new Event('input', { bubbles: true }));
			return;
		}

		// صفحه ویرایش دیدگاه: هرگز #content (متن دیدگاه اصلی) را بازنویسی نکن
		var draft = document.getElementById('wpa-ed-reply-draft');
		if (!draft) {
			var anchor =
				document.querySelector('.wpa-ed-comment-btn') ||
				document.getElementById('namediv') ||
				document.getElementById('submitdiv');
			if (!anchor) return;
			var wrap = document.createElement('div');
			wrap.className = 'wpa-ed-reply-draft-wrap';
			wrap.innerHTML =
				'<label for="wpa-ed-reply-draft"><strong>' +
				esc(i18n.draftBox || 'پیش‌نویس پاسخ ایجنت') +
				'</strong></label>' +
				'<textarea id="wpa-ed-reply-draft" class="large-text" rows="5"></textarea>';
			anchor.parentNode.insertBefore(wrap, anchor.nextSibling);
			draft = document.getElementById('wpa-ed-reply-draft');
		}
		draft.value = reply;
		draft.focus();
		draft.select();
	}

	function maybeReload(data) {
		if (!data.reload) return;
		if (cfg.isBlock || cfg.forBlock) {
			// در گوتنبرگ ترجیح با درج کلاینتی است؛ رفرش اجباری نکن
			return;
		}
		var ask = i18n.reloadAsk || 'رفرش؟';
		if (window.confirm(ask)) {
			window.location.reload();
		}
	}

	function afterSuccess(data) {
		setStatus(data.message || i18n.ok || 'OK', false);
		if (currentAction === 'featured') applyFeaturedUi(data);
		if (currentAction === 'tags') applyTagsUi(data);
		if (currentAction === 'excerpt' && data.excerpt) applyExcerptUi(data.excerpt);
		if (currentAction === 'comment_reply' && data.reply) applyReplyUi(data.reply);
		if (currentAction === 'content_block' && data.plainText) {
			if (insertBlockInEditor(data.plainText)) {
				setStatus(i18n.ok || 'بلوک اضافه شد', false);
			} else {
				maybeReload(data);
			}
		} else {
			maybeReload(data);
		}
		if (data.url) {
			var prev = document.getElementById('wpa-ed-preview');
			prev.hidden = false;
			prev.innerHTML = '<img src="' + esc(data.url) + '" alt="" />';
		}
		resetRunButton();
	}

	function enterConfirmMode(token, label, warning) {
		pendingToken = token || '';
		runMode = 'confirm';
		var run = document.getElementById('wpa-ed-run');
		run.disabled = false;
		run.textContent = label || i18n.confirmNext || 'مدل دیگر';
		setStatus(warning || '', true);
	}

	function confirmNextModel(token, postId) {
		setStatus(i18n.loading || '…', false);
		var run = document.getElementById('wpa-ed-run');
		run.disabled = true;
		return api('agent_wp_confirm_pending', { token: token }).then(function (json) {
			if (!json || !json.success) {
				setStatus((json && json.data && json.data.message) || i18n.fail, true);
				resetRunButton();
				return;
			}
			var d = json.data || {};
			var nested = d.data || {};
			var mediaId =
				nested.attachmentId ||
				d.attachmentId ||
				(nested.attachments && nested.attachments[0] && nested.attachments[0].id);
			if (!mediaId) {
				if (nested.needsConfirm && nested.confirmToken) {
					enterConfirmMode(
						nested.confirmToken,
						nested.confirmLabel || i18n.confirmNext,
						nested.warning || d.message || ''
					);
					return;
				}
				setStatus(i18n.fail || 'ناموفق', true);
				resetRunButton();
				return;
			}
			pendingToken = '';
			return api('agent_wp_editor_assist', {
				assist: 'set_featured',
				post_id: String(postId),
				media_id: String(mediaId),
			}).then(function (j2) {
				if (!j2 || !j2.success) {
					setStatus((j2 && j2.data && j2.data.message) || i18n.fail, true);
					resetRunButton();
					return;
				}
				afterSuccess(j2.data || {});
			});
		});
	}

	function onPrimaryClick() {
		if (runMode === 'confirm' && pendingToken) {
			confirmNextModel(pendingToken, currentPostId);
			return;
		}
		runAssist();
	}

	function runAssist() {
		var prompt = (document.getElementById('wpa-ed-prompt').value || '').trim();
		if (!prompt) {
			setStatus(i18n.needPrompt || 'پرامپت', true);
			return;
		}
		var run = document.getElementById('wpa-ed-run');
		run.disabled = true;
		run.textContent = i18n.loading || '…';
		setStatus(i18n.loading || '…', false);

		var payload = {
			assist: currentAction,
			prompt: prompt,
			post_id: String(currentPostId || 0),
			comment_id: String(currentCommentId || 0),
			preferred_image_model: preferredImageModel(),
		};
		if (currentAction === 'content_block' && (cfg.isBlock || cfg.forBlock)) {
			payload.client_insert = '1';
		}

		api('agent_wp_editor_assist', payload)
			.then(function (json) {
				if (!json || !json.success) {
					resetRunButton();
					setStatus((json && json.data && json.data.message) || i18n.fail, true);
					return;
				}
				var data = json.data || {};
				if (data.needsConfirm && data.confirmToken) {
					enterConfirmMode(
						data.confirmToken,
						data.confirmLabel || i18n.confirmNext,
						data.warning || ''
					);
					return;
				}
				afterSuccess(data);
			})
			.catch(function () {
				resetRunButton();
				setStatus(i18n.fail || 'خطا', true);
			});
	}

	function onClick(e) {
		var btn = e.target && e.target.closest ? e.target.closest('[data-wpa-ed-action]') : null;
		if (!btn) return;
		e.preventDefault();
		lastTrigger = btn;
		openModal(btn.getAttribute('data-wpa-ed-action'), {
			postId: parseInt(btn.getAttribute('data-post-id') || cfg.postId || '0', 10),
			commentId: parseInt(btn.getAttribute('data-comment-id') || cfg.commentId || '0', 10),
		});
	}

	document.addEventListener('click', onClick);

	window.agentWpEditorAssistOpen = function (action, opts) {
		openModal(action, opts || {});
	};

	window.addEventListener('agent-wp-editor-assist', function (ev) {
		var d = (ev && ev.detail) || {};
		if (d.action) openModal(d.action, d);
	});

	// باز شدن خودکار بعد از هدایت از پیشنهاد صفحه
	if (cfg.autoOpen) {
		window.setTimeout(function () {
			openModal(cfg.autoOpen, {
				postId: cfg.postId,
				commentId: cfg.commentId,
				prompt: cfg.autoPrompt || '',
			});
			try {
				var u = new URL(window.location.href);
				u.searchParams.delete('agent_wp_assist');
				u.searchParams.delete('agent_wp_prompt');
				window.history.replaceState({}, '', u.toString());
			} catch (e) {}
		}, 400);
	}
})();
