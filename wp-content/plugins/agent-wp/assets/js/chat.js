(function () {
	'use strict';

	/**
	 * UI تلگرامی + persistence سرور.
	 * UX: کش پیام، لودینگ هنگام سوییچ، ساخت خوش‌بینانه، ویرایش در کامپوزر، منوی حذف.
	 */
	function ready(fn) {
		if (document.readyState !== 'loading') fn();
		else document.addEventListener('DOMContentLoaded', fn);
	}

	ready(function () {
		var app = document.getElementById('wpa-app');
		if (!app) return;

		var cfg = window.agentWpChat || {};
		var i18n = cfg.i18n || {};
		var modelsCache = [];
		var chatsCache = [];
		var messageCache = {};

		var input = document.getElementById('wpa-input');
		var sendBtn = document.getElementById('wpa-send');
		var stopBtn = document.getElementById('wpa-stop');
		var scroller = document.getElementById('wpa-messages-inner');
		var empty = document.getElementById('wpa-empty');
		var loading = document.getElementById('wpa-loading');
		var scrollDown = document.getElementById('wpa-scroll-down');
		var template = document.getElementById('wpa-bubble-template');
		var toolCardTemplate = document.getElementById('wpa-tool-card-template');
		var chatList = document.getElementById('wpa-chat-list');
		var backBtn = document.getElementById('wpa-back');
		var newChatBtn = document.getElementById('wpa-new-chat');
		var topTitle = document.getElementById('wpa-top-title');
		var menuBtn = document.getElementById('wpa-menu-btn');
		var moreBtn = document.getElementById('wpa-more-btn');
		var chatMenu = document.getElementById('wpa-chat-menu');
		var chatRenameBtn = document.getElementById('wpa-chat-rename');
		var chatExportBtn = document.getElementById('wpa-chat-export');
		var chatDeleteBtn = document.getElementById('wpa-chat-delete');
		var netBanner = document.getElementById('wpa-net-banner');
		var lastFailedSend = null;
		var lastDateLabel = '';

		var renameDialog = document.getElementById('wpa-rename-dialog');
		var renameInput = document.getElementById('wpa-rename-input');
		var renameSave = document.getElementById('wpa-rename-save');
		var renameCancel = document.getElementById('wpa-rename-cancel');
		var renameBackdrop = document.getElementById('wpa-rename-backdrop');

		var editBar = document.getElementById('wpa-edit-bar');
		var editPreview = document.getElementById('wpa-edit-preview');
		var editCancelBtn = document.getElementById('wpa-edit-cancel');
		var editingMessageId = 0;
		var editingOriginal = '';
		var replyBar = document.getElementById('wpa-reply-bar');
		var replyPreview = document.getElementById('wpa-reply-preview');
		var replyCancelBtn = document.getElementById('wpa-reply-cancel');
		var replyTo = null; // { id, preview }

		var msgMenu = document.getElementById('wpa-msg-menu');
		var msgCopyBtn = document.getElementById('wpa-msg-copy');
		var msgEditBtn = document.getElementById('wpa-msg-edit');
		var msgDeleteBtn = document.getElementById('wpa-msg-delete');
		var chatPinBtn = document.getElementById('wpa-chat-pin');
		var chatArchiveBtn = document.getElementById('wpa-chat-archive');
		var chatRegenBtn = document.getElementById('wpa-chat-regenerate');
		var msgMenuTargetId = 0;
		var msgMenuTargetText = '';
		var msgMenuIsUser = false;

		var plusBtn = document.getElementById('wpa-plus-btn');
		var plusMenu = document.getElementById('wpa-plus-menu');
		var attachBtn = document.getElementById('wpa-attach-btn');
		var attachImageBtn = document.getElementById('wpa-attach-image-btn');
		var fileInput = document.getElementById('wpa-file-input');
		var imageInput = document.getElementById('wpa-image-input');
		var attachBar = document.getElementById('wpa-attach-bar');
		var pendingAttachments = []; // { id, file, name, mime, previewUrl, isImage }

		var modelBtn = document.getElementById('wpa-model-btn');
		var modelMenu = document.getElementById('wpa-model-menu');
		var modelLabel = document.getElementById('wpa-model-label');

		var settings = document.getElementById('wpa-settings');
		var settingsPanel = document.getElementById('wpa-settings-panel');
		var settingsNav = document.getElementById('wpa-settings-nav');
		var settingsTitle = document.getElementById('wpa-settings-title');
		var settingsBackdrop = document.getElementById('wpa-settings-backdrop');
		var settingsPageMenu = document.getElementById('wpa-settings-page-menu');
		var settingsPageAddModel = document.getElementById('wpa-settings-page-add-model');
		var settingsPageActionLog = document.getElementById('wpa-settings-page-action-log');
		var settingsPageAssistant = document.getElementById('wpa-settings-page-assistant');
		var settingsPageApi = document.getElementById('wpa-settings-page-api');
		var actionLogEl = document.getElementById('wpa-action-log');
		var modelForm = document.getElementById('wpa-model-form');
		var savedModelsEl = document.getElementById('wpa-saved-models');
		var searchInput = document.getElementById('wpa-search');
		var msgSearchBtn = document.getElementById('wpa-msg-search-btn');
		var msgSearchBar = document.getElementById('wpa-msg-search');
		var msgSearchInput = document.getElementById('wpa-msg-search-input');
		var msgSearchCount = document.getElementById('wpa-msg-search-count');
		var msgSearchPrev = document.getElementById('wpa-msg-search-prev');
		var msgSearchNext = document.getElementById('wpa-msg-search-next');
		var msgSearchClose = document.getElementById('wpa-msg-search-close');
		var msgSearchHits = [];
		var msgSearchIndex = -1;

		var toolsView = document.getElementById('wpa-tools-view');
		var toolsList = document.getElementById('wpa-tools-list');
		var toolsHint = document.getElementById('wpa-tools-hint');
		var toolsBackBtn = document.getElementById('wpa-tools-back');
		var toolsLevel = 'categories'; // categories | models
		var toolsActiveCategory = '';
		var toolsCatalogRaw = cfg.toolsCatalog || {};
		var toolsCategoryUi = cfg.toolsCategoryUi || {};
		var siteTopic = (cfg.siteTopic || '').trim() || 'سایت';

		function normalizeToolsCatalog() {
			// PHP associative → object; also tolerate array of groups
			var out = {};
			if (Array.isArray(toolsCatalogRaw)) {
				toolsCatalogRaw.forEach(function (g, i) {
					var id = g.id || g.category || String(i);
					out[id] = { label: g.label || id, items: g.items || [] };
				});
				return out;
			}
			Object.keys(toolsCatalogRaw).forEach(function (id) {
				var g = toolsCatalogRaw[id] || {};
				out[id] = { label: g.label || id, items: g.items || [] };
			});
			return out;
		}
		var toolsCatalogMap = normalizeToolsCatalog();

		var SETTINGS_TITLES = {
			menu: 'تنظیمات',
			api: 'کلید API پیش‌فرض',
			assistant: 'دستیار زنده سایت',
			'add-model': 'افزودن مدل هوش مصنوعی',
			'action-log': 'تاریخچه اکشن‌ها',
		};

		var MODEL_STORAGE_KEY = 'agent_wp_selected_model_id';
		var IMAGE_MODEL_STORAGE_KEY = 'agent_wp_preferred_image_model';
		var VIDEO_MODEL_STORAGE_KEY = 'agent_wp_preferred_video_model';
		var AUDIO_MODEL_STORAGE_KEY = 'agent_wp_preferred_audio_model';
		var creditBalanceEl = document.getElementById('wpa-credit-balance');
		var creditDetailEl = document.getElementById('wpa-credit-detail');
		var creditLabelEl = document.getElementById('wpa-credit-label');
		var creditWarnEl = document.getElementById('wpa-credit-warn');
		var creditRefreshBtn = document.getElementById('wpa-credit-refresh');
		var creditCard = document.getElementById('wpa-credit-card');
		var creditLoading = false;
		var creditAbort = null;
		var creditSeq = 0;

		var stickToBottom = true;
		var activeChatId = 0;
		var selectedModelId = '';
		var preferredImageModel = '';
		var preferredVideoModel = '';
		var preferredAudioModel = '';
		var sending = false;
		var creatingChat = false;
		var openRequestSeq = 0;
		var mobileMq = window.matchMedia('(max-width: 900px)');
		var sendQueue = [];
		var activeSend = null; // { requestId, controller, typing, optimisticRow, chatId, text }
		var sendQueueEl = document.getElementById('wpa-send-queue');
		var drainingQueue = false;
		var speechRec = null;
		var speechListening = false;

		function getSpeechRecognition() {
			var Ctor = window.SpeechRecognition || window.webkitSpeechRecognition;
			return Ctor ? Ctor : null;
		}

		function stopSpeechInput() {
			speechListening = false;
			if (speechRec) {
				try {
					speechRec.onresult = null;
					speechRec.onerror = null;
					speechRec.onend = null;
					speechRec.stop();
				} catch (err) {}
				speechRec = null;
			}
			setSendMode();
		}

		function startSpeechInput() {
			var Ctor = getSpeechRecognition();
			if (!Ctor) {
				window.alert(
					'مرورگر از تبدیل گفتار به متن پشتیبانی نمی‌کند.\nChrome یا Edge را امتحان کنید (رایگان، بدون API جدا).'
				);
				return;
			}
			if (speechListening) {
				stopSpeechInput();
				return;
			}
			try {
				speechRec = new Ctor();
			} catch (err) {
				window.alert('راه‌اندازی میکروفون ناموفق بود.');
				return;
			}
			speechRec.lang = 'fa-IR';
			speechRec.continuous = true;
			speechRec.interimResults = true;
			speechRec.maxAlternatives = 1;

			var base = input.value;
			if (base && !/\s$/.test(base)) base += ' ';
			var committed = '';

			speechListening = true;
			setSendMode();

			speechRec.onresult = function (event) {
				var interim = '';
				var finals = '';
				for (var i = event.resultIndex; i < event.results.length; i++) {
					var piece = event.results[i][0].transcript || '';
					if (event.results[i].isFinal) finals += piece;
					else interim += piece;
				}
				if (finals) {
					committed += finals;
					if (committed && !/\s$/.test(committed)) committed += ' ';
				}
				input.value = base + committed + interim;
				autosize();
				setSendMode();
				scrollToEnd(false);
			};
			speechRec.onerror = function (event) {
				var code = event && event.error ? event.error : '';
				stopSpeechInput();
				if (code === 'not-allowed' || code === 'service-not-allowed') {
					window.alert('دسترسی میکروفون رد شد. در تنظیمات مرورگر اجازه دهید.');
				} else if (code === 'no-speech') {
					// بی‌صدا ماند — بدون آلارم آزاردهنده
				} else if (code && code !== 'aborted') {
					window.alert('خطای گفتار: ' + code);
				}
			};
			speechRec.onend = function () {
				if (!speechListening) return;
				// بعضی مرورگرها continuous را قطع می‌کنند — اگر هنوز listening بود دوباره شروع نکن؛ کاربر دوباره بزند
				speechListening = false;
				speechRec = null;
				setSendMode();
			};

			try {
				speechRec.start();
			} catch (err2) {
				stopSpeechInput();
				window.alert('شروع ضبط گفتار ناموفق بود.');
			}
		}

		function looksLikeSmallTalk(text) {
			var t = String(text || '')
				.replace(/\s+/g, ' ')
				.trim();
			if (!t) return true;
			if (
				/^(سلام|درود|هی|hi|hello|hey|صبح بخیر|ظهر بخیر|عصر بخیر|شب بخیر|خداحافظ|بای|ممنون|مرسی|خوبی\??|چطوری\??|چه خبر\??)([!.؟\s]*)$/i.test(
					t
				)
			) {
				return true;
			}
			if (t.length <= 16 && !/(بساز|تغییر|رنگ|حذف|اضافه|نصب|قالب|برگه|نوشته|css|سبز|قرمز|آبی|آپلود|فایل|تصویر)/i.test(t)) {
				return true;
			}
			return false;
		}

		function looksLikeSiteAction(text) {
			return /(بساز|تغییر|رنگ|حذف|اضافه|نصب|قالب|برگه|نوشته|css|سبز|قرمز|آبی|ویرایش|فعال|غیرفعال|آپلود|بنر|لوگو|منو)/i.test(
				String(text || '')
			);
		}

		function typingPlanFor(text) {
			if (looksLikeSmallTalk(text)) {
				return {
					initial: 'در حال نوشتن پاسخ…',
					later: null,
				};
			}
			if (looksLikeSiteAction(text)) {
				return {
					initial: 'در حال فکر کردن…',
					later: 'در حال اعمال تغییر روی وردپرس…',
				};
			}
			return {
				initial: 'در حال فکر کردن…',
				later: 'هنوز در حال آماده‌سازی پاسخ…',
			};
		}

		function renderAttachBar() {
			if (!attachBar) return;
			attachBar.innerHTML = '';
			if (!pendingAttachments.length) {
				attachBar.hidden = true;
				setSendMode();
				return;
			}
			attachBar.hidden = false;
			pendingAttachments.forEach(function (att) {
				var chip = document.createElement('div');
				chip.className = 'wpa-attach-chip';
				if (att.isImage && att.previewUrl) {
					chip.innerHTML =
						'<img class="wpa-attach-chip__thumb" alt="" /><span class="wpa-attach-chip__name"></span><button type="button" class="wpa-attach-chip__x" aria-label="حذف">×</button>';
					chip.querySelector('img').src = att.previewUrl;
				} else {
					chip.innerHTML =
						'<span class="wpa-attach-chip__icon">FILE</span><span class="wpa-attach-chip__name"></span><button type="button" class="wpa-attach-chip__x" aria-label="حذف">×</button>';
				}
				chip.querySelector('.wpa-attach-chip__name').textContent = att.name;
				chip.querySelector('.wpa-attach-chip__x').addEventListener('click', function () {
					removePendingAttachment(att.id);
				});
				attachBar.appendChild(chip);
			});
			setSendMode();
		}

		function removePendingAttachment(id) {
			pendingAttachments = pendingAttachments.filter(function (a) {
				if (a.id === id && a.previewUrl) {
					try {
						URL.revokeObjectURL(a.previewUrl);
					} catch (err) {}
				}
				return a.id !== id;
			});
			renderAttachBar();
		}

		function clearPendingAttachments() {
			pendingAttachments.forEach(function (a) {
				if (a.previewUrl) {
					try {
						URL.revokeObjectURL(a.previewUrl);
					} catch (err) {}
				}
			});
			pendingAttachments = [];
			renderAttachBar();
		}

		function addFilesToPending(fileList) {
			var files = Array.prototype.slice.call(fileList || []);
			var maxN = 5;
			var maxB = 8 * 1024 * 1024;
			files.forEach(function (file) {
				if (pendingAttachments.length >= maxN) {
					window.alert('حداکثر ۵ پیوست در هر پیام.');
					return;
				}
				if (!file || file.size > maxB) {
					window.alert('حجم هر فایل حداکثر ۸ مگابایت است: ' + (file && file.name ? file.name : ''));
					return;
				}
				var isImage = /^image\//i.test(file.type || '');
				pendingAttachments.push({
					id: 'att-' + Date.now() + '-' + Math.random().toString(36).slice(2, 6),
					file: file,
					name: file.name || 'file',
					mime: file.type || '',
					isImage: isImage,
					previewUrl: isImage ? URL.createObjectURL(file) : '',
				});
			});
			renderAttachBar();
		}

		function fillBubbleAttachments(node, attachments) {
			var box = node.querySelector('.wpa-bubble__atts');
			if (!box) return;
			box.innerHTML = '';
			if (!attachments || !attachments.length) {
				box.hidden = true;
				return;
			}
			box.hidden = false;
			attachments.forEach(function (att) {
				if (att.isImage && att.url) {
					var img = document.createElement('img');
					img.className = 'wpa-bubble__att-img';
					img.src = att.url;
					img.alt = att.name || '';
					img.loading = 'lazy';
					box.appendChild(img);
				} else if (att.isVideo && att.url) {
					var vid = document.createElement('video');
					vid.className = 'wpa-bubble__att-video';
					vid.src = att.url;
					vid.controls = true;
					vid.playsInline = true;
					box.appendChild(vid);
				} else if (att.isAudio && att.url) {
					var aud = document.createElement('audio');
					aud.className = 'wpa-bubble__att-audio';
					aud.src = att.url;
					aud.controls = true;
					box.appendChild(aud);
				} else if (att.url) {
					var a = document.createElement('a');
					a.className = 'wpa-bubble__att-file';
					a.href = att.url;
					a.target = '_blank';
					a.rel = 'noopener';
					a.textContent = att.name || 'فایل';
					box.appendChild(a);
				}
			});
		}

		function api(action, data, opts) {
			opts = opts || {};
			var body = new window.FormData();
			body.append('action', action);
			body.append('nonce', cfg.nonce || '');
			if (data) {
				Object.keys(data).forEach(function (key) {
					body.append(key, data[key]);
				});
			}
			var fetchOpts = {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			};
			if (opts.signal) fetchOpts.signal = opts.signal;
			return fetch(cfg.ajaxUrl, fetchOpts).then(function (res) {
				return res.json();
			});
		}

		function setView(view) {
			app.setAttribute('data-view', view);
			if (toolsView) toolsView.hidden = view !== 'tools';
			if (toolsBackBtn) toolsBackBtn.hidden = view !== 'tools';
			if (backBtn) backBtn.hidden = view === 'tools';
			if (view !== 'tools' && topTitle && activeChatId) {
				var chat = findChat(activeChatId);
				if (chat) topTitle.textContent = chat.title || 'دستیار';
			}
		}

		function isMobile() {
			return mobileMq.matches;
		}

		function isTempId(id) {
			return String(id).indexOf('tmp-') === 0;
		}

		function pad(n) {
			return n < 10 ? '0' + n : String(n);
		}

		function formatTime(date) {
			return pad(date.getHours()) + ':' + pad(date.getMinutes());
		}

		function formatMsgTime(value) {
			if (!value) return formatTime(new Date());
			var d = new Date(String(value).replace(' ', 'T'));
			if (isNaN(d.getTime())) return formatTime(new Date());
			return formatTime(d);
		}

		function nowMysql() {
			var d = new Date();
			return (
				d.getFullYear() +
				'-' +
				pad(d.getMonth() + 1) +
				'-' +
				pad(d.getDate()) +
				' ' +
				pad(d.getHours()) +
				':' +
				pad(d.getMinutes()) +
				':' +
				pad(d.getSeconds())
			);
		}

		function escapeHtml(str) {
			return String(str)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;');
		}

		function closePopovers() {
			if (plusMenu) plusMenu.hidden = true;
			if (plusBtn) plusBtn.setAttribute('aria-expanded', 'false');
			if (modelMenu) modelMenu.hidden = true;
			if (modelBtn) modelBtn.setAttribute('aria-expanded', 'false');
			hideChatMenu();
			hideMsgMenu();
		}

		function hideChatMenu() {
			if (chatMenu) chatMenu.hidden = true;
			if (moreBtn) moreBtn.setAttribute('aria-expanded', 'false');
		}

		function toggleChatMenu(e) {
			if (e) e.stopPropagation();
			if (!chatMenu || !activeChatId || isTempId(activeChatId)) return;
			var open = chatMenu.hidden;
			closePopovers();
			if (open) {
				chatMenu.hidden = false;
				moreBtn.setAttribute('aria-expanded', 'true');
			}
		}

		function hideMsgMenu() {
			if (msgMenu) msgMenu.hidden = true;
			msgMenuTargetId = 0;
			msgMenuTargetText = '';
			msgMenuIsUser = false;
		}

		function showMsgMenu(x, y, messageId, text, isUser) {
			if (!msgMenu) return;
			closePopovers();
			msgMenuTargetId = messageId;
			msgMenuTargetText = text || '';
			msgMenuIsUser = !!isUser;
			if (msgEditBtn) msgEditBtn.hidden = !msgMenuIsUser;
			msgMenu.hidden = false;
			var w = msgMenu.offsetWidth || 170;
			var h = msgMenu.offsetHeight || 90;
			var left = Math.min(Math.max(8, x), window.innerWidth - w - 8);
			var top = Math.min(Math.max(8, y), window.innerHeight - h - 8);
			msgMenu.style.left = left + 'px';
			msgMenu.style.top = top + 'px';
		}

		function setSettingsPage(page) {
			settingsPanel.setAttribute('data-page', page);
			settingsTitle.textContent = SETTINGS_TITLES[page] || SETTINGS_TITLES.menu;
			settingsPageMenu.hidden = page !== 'menu';
			settingsPageAddModel.hidden = page !== 'add-model';
			if (settingsPageActionLog) settingsPageActionLog.hidden = page !== 'action-log';
			if (settingsPageAssistant) settingsPageAssistant.hidden = page !== 'assistant';
			if (settingsPageApi) settingsPageApi.hidden = page !== 'api';
			settingsNav.setAttribute('aria-label', page === 'menu' ? 'بستن' : 'بازگشت');
			if (page === 'add-model') refreshModels();
			if (page === 'action-log') refreshActionLog();
			if (page === 'menu') refreshCredit();
			if (page === 'api') {
				var maskEl = document.getElementById('wpa-api-key-mask');
				var menuSub = document.getElementById('wpa-api-menu-sub');
				var keyEl = document.getElementById('wpa-api-key');
				if (keyEl) keyEl.value = '';
			}
		}

		function openSettings() {
			closePopovers();
			setSettingsPage('menu');
			settings.hidden = false;
		}

		function closeSettings() {
			settings.hidden = true;
			setSettingsPage('menu');
		}

		function activeItem() {
			return chatList.querySelector('.wpa-chat-item.is-active');
		}

		function openRenameDialog() {
			closePopovers();
			var item = activeItem();
			var current =
				item && item.querySelector('.wpa-chat-item__title')
					? item.querySelector('.wpa-chat-item__title').textContent
					: topTitle
						? topTitle.textContent
						: '';
			renameInput.value = current || '';
			renameDialog.hidden = false;
			setTimeout(function () {
				renameInput.focus();
				renameInput.select();
			}, 30);
		}

		function closeRenameDialog() {
			renameDialog.hidden = true;
		}

		function saveRename() {
			var name = renameInput.value.trim();
			if (!name || !activeChatId || isTempId(activeChatId)) {
				renameInput.focus();
				return;
			}
			api('agent_wp_rename_chat', {
				chat_id: String(activeChatId),
				title: name,
			}).then(function (json) {
				if (!json || !json.success) {
					window.alert((json && json.data && json.data.message) || 'خطا در ذخیره نام');
					return;
				}
				chatsCache = (json.data && json.data.chats) || chatsCache;
				renderChatList(chatsCache, activeChatId);
				if (topTitle) topTitle.textContent = name;
				closeRenameDialog();
			});
		}

		function deleteActiveChat() {
			hideChatMenu();
			if (!activeChatId || isTempId(activeChatId)) return;
			if (!window.confirm('این گفتگو حذف شود؟')) return;
			var deletingId = activeChatId;
			api('agent_wp_delete_chat', { chat_id: String(deletingId) }).then(function (json) {
				if (!json || !json.success) {
					window.alert((json && json.data && json.data.message) || 'حذف ناموفق');
					return;
				}
				delete messageCache[String(deletingId)];
				chatsCache = (json.data && json.data.chats) || [];
				if (!chatsCache.length) {
					activeChatId = 0;
					clearMessageRows();
					showEmpty();
					renderChatList([], 0);
					return;
				}
				renderChatList(chatsCache, chatsCache[0].id);
				openChat(chatsCache[0].id);
			});
		}

		function startEditMessage(messageId, text) {
			hideMsgMenu();
			cancelReplyTo();
			editingMessageId = messageId;
			editingOriginal = text || '';
			if (editBar) editBar.hidden = false;
			if (editPreview) {
				editPreview.textContent = editingOriginal;
				editPreview.title = editingOriginal;
			}
			input.value = editingOriginal;
			autosize();
			setSendMode();
			input.focus();
			try {
				input.setSelectionRange(input.value.length, input.value.length);
			} catch (err) {}
		}

		function cancelEditMessage() {
			editingMessageId = 0;
			editingOriginal = '';
			if (editBar) editBar.hidden = true;
			if (editPreview) editPreview.textContent = '';
			input.value = '';
			autosize();
			setSendMode();
		}

		function startReplyTo(messageId, text) {
			hideMsgMenu();
			if (editingMessageId) cancelEditMessage();
			var preview = String(text || '')
				.replace(/\s+/g, ' ')
				.trim()
				.slice(0, 140);
			replyTo = {
				id: messageId,
				preview: preview,
				full: String(text || ''),
			};
			if (replyBar) replyBar.hidden = false;
			if (replyPreview) {
				replyPreview.textContent = preview;
				replyPreview.title = String(text || '');
			}
			input.focus();
			setSendMode();
		}

		function cancelReplyTo() {
			replyTo = null;
			if (replyBar) replyBar.hidden = true;
			if (replyPreview) replyPreview.textContent = '';
		}

		function looksLikeConfirmAsk(text) {
			var t = String(text || '');
			return /نیاز به تأیید|آیا می‌خواهید ادامه|تأیید شما دارد|ادامه دهید\s*\؟|آیا ادامه|می‌خواهید ادامه/i.test(
				t
			);
		}

		function saveEditMessage() {
			var text = input.value.trim();
			if (!text || !editingMessageId) {
				input.focus();
				return;
			}
			if (text === editingOriginal.trim()) {
				cancelEditMessage();
				return;
			}
			var mid = editingMessageId;
			sending = true;
			api('agent_wp_edit_message', {
				message_id: String(mid),
				content: text,
			})
				.then(function (json) {
					sending = false;
					if (!json || !json.success) {
						window.alert((json && json.data && json.data.message) || 'ویرایش ناموفق');
						return;
					}
					var msg = json.data.message;
					var row = scroller.querySelector('.wpa-row[data-message-id="' + msg.id + '"]');
					if (row) {
						row.querySelector('.wpa-bubble__text').textContent = msg.content;
						var edited = row.querySelector('.wpa-bubble__edited');
						if (edited) edited.hidden = false;
					}
					patchCachedMessage(activeChatId, msg);
					if (json.data.chats) {
						chatsCache = json.data.chats;
						renderChatList(chatsCache, activeChatId);
					}
					cancelEditMessage();
					input.focus();
				})
				.catch(function () {
					sending = false;
					window.alert('خطای شبکه');
				});
		}

		function deleteTargetMessage() {
			var mid = msgMenuTargetId;
			hideMsgMenu();
			if (!mid) return;
			if (!window.confirm('این پیام حذف شود؟')) return;
			api('agent_wp_delete_message', { message_id: String(mid) }).then(function (json) {
				if (!json || !json.success) {
					window.alert((json && json.data && json.data.message) || 'حذف ناموفق');
					return;
				}
				var row = scroller.querySelector('.wpa-row[data-message-id="' + mid + '"]');
				if (row) row.remove();
				removeCachedMessage(activeChatId, mid);
				if (editingMessageId === mid) cancelEditMessage();
				if (!scroller.querySelector('.wpa-row')) showEmpty();
				if (json.data && json.data.chats) {
					chatsCache = json.data.chats;
					renderChatList(chatsCache, activeChatId);
				}
			});
		}

		function patchCachedMessage(chatId, msg) {
			var key = String(chatId);
			var list = messageCache[key];
			if (!list) return;
			for (var i = 0; i < list.length; i++) {
				if (Number(list[i].id) === Number(msg.id)) {
					list[i] = msg;
					break;
				}
			}
		}

		function removeCachedMessage(chatId, messageId) {
			var key = String(chatId);
			var list = messageCache[key];
			if (!list) return;
			messageCache[key] = list.filter(function (m) {
				return Number(m.id) !== Number(messageId);
			});
		}

		function appendCachedMessage(chatId, msg) {
			var key = String(chatId);
			if (!messageCache[key]) messageCache[key] = [];
			messageCache[key].push(msg);
		}

		function refreshActionLog() {
			if (!actionLogEl) return;
			actionLogEl.innerHTML = '<div class="wpa-action-log__empty">در حال بارگذاری…</div>';
			return api('agent_wp_list_logs')
				.then(function (json) {
					var logs = (json && json.success && json.data && json.data.logs) || [];
					renderActionLog(logs);
				})
				.catch(function () {
					actionLogEl.innerHTML = '<div class="wpa-action-log__empty">بارگذاری ناموفق</div>';
				});
		}

		function statusLabel(status) {
			var map = {
				done: 'موفق',
				failed: 'ناموفق',
				running: 'در حال اجرا',
				rolled_back: 'برگشت‌خورده',
			};
			return map[status] || status;
		}

		function renderActionLog(logs) {
			if (!actionLogEl) return;
			actionLogEl.innerHTML = '';
			if (!logs.length) {
				actionLogEl.innerHTML = '<div class="wpa-action-log__empty">هنوز اکشنی ثبت نشده.</div>';
				return;
			}
			logs.forEach(function (log) {
				var row = document.createElement('div');
				row.className = 'wpa-action-log__row';
				row.setAttribute('data-log-id', String(log.id));
				var canRollback = log.status === 'done';
				row.innerHTML =
					'<div class="wpa-action-log__main">' +
					'<div class="wpa-action-log__title">' +
					escapeHtml(log.tool_id || '') +
					' · ' +
					escapeHtml(log.action_name || '') +
					'</div>' +
					'<div class="wpa-action-log__meta">' +
					'<span class="wpa-action-log__status is-' +
					escapeHtml(log.status || '') +
					'">' +
					escapeHtml(statusLabel(log.status)) +
					'</span>' +
					'<span>' +
					escapeHtml(formatMsgTime(log.created_at)) +
					'</span></div></div>';
				if (canRollback) {
					var btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'wpa-action-log__rollback';
					btn.textContent = 'برگرداندن';
					btn.addEventListener('click', function () {
						if (!window.confirm('این اکشن برگردانده شود؟')) return;
						btn.disabled = true;
						api('agent_wp_rollback', { log_id: String(log.id) })
							.then(function (json) {
								if (!json || !json.success) {
									btn.disabled = false;
									window.alert((json && json.data && json.data.message) || 'برگشت ناموفق');
									return;
								}
								refreshActionLog();
							})
							.catch(function () {
								btn.disabled = false;
								window.alert('خطای شبکه');
							});
					});
					row.appendChild(btn);
				}
				actionLogEl.appendChild(row);
			});
		}

		function filterChatList(query) {
			query = String(query || '')
				.trim()
				.toLowerCase();
			Array.prototype.forEach.call(chatList.querySelectorAll('.wpa-chat-item'), function (item) {
				if (!query) {
					item.hidden = false;
					return;
				}
				var title = item.querySelector('.wpa-chat-item__title');
				var preview = item.querySelector('.wpa-chat-item__preview');
				var hay =
					((title && title.textContent) || '') + ' ' + ((preview && preview.textContent) || '');
				item.hidden = hay.toLowerCase().indexOf(query) === -1;
			});
		}

		function refreshCredit(force) {
			if (!creditBalanceEl) return Promise.resolve();

			// رفرش دستی درخواست قبلی را قطع می‌کند؛ وگرنه تا اتمام timeout دکمه بی‌اثر است
			if (creditLoading && !force) return Promise.resolve();
			if (creditAbort) {
				try {
					creditAbort.abort();
				} catch (e) {}
				creditAbort = null;
			}

			var mySeq = ++creditSeq;
			creditLoading = true;
			if (creditCard) creditCard.classList.add('is-loading');

			var ac =
				typeof window.AbortController !== 'undefined' ? new window.AbortController() : null;
			creditAbort = ac;
			var payload = force ? { force: '1' } : {};

			return api('agent_wp_gapgpt_account', payload, { signal: ac ? ac.signal : null })
				.then(function (json) {
					if (mySeq !== creditSeq) return;
					var account =
						(json && json.data && json.data.account) ||
						(json && json.success === false && json.data && json.data.account) ||
						null;
					var display = (account && account.display) || {};
					var chargeEl = document.getElementById('wpa-credit-charge');

					if (creditLabelEl && display.label) creditLabelEl.textContent = display.label;
					creditBalanceEl.textContent = display.balance || '—';
					if (creditDetailEl) {
						creditDetailEl.textContent =
							display.detail ||
							(json && json.data && json.data.message) ||
							'';
					}
					var fromStale = !!(account && account.fromLastError);
					var liveOk = !!(account && account.live);
					var low = !!display.low || display.balance === '؟';
					// عدد قدیمیِ خطای مدل را «اعتبار کم» وحشتناک نشان نده
					if (fromStale) {
						low = false;
					}
					if (creditWarnEl) {
						if (fromStale) {
							creditWarnEl.hidden = false;
							creditWarnEl.textContent =
								'این عدد از خطای قدیمی مدل است و بعد از شارژ ممکن است اشتباه باشد — بروزرسانی را بزنید یا پنل GapGPT را ببینید.';
						} else if (low && !liveOk && display.balance === '؟') {
							creditWarnEl.hidden = false;
							creditWarnEl.textContent =
								'موجودی زنده خوانده نشد — موجودی واقعی را در پنل GapGPT چک کنید.';
						} else if (low) {
							creditWarnEl.hidden = false;
							creditWarnEl.textContent =
								'اعتبار کم است — برای ادامه کار شارژ کنید.';
						} else {
							creditWarnEl.hidden = true;
						}
					}
					if (creditCard) {
						creditCard.classList.toggle('is-low', low && !fromStale);
						creditCard.classList.toggle('is-stale', fromStale || display.balance === '؟');
					}
					if (chargeEl && display.chargeUrl) {
						chargeEl.href = display.chargeUrl;
					}
				})
				.catch(function (err) {
					if (mySeq !== creditSeq) return;
					if (err && err.name === 'AbortError') return;
					creditBalanceEl.textContent = '؟';
					if (creditDetailEl) {
						creditDetailEl.textContent =
							i18n.creditFail || 'خواندن اعتبار ناموفق بود. دکمه بروزرسانی را بزنید.';
					}
					if (creditWarnEl) {
						creditWarnEl.hidden = false;
						creditWarnEl.textContent = 'موجودی در دسترس نیست — پنل GapGPT را بررسی کنید.';
					}
					if (creditCard) creditCard.classList.add('is-low');
				})
				.then(function () {
					if (mySeq !== creditSeq) return;
					creditLoading = false;
					creditAbort = null;
					if (creditCard) creditCard.classList.remove('is-loading');
				});
		}

		function rememberSelectedModel(id) {
			selectedModelId = id ? String(id) : '';
			try {
				if (selectedModelId) {
					window.localStorage.setItem(MODEL_STORAGE_KEY, selectedModelId);
				} else {
					window.localStorage.removeItem(MODEL_STORAGE_KEY);
				}
			} catch (e) {}
			refreshModelChipLabel();
		}

		function rememberPreferredImageModel(id) {
			preferredImageModel = id ? String(id) : '';
			try {
				if (preferredImageModel) {
					window.localStorage.setItem(IMAGE_MODEL_STORAGE_KEY, preferredImageModel);
				} else {
					window.localStorage.removeItem(IMAGE_MODEL_STORAGE_KEY);
				}
			} catch (e) {}
			refreshModelChipLabel();
		}

		function rememberPreferredVideoModel(id) {
			preferredVideoModel = id ? String(id) : '';
			try {
				if (preferredVideoModel) {
					window.localStorage.setItem(VIDEO_MODEL_STORAGE_KEY, preferredVideoModel);
				} else {
					window.localStorage.removeItem(VIDEO_MODEL_STORAGE_KEY);
				}
			} catch (e) {}
			refreshModelChipLabel();
		}

		function rememberPreferredAudioModel(id) {
			preferredAudioModel = id ? String(id) : '';
			try {
				if (preferredAudioModel) {
					window.localStorage.setItem(AUDIO_MODEL_STORAGE_KEY, preferredAudioModel);
				} else {
					window.localStorage.removeItem(AUDIO_MODEL_STORAGE_KEY);
				}
			} catch (e) {}
			refreshModelChipLabel();
		}

		function loadRememberedModelId() {
			try {
				return window.localStorage.getItem(MODEL_STORAGE_KEY) || '';
			} catch (e) {
				return '';
			}
		}

		function loadPreferredImageModel() {
			try {
				return window.localStorage.getItem(IMAGE_MODEL_STORAGE_KEY) || '';
			} catch (e) {
				return '';
			}
		}

		function loadPreferredVideoModel() {
			try {
				return window.localStorage.getItem(VIDEO_MODEL_STORAGE_KEY) || '';
			} catch (e) {
				return '';
			}
		}

		function loadPreferredAudioModel() {
			try {
				return window.localStorage.getItem(AUDIO_MODEL_STORAGE_KEY) || '';
			} catch (e) {
				return '';
			}
		}

		function catalogModelLabel(id) {
			var found = '';
			Object.keys(toolsCatalogMap).forEach(function (cid) {
				(toolsCatalogMap[cid].items || []).forEach(function (it) {
					if (String(it.id) === String(id)) found = it.label || it.id;
				});
			});
			return found || id || '';
		}

		function imageModelLabel(id) {
			return catalogModelLabel(id);
		}

		function refreshModelChipLabel() {
			if (!modelLabel) return;
			var base = 'خودکار';
			if (selectedModelId && selectedModelId !== 'auto') {
				base = selectedModelId;
				modelsCache.forEach(function (m) {
					if (String(m.id) === String(selectedModelId)) {
						base = m.name || selectedModelId;
					}
				});
			}
			var bits = [base];
			if (preferredImageModel) bits.push('تصویر: ' + catalogModelLabel(preferredImageModel));
			if (preferredVideoModel) bits.push('ویدیو: ' + catalogModelLabel(preferredVideoModel));
			if (preferredAudioModel) bits.push('صوت: ' + catalogModelLabel(preferredAudioModel));
			modelLabel.textContent = bits.join(' · ');
		}

		function refreshModels() {
			return api('agent_wp_list_models')
				.then(function (json) {
					modelsCache =
						json && json.success && json.data && Array.isArray(json.data.models)
							? json.data.models
							: [];
					renderSavedModels();
					return modelsCache;
				})
				.catch(function () {
					modelsCache = [];
					renderSavedModels();
					return modelsCache;
				});
		}

		function categoryOrder() {
			return ['coding', 'reasoning', 'chat', 'mini', 'image', 'video', 'audio', 'vision', 'other'];
		}

		function suggestedPrompt(category, modelLabel) {
			var topic = siteTopic;
			var modelBit = modelLabel ? ' با مدل «' + modelLabel + '»' : '';
			var map = {
				coding:
					'برای سایت «' +
					topic +
					'»' +
					modelBit +
					' یک بهبود عملی در کد/قالب/افزونه پیشنهاد بده و اگر تأیید کردم با ابزارها اعمال کن.',
				reasoning:
					'برای سایت «' +
					topic +
					'»' +
					modelBit +
					' یک مسئله مهم (فروش، ساختار، یا تجربه کاربری) را عمیق تحلیل کن و ۳ راهکار اولویت‌دار بده.',
				chat:
					'به‌عنوان مشاور سایت «' +
					topic +
					'»' +
					modelBit +
					'، ۳ ایده محتوا/کمپین این هفته پیشنهاد بده که با هویت سایت جور باشد.',
				mini:
					'برای سایت «' +
					topic +
					'»' +
					modelBit +
					' خلاصه و سریع بگو الان مهم‌ترین کار بعدی چیست و چرا.',
				image:
					'برای سایت «' +
					topic +
					'»' +
					modelBit +
					' تصاویر لازم را با generate_image بساز و در رسانه ذخیره کن. اگر لندینگ خواستم: اول هیرو و تصاویر بخش‌ها، بعد برگه لندینگ را با همان URLهای رسانه بساز.',
				video:
					'برای سایت «' +
					topic +
					'»' +
					modelBit +
					' یک ویدیو کوتاه واقعی با generate_video بساز، در رسانه ذخیره کن و لینک را نشان بده.',
				audio:
					'برای سایت «' +
					topic +
					'»' +
					modelBit +
					' یک نریشن کوتاه با generate_speech بساز، در رسانه ذخیره کن و پخش را نشان بده.',
				vision:
					'برای سایت «' +
					topic +
					'»' +
					modelBit +
					' بگو برای بررسی ظاهر صفحه چه چیزهایی را در اسکرین‌شات چک کنم؛ بعد اگر تصویر فرستادم تحلیل کن.',
				other:
					'برای سایت «' +
					topic +
					'»' +
					modelBit +
					' کمک کن هدف این گفتگو را مشخص کنیم و اولین قدم را پیشنهاد بده.',
			};
			return map[category] || map.other;
		}

		function openToolsView() {
			toolsLevel = 'categories';
			toolsActiveCategory = '';
			renderToolsCategories();
			if (topTitle) topTitle.textContent = 'ابزارها';
			var statusEl = document.getElementById('wpa-status');
			if (statusEl) statusEl.textContent = 'دسته و مدل را انتخاب کنید';
			setView('tools');
		}

		function closeToolsView() {
			if (isMobile()) setView('list');
			else setView('chat');
			var statusEl = document.getElementById('wpa-status');
			if (statusEl) statusEl.textContent = i18n.online || 'آنلاین';
			if (topTitle) {
				var chat = findChat(activeChatId);
				topTitle.textContent = (chat && chat.title) || 'دستیار';
			}
		}

		function toolsBack() {
			if (toolsLevel === 'models') {
				toolsLevel = 'categories';
				toolsActiveCategory = '';
				renderToolsCategories();
				if (topTitle) topTitle.textContent = 'ابزارها';
				return;
			}
			closeToolsView();
		}

		function renderToolsCategories() {
			if (!toolsList) return;
			toolsList.innerHTML = '';
			if (toolsHint) {
				toolsHint.textContent = 'یک دسته انتخاب کنید تا مدل‌های مرتبط را ببینید.';
			}
			var order = categoryOrder();
			var rendered = {};
			order.forEach(function (catId) {
				var group = toolsCatalogMap[catId];
				if (!group || !group.items || !group.items.length) return;
				rendered[catId] = true;
				var ui = toolsCategoryUi[catId] || {};
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'wpa-tools-row';
				btn.setAttribute('role', 'listitem');
				btn.innerHTML =
					'<span class="wpa-tools-row__icon" style="--ico:' +
					escapeHtml(ui.color || '#8a939b') +
					'">' +
					escapeHtml(ui.emoji || '⋯') +
					'</span><span class="wpa-tools-row__text"><span class="wpa-tools-row__title">' +
					escapeHtml(ui.label || group.label || catId) +
					'</span><span class="wpa-tools-row__sub">' +
					escapeHtml(ui.hint || '') +
					' · ' +
					group.items.length +
					' مدل</span></span><span class="wpa-tools-row__chev" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg></span>';
				btn.addEventListener('click', function () {
					openToolsModels(catId);
				});
				toolsList.appendChild(btn);
			});

			Object.keys(toolsCatalogMap).forEach(function (catId) {
				if (rendered[catId]) return;
				var group = toolsCatalogMap[catId];
				if (!group || !group.items || !group.items.length) return;
				var ui = toolsCategoryUi[catId] || {};
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'wpa-tools-row';
				btn.setAttribute('role', 'listitem');
				btn.innerHTML =
					'<span class="wpa-tools-row__icon" style="--ico:' +
					escapeHtml(ui.color || '#8a939b') +
					'">' +
					escapeHtml(ui.emoji || '⋯') +
					'</span><span class="wpa-tools-row__text"><span class="wpa-tools-row__title">' +
					escapeHtml(group.label || ui.label || catId) +
					'</span><span class="wpa-tools-row__sub">' +
					escapeHtml(ui.hint || '') +
					' · ' +
					group.items.length +
					' مدل</span></span><span class="wpa-tools-row__chev" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg></span>';
				btn.addEventListener('click', function () {
					openToolsModels(catId);
				});
				toolsList.appendChild(btn);
			});
		}

		function getCatalogItems(catId) {
			var group = toolsCatalogMap[catId];
			return group && Array.isArray(group.items) ? group.items : [];
		}

		function openToolsModels(catId) {
			toolsLevel = 'models';
			toolsActiveCategory = catId;
			var ui = toolsCategoryUi[catId] || {};
			var group = toolsCatalogMap[catId] || {};
			if (topTitle) topTitle.textContent = ui.label || group.label || catId;
			if (toolsHint) {
				toolsHint.textContent = 'مدل را انتخاب کنید تا گفتگوی جدید با متن پیشنهادی باز شود.';
			}
			if (!toolsList) return;
			toolsList.innerHTML = '';
			var items = getCatalogItems(catId);
			if (!items.length) {
				var emptyEl = document.createElement('div');
				emptyEl.className = 'wpa-tools-empty';
				emptyEl.textContent = 'مدلی در این دسته نیست.';
				toolsList.appendChild(emptyEl);
				return;
			}
			items.forEach(function (item) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'wpa-tools-row';
				btn.setAttribute('role', 'listitem');
				var sub = (item.family ? item.family + ' · ' : '') + (item.id || '');
				btn.innerHTML =
					'<span class="wpa-tools-row__icon" style="--ico:' +
					escapeHtml(ui.color || '#3390ec') +
					'">AI</span><span class="wpa-tools-row__text"><span class="wpa-tools-row__title">' +
					escapeHtml(item.label || item.id) +
					'</span><span class="wpa-tools-row__sub">' +
					escapeHtml(sub) +
					'</span></span><span class="wpa-tools-row__chev" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg></span>';
				btn.addEventListener('click', function () {
					startToolChat(catId, item);
				});
				toolsList.appendChild(btn);
			});
		}

		function startToolChat(catId, item) {
			var ui = toolsCategoryUi[catId] || {};
			var group = toolsCatalogMap[catId] || {};
			var catLabel = ui.label || group.label || catId;
			var modelLabelText = item.label || item.id || '';
			var title = catLabel + ' · ' + modelLabelText;
			var prompt = suggestedPrompt(catId, modelLabelText);
			if (catId === 'image') {
				createNewChat({
					title: title,
					modelId: 'auto',
					imageModelId: item.id || '',
					prompt: prompt,
				});
				return;
			}
			if (catId === 'video') {
				createNewChat({
					title: title,
					modelId: 'auto',
					videoModelId: item.id || '',
					prompt: prompt,
				});
				return;
			}
			if (catId === 'audio') {
				createNewChat({
					title: title,
					modelId: 'auto',
					audioModelId: item.id || '',
					prompt: prompt,
				});
				return;
			}
			createNewChat({
				title: title,
				modelId: item.id || '',
				prompt: prompt,
			});
		}

		function groupModelsByCategory(models) {
			var groups = {};
			var order = categoryOrder();
			order.forEach(function (id) {
				groups[id] = [];
			});
			(models || []).forEach(function (m) {
				var cat = m.category || 'other';
				if (!groups[cat]) {
					groups[cat] = [];
					order.push(cat);
				}
				groups[cat].push(m);
			});
			return { groups: groups, order: order };
		}

		function renderSavedModels() {
			savedModelsEl.innerHTML = '';
			var defaultId = '';
			var packed = groupModelsByCategory(modelsCache);
			packed.order.forEach(function (catId) {
				var list = packed.groups[catId] || [];
				if (!list.length) return;
				var head = document.createElement('li');
				head.className = 'wpa-saved-models__group';
				head.textContent =
					(list[0] && list[0].categoryLabel) || catId;
				savedModelsEl.appendChild(head);

				list.forEach(function (m) {
					if (m.isDefault) defaultId = String(m.id);
					var li = document.createElement('li');
					li.className = 'wpa-saved-models__item';
					var info = document.createElement('div');
					info.className = 'wpa-saved-models__info';
					info.innerHTML =
						'<strong>' +
						escapeHtml(m.name || m.id) +
						(m.isDefault ? ' <em class="wpa-saved-models__default">پیش‌فرض</em>' : '') +
						'</strong><span>' +
						escapeHtml(m.categoryLabel || m.family || 'GapGPT') +
						' · ' +
						escapeHtml(m.family || '') +
						' · ' +
						escapeHtml(m.apiKeyMask || '') +
						'</span>';
					var actions = document.createElement('div');
					actions.className = 'wpa-saved-models__actions';
					if (!m.isDefault) {
						var defBtn = document.createElement('button');
						defBtn.type = 'button';
						defBtn.className = 'wpa-saved-models__btn';
						defBtn.textContent = 'پیش‌فرض';
						defBtn.addEventListener('click', function () {
							api('agent_wp_set_default_model', { id: String(m.id) }).then(function (json) {
								if (!json || !json.success) {
									window.alert((json && json.data && json.data.message) || 'خطا');
									return;
								}
								modelsCache = (json.data && json.data.models) || [];
								rememberSelectedModel(m.id);
								renderSavedModels();
							});
						});
						actions.appendChild(defBtn);
					}
					var delBtn = document.createElement('button');
					delBtn.type = 'button';
					delBtn.className = 'wpa-saved-models__btn wpa-saved-models__btn--danger';
					delBtn.textContent = 'حذف';
					delBtn.addEventListener('click', function () {
						if (!window.confirm('این مدل حذف شود؟')) return;
						api('agent_wp_delete_model', { id: String(m.id) }).then(function (json) {
							if (!json || !json.success) {
								window.alert((json && json.data && json.data.message) || 'حذف ناموفق');
								return;
							}
							modelsCache = (json.data && json.data.models) || [];
							if (String(selectedModelId) === String(m.id)) {
								pickDefaultModel();
							}
							renderSavedModels();
						});
					});
					actions.appendChild(delBtn);
					li.appendChild(info);
					li.appendChild(actions);
					savedModelsEl.appendChild(li);
				});
			});
			if (!selectedModelId && defaultId) {
				rememberSelectedModel(defaultId);
			}
			pickDefaultModel(false);
			renderModelMenu(modelsCache);
		}

		function pickDefaultModel(forceLabel) {
			if (!modelsCache.length) {
				rememberSelectedModel('auto');
				return;
			}
			if (String(selectedModelId) === 'auto') {
				if (forceLabel !== false) {
					refreshModelChipLabel();
				}
				return;
			}
			var chosen = null;
			modelsCache.forEach(function (m) {
				if (String(m.id) === String(selectedModelId)) chosen = m;
			});
			if (!chosen) {
				modelsCache.forEach(function (m) {
					if (m.isDefault) chosen = m;
				});
			}
			if (!chosen) {
				rememberSelectedModel('auto');
				return;
			}
			rememberSelectedModel(chosen.id);
		}

		function renderModelMenu(models) {
			modelMenu.innerHTML = '';

			var autoBtn = document.createElement('button');
			autoBtn.type = 'button';
			autoBtn.className =
				'wpa-model-menu__item wpa-model-menu__item--auto' +
				(String(selectedModelId) === 'auto' || !selectedModelId ? ' is-active' : '');
			autoBtn.innerHTML =
				'<strong>خودکار</strong><span>بهترین مدل بر اساس کار شما</span>';
			autoBtn.addEventListener('click', function () {
				rememberSelectedModel('auto');
				closePopovers();
				renderModelMenu(modelsCache);
			});
			modelMenu.appendChild(autoBtn);

			if (!models.length) {
				var hint = document.createElement('button');
				hint.type = 'button';
				hint.className = 'wpa-model-menu__item is-muted';
				hint.disabled = true;
				hint.textContent = i18n.addFromSettings || 'مدل‌ها را از تنظیمات اضافه کنید';
				modelMenu.appendChild(hint);
				return;
			}

			var packed = groupModelsByCategory(models);
			packed.order.forEach(function (catId) {
				var list = packed.groups[catId] || [];
				if (!list.length) return;
				var head = document.createElement('div');
				head.className = 'wpa-model-menu__group';
				head.textContent = (list[0] && list[0].categoryLabel) || catId;
				modelMenu.appendChild(head);
				list.forEach(function (m) {
					var id = String(m.id);
					var btn = document.createElement('button');
					btn.type = 'button';
					btn.className =
						'wpa-model-menu__item' + (String(selectedModelId) === id ? ' is-active' : '');
					btn.innerHTML =
						'<strong>' +
						escapeHtml(m.name) +
						'</strong><span>' +
						escapeHtml(m.family || '') +
						'</span>';
					btn.addEventListener('click', function () {
						var cat = m.category || '';
						if (cat === 'image') {
							rememberPreferredImageModel(m.name || id);
							rememberSelectedModel('auto');
						} else if (cat === 'video') {
							rememberPreferredVideoModel(m.name || id);
							rememberSelectedModel('auto');
						} else if (cat === 'audio') {
							rememberPreferredAudioModel(m.name || id);
							rememberSelectedModel('auto');
						} else {
							rememberSelectedModel(id);
						}
						closePopovers();
						renderModelMenu(modelsCache);
					});
					modelMenu.appendChild(btn);
				});
			});
		}

		function autosize() {
			input.style.height = 'auto';
			input.style.height = Math.min(input.scrollHeight, 160) + 'px';
		}

		function setSendMode() {
			if (!sendBtn) return;

			// توقف جدا از ارسال: وقتی درخواست جاری است همیشه دیده می‌شود
			if (stopBtn) {
				stopBtn.hidden = !sending;
			}

			if (speechListening) {
				sendBtn.dataset.mode = 'mic';
				sendBtn.setAttribute('aria-label', 'توقف تبدیل گفتار');
				sendBtn.classList.add('is-listening');
				return;
			}
			sendBtn.classList.remove('is-listening');
			if (editingMessageId) {
				sendBtn.dataset.mode = 'check';
				sendBtn.setAttribute('aria-label', 'ذخیره ویرایش');
				return;
			}
			var hasText = input.value.trim().length > 0;
			var hasAtt = pendingAttachments.length > 0;
			sendBtn.dataset.mode = hasText || hasAtt ? 'send' : 'mic';
			sendBtn.setAttribute(
				'aria-label',
				sendBtn.dataset.mode === 'send'
					? sending
						? 'ارسال به صف'
						: 'ارسال'
					: 'گفتار به متن'
			);
		}

		function renderSendQueue() {
			if (!sendQueueEl) return;
			sendQueueEl.innerHTML = '';
			if (!sendQueue.length) {
				sendQueueEl.hidden = true;
				return;
			}
			sendQueueEl.hidden = false;
			var head = document.createElement('div');
			head.className = 'wpa-send-queue__head';
			head.textContent = 'صف ارسال (' + sendQueue.length + ') — برای اجرای فوری کلیک کنید';
			sendQueueEl.appendChild(head);
			sendQueue.forEach(function (item) {
				var row = document.createElement('div');
				row.className = 'wpa-send-queue__item-wrap';
				row.style.display = 'flex';
				row.style.gap = '4px';
				row.style.alignItems = 'stretch';

				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'wpa-send-queue__item';
				btn.innerHTML =
					'<span class="wpa-send-queue__text">' +
					escapeHtml(item.text) +
					'</span><span class="wpa-send-queue__hint">الان</span>';
				btn.addEventListener('click', function () {
					promoteQueueItem(item.id);
				});

				var rm = document.createElement('button');
				rm.type = 'button';
				rm.className = 'wpa-send-queue__remove';
				rm.setAttribute('aria-label', 'حذف از صف');
				rm.textContent = '×';
				rm.addEventListener('click', function (e) {
					e.stopPropagation();
					sendQueue = sendQueue.filter(function (q) {
						return q.id !== item.id;
					});
					renderSendQueue();
				});

				row.appendChild(btn);
				row.appendChild(rm);
				sendQueueEl.appendChild(row);
			});
		}

		function enqueueSend(text, replyMeta, files) {
			sendQueue.push({
				id: 'q-' + Date.now() + '-' + Math.random().toString(36).slice(2, 6),
				text: text,
				replyTo: replyMeta || null,
				files: files || [],
				chatId: activeChatId,
			});
			renderSendQueue();
		}

		function drainSendQueue() {
			if (sending || drainingQueue || !sendQueue.length) return;
			var idx = -1;
			for (var i = 0; i < sendQueue.length; i++) {
				if (String(sendQueue[i].chatId) === String(activeChatId)) {
					idx = i;
					break;
				}
			}
			if (idx < 0) return;
			drainingQueue = true;
			var next = sendQueue[idx];
			if (next.replyTo) {
				replyTo = next.replyTo;
			}
			// فقط اگر ارسال واقعاً شروع شد از صف بردار — وگرنه پیام گم می‌شود
			var started = sendPayload(next.text, next.files || [], { fromQueue: true });
			if (started) {
				sendQueue.splice(idx, 1);
				renderSendQueue();
			}
			drainingQueue = false;
		}

		function promoteQueueItem(queueId) {
			var idx = -1;
			var item = null;
			sendQueue.forEach(function (q, i) {
				if (q.id === queueId) {
					idx = i;
					item = q;
				}
			});
			if (!item) return;
			if (
				!window.confirm(
					'درخواست فعلی متوقف شود و این پیام از صف همین الان اجرا شود؟\n\nاکشن‌های نیمه‌کاره ممکن است روی سایت مانده باشند.'
				)
			) {
				return;
			}
			sendQueue.splice(idx, 1);
			renderSendQueue();
			stopActiveSend(true).then(function () {
				if (item.replyTo) replyTo = item.replyTo;
				var ok = sendPayload(item.text, item.files || [], { fromQueue: true });
				if (!ok) {
					// برگردان به صف اگر شروع نشد
					sendQueue.unshift(item);
					renderSendQueue();
				}
			});
		}

		function stopActiveSend(skipDrain) {
			return new Promise(function (resolve) {
				if (!sending || !activeSend) {
					resolve();
					return;
				}
				var snap = activeSend;
				snap.aborted = true;
				if (snap.controller) {
					try {
						snap.controller.abort();
					} catch (err) {}
				}
				if (snap.requestId) {
					api('agent_wp_cancel_request', { request_id: String(snap.requestId) }).catch(function () {});
				}
				if (snap.statusTimer) window.clearTimeout(snap.statusTimer);
				if (snap.typing && snap.typing.parentNode) snap.typing.remove();
				if (snap.optimisticRow) {
					snap.optimisticRow.classList.remove('is-pending');
				}
				var cancelMsg = {
					id: 'local-cancel-' + Date.now(),
					role: 'assistant',
					content: 'درخواست متوقف شد.',
					createdAt: nowMysql(),
					updatedAt: nowMysql(),
					meta: { cancelled: true },
				};
				if (String(activeChatId) === String(snap.chatId)) {
					appendMessage(cancelMsg, false);
				}
				appendCachedMessage(snap.chatId, cancelMsg);

				sending = false;
				activeSend = null;
				setSendMode();
				if (!skipDrain) {
					window.setTimeout(function () {
						drainSendQueue();
					}, 50);
				}
				resolve();
			});
		}

		function nearBottom() {
			return scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 80;
		}

		function updateScrollDown() {
			if (nearBottom()) {
				scrollDown.hidden = true;
				stickToBottom = true;
			} else {
				scrollDown.hidden = false;
				stickToBottom = false;
			}
		}

		function scrollToEnd(force) {
			if (!force && !stickToBottom) return;
			scroller.scrollTop = scroller.scrollHeight;
			scrollDown.hidden = true;
			stickToBottom = true;
		}

		function hideEmpty() {
			if (empty) empty.classList.add('is-hidden');
		}

		function showEmpty() {
			hideLoading();
			if (empty) empty.classList.remove('is-hidden');
		}

		function showLoading() {
			hideEmpty();
			if (loading) loading.hidden = false;
		}

		function hideLoading() {
			if (loading) loading.hidden = true;
		}

		function updateSidebarPreview(text) {
			var item = activeItem();
			if (!item) return;
			var preview = item.querySelector('.wpa-chat-item__preview');
			var timeLabel = item.querySelector('.wpa-chat-item__time');
			if (preview) preview.textContent = text;
			if (timeLabel) timeLabel.textContent = formatTime(new Date());
		}

		function clearMessageRows() {
			Array.prototype.forEach.call(scroller.querySelectorAll('.wpa-row'), function (row) {
				row.remove();
			});
		}

		function toolLabel(idGuess) {
			var labels = {
				create_page: 'ساخت برگه',
				create_post: 'ساخت نوشته',
				update_site_title: 'عنوان سایت',
				update_content: 'ویرایش محتوا',
				trash_content: 'زباله‌دان',
				get_content: 'خواندن محتوا',
				list_posts: 'لیست نوشته‌ها',
				list_pages: 'لیست برگه‌ها',
				site_info: 'اطلاعات سایت',
				update_option: 'تنظیمات',
				list_files: 'لیست فایل',
				read_file: 'خواندن فایل',
				write_file: 'نوشتن فایل',
				custom_css: 'CSS سفارشی',
				media: 'رسانه',
				generate_image: 'ساخت تصویر',
				generate_video: 'ساخت ویدیو',
				generate_speech: 'ساخت گفتار',
				transcribe_audio: 'رونویسی صوت',
				terms: 'دسته/برچسب',
				menus: 'منو',
				search_replace: 'جستجو/جایگزینی',
				plugins_themes: 'افزونه/قالب',
				woo_products: 'محصولات',
				discover: 'کشف سایت',
				wp_content: 'محتوای عمومی',
				post_meta: 'متا',
				rest: 'REST',
				ping: 'پینگ',
			};
			return labels[idGuess] || idGuess || 'ابزار';
		}

		function attachToolLink(actions, url, label, openBlank) {
			if (!actions || !url) return;
			var a = document.createElement('a');
			a.href = url;
			a.className = 'wpa-tool-card__link';
			a.textContent = label;
			a.rel = 'noopener noreferrer';
			if (openBlank) a.target = '_blank';
			a.addEventListener('click', function (e) {
				// جلوگیری از باز شدن دوبل (target=_blank + هندلر مرورگر تعبیه‌شده)
				e.preventDefault();
				e.stopPropagation();
				if (a.getAttribute('data-wpa-opening') === '1') return;
				a.setAttribute('data-wpa-opening', '1');
				window.setTimeout(function () {
					a.removeAttribute('data-wpa-opening');
				}, 900);
				if (openBlank) {
					var win = window.open(url, '_blank', 'noopener,noreferrer');
					if (!win) window.location.assign(url);
				} else {
					window.location.assign(url);
				}
			});
			actions.appendChild(a);
		}

		function appendToolMediaPreview(container, data) {
			if (!container || !data || !data.url) return;
			var mime = String(data.mime || '');
			var url = String(data.url);
			var isVid =
				!!data.isVideo ||
				mime.indexOf('video/') === 0 ||
				/\.(mp4|webm|mov)(\?|$)/i.test(url);
			var isAud =
				!!data.isAudio ||
				mime.indexOf('audio/') === 0 ||
				/\.(mp3|wav|ogg|m4a)(\?|$)/i.test(url);
			container.appendChild(document.createElement('br'));
			if (isVid) {
				var v = document.createElement('video');
				v.className = 'wpa-tool-card__video';
				v.src = url;
				v.controls = true;
				v.playsInline = true;
				container.appendChild(v);
				return;
			}
			if (isAud) {
				var au = document.createElement('audio');
				au.className = 'wpa-tool-card__audio';
				au.src = url;
				au.controls = true;
				container.appendChild(au);
				return;
			}
			var img = document.createElement('img');
			img.className = 'wpa-tool-card__img';
			img.src = url;
			img.alt = '';
			img.loading = 'lazy';
			container.appendChild(img);
		}

		function buildToolCard(tool, messageId) {
			if (!toolCardTemplate) return null;
			var card = toolCardTemplate.content.firstElementChild.cloneNode(true);
			var badge = card.querySelector('.wpa-tool-card__badge');
			var status = card.querySelector('.wpa-tool-card__status');
			var body = card.querySelector('.wpa-tool-card__body');
			var actions = card.querySelector('.wpa-tool-card__actions');
			var idGuess = tool.id || '';
			if (!idGuess) {
				if (tool.data && tool.data.pageId) idGuess = 'create_page';
				else if (tool.data && tool.data.postId) idGuess = 'create_post';
				else if (tool.data && tool.data.path) idGuess = 'write_file';
				else if (tool.data && tool.data.needsConfirm) idGuess = 'confirm';
			}

			var confirmStatus =
				(tool.data && tool.data.confirmStatus) ||
				(tool.data && tool.data.cancelled ? 'cancelled' : '');
			var needsConfirm = !!(
				tool.data &&
				tool.data.needsConfirm &&
				tool.data.confirmToken &&
				!confirmStatus
			);

			if (badge) badge.textContent = toolLabel(idGuess);
			if (status) {
				if (needsConfirm) {
					status.textContent = 'در انتظار تأیید';
					status.className = 'wpa-tool-card__status is-wait';
				} else if (confirmStatus === 'cancelled' || confirmStatus === 'expired') {
					status.textContent = confirmStatus === 'cancelled' ? 'لغو شد' : 'منقضی شد';
					status.className = 'wpa-tool-card__status is-fail';
				} else {
					status.textContent = tool.ok ? 'موفق' : 'ناموفق';
					status.className = 'wpa-tool-card__status ' + (tool.ok ? 'is-ok' : 'is-fail');
				}
			}
			if (body) {
				var bodyText = tool.message || '';
				var warn = tool.data && tool.data.warning ? String(tool.data.warning) : '';
				if (needsConfirm && warn) {
					if (bodyText.indexOf(warn) !== -1) {
						bodyText = bodyText.split(warn).join('').replace(/^\s+|\s+$/g, '');
					}
					bodyText = warn + (bodyText ? '\n\n' + bodyText : '');
					if (tool.data && tool.data.preview) {
						bodyText += '\n\n' + (tool.data.preview || '');
					}
				} else if (!needsConfirm && tool.data && tool.data.path && bodyText.indexOf(tool.data.path) === -1) {
					bodyText = (bodyText ? bodyText + '\n' : '') + 'مسیر: ' + tool.data.path;
				}
				if (tool.data && tool.data.restorePointId) {
					bodyText += '\nنقطه بازگشت: ' + tool.data.restorePointId;
				}
				if (tool.data && tool.data.model && tool.ok && !needsConfirm) {
					bodyText += (bodyText ? '\n' : '') + 'مدل: ' + tool.data.model;
				}
				body.textContent = bodyText;
				if (tool.ok && !needsConfirm && tool.data && tool.data.url) {
					appendToolMediaPreview(body, tool.data);
				}
			}
			if (actions) {
				actions.innerHTML = '';
				if (needsConfirm) {
					var conf = document.createElement('button');
					conf.type = 'button';
					conf.className = 'wpa-tool-card__btn wpa-tool-card__btn--ok';
					conf.textContent =
						(tool.data && tool.data.confirmLabel) ||
						(tool.data && tool.data.highRisk ? 'متوجه شدم — تأیید اجرا' : 'تأیید اجرا');
					conf.addEventListener('click', function () {
						var warnText =
							(tool.data && tool.data.warning) ||
							'این تغییر ممکن است سایت را خراب کند. ادامه؟';
						if (tool.data && tool.data.highRisk) {
							if (!window.confirm(warnText + '\n\nنقطه بازگشت بعد از تأیید ساخته می‌شود.')) {
								return;
							}
						}
						conf.disabled = true;
						api('agent_wp_confirm_pending', {
							token: String(tool.data.confirmToken),
							message_id: String(messageId || ''),
						}).then(function (json) {
							if (!json || !json.success) {
								conf.disabled = false;
								window.alert((json && json.data && json.data.message) || 'تأیید ناموفق');
								return;
							}
							var nextData = (json.data && json.data.data) || {};
							// مدل جایگزین هم شکست خورد → دوباره تأیید بخواه
							if (nextData.needsConfirm && nextData.confirmToken) {
								var refreshed = {
									ok: true,
									message: (json.data && json.data.message) || tool.message,
									data: nextData,
									id: tool.id || 'generate_image',
								};
								var freshCard = buildToolCard(refreshed, messageId);
								if (card.parentNode) {
									card.parentNode.replaceChild(freshCard, card);
								}
								patchCachedTool(messageId, tool.data.confirmToken, refreshed);
								return;
							}
							status.textContent = 'موفق';
							status.className = 'wpa-tool-card__status is-ok';
							var doneMsg = (json.data && json.data.message) || 'انجام شد';
							if (json.data && json.data.data && json.data.data.restorePointId) {
								doneMsg += '\nنقطه بازگشت: ' + json.data.data.restorePointId;
							}
							if (json.data && json.data.data && json.data.data.model) {
								doneMsg += '\nمدل: ' + json.data.data.model;
							}
							body.textContent = doneMsg;
							actions.innerHTML = '';
							var editUrl = json.data && json.data.data && json.data.data.editUrl;
							if (editUrl) {
								attachToolLink(actions, editUrl, 'ویرایش', false);
							}
							var viewUrlDone = json.data && json.data.data && json.data.data.viewUrl;
							if (viewUrlDone) {
								attachToolLink(actions, viewUrlDone, 'مشاهده', true);
							}
							var mediaData = json.data && json.data.data ? json.data.data : null;
							if (mediaData && mediaData.url) {
								appendToolMediaPreview(body, mediaData);
							}
							var row = scroller.querySelector('.wpa-row[data-message-id="' + String(messageId) + '"]');
							var atts =
								(json.data &&
									json.data.chatMessage &&
									json.data.chatMessage.meta &&
									json.data.chatMessage.meta.attachments) ||
								(json.data && json.data.data && json.data.data.attachments) ||
								null;
							if (row && atts && atts.length) {
								fillBubbleAttachments(row, atts);
							}
							patchCachedTool(messageId, tool.data.confirmToken, {
								ok: true,
								message: doneMsg,
								data: Object.assign({}, tool.data, json.data && json.data.data ? json.data.data : {}, {
									needsConfirm: false,
									confirmStatus: 'done',
									confirmToken: '',
								}),
							});
							if (atts && atts.length) {
								patchCachedAttachments(messageId, atts);
							}
						});
					});
					var cancel = document.createElement('button');
					cancel.type = 'button';
					cancel.className = 'wpa-tool-card__btn';
					cancel.textContent = 'لغو';
					cancel.addEventListener('click', function () {
						cancel.disabled = true;
						conf.disabled = true;
						var tok = String(tool.data.confirmToken);
						api('agent_wp_cancel_pending', {
							token: tok,
							message_id: String(messageId || ''),
						}).then(function () {
							status.textContent = 'لغو شد';
							status.className = 'wpa-tool-card__status is-fail';
							body.textContent = 'اکشن توسط شما لغو شد.';
							actions.innerHTML = '';
							patchCachedTool(messageId, tok, {
								ok: false,
								message: 'اکشن توسط شما لغو شد.',
								data: Object.assign({}, tool.data, {
									needsConfirm: false,
									confirmStatus: 'cancelled',
									confirmToken: '',
								}),
							});
						});
					});
					actions.appendChild(conf);
					actions.appendChild(cancel);
					var other = document.createElement('button');
					other.type = 'button';
					other.className = 'wpa-tool-card__btn';
					other.textContent = 'پاسخ دیگر';
					other.addEventListener('click', function () {
						startReplyTo(
							messageId,
							(tool.data && tool.data.warning) || tool.message || body.textContent || ''
						);
					});
					actions.appendChild(other);
					return card;
				}
				var editUrl = tool.data && tool.data.editUrl;
				var viewUrl = tool.data && tool.data.viewUrl;
				if (editUrl) {
					attachToolLink(actions, editUrl, 'ویرایش', false);
				}
				if (viewUrl) {
					attachToolLink(actions, viewUrl, 'مشاهده', true);
				}
			}
			return card;
		}

		function patchCachedTool(messageId, token, patch) {
			if (!messageId) return;
			Object.keys(messageCache).forEach(function (key) {
				var list = messageCache[key];
				if (!Array.isArray(list)) return;
				list.forEach(function (msg) {
					if (String(msg.id) !== String(messageId) || !msg.meta) return;
					var tools = Array.isArray(msg.meta.tools) ? msg.meta.tools : [];
					tools.forEach(function (t, idx) {
						if (!t.data) return;
						if (String(t.data.confirmToken || '') !== String(token || '')) return;
						tools[idx] = Object.assign({}, t, patch, {
							data: Object.assign({}, t.data || {}, patch.data || {}),
						});
					});
					msg.meta.tools = tools;
					if (tools.length) msg.meta.tool = tools[tools.length - 1];
				});
			});
		}

		function patchCachedAttachments(messageId, attachments) {
			if (!messageId || !Array.isArray(attachments) || !attachments.length) return;
			Object.keys(messageCache).forEach(function (key) {
				var list = messageCache[key];
				if (!Array.isArray(list)) return;
				list.forEach(function (msg) {
					if (String(msg.id) !== String(messageId)) return;
					msg.meta = msg.meta || {};
					var prev = Array.isArray(msg.meta.attachments) ? msg.meta.attachments : [];
					var map = {};
					prev.forEach(function (a) {
						if (a && a.id) map[String(a.id)] = a;
					});
					attachments.forEach(function (a) {
						if (a && a.id) map[String(a.id)] = a;
					});
					msg.meta.attachments = Object.keys(map).map(function (k) {
						return map[k];
					});
				});
			});
		}

		function fillSoftConfirmCard(node, msg) {
			var stack = node.querySelector('.wpa-tools');
			if (!stack) return;
			if (!toolCardTemplate) return;
			stack.innerHTML = '';
			stack.hidden = false;
			stack.setAttribute('data-soft-confirm', '1');
			var card = toolCardTemplate.content.firstElementChild.cloneNode(true);
			var badge = card.querySelector('.wpa-tool-card__badge');
			var status = card.querySelector('.wpa-tool-card__status');
			var body = card.querySelector('.wpa-tool-card__body');
			var actions = card.querySelector('.wpa-tool-card__actions');
			if (badge) badge.textContent = 'تأیید';
			if (status) {
				status.textContent = 'در انتظار تأیید';
				status.className = 'wpa-tool-card__status is-wait';
			}
			if (body) {
				body.textContent = 'برای ادامه یکی از گزینه‌ها را بزنید، یا با «پاسخ دیگر» توضیح بدهید.';
			}
			if (actions) {
				actions.innerHTML = '';
				var conf = document.createElement('button');
				conf.type = 'button';
				conf.className = 'wpa-tool-card__btn wpa-tool-card__btn--ok';
				conf.textContent = 'تأیید';
				conf.addEventListener('click', function () {
					if (sending) return;
					replyTo = {
						id: msg.id,
						preview: String(msg.content || '')
							.replace(/\s+/g, ' ')
							.trim()
							.slice(0, 140),
					};
					sendPayload('بله، تأیید می‌کنم. لطفاً همان تغییر را الان با ابزار اجرا کن.');
				});
				var cancel = document.createElement('button');
				cancel.type = 'button';
				cancel.className = 'wpa-tool-card__btn';
				cancel.textContent = 'لغو';
				cancel.addEventListener('click', function () {
					if (sending) return;
					replyTo = {
						id: msg.id,
						preview: String(msg.content || '')
							.replace(/\s+/g, ' ')
							.trim()
							.slice(0, 140),
					};
					sendPayload('لغو — این تغییر را انجام نده.');
				});
				var other = document.createElement('button');
				other.type = 'button';
				other.className = 'wpa-tool-card__btn';
				other.textContent = 'پاسخ دیگر';
				other.addEventListener('click', function () {
					startReplyTo(msg.id, msg.content || '');
				});
				actions.appendChild(conf);
				actions.appendChild(cancel);
				actions.appendChild(other);
			}
			stack.appendChild(card);
		}

		function stripSoftConfirms() {
			Array.prototype.forEach.call(
				scroller.querySelectorAll('.wpa-tools[data-soft-confirm="1"]'),
				function (el) {
					el.innerHTML = '';
					el.hidden = true;
					el.removeAttribute('data-soft-confirm');
				}
			);
		}

		function fillToolCards(node, tools, messageId) {
			var stack = node.querySelector('.wpa-tools');
			if (!stack) return;
			stack.removeAttribute('data-soft-confirm');
			stack.innerHTML = '';
			if (!tools || !tools.length) {
				stack.hidden = true;
				return;
			}
			stack.hidden = false;
			tools.forEach(function (tool) {
				var card = buildToolCard(tool, messageId);
				if (card) stack.appendChild(card);
			});
		}

		function createBubbleFromMessage(msg, opts) {
			opts = opts || {};
			var outgoing = msg.role === 'user';
			var node = template.content.firstElementChild.cloneNode(true);
			node.classList.add(outgoing ? 'is-out' : 'is-in');
			node.setAttribute('data-message-id', String(msg.id || ''));
			node.setAttribute('data-role', msg.role || '');

			var replyBox = node.querySelector('.wpa-bubble__reply');
			var replyPrev = node.querySelector('.wpa-bubble__reply-preview');
			var replyMeta = msg.meta && msg.meta.replyTo ? msg.meta.replyTo : null;
			if (replyBox) {
				if (replyMeta && (replyMeta.preview || replyMeta.id)) {
					replyBox.hidden = false;
					if (replyPrev) replyPrev.textContent = replyMeta.preview || ('#' + replyMeta.id);
				} else {
					replyBox.hidden = true;
					if (replyPrev) replyPrev.textContent = '';
				}
			}

			node.querySelector('.wpa-bubble__text').textContent = msg.content || '';
			fillBubbleAttachments(
				node,
				(msg.meta && msg.meta.attachments) || msg.attachments || []
			);
			node.querySelector('.wpa-bubble__time').textContent = formatMsgTime(msg.updatedAt || msg.createdAt);
			var edited = node.querySelector('.wpa-bubble__edited');
			if (edited) {
				edited.hidden = !(msg.updatedAt && msg.createdAt && msg.updatedAt !== msg.createdAt);
			}

			var tokensEl = node.querySelector('.wpa-bubble__tokens');
			var routeEl = node.querySelector('.wpa-bubble__route');
			var usage =
				(msg.meta && msg.meta.usage) ||
				msg.usage ||
				null;
			var route =
				(msg.meta && msg.meta.route) ||
				msg.route ||
				null;
			if (routeEl) {
				if (!outgoing && route && route.modelName) {
					routeEl.hidden = false;
					routeEl.textContent = route.auto
						? 'خودکار · ' + route.modelName
						: route.modelName;
					if (route.taskLabel) {
						routeEl.title = route.reason || route.taskLabel;
					}
				} else {
					routeEl.hidden = true;
					routeEl.textContent = '';
				}
			}
			if (tokensEl) {
				var total =
					usage && (usage.totalTokens || usage.total_tokens)
						? usage.totalTokens || usage.total_tokens
						: 0;
				if (!outgoing && total > 0) {
					tokensEl.hidden = false;
					tokensEl.textContent =
						total + ' ' + (i18n.tokens || 'توکن');
				} else {
					tokensEl.hidden = true;
					tokensEl.textContent = '';
				}
			}

			var toolsList = [];
			if (msg.meta && Array.isArray(msg.meta.tools) && msg.meta.tools.length) {
				toolsList = msg.meta.tools;
			} else if (Array.isArray(msg.tools) && msg.tools.length) {
				toolsList = msg.tools;
			} else {
				var one = (msg.meta && msg.meta.tool) || msg.tool || null;
				if (one) toolsList = [one];
			}
			fillToolCards(node, toolsList, msg.id);
			if (
				opts.isLatest &&
				!outgoing &&
				!toolsList.length &&
				looksLikeConfirmAsk(msg.content) &&
				msg.id &&
				String(msg.id).indexOf('local-') !== 0
			) {
				fillSoftConfirmCard(node, msg);
			}

			function openMenu(clientX, clientY) {
				if (!msg.id || String(msg.id).indexOf('local-') === 0) return;
				showMsgMenu(clientX, clientY, msg.id, node.querySelector('.wpa-bubble__text').textContent, outgoing);
			}

			node.addEventListener('contextmenu', function (e) {
				e.preventDefault();
				openMenu(e.clientX, e.clientY);
			});

			var pressTimer = null;
			node.addEventListener('touchstart', function (e) {
				if (!e.touches || !e.touches[0]) return;
				var t = e.touches[0];
				pressTimer = window.setTimeout(function () {
					openMenu(t.clientX, t.clientY);
				}, 480);
			}, { passive: true });
			node.addEventListener('touchend', function () {
				if (pressTimer) window.clearTimeout(pressTimer);
				pressTimer = null;
			});
			node.addEventListener('touchmove', function () {
				if (pressTimer) window.clearTimeout(pressTimer);
				pressTimer = null;
			});

			if (outgoing && msg.id && String(msg.id).indexOf('local-') !== 0) {
				node.querySelector('.wpa-bubble__text').addEventListener('dblclick', function () {
					startEditMessage(msg.id, node.querySelector('.wpa-bubble__text').textContent);
				});
			}

			return node;
		}

		function appendMessage(msg, updatePreview) {
			hideLoading();
			hideEmpty();
			stripSoftConfirms();
			var day = (msg.createdAt || '').slice(0, 10);
			if (day && day !== lastDateLabel) {
				lastDateLabel = day;
				var sep = document.createElement('div');
				sep.className = 'wpa-date-sep';
				sep.textContent = day;
				scroller.appendChild(sep);
			}
			scroller.appendChild(createBubbleFromMessage(msg, { isLatest: true }));
			if (updatePreview !== false) updateSidebarPreview(msg.content);
			scrollToEnd(true);
		}

		function renderMessages(messages) {
			clearMessageRows();
			hideLoading();
			lastDateLabel = '';
			if (!messages || !messages.length) {
				showEmpty();
				return;
			}
			hideEmpty();
			messages.forEach(function (msg, idx) {
				var day = (msg.createdAt || '').slice(0, 10);
				if (day && day !== lastDateLabel) {
					lastDateLabel = day;
					var sep = document.createElement('div');
					sep.className = 'wpa-date-sep';
					sep.textContent = day;
					scroller.appendChild(sep);
				}
				scroller.appendChild(
					createBubbleFromMessage(msg, { isLatest: idx === messages.length - 1 })
				);
			});
			updateSidebarPreview(messages[messages.length - 1].content);
			scrollToEnd(true);
		}

		function avatarHtml(chat) {
			var letter = (chat.title || 'گ').trim().charAt(0);
			return '<div class="wpa-avatar wpa-avatar--chat" aria-hidden="true">' + escapeHtml(letter) + '</div>';
		}

		function renderChatList(chats, activeId) {
			chatsCache = chats || [];
			chatList.innerHTML = '';
			var sideLoad = document.getElementById('wpa-sidebar-loading');
			if (sideLoad) sideLoad.remove();

			// پین بالای لیست — مثل فولدر/ابزار تلگرام
			var toolsBtn = document.createElement('button');
			toolsBtn.type = 'button';
			toolsBtn.className = 'wpa-chat-item wpa-chat-item--tools';
			toolsBtn.setAttribute('role', 'listitem');
			toolsBtn.innerHTML =
				'<div class="wpa-avatar wpa-avatar--tools" aria-hidden="true">' +
				'<svg viewBox="0 0 24 24" width="22" height="22"><path fill="#fff" d="M22.7 19l-9.1-9.1c.9-2.3.4-5-1.5-6.9-2-2-5-2.4-7.4-1.3L9 6 6 9 1.6 4.7C.4 7.1.9 10.1 2.9 12.1c1.9 1.9 4.6 2.4 6.9 1.5l9.1 9.1c.4.4 1 .4 1.4 0l2.3-2.3c.5-.4.5-1.1.1-1.4z"/></svg>' +
				'</div><div class="wpa-chat-item__body"><div class="wpa-chat-item__top">' +
				'<span class="wpa-chat-item__title">ابزارها</span>' +
				'<span class="wpa-chat-item__time"></span></div>' +
				'<div class="wpa-chat-item__bottom"><span class="wpa-chat-item__preview">تصویر، ویدیو، کد و مدل‌ها</span></div></div>';
			toolsBtn.addEventListener('click', function () {
				openToolsView();
			});
			chatList.appendChild(toolsBtn);

			chatsCache.forEach(function (chat) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className =
					'wpa-chat-item' +
					(String(chat.id) === String(activeId) ? ' is-active' : '') +
					(chat.pending ? ' is-pending' : '') +
					(chat.pinned ? ' is-pinned' : '');
				btn.setAttribute('role', 'listitem');
				btn.setAttribute('data-chat-id', String(chat.id));
				btn.innerHTML =
					avatarHtml(chat) +
					'<div class="wpa-chat-item__body"><div class="wpa-chat-item__top">' +
					'<span class="wpa-chat-item__title">' +
					(chat.pinned ? '📌 ' : '') +
					escapeHtml(chat.title || 'گفتگو') +
					'</span><span class="wpa-chat-item__time">' +
					escapeHtml(formatMsgTime(chat.updatedAt)) +
					'</span></div><div class="wpa-chat-item__bottom"><span class="wpa-chat-item__preview">' +
					escapeHtml(chat.lastMessage || 'گفتگوی خالی') +
					'</span></div></div>';
				btn.addEventListener('click', function () {
					if (chat.pending) return;
					if (app.getAttribute('data-view') === 'tools') closeToolsView();
					openChat(chat.id);
				});
				chatList.appendChild(btn);
			});
		}

		function findChat(chatId) {
			var found = null;
			chatsCache.forEach(function (c) {
				if (String(c.id) === String(chatId)) found = c;
			});
			return found;
		}

		function openChat(chatId, opts) {
			opts = opts || {};
			cancelEditMessage();
			cancelReplyTo();
			hideMsgMenu();
			hideChatMenu();
			closeMsgSearch();

			activeChatId = isTempId(chatId) ? chatId : Number(chatId) || 0;
			var chat = findChat(chatId);
			renderChatList(chatsCache, activeChatId);
			if (chat && topTitle) topTitle.textContent = chat.title || 'دستیار';

			// موبایل: فوراً به پنل چت برو تا لودینگ/پیام‌ها دیده شوند (نه بعد از اتمام API)
			if (isMobile()) setView('chat');

			var key = String(chatId);
			var cached = messageCache[key];
			var hasCache = Object.prototype.hasOwnProperty.call(messageCache, key);

			if (opts.skipFetch || hasCache) {
				renderMessages(cached || []);
			} else {
				clearMessageRows();
				showLoading();
			}

			if (opts.skipFetch || isTempId(chatId)) {
				return;
			}

			var requestId = ++openRequestSeq;
			api('agent_wp_get_messages', { chat_id: String(chatId) })
				.then(function (json) {
					if (requestId !== openRequestSeq || String(activeChatId) !== String(chatId)) return;
					if (!json || !json.success) {
						if (!hasCache) {
							hideLoading();
							showEmpty();
						}
						return;
					}
					var messages = (json.data && json.data.messages) || [];
					messageCache[key] = messages;
					renderMessages(messages);
					if (isMobile()) {
						setTimeout(function () {
							scrollToEnd(true);
							input.focus();
						}, 50);
					}
				})
				.catch(function () {
					if (requestId !== openRequestSeq || String(activeChatId) !== String(chatId)) return;
					if (!hasCache) {
						hideLoading();
						showEmpty();
					}
				});
		}

		function bootstrapChats() {
			var seeded = Array.isArray(cfg.initialChats) ? cfg.initialChats : null;
			var seedId = cfg.initialChatId || (seeded && seeded[0] && seeded[0].id) || 0;
			var seedMsgs = Array.isArray(cfg.initialMessages) ? cfg.initialMessages : null;

			// هیدریت فوری از PHP — بدون فریم خالی.
			if (seeded && seeded.length && seedId) {
				if (seedMsgs) messageCache[String(seedId)] = seedMsgs;
				renderChatList(seeded, seedId);
				openChat(seedId, { skipFetch: !!seedMsgs });
				// همگام‌سازی نرم در پس‌زمینه
				api('agent_wp_list_chats')
					.then(function (json) {
						if (!json || !json.success) return;
						var chats = (json.data && json.data.chats) || [];
						if (!chats.length) return;
						chatsCache = chats;
						renderChatList(chats, activeChatId || chats[0].id);
					})
					.catch(function () {});
				return;
			}

			showLoading();
			return api('agent_wp_list_chats')
				.then(function (json) {
					var chats = (json && json.success && json.data && json.data.chats) || [];
					if (!chats.length) {
						hideLoading();
						showEmpty();
						return;
					}
					renderChatList(chats, chats[0].id);
					openChat(chats[0].id);
				})
				.catch(function () {
					hideLoading();
					showEmpty();
					window.alert(i18n.loadFail || 'بارگذاری گفتگوها ناموفق بود.');
				});
		}

		function createNewChat(opts) {
			opts = opts || {};
			if (creatingChat) return;
			creatingChat = true;
			if (newChatBtn) newChatBtn.classList.add('is-loading');

			var title = (opts.title || 'گفتگوی جدید').trim() || 'گفتگوی جدید';
			var modelId = opts.modelId ? String(opts.modelId) : '';
			var imageModelId = opts.imageModelId ? String(opts.imageModelId) : '';
			var videoModelId = opts.videoModelId ? String(opts.videoModelId) : '';
			var audioModelId = opts.audioModelId ? String(opts.audioModelId) : '';
			var prompt = opts.prompt ? String(opts.prompt) : '';

			var tempId = 'tmp-' + Date.now();
			var optimistic = {
				id: tempId,
				title: title,
				updatedAt: nowMysql(),
				lastMessage: '',
				pending: true,
			};
			chatsCache = [optimistic].concat(
				chatsCache.filter(function (c) {
					return !c.pending;
				})
			);
			messageCache[tempId] = [];
			openChat(tempId, { skipFetch: true });
			setView('chat');

			if (imageModelId) rememberPreferredImageModel(imageModelId);
			if (videoModelId) rememberPreferredVideoModel(videoModelId);
			if (audioModelId) rememberPreferredAudioModel(audioModelId);
			if (modelId) {
				rememberSelectedModel(modelId);
				refreshModelChipLabel();
				renderModelMenu(modelsCache);
			} else if (imageModelId || videoModelId || audioModelId) {
				refreshModelChipLabel();
			}

			if (prompt && input) {
				input.value = prompt;
				autosize();
				setSendMode();
			}

			api('agent_wp_create_chat', { title: title })
				.then(function (json) {
					creatingChat = false;
					if (newChatBtn) newChatBtn.classList.remove('is-loading');
					if (!json || !json.success) {
						chatsCache = chatsCache.filter(function (c) {
							return String(c.id) !== tempId;
						});
						delete messageCache[tempId];
						renderChatList(chatsCache, chatsCache[0] ? chatsCache[0].id : 0);
						if (chatsCache[0]) openChat(chatsCache[0].id);
						window.alert((json && json.data && json.data.message) || 'ساخت گفتگو ناموفق');
						return;
					}
					var real = json.data.chat;
					delete messageCache[tempId];
					messageCache[String(real.id)] = [];
					chatsCache = (json.data && json.data.chats) || chatsCache;
					openChat(real.id, { skipFetch: true });
					setView('chat');
					if (prompt && input) {
						input.value = prompt;
						autosize();
						setSendMode();
					}
					input.focus();
				})
				.catch(function () {
					creatingChat = false;
					if (newChatBtn) newChatBtn.classList.remove('is-loading');
					chatsCache = chatsCache.filter(function (c) {
						return String(c.id) !== tempId;
					});
					delete messageCache[tempId];
					renderChatList(chatsCache, chatsCache[0] ? chatsCache[0].id : 0);
					if (chatsCache[0]) openChat(chatsCache[0].id);
					window.alert('خطای شبکه');
				});
		}

		function showTyping(statusText) {
			hideLoading();
			hideEmpty();
			var row = document.createElement('div');
			row.className = 'wpa-row is-in wpa-row--typing';
			row.innerHTML =
				'<div class="wpa-bubble wpa-bubble--typing">' +
				'<div class="wpa-typing"><span></span><span></span><span></span></div>' +
				'<div class="wpa-typing__status"></div>' +
				'</div>';
			var statusEl = row.querySelector('.wpa-typing__status');
			if (statusEl) {
				statusEl.textContent = statusText || 'در حال کار روی سایت…';
			}
			scroller.appendChild(row);
			scrollToEnd(true);
			return row;
		}

		function sendPayload(text, files, opts) {
			opts = opts || {};
			files = files || [];
			text = String(text || '').trim();
			if ((!text && !files.length) || !activeChatId || sending || isTempId(activeChatId)) {
				return false;
			}
			sending = true;
			setSendMode();

			var activeReply = replyTo ? { id: replyTo.id, preview: replyTo.preview } : null;
			cancelReplyTo();

			var localAtts = files.map(function (f) {
				return {
					name: f.name,
					mime: f.mime || (f.file && f.file.type) || '',
					isImage: !!f.isImage,
					url: f.previewUrl || '',
				};
			});

			var tempUserId = 'local-' + Date.now();
			var optimistic = {
				id: tempUserId,
				role: 'user',
				content: text || (files.length ? 'پیوست ارسال شد.' : ''),
				createdAt: nowMysql(),
				updatedAt: nowMysql(),
				meta: {
					replyTo: activeReply || undefined,
					attachments: localAtts.length ? localAtts : undefined,
				},
			};
			appendMessage(optimistic, true);
			var optimisticRow = scroller.querySelector('.wpa-row[data-message-id="' + tempUserId + '"]');
			if (optimisticRow) optimisticRow.classList.add('is-pending');

			var plan = typingPlanFor(text);
			var typing = showTyping(plan.initial);
			var chatIdAtSend = activeChatId;
			var requestId =
				String(chatIdAtSend) + '-' + String(Date.now()) + '-' + Math.random().toString(36).slice(2, 8);
			var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;

			var statusTimer = null;
			if (plan.later) {
				statusTimer = window.setTimeout(function () {
					if (!typing || !typing.parentNode) return;
					var st = typing.querySelector('.wpa-typing__status');
					if (st) st.textContent = plan.later;
				}, 2200);
			}

			activeSend = {
				requestId: requestId,
				controller: controller,
				typing: typing,
				optimisticRow: optimisticRow,
				chatId: chatIdAtSend,
				text: text,
				statusTimer: statusTimer,
				aborted: false,
			};

			var payload = {
				chat_id: String(chatIdAtSend),
				content: text,
				model_id: selectedModelId && selectedModelId !== 'auto' ? String(selectedModelId) : 'auto',
				model_mode: selectedModelId && selectedModelId !== 'auto' ? 'manual' : 'auto',
				request_id: requestId,
			};
			if (preferredImageModel) {
				payload.preferred_image_model = String(preferredImageModel);
			}
			if (preferredVideoModel) {
				payload.preferred_video_model = String(preferredVideoModel);
			}
			if (preferredAudioModel) {
				payload.preferred_audio_model = String(preferredAudioModel);
			}
			if (activeReply && activeReply.id) {
				payload.reply_to = String(activeReply.id);
				payload.reply_preview = String(activeReply.preview || '');
			}

			var body = new window.FormData();
			body.append('action', 'agent_wp_send_message');
			body.append('nonce', cfg.nonce || '');
			Object.keys(payload).forEach(function (key) {
				body.append(key, payload[key]);
			});
			files.forEach(function (att) {
				if (att.file) body.append('attachments[]', att.file, att.name || att.file.name);
			});

			var fetchOpts = {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			};
			if (controller) fetchOpts.signal = controller.signal;

			function isStale() {
				return !activeSend || activeSend.requestId !== requestId;
			}

			function finishAndDrain() {
				if (isStale()) return;
				sending = false;
				activeSend = null;
				setSendMode();
				window.setTimeout(function () {
					drainSendQueue();
				}, 30);
			}

			fetch(cfg.ajaxUrl, fetchOpts)
				.then(function (res) {
					return res.json();
				})
				.then(function (json) {
					// پاسخ دیررسِ درخواست قبلی را نادیده بگیر (باگ صف)
					if (isStale() || (activeSend && activeSend.aborted)) return;
					if (statusTimer) window.clearTimeout(statusTimer);
					if (typing && typing.parentNode) typing.remove();

					if (!json || !json.success) {
						if (optimisticRow) optimisticRow.remove();
						window.alert((json && json.data && json.data.message) || 'ارسال ناموفق');
						finishAndDrain();
						return;
					}

					var stillHere = String(activeChatId) === String(chatIdAtSend);

					if (json.data.userMessage) {
						if (stillHere && optimisticRow) {
							optimisticRow.setAttribute('data-message-id', String(json.data.userMessage.id));
							optimisticRow.classList.remove('is-pending');
							var textEl = optimisticRow.querySelector('.wpa-bubble__text');
							if (textEl) textEl.textContent = json.data.userMessage.content || '';
							fillBubbleAttachments(
								optimisticRow,
								(json.data.userMessage.meta && json.data.userMessage.meta.attachments) || []
							);
							var edited = optimisticRow.querySelector('.wpa-bubble__edited');
							if (edited) edited.hidden = true;
						} else if (stillHere) {
							appendMessage(json.data.userMessage, false);
						}
						appendCachedMessage(chatIdAtSend, json.data.userMessage);
					} else if (optimisticRow) {
						optimisticRow.remove();
					}

					if (json.data.assistantMessage) {
						if (stillHere) appendMessage(json.data.assistantMessage, true);
						appendCachedMessage(chatIdAtSend, json.data.assistantMessage);
						var replyText =
							(json.data.assistantMessage && json.data.assistantMessage.content) || '';
						if (/اعتبار GapGPT|سهمیه GapGPT|شارژ کنید/i.test(replyText)) {
							refreshCredit(true);
						}
					}

					if (json.data.chats) {
						chatsCache = json.data.chats;
						renderChatList(chatsCache, activeChatId);
					}
					if (stillHere) input.focus();
					finishAndDrain();
				})
				.catch(function (err) {
					if (isStale()) return;
					var aborted =
						(activeSend && activeSend.aborted) ||
						(err && (err.name === 'AbortError' || err.code === 20));
					if (statusTimer) window.clearTimeout(statusTimer);
					if (aborted) {
						if (activeSend && activeSend.requestId === requestId) {
							sending = false;
							activeSend = null;
							setSendMode();
						}
						return;
					}
					if (typing && typing.parentNode) typing.remove();
					if (optimisticRow) optimisticRow.remove();
					lastFailedSend = text;
					if (netBanner) {
						netBanner.hidden = false;
					} else {
						window.alert('خطای شبکه');
					}
					finishAndDrain();
				});

			return true;
		}

		function sendMessage() {
			if (editingMessageId) {
				saveEditMessage();
				return;
			}
			if (speechListening) stopSpeechInput();
			var text = input.value.replace(/\s+$/g, '');
			var files = pendingAttachments.slice();
			if (!text.trim() && !files.length) return;
			if (isTempId(activeChatId)) return;

			var activeReply = replyTo ? { id: replyTo.id, preview: replyTo.preview } : null;
			input.value = '';
			pendingAttachments = [];
			renderAttachBar();
			autosize();
			setSendMode();
			lastFailedSend = null;
			if (netBanner) netBanner.hidden = true;

			if (sending) {
				enqueueSend(text.trim(), activeReply, files);
				cancelReplyTo();
				return;
			}
			if (activeReply) {
				replyTo = activeReply;
			}
			sendPayload(text.trim(), files);
		}

		input.addEventListener('input', function () {
			autosize();
			setSendMode();
		});
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter' && !e.shiftKey) {
				e.preventDefault();
				sendMessage();
			}
			if (e.key === 'Escape' && editingMessageId) {
				e.preventDefault();
				cancelEditMessage();
			}
			if (e.key === 'Escape' && replyTo) {
				e.preventDefault();
				cancelReplyTo();
			}
		});
		sendBtn.addEventListener('click', function () {
			if (speechListening) {
				stopSpeechInput();
				return;
			}
			if (sendBtn.dataset.mode === 'mic') {
				startSpeechInput();
				return;
			}
			if (sendBtn.dataset.mode === 'send' || sendBtn.dataset.mode === 'check') sendMessage();
		});
		if (stopBtn) {
			stopBtn.addEventListener('click', function () {
				stopActiveSend(false);
			});
		}
		scroller.addEventListener('scroll', updateScrollDown);
		scrollDown.addEventListener('click', function () {
			scrollToEnd(true);
		});
		if (backBtn) {
			backBtn.addEventListener('click', function () {
				setView('list');
			});
		}
		if (toolsBackBtn) {
			toolsBackBtn.addEventListener('click', function () {
				toolsBack();
			});
		}
		if (newChatBtn) newChatBtn.addEventListener('click', function () {
			createNewChat();
		});

		plusBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			var open = plusMenu.hidden;
			closePopovers();
			if (open) {
				plusMenu.hidden = false;
				plusBtn.setAttribute('aria-expanded', 'true');
			}
		});
		attachBtn.addEventListener('click', function () {
			closePopovers();
			if (fileInput) fileInput.click();
		});
		if (attachImageBtn) {
			attachImageBtn.addEventListener('click', function () {
				closePopovers();
				if (imageInput) imageInput.click();
			});
		}

		function clearMsgSearchHighlights() {
			Array.prototype.forEach.call(scroller.querySelectorAll('.wpa-row.is-search-hit, .wpa-row.is-search-active'), function (row) {
				row.classList.remove('is-search-hit', 'is-search-active');
			});
			msgSearchHits = [];
			msgSearchIndex = -1;
			if (msgSearchCount) msgSearchCount.textContent = '';
		}

		function runMsgSearch(query) {
			clearMsgSearchHighlights();
			query = String(query || '').trim().toLowerCase();
			if (!query) return;
			Array.prototype.forEach.call(scroller.querySelectorAll('.wpa-row'), function (row) {
				var textEl = row.querySelector('.wpa-bubble__text');
				var text = (textEl && textEl.textContent) || '';
				if (text.toLowerCase().indexOf(query) !== -1) {
					row.classList.add('is-search-hit');
					msgSearchHits.push(row);
				}
			});
			if (msgSearchCount) {
				msgSearchCount.textContent = msgSearchHits.length
					? '0/' + msgSearchHits.length
					: '۰ نتیجه';
			}
			if (msgSearchHits.length) jumpMsgSearch(0);
		}

		function jumpMsgSearch(index) {
			if (!msgSearchHits.length) return;
			if (msgSearchIndex >= 0 && msgSearchHits[msgSearchIndex]) {
				msgSearchHits[msgSearchIndex].classList.remove('is-search-active');
			}
			msgSearchIndex = (index + msgSearchHits.length) % msgSearchHits.length;
			var row = msgSearchHits[msgSearchIndex];
			row.classList.add('is-search-active');
			row.scrollIntoView({ block: 'center', behavior: 'smooth' });
			if (msgSearchCount) {
				msgSearchCount.textContent = msgSearchIndex + 1 + '/' + msgSearchHits.length;
			}
		}

		function openMsgSearch() {
			if (!msgSearchBar) return;
			msgSearchBar.hidden = false;
			if (msgSearchBtn) msgSearchBtn.setAttribute('aria-expanded', 'true');
			if (msgSearchInput) {
				msgSearchInput.focus();
				msgSearchInput.select();
			}
		}

		function closeMsgSearch() {
			if (!msgSearchBar) return;
			msgSearchBar.hidden = true;
			if (msgSearchBtn) msgSearchBtn.setAttribute('aria-expanded', 'false');
			if (msgSearchInput) msgSearchInput.value = '';
			clearMsgSearchHighlights();
		}

		if (msgSearchBtn) {
			msgSearchBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				if (msgSearchBar && msgSearchBar.hidden) openMsgSearch();
				else closeMsgSearch();
			});
		}
		if (msgSearchClose) msgSearchClose.addEventListener('click', closeMsgSearch);
		if (msgSearchPrev) {
			msgSearchPrev.addEventListener('click', function () {
				jumpMsgSearch(msgSearchIndex - 1);
			});
		}
		if (msgSearchNext) {
			msgSearchNext.addEventListener('click', function () {
				jumpMsgSearch(msgSearchIndex + 1);
			});
		}
		if (msgSearchInput) {
			msgSearchInput.addEventListener('input', function () {
				runMsgSearch(msgSearchInput.value);
			});
			msgSearchInput.addEventListener('keydown', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					if (e.shiftKey) jumpMsgSearch(msgSearchIndex - 1);
					else jumpMsgSearch(msgSearchIndex + 1);
				}
				if (e.key === 'Escape') closeMsgSearch();
			});
		}

		if (searchInput) {
			searchInput.addEventListener('input', function () {
				filterChatList(searchInput.value);
			});
		}
		fileInput.addEventListener('change', function () {
			if (!fileInput.files || !fileInput.files.length) return;
			addFilesToPending(fileInput.files);
			fileInput.value = '';
		});
		if (imageInput) {
			imageInput.addEventListener('change', function () {
				if (!imageInput.files || !imageInput.files.length) return;
				addFilesToPending(imageInput.files);
				imageInput.value = '';
			});
		}

		modelBtn.addEventListener('click', function (e) {
			e.stopPropagation();
			var open = modelMenu.hidden;
			closePopovers();
			if (open) {
				modelMenu.hidden = false;
				modelBtn.setAttribute('aria-expanded', 'true');
			}
		});

		if (netBanner) {
			netBanner.addEventListener('click', function () {
				if (!lastFailedSend || sending) return;
				netBanner.hidden = true;
				var t = lastFailedSend;
				lastFailedSend = null;
				sendPayload(t);
			});
		}

		menuBtn.addEventListener('click', openSettings);
		if (moreBtn) moreBtn.addEventListener('click', toggleChatMenu);
		if (chatRenameBtn) {
			chatRenameBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				hideChatMenu();
				openRenameDialog();
			});
		}
		if (chatExportBtn) {
			chatExportBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				hideChatMenu();
				if (!activeChatId || isTempId(activeChatId)) return;
				api('agent_wp_export_chat', { chat_id: String(activeChatId) }).then(function (json) {
					if (!json || !json.success) {
						window.alert((json && json.data && json.data.message) || 'خروجی ناموفق');
						return;
					}
					var blob = new Blob([JSON.stringify(json.data, null, 2)], { type: 'application/json' });
					var a = document.createElement('a');
					a.href = URL.createObjectURL(blob);
					a.download = 'agent-wp-chat-' + activeChatId + '.json';
					a.click();
					URL.revokeObjectURL(a.href);
				});
			});
		}
		if (chatPinBtn) {
			chatPinBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				hideChatMenu();
				if (!activeChatId || isTempId(activeChatId)) return;
				var chat = findChat(activeChatId);
				api('agent_wp_chat_flag', {
					chat_id: String(activeChatId),
					key: 'pinned',
					value: chat && chat.pinned ? '0' : '1',
				}).then(function (json) {
					if (json && json.success && json.data.chats) {
						renderChatList(json.data.chats, activeChatId);
					}
				});
			});
		}
		if (chatArchiveBtn) {
			chatArchiveBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				hideChatMenu();
				if (!activeChatId || isTempId(activeChatId)) return;
				api('agent_wp_chat_flag', {
					chat_id: String(activeChatId),
					key: 'archived',
					value: '1',
				}).then(function (json) {
					if (json && json.success && json.data.chats) {
						chatsCache = json.data.chats;
						renderChatList(chatsCache, chatsCache[0] ? chatsCache[0].id : 0);
						if (chatsCache[0]) openChat(chatsCache[0].id);
					}
				});
			});
		}
		if (chatRegenBtn) {
			chatRegenBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				hideChatMenu();
				if (!activeChatId || isTempId(activeChatId) || sending) return;
				sending = true;
				api('agent_wp_regenerate', {
					chat_id: String(activeChatId),
					model_id: String(selectedModelId || 'auto'),
					preferred_image_model: preferredImageModel ? String(preferredImageModel) : '',
					preferred_video_model: preferredVideoModel ? String(preferredVideoModel) : '',
					preferred_audio_model: preferredAudioModel ? String(preferredAudioModel) : '',
				})
					.then(function (json) {
						sending = false;
						if (!json || !json.success) {
							window.alert((json && json.data && json.data.message) || 'بازتولید ناموفق');
							return;
						}
						if (json.data.messages) {
							messageCache[String(activeChatId)] = json.data.messages;
							renderMessages(json.data.messages);
						}
					})
					.catch(function () {
						sending = false;
					});
			});
		}
		if (chatDeleteBtn) {
			chatDeleteBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				deleteActiveChat();
			});
		}
		if (chatMenu) {
			chatMenu.addEventListener('click', function (e) {
				e.stopPropagation();
			});
		}

		if (editCancelBtn) editCancelBtn.addEventListener('click', cancelEditMessage);
		if (replyCancelBtn) replyCancelBtn.addEventListener('click', cancelReplyTo);
		if (msgCopyBtn) {
			msgCopyBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				var text = msgMenuTargetText || '';
				hideMsgMenu();
				if (!text) return;
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text);
				}
			});
		}
		if (msgEditBtn) {
			msgEditBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				if (msgMenuTargetId && msgMenuIsUser) {
					startEditMessage(msgMenuTargetId, msgMenuTargetText);
				} else {
					hideMsgMenu();
				}
			});
		}
		if (msgDeleteBtn) {
			msgDeleteBtn.addEventListener('click', function (e) {
				e.stopPropagation();
				deleteTargetMessage();
			});
		}
		if (msgMenu) {
			msgMenu.addEventListener('click', function (e) {
				e.stopPropagation();
			});
		}

		renameSave.addEventListener('click', saveRename);
		renameCancel.addEventListener('click', closeRenameDialog);
		renameBackdrop.addEventListener('click', closeRenameDialog);
		renameInput.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') {
				e.preventDefault();
				saveRename();
			}
		});

		settingsNav.addEventListener('click', function () {
			var page = settingsPanel.getAttribute('data-page') || 'menu';
			if (page === 'menu') closeSettings();
			else setSettingsPage('menu');
		});
		settingsBackdrop.addEventListener('click', closeSettings);
		Array.prototype.forEach.call(settings.querySelectorAll('[data-open-page]'), function (btn) {
			btn.addEventListener('click', function () {
				setSettingsPage(btn.getAttribute('data-open-page'));
			});
		});

		modelForm.addEventListener('submit', function (e) {
			e.preventDefault();
			var nameEl = document.getElementById('wpa-model-name');
			var name = nameEl ? nameEl.value.trim() : '';
			var keyEl = document.getElementById('wpa-model-key');
			var key = keyEl ? keyEl.value.trim() : '';
			var saveBtn = document.getElementById('wpa-model-save');
			var baseEl = document.getElementById('wpa-model-base');
			if (!name) {
				window.alert('نام مدل لازم است.');
				return;
			}
			saveBtn.disabled = true;
			api('agent_wp_save_model', {
				provider: 'gapgpt',
				model_name: name,
				api_key: key,
				base_url: baseEl ? baseEl.value : '',
			})
				.then(function (json) {
					saveBtn.disabled = false;
					if (!json || !json.success) {
						window.alert((json && json.data && json.data.message) || i18n.saveFail || 'خطا');
						return;
					}
					modelsCache = (json.data && json.data.models) || [];
					pickDefaultModel(true);
					if (keyEl) keyEl.value = '';
					renderSavedModels();
					try {
						window.localStorage.removeItem('agentWpModels');
					} catch (err) {}
					closeSettings();
				})
				.catch(function () {
					saveBtn.disabled = false;
					window.alert(i18n.saveFail || 'خطا');
				});
		});

		function applyKeyToUi(data) {
			if (!data) return;
			if (data.keyMask) {
				var maskEl = document.getElementById('wpa-api-key-mask');
				if (maskEl) maskEl.textContent = data.keyMask;
				var menuSub = document.getElementById('wpa-api-menu-sub');
				if (menuSub) menuSub.textContent = 'ذخیره‌شده: ' + data.keyMask;
			}
			if (data.baseUrl) {
				var baseEl = document.getElementById('wpa-model-base');
				if (baseEl) baseEl.value = data.baseUrl;
			}
			if (data.models) {
				modelsCache = data.models;
				renderSavedModels();
				pickDefaultModel(true);
			}
		}

		function applyGapgptEndpoint(mode) {
			api('agent_wp_set_gapgpt_endpoint', { mode: mode }).then(function (json) {
				if (!json || !json.success) {
					window.alert((json && json.data && json.data.message) || 'خطا در تغییر آدرس');
					return;
				}
				var baseEl = document.getElementById('wpa-model-base');
				if (baseEl && json.data.baseUrl) baseEl.value = json.data.baseUrl;
				if (json.data.models) {
					modelsCache = json.data.models;
					renderSavedModels();
				}
			});
		}

		Array.prototype.forEach.call(document.querySelectorAll('input[name="gapgpt_mode"]'), function (radio) {
			radio.addEventListener('change', function () {
				if (radio.checked) applyGapgptEndpoint(radio.value);
			});
		});

		var apiSaveBtn = document.getElementById('wpa-api-save');
		var apiHint = document.getElementById('wpa-api-hint');
		if (apiSaveBtn) {
			apiSaveBtn.addEventListener('click', function () {
				var keyEl = document.getElementById('wpa-api-key');
				var key = keyEl ? keyEl.value.trim() : '';
				if (!key) {
					window.alert('کلید جدید را وارد کنید.');
					return;
				}
				apiSaveBtn.disabled = true;
				api('agent_wp_save_gapgpt_api', { api_key: key })
					.then(function (json) {
						apiSaveBtn.disabled = false;
						if (!json || !json.success) {
							if (apiHint) {
								apiHint.hidden = false;
								apiHint.textContent =
									(json && json.data && json.data.message) || 'ذخیره ناموفق';
							}
							return;
						}
						applyKeyToUi(json.data);
						if (apiHint) {
							apiHint.hidden = false;
							apiHint.textContent = (json.data && json.data.message) || 'کلید ذخیره شد';
						}
						if (keyEl) keyEl.value = '';
					})
					.catch(function () {
						apiSaveBtn.disabled = false;
						if (apiHint) {
							apiHint.hidden = false;
							apiHint.textContent = 'خطای شبکه';
						}
					});
			});
		}

		var apiTestBtn = document.getElementById('wpa-api-test');
		if (apiTestBtn) {
			apiTestBtn.addEventListener('click', function () {
				apiTestBtn.disabled = true;
				api('agent_wp_gapgpt_test')
					.then(function (json) {
						apiTestBtn.disabled = false;
						if (apiHint) {
							apiHint.hidden = false;
							apiHint.textContent =
								(json && json.data && json.data.message) ||
								(json && json.success ? 'اتصال موفق' : 'تست ناموفق');
						}
					})
					.catch(function () {
						apiTestBtn.disabled = false;
						if (apiHint) {
							apiHint.hidden = false;
							apiHint.textContent = 'خطای شبکه';
						}
					});
			});
		}

		var syncBtn = document.getElementById('wpa-sync-gapgpt-models');
		if (syncBtn) {
			syncBtn.addEventListener('click', function () {
				syncBtn.disabled = true;
				api('agent_wp_sync_gapgpt_models', {})
					.then(function (json) {
						syncBtn.disabled = false;
						if (!json || !json.success) {
							window.alert((json && json.data && json.data.message) || 'همگام‌سازی ناموفق');
							return;
						}
						modelsCache = (json.data && json.data.models) || [];
						if (json.data && json.data.toolsCatalog) {
							toolsCatalogRaw = json.data.toolsCatalog;
							toolsCatalogMap = normalizeToolsCatalog();
						}
						pickDefaultModel(true);
						renderSavedModels();
						window.alert((json.data && json.data.message) || 'همگام شد');
					})
					.catch(function () {
						syncBtn.disabled = false;
						window.alert('خطای شبکه');
					});
			});
		}

		document.addEventListener('click', closePopovers);
		plusMenu.addEventListener('click', function (e) {
			e.stopPropagation();
		});
		modelMenu.addEventListener('click', function (e) {
			e.stopPropagation();
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				closePopovers();
				closeSettings();
				closeRenameDialog();
				closeMsgSearch();
				if (editingMessageId) cancelEditMessage();
				if (replyTo) cancelReplyTo();
			}
		});

		if (typeof mobileMq.addEventListener === 'function') {
			mobileMq.addEventListener('change', function () {
				if (!isMobile()) setView('chat');
			});
		}

		refreshModels().then(function () {
			preferredImageModel = loadPreferredImageModel();
			preferredVideoModel = loadPreferredVideoModel();
			preferredAudioModel = loadPreferredAudioModel();
			var remembered = loadRememberedModelId();
			if (remembered === 'auto' || (!remembered && cfg.preferAutoModel !== false)) {
				rememberSelectedModel('auto');
			} else if (remembered) {
				selectedModelId = remembered;
				refreshModelChipLabel();
			} else if (cfg.defaultModelId) {
				selectedModelId = String(cfg.defaultModelId);
				refreshModelChipLabel();
			}
			pickDefaultModel(true);
			renderModelMenu(modelsCache);
		});
		bootstrapChats();
		setSendMode();
		if (creditRefreshBtn) {
			creditRefreshBtn.addEventListener('click', function (e) {
				e.preventDefault();
				e.stopPropagation();
				refreshCredit(true);
			});
		}

		var assistantEnabled = document.getElementById('wpa-assistant-enabled');
		var assistantTopic = document.getElementById('wpa-assistant-topic');
		var assistantFabSide = document.getElementById('wpa-assistant-fab-side');
		var assistantInterval = document.getElementById('wpa-assistant-interval');
		var assistantQuiet = document.getElementById('wpa-assistant-quiet');
		var assistantNotify = document.getElementById('wpa-assistant-notify');
		var assistantSound = document.getElementById('wpa-assistant-sound');
		var assistantDebug = document.getElementById('wpa-assistant-debug');
		var assistantDigestMail = document.getElementById('wpa-assistant-digest-mail');
		var assistantSurface = document.getElementById('wpa-assistant-surface');
		var assistantMaxOpen = document.getElementById('wpa-assistant-max-open');
		var assistantNote = document.getElementById('wpa-assistant-note');
		var assistantSave = document.getElementById('wpa-assistant-save');
		var assistantPurge = document.getElementById('wpa-assistant-purge');
		var assistantHealth = document.getElementById('wpa-assistant-health');
		var gapgptTestBtn = document.getElementById('wpa-gapgpt-test');
		var toolStatsBtn = document.getElementById('wpa-tool-stats');
		var toolStatsBox = document.getElementById('wpa-tool-stats-box');
		var assistantHint = document.getElementById('wpa-assistant-hint');
		if (assistantSave) {
			assistantSave.addEventListener('click', function () {
				assistantSave.disabled = true;
				var modules = {};
				Array.prototype.forEach.call(document.querySelectorAll('#wpa-assistant-modules [data-module]'), function (el) {
					modules[el.getAttribute('data-module')] = el.checked ? 1 : 0;
				});
				api('agent_wp_assistant_set', {
					enabled: assistantEnabled && assistantEnabled.checked ? '1' : '0',
					topic: assistantTopic ? assistantTopic.value : '',
					fab_side: assistantFabSide ? assistantFabSide.value : 'left',
					interval_hours: assistantInterval ? assistantInterval.value : '6',
					quiet: assistantQuiet && assistantQuiet.checked ? '1' : '0',
					notify: assistantNotify && assistantNotify.checked ? '1' : '0',
					sound: assistantSound && assistantSound.checked ? '1' : '0',
					debug: assistantDebug && assistantDebug.checked ? '1' : '0',
					digest_mail: assistantDigestMail && assistantDigestMail.checked ? '1' : '0',
					surface: assistantSurface ? assistantSurface.value : 'both',
					max_open: assistantMaxOpen ? assistantMaxOpen.value : '40',
					note: assistantNote ? assistantNote.value : '',
					modules: JSON.stringify(modules),
				})
					.then(function (json) {
						assistantSave.disabled = false;
						if (assistantHint) {
							assistantHint.hidden = false;
							assistantHint.textContent =
								(json && json.data && json.data.message) ||
								(json && json.success ? 'ذخیره شد' : 'خطا');
						}
					})
					.catch(function () {
						assistantSave.disabled = false;
						if (assistantHint) {
							assistantHint.hidden = false;
							assistantHint.textContent = 'خطای شبکه';
						}
					});
			});
		}
		if (assistantPurge) {
			assistantPurge.addEventListener('click', function () {
				if (!window.confirm('پیشنهادهای انجام‌شده/ردشدهٔ قدیمی‌تر از ۱۴ روز پاک شوند؟')) return;
				assistantPurge.disabled = true;
				api('agent_wp_assistant_purge', { days: '14' })
					.then(function (json) {
						assistantPurge.disabled = false;
						if (assistantHint) {
							assistantHint.hidden = false;
							assistantHint.textContent =
								(json && json.data && json.data.message) || 'انجام شد';
						}
					})
					.catch(function () {
						assistantPurge.disabled = false;
					});
			});
		}
		if (assistantHealth) {
			assistantHealth.addEventListener('click', function () {
				assistantHealth.disabled = true;
				api('agent_wp_health')
					.then(function (json) {
						assistantHealth.disabled = false;
						if (assistantHint) {
							assistantHint.hidden = false;
							assistantHint.textContent =
								(json && json.data && json.data.summary) || 'بررسی شد';
						}
					})
					.catch(function () {
						assistantHealth.disabled = false;
					});
			});
		}
		if (gapgptTestBtn) {
			gapgptTestBtn.addEventListener('click', function () {
				gapgptTestBtn.disabled = true;
				api('agent_wp_gapgpt_test')
					.then(function (json) {
						gapgptTestBtn.disabled = false;
						if (assistantHint) {
							assistantHint.hidden = false;
							assistantHint.textContent =
								(json && json.data && json.data.message) ||
								(json && json.success ? 'OK' : 'خطا');
						}
					})
					.catch(function () {
						gapgptTestBtn.disabled = false;
					});
			});
		}
		if (toolStatsBtn) {
			toolStatsBtn.addEventListener('click', function () {
				toolStatsBtn.disabled = true;
				api('agent_wp_tool_stats')
					.then(function (json) {
						toolStatsBtn.disabled = false;
						if (!toolStatsBox) return;
						var stats = (json && json.data && json.data.stats) || [];
						toolStatsBox.hidden = false;
						toolStatsBox.textContent = stats.length
							? stats
									.map(function (s) {
										return s.tool + ': ' + s.total + ' (ok ' + s.done + ')';
									})
									.join('\n')
							: 'آماری نیست';
					})
					.catch(function () {
						toolStatsBtn.disabled = false;
					});
			});
		}

		if (!isMobile()) input.focus();
	});
})();
