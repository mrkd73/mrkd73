(function (wp) {
	'use strict';
	if (!wp || !wp.plugins || !wp.editPost || !wp.element || !wp.components) return;
	var el = wp.element.createElement;
	var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
	var Button = wp.components.Button;
	var Fragment = wp.element.Fragment;
	var cfg = window.agentWpEditorAssist || {};
	var i18n = cfg.i18n || {};
	var supports = cfg.supports || { tags: true, excerpt: true, featured: true, content: true };

	function open(action) {
		var postId = 0;
		try {
			postId = wp.data.select('core/editor').getCurrentPostId();
		} catch (e) {}
		if (window.agentWpEditorAssistOpen) {
			window.agentWpEditorAssistOpen(action, { postId: postId });
		}
	}

	function btn(label, action, primary) {
		return el(Button, {
			isPrimary: !!primary,
			onClick: function () {
				open(action);
			},
			style: { marginBottom: '8px', width: '100%', justifyContent: 'center' },
		}, label);
	}

	function Panel() {
		var kids = [];
		if (supports.featured) {
			kids.push(btn(i18n.featuredShort || 'ساخت تصویر شاخص', 'featured', true));
		}
		if (supports.tags) {
			kids.push(btn(i18n.tagsShort || 'پیشنهاد برچسب‌ها', 'tags', false));
		}
		if (supports.excerpt) {
			kids.push(btn(i18n.excerptShort || 'نوشتن خلاصه', 'excerpt', false));
		}
		if (supports.content) {
			kids.push(btn(i18n.contentShort || 'افزودن بلوک متن', 'content_block', false));
		}
		if (!kids.length) return null;
		return el(
			PluginDocumentSettingPanel,
			{ name: 'agent-wp-assist', title: i18n.panelTitle || 'ایجنت', className: 'wpa-ed-block-panel' },
			el(Fragment, null, kids)
		);
	}

	wp.plugins.registerPlugin('agent-wp-editor-assist', { render: Panel });
})(window.wp);
