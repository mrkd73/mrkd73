<?php
/**
 * مارک‌آپ صفحه چت — نزدیک به Telegram Web/Desktop.
 * چرا فقط UI: فاز صفر؛ مدل/اتچ فعلاً ظاهری است.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$current_user  = wp_get_current_user();
$display_name  = $current_user instanceof WP_User ? $current_user->display_name : __( 'مدیر', 'agent-wp' );
$avatar_letter = function_exists( 'mb_substr' )
	? mb_substr( $display_name, 0, 1, 'UTF-8' )
	: substr( $display_name, 0, 1 );
?>
<div class="wpa-app" id="wpa-app" dir="rtl" lang="fa" data-view="chat">
	<aside class="wpa-sidebar" aria-label="<?php esc_attr_e( 'فهرست گفتگوها', 'agent-wp' ); ?>">
		<header class="wpa-sidebar__header">
			<button type="button" class="wpa-icon-btn" id="wpa-menu-btn" aria-label="<?php esc_attr_e( 'منو و تنظیمات', 'agent-wp' ); ?>">
				<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/></svg>
			</button>
			<div class="wpa-search">
				<svg class="wpa-search__icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
				<input type="search" class="wpa-search__input" id="wpa-search" placeholder="<?php esc_attr_e( 'جستجو', 'agent-wp' ); ?>" autocomplete="off" />
			</div>
		</header>

		<div class="wpa-chat-list" id="wpa-chat-list" role="list">
			<div class="wpa-sidebar-loading" id="wpa-sidebar-loading" aria-hidden="true">
				<div class="wpa-sidebar-skel"></div>
				<div class="wpa-sidebar-skel"></div>
				<div class="wpa-sidebar-skel"></div>
			</div>
		</div>

		<button type="button" class="wpa-fab" id="wpa-new-chat" aria-label="<?php esc_attr_e( 'گفتگوی جدید', 'agent-wp' ); ?>" title="<?php esc_attr_e( 'گفتگوی جدید', 'agent-wp' ); ?>">
			<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
		</button>
	</aside>

	<section class="wpa-main" aria-label="<?php esc_attr_e( 'پنجره گفتگو', 'agent-wp' ); ?>">
		<header class="wpa-topbar">
			<div class="wpa-topbar__peer">
				<button type="button" class="wpa-icon-btn wpa-back" id="wpa-back" aria-label="<?php esc_attr_e( 'بازگشت به فهرست', 'agent-wp' ); ?>">
					<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M8.59 16.59 13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
				</button>
				<button type="button" class="wpa-icon-btn wpa-tools-back" id="wpa-tools-back" hidden aria-label="<?php esc_attr_e( 'بازگشت', 'agent-wp' ); ?>">
					<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M8.59 16.59 13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
				</button>
				<div class="wpa-avatar wpa-avatar--assistant wpa-avatar--sm" id="wpa-top-avatar" aria-hidden="true">
					<svg viewBox="0 0 24 24" width="22" height="22"><path fill="#fff" d="M12 2a10 10 0 1 0 .001 20.001A10 10 0 0 0 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
				</div>
				<div class="wpa-topbar__meta">
					<div class="wpa-topbar__title" id="wpa-top-title"><?php esc_html_e( 'دستیار', 'agent-wp' ); ?></div>
					<div class="wpa-topbar__status" id="wpa-status"><?php esc_html_e( 'آنلاین', 'agent-wp' ); ?></div>
				</div>
			</div>
			<div class="wpa-topbar__actions">
				<button type="button" class="wpa-icon-btn" id="wpa-msg-search-btn" aria-expanded="false" aria-controls="wpa-msg-search" aria-label="<?php esc_attr_e( 'جستجو در گفتگو', 'agent-wp' ); ?>">
					<svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
				</button>
				<div class="wpa-more-wrap">
					<button type="button" class="wpa-icon-btn" id="wpa-more-btn" aria-expanded="false" aria-controls="wpa-chat-menu" aria-label="<?php esc_attr_e( 'گزینه‌های گفتگو', 'agent-wp' ); ?>">
						<svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M12 8c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm0 2c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zm0 6c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/></svg>
					</button>
					<div class="wpa-pop-menu" id="wpa-chat-menu" hidden role="menu">
						<button type="button" class="wpa-pop-menu__item" id="wpa-chat-rename" role="menuitem"><?php esc_html_e( 'ویرایش نام', 'agent-wp' ); ?></button>
						<button type="button" class="wpa-pop-menu__item" id="wpa-chat-pin" role="menuitem"><?php esc_html_e( 'پین / برداشتن پین', 'agent-wp' ); ?></button>
						<button type="button" class="wpa-pop-menu__item" id="wpa-chat-archive" role="menuitem"><?php esc_html_e( 'آرشیو', 'agent-wp' ); ?></button>
						<button type="button" class="wpa-pop-menu__item" id="wpa-chat-regenerate" role="menuitem"><?php esc_html_e( 'بازتولید آخرین پاسخ', 'agent-wp' ); ?></button>
						<button type="button" class="wpa-pop-menu__item" id="wpa-chat-export" role="menuitem"><?php esc_html_e( 'خروجی JSON گفتگو', 'agent-wp' ); ?></button>
						<button type="button" class="wpa-pop-menu__item wpa-pop-menu__item--danger" id="wpa-chat-delete" role="menuitem"><?php esc_html_e( 'حذف گفتگو', 'agent-wp' ); ?></button>
					</div>
				</div>
			</div>
		</header>

		<div class="wpa-msg-search" id="wpa-msg-search" hidden>
			<input type="search" class="wpa-msg-search__input" id="wpa-msg-search-input" placeholder="<?php esc_attr_e( 'جستجو در این گفتگو…', 'agent-wp' ); ?>" autocomplete="off" />
			<span class="wpa-msg-search__count" id="wpa-msg-search-count"></span>
			<button type="button" class="wpa-icon-btn" id="wpa-msg-search-prev" aria-label="<?php esc_attr_e( 'قبلی', 'agent-wp' ); ?>">
				<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M7.41 15.41 12 10.83l4.59 4.58L18 14l-6-6-6 6z"/></svg>
			</button>
			<button type="button" class="wpa-icon-btn" id="wpa-msg-search-next" aria-label="<?php esc_attr_e( 'بعدی', 'agent-wp' ); ?>">
				<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6z"/></svg>
			</button>
			<button type="button" class="wpa-icon-btn" id="wpa-msg-search-close" aria-label="<?php esc_attr_e( 'بستن', 'agent-wp' ); ?>">
				<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
			</button>
		</div>

		<div class="wpa-messages" id="wpa-messages" role="log" aria-live="polite">
			<div class="wpa-messages__pattern" aria-hidden="true"></div>
			<div class="wpa-net-banner" id="wpa-net-banner" hidden><?php esc_html_e( 'اتصال برقرار نیست — برای تلاش دوباره کلیک کنید', 'agent-wp' ); ?></div>
			<div class="wpa-messages__inner" id="wpa-messages-inner">
				<div class="wpa-loading" id="wpa-loading">
					<div class="wpa-loading__card">
						<div class="wpa-loading__dots" aria-hidden="true"><span></span><span></span><span></span></div>
						<div class="wpa-loading__text"><?php esc_html_e( 'در حال بارگذاری…', 'agent-wp' ); ?></div>
					</div>
				</div>
				<div class="wpa-empty is-hidden" id="wpa-empty">
					<div class="wpa-empty__card">
						<div class="wpa-empty__title"><?php esc_html_e( 'هنوز پیامی نیست', 'agent-wp' ); ?></div>
						<div class="wpa-empty__hint"><?php esc_html_e( 'بگو چه می‌خواهی؛ اول سایت را کشف می‌کند، بعد با REST/محتوا/متا تغییر می‌دهد.', 'agent-wp' ); ?></div>
					</div>
				</div>
			</div>
			<button type="button" class="wpa-scroll-down" id="wpa-scroll-down" hidden aria-label="<?php esc_attr_e( 'رفتن به پایین', 'agent-wp' ); ?>">
				<svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M7.41 8.59 12 13.17l4.59-4.58L18 10l-6 6-6-6z"/></svg>
			</button>
		</div>

		<div class="wpa-tools-view" id="wpa-tools-view" hidden>
			<div class="wpa-tools-view__hint" id="wpa-tools-hint"><?php esc_html_e( 'یک دسته انتخاب کنید تا مدل‌های مرتبط را ببینید.', 'agent-wp' ); ?></div>
			<div class="wpa-tools-list" id="wpa-tools-list" role="list"></div>
		</div>

		<div class="wpa-composer-wrap">
			<div class="wpa-edit-bar" id="wpa-edit-bar" hidden>
				<button type="button" class="wpa-icon-btn" id="wpa-edit-cancel" aria-label="<?php esc_attr_e( 'لغو ویرایش', 'agent-wp' ); ?>">
					<svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
				</button>
				<div class="wpa-edit-bar__meta">
					<div class="wpa-edit-bar__title"><?php esc_html_e( 'ویرایش پیام', 'agent-wp' ); ?></div>
					<div class="wpa-edit-bar__preview" id="wpa-edit-preview"></div>
				</div>
			</div>

			<div class="wpa-reply-bar" id="wpa-reply-bar" hidden>
				<button type="button" class="wpa-icon-btn" id="wpa-reply-cancel" aria-label="<?php esc_attr_e( 'لغو پاسخ', 'agent-wp' ); ?>">
					<svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
				</button>
				<div class="wpa-reply-bar__meta">
					<div class="wpa-reply-bar__title"><?php esc_html_e( 'پاسخ', 'agent-wp' ); ?></div>
					<div class="wpa-reply-bar__preview" id="wpa-reply-preview"></div>
				</div>
			</div>

			<div class="wpa-send-queue" id="wpa-send-queue" hidden aria-live="polite"></div>

			<div class="wpa-attach-bar" id="wpa-attach-bar" hidden></div>

			<footer class="wpa-composer">
				<div class="wpa-composer__tools">
					<div class="wpa-plus-wrap">
						<button type="button" class="wpa-icon-btn wpa-plus" id="wpa-plus-btn" aria-expanded="false" aria-controls="wpa-plus-menu" aria-label="<?php esc_attr_e( 'افزودن', 'agent-wp' ); ?>">
							<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
						</button>
						<div class="wpa-plus-menu" id="wpa-plus-menu" hidden role="menu">
							<button type="button" class="wpa-plus-menu__item" id="wpa-attach-image-btn" role="menuitem">
								<span class="wpa-plus-menu__icon" aria-hidden="true">
									<svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>
								</span>
								<span><?php esc_html_e( 'تصویر', 'agent-wp' ); ?></span>
							</button>
							<button type="button" class="wpa-plus-menu__item" id="wpa-attach-btn" role="menuitem">
								<span class="wpa-plus-menu__icon" aria-hidden="true">
									<svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M16.5 6v11.5c0 2.21-1.79 4-4 4s-4-1.79-4-4V5a2.5 2.5 0 0 1 5 0v10.5c0 .55-.45 1-1 1s-1-.45-1-1V6H10v9.5a2.5 2.5 0 0 0 5 0V5c0-1.93-1.57-3.5-3.5-3.5S8 3.07 8 5v12.5c0 3.04 2.46 5.5 5.5 5.5s5.5-2.46 5.5-5.5V6h-2.5z"/></svg>
								</span>
								<span><?php esc_html_e( 'فایل', 'agent-wp' ); ?></span>
							</button>
						</div>
					</div>

					<button type="button" class="wpa-model-btn" id="wpa-model-btn" aria-expanded="false" aria-controls="wpa-model-menu">
						<span class="wpa-model-btn__label" id="wpa-model-label"><?php esc_html_e( 'خودکار', 'agent-wp' ); ?></span>
						<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M7 10l5 5 5-5z"/></svg>
					</button>
					<div class="wpa-model-menu" id="wpa-model-menu" hidden role="listbox">
						<button type="button" class="wpa-model-menu__item is-active" data-model="none" role="option"><?php esc_html_e( 'مدلی انتخاب نشده', 'agent-wp' ); ?></button>
						<button type="button" class="wpa-model-menu__item is-muted" data-model="hint" role="option" disabled><?php esc_html_e( 'مدل‌ها را از تنظیمات اضافه کنید', 'agent-wp' ); ?></button>
					</div>
				</div>

				<div class="wpa-composer__shell">
					<textarea
						id="wpa-input"
						class="wpa-composer__input"
						rows="1"
						placeholder="<?php esc_attr_e( 'پیام', 'agent-wp' ); ?>"
						autocomplete="off"
					></textarea>
				</div>

				<button type="button" class="wpa-stop" id="wpa-stop" hidden aria-label="<?php esc_attr_e( 'توقف درخواست فعلی', 'agent-wp' ); ?>" title="<?php esc_attr_e( 'توقف', 'agent-wp' ); ?>">
					<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M6 6h12v12H6z"/></svg>
				</button>

				<button type="button" class="wpa-send" id="wpa-send" aria-label="<?php esc_attr_e( 'ارسال', 'agent-wp' ); ?>" data-mode="mic">
					<span class="wpa-send__icon wpa-send__icon--mic" aria-hidden="true">
						<svg viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5-3c0 2.76-2.24 5-5 5s-5-2.24-5-5H5c0 3.53 2.61 6.43 6 6.92V21h2v-3.08c3.39-.49 6-3.39 6-6.92h-2z"/></svg>
					</span>
					<span class="wpa-send__icon wpa-send__icon--send" aria-hidden="true">
						<svg viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M2.01 21 23 12 2.01 3 2 10l15 2-15 2z"/></svg>
					</span>
					<span class="wpa-send__icon wpa-send__icon--check" aria-hidden="true">
						<svg viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
					</span>
				</button>

				<input type="file" id="wpa-file-input" class="wpa-file-input" hidden multiple accept="image/*,.pdf,.txt,.csv,.doc,.docx,.xls,.xlsx,.zip,.json,.md,.css,.js,.html" />
				<input type="file" id="wpa-image-input" class="wpa-file-input" hidden multiple accept="image/*" />
			</footer>
		</div>
	</section>

	<div class="wpa-pop-menu wpa-pop-menu--float" id="wpa-msg-menu" hidden role="menu">
		<button type="button" class="wpa-pop-menu__item" id="wpa-msg-copy" role="menuitem"><?php esc_html_e( 'کپی متن', 'agent-wp' ); ?></button>
		<button type="button" class="wpa-pop-menu__item" id="wpa-msg-edit" role="menuitem"><?php esc_html_e( 'ویرایش', 'agent-wp' ); ?></button>
		<button type="button" class="wpa-pop-menu__item wpa-pop-menu__item--danger" id="wpa-msg-delete" role="menuitem"><?php esc_html_e( 'حذف', 'agent-wp' ); ?></button>
	</div>

	<div class="wpa-dialog" id="wpa-rename-dialog" hidden>
		<div class="wpa-dialog__backdrop" id="wpa-rename-backdrop"></div>
		<div class="wpa-dialog__card" role="dialog" aria-modal="true" aria-labelledby="wpa-rename-title">
			<h3 class="wpa-dialog__title" id="wpa-rename-title"><?php esc_html_e( 'ویرایش نام گفتگو', 'agent-wp' ); ?></h3>
			<input type="text" class="wpa-dialog__input" id="wpa-rename-input" maxlength="80" autocomplete="off" />
			<div class="wpa-dialog__actions">
				<button type="button" class="wpa-dialog__btn" id="wpa-rename-cancel"><?php esc_html_e( 'انصراف', 'agent-wp' ); ?></button>
				<button type="button" class="wpa-dialog__btn wpa-dialog__btn--primary" id="wpa-rename-save"><?php esc_html_e( 'ذخیره', 'agent-wp' ); ?></button>
			</div>
		</div>
	</div>

	<!-- تنظیمات شبیه تلگرام: اول منو با آیکون، بعد صفحه جزئیات -->
	<div class="wpa-settings" id="wpa-settings" hidden>
		<div class="wpa-settings__backdrop" id="wpa-settings-backdrop"></div>
		<div class="wpa-settings__panel" id="wpa-settings-panel" role="dialog" aria-modal="true" aria-labelledby="wpa-settings-title" data-page="menu">
			<header class="wpa-settings__header">
				<button type="button" class="wpa-icon-btn" id="wpa-settings-nav" aria-label="<?php esc_attr_e( 'بستن', 'agent-wp' ); ?>">
					<svg class="wpa-settings__icon-close" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
					<svg class="wpa-settings__icon-back" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path fill="currentColor" d="M8.59 16.59 13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg>
				</button>
				<h2 class="wpa-settings__title" id="wpa-settings-title"><?php esc_html_e( 'تنظیمات', 'agent-wp' ); ?></h2>
			</header>

			<div class="wpa-settings__pages">
				<!-- صفحه ۱: منوی تنظیمات (مثل تلگرام) -->
				<section class="wpa-settings__page" data-page="menu" id="wpa-settings-page-menu">
					<div class="wpa-credit-card" id="wpa-credit-card" aria-live="polite">
						<div class="wpa-credit-card__head">
							<span class="wpa-credit-card__label" id="wpa-credit-label"><?php esc_html_e( 'اعتبار GapGPT', 'agent-wp' ); ?></span>
							<button type="button" class="wpa-credit-card__refresh" id="wpa-credit-refresh" aria-label="<?php esc_attr_e( 'بروزرسانی اعتبار', 'agent-wp' ); ?>">
								<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M12 6V3L8 7l4 4V8c2.76 0 5 2.24 5 5a5 5 0 0 1-8.9 3.1L6.7 17.5A7 7 0 0 0 19 13c0-3.87-3.13-7-7-7zm-7 7c0-1.1.25-2.14.7-3.07L4.3 8.5A7 7 0 0 0 5 19h3v-2H5c-.55 0-1-.45-1-1z"/></svg>
							</button>
						</div>
						<div class="wpa-credit-card__balance" id="wpa-credit-balance">—</div>
						<div class="wpa-credit-card__detail" id="wpa-credit-detail"><?php esc_html_e( 'در حال خواندن موجودی…', 'agent-wp' ); ?></div>
						<div class="wpa-credit-card__warn" id="wpa-credit-warn" hidden><?php esc_html_e( 'اعتبار کم است؛ برای ادامه کار شارژ کنید.', 'agent-wp' ); ?></div>
						<a class="wpa-credit-card__charge" id="wpa-credit-charge" href="https://gapgpt.app/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'شارژ حساب GapGPT', 'agent-wp' ); ?></a>
					</div>

					<div class="wpa-settings__section">
						<button type="button" class="wpa-settings-item" data-open-page="api">
							<span class="wpa-settings-item__icon" style="--ico:#3390ec" aria-hidden="true">
								<svg viewBox="0 0 24 24" width="22" height="22"><path fill="#fff" d="M12 1 3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg>
							</span>
							<span class="wpa-settings-item__text">
								<span class="wpa-settings-item__title"><?php esc_html_e( 'کلید API پیش‌فرض', 'agent-wp' ); ?></span>
								<span class="wpa-settings-item__sub" id="wpa-api-menu-sub"><?php
									$mask = Agent_WP_Models::get_gapgpt_key_mask();
									echo $mask
										? esc_html( sprintf( /* translators: %s masked key */ __( 'ذخیره‌شده: %s', 'agent-wp' ), $mask ) )
										: esc_html__( 'هنوز کلیدی ذخیره نشده', 'agent-wp' );
								?></span>
							</span>
							<span class="wpa-settings-item__chev" aria-hidden="true">
								<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
							</span>
						</button>
						<button type="button" class="wpa-settings-item" data-open-page="assistant">
							<span class="wpa-settings-item__icon" style="--ico:#f2a93b" aria-hidden="true">
								<svg viewBox="0 0 24 24" width="22" height="22"><path fill="#fff" d="M12 2a10 10 0 1 0 .001 20.001A10 10 0 0 0 12 2zm1 5h-2v6h2V7zm0 8h-2v2h2v-2z"/></svg>
							</span>
							<span class="wpa-settings-item__text">
								<span class="wpa-settings-item__title"><?php esc_html_e( 'دستیار زنده سایت', 'agent-wp' ); ?></span>
								<span class="wpa-settings-item__sub"><?php esc_html_e( 'ویجت شناور، اسکن و پیشنهادها', 'agent-wp' ); ?></span>
							</span>
							<span class="wpa-settings-item__chev" aria-hidden="true">
								<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
							</span>
						</button>
						<button type="button" class="wpa-settings-item" data-open-page="add-model">
							<span class="wpa-settings-item__icon" style="--ico:#8e6cc9" aria-hidden="true">
								<svg viewBox="0 0 24 24" width="22" height="22"><path fill="#fff" d="M12 2a10 10 0 1 0 .001 20.001A10 10 0 0 0 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
							</span>
							<span class="wpa-settings-item__text">
								<span class="wpa-settings-item__title"><?php esc_html_e( 'افزودن مدل هوش مصنوعی', 'agent-wp' ); ?></span>
								<span class="wpa-settings-item__sub"><?php esc_html_e( 'مدل‌های زبانی از درگاه GapGPT', 'agent-wp' ); ?></span>
							</span>
							<span class="wpa-settings-item__chev" aria-hidden="true">
								<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
							</span>
						</button>
						<button type="button" class="wpa-settings-item" data-open-page="action-log">
							<span class="wpa-settings-item__icon" style="--ico:#31a76c" aria-hidden="true">
								<svg viewBox="0 0 24 24" width="22" height="22"><path fill="#fff" d="M13 3a9 9 0 0 0-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42A8.954 8.954 0 0 0 13 21a9 9 0 0 0 0-18zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></svg>
							</span>
							<span class="wpa-settings-item__text">
								<span class="wpa-settings-item__title"><?php esc_html_e( 'تاریخچه اکشن‌ها', 'agent-wp' ); ?></span>
								<span class="wpa-settings-item__sub"><?php esc_html_e( 'لاگ Toolها و امکان rollback', 'agent-wp' ); ?></span>
							</span>
							<span class="wpa-settings-item__chev" aria-hidden="true">
								<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>
							</span>
						</button>
					</div>
				</section>

				<section class="wpa-settings__page" data-page="api" id="wpa-settings-page-api" hidden>
					<p class="wpa-settings__hint"><?php esc_html_e( 'همین کلید برای همه مدل‌های GapGPT استفاده می‌شود. کلید جدید را وارد و ذخیره کنید.', 'agent-wp' ); ?></p>

					<div class="wpa-tg-section">
						<div class="wpa-tg-row wpa-tg-row--stack">
							<span class="wpa-tg-row__label"><?php esc_html_e( 'کلید فعلی', 'agent-wp' ); ?></span>
							<code class="wpa-api-active-url" id="wpa-api-key-mask" dir="ltr"><?php echo esc_html( Agent_WP_Models::get_gapgpt_key_mask() ? Agent_WP_Models::get_gapgpt_key_mask() : '—' ); ?></code>
						</div>
						<div class="wpa-tg-row wpa-tg-row--stack">
							<span class="wpa-tg-row__label"><?php esc_html_e( 'کلید جدید', 'agent-wp' ); ?></span>
							<input type="password" class="wpa-tg-input" id="wpa-api-key" placeholder="sk-..." autocomplete="off" />
						</div>
					</div>

					<div class="wpa-tg-actions">
						<button type="button" class="wpa-btn-primary" id="wpa-api-save"><?php esc_html_e( 'ذخیره کلید', 'agent-wp' ); ?></button>
						<button type="button" class="wpa-btn-secondary" id="wpa-api-test"><?php esc_html_e( 'تست اتصال', 'agent-wp' ); ?></button>
						<p class="wpa-assistant-card__hint" id="wpa-api-hint" hidden></p>
					</div>
				</section>

				<section class="wpa-settings__page" data-page="assistant" id="wpa-settings-page-assistant" hidden>
					<p class="wpa-settings__hint"><?php esc_html_e( 'مثل تنظیمات تلگرام: هر گزینه یک ردیف. تغییرها با «ذخیره» اعمال می‌شوند.', 'agent-wp' ); ?></p>

					<div class="wpa-tg-section">
						<div class="wpa-tg-row">
							<span class="wpa-tg-row__label"><?php esc_html_e( 'فعال‌سازی دستیار', 'agent-wp' ); ?></span>
							<label class="wpa-switch">
								<input type="checkbox" id="wpa-assistant-enabled" <?php checked( Agent_WP_Assistant::is_enabled() ); ?> />
								<span class="wpa-switch__ui" aria-hidden="true"></span>
							</label>
						</div>
						<div class="wpa-tg-row wpa-tg-row--stack">
							<span class="wpa-tg-row__label"><?php esc_html_e( 'موضوع / هویت سایت', 'agent-wp' ); ?></span>
							<input type="text" class="wpa-tg-input" id="wpa-assistant-topic" value="<?php echo esc_attr( Agent_WP_Assistant::get_topic() ); ?>" placeholder="<?php esc_attr_e( 'مثلاً فروشگاه پوشاک زنانه', 'agent-wp' ); ?>" />
						</div>
						<div class="wpa-tg-row wpa-tg-row--stack">
							<span class="wpa-tg-row__label"><?php esc_html_e( 'یادداشت برای دستیار', 'agent-wp' ); ?></span>
							<textarea class="wpa-tg-input" id="wpa-assistant-note" rows="2" placeholder="<?php esc_attr_e( 'مثلاً لحن رسمی، تمرکز روی فروش…', 'agent-wp' ); ?>"><?php echo esc_textarea( Agent_WP_Assistant::get_note() ); ?></textarea>
						</div>
					</div>

					<div class="wpa-tg-section">
						<div class="wpa-tg-section__title"><?php esc_html_e( 'نمایش', 'agent-wp' ); ?></div>
						<div class="wpa-tg-row wpa-tg-row--stack">
							<span class="wpa-tg-row__label"><?php esc_html_e( 'موقعیت آیکن شناور', 'agent-wp' ); ?></span>
							<select class="wpa-tg-input" id="wpa-assistant-fab-side">
								<option value="left" <?php selected( Agent_WP_Assistant::get_fab_side(), 'left' ); ?>><?php esc_html_e( 'چپ', 'agent-wp' ); ?></option>
								<option value="right" <?php selected( Agent_WP_Assistant::get_fab_side(), 'right' ); ?>><?php esc_html_e( 'راست', 'agent-wp' ); ?></option>
							</select>
						</div>
						<div class="wpa-tg-row wpa-tg-row--stack">
							<span class="wpa-tg-row__label"><?php esc_html_e( 'نمایش ویجت', 'agent-wp' ); ?></span>
							<select class="wpa-tg-input" id="wpa-assistant-surface">
								<option value="both" <?php selected( Agent_WP_Assistant::get_surface(), 'both' ); ?>><?php esc_html_e( 'سایت + پیشخوان', 'agent-wp' ); ?></option>
								<option value="front" <?php selected( Agent_WP_Assistant::get_surface(), 'front' ); ?>><?php esc_html_e( 'فقط سایت', 'agent-wp' ); ?></option>
								<option value="admin" <?php selected( Agent_WP_Assistant::get_surface(), 'admin' ); ?>><?php esc_html_e( 'فقط پیشخوان', 'agent-wp' ); ?></option>
							</select>
						</div>
					</div>

					<div class="wpa-tg-section">
						<div class="wpa-tg-section__title"><?php esc_html_e( 'اعلان و زمان‌بندی', 'agent-wp' ); ?></div>
						<div class="wpa-tg-row wpa-tg-row--stack">
							<span class="wpa-tg-row__label"><?php esc_html_e( 'فاصله اسکن خودکار', 'agent-wp' ); ?></span>
							<select class="wpa-tg-input" id="wpa-assistant-interval">
								<option value="6" <?php selected( Agent_WP_Assistant::get_interval_hours(), 6 ); ?>><?php esc_html_e( 'هر ۶ ساعت', 'agent-wp' ); ?></option>
								<option value="12" <?php selected( Agent_WP_Assistant::get_interval_hours(), 12 ); ?>><?php esc_html_e( 'هر ۱۲ ساعت', 'agent-wp' ); ?></option>
								<option value="24" <?php selected( Agent_WP_Assistant::get_interval_hours(), 24 ); ?>><?php esc_html_e( 'هر ۲۴ ساعت', 'agent-wp' ); ?></option>
							</select>
						</div>
						<div class="wpa-tg-row">
							<span class="wpa-tg-row__label"><?php esc_html_e( 'ساعات سکوت (۰۰–۰۷)', 'agent-wp' ); ?></span>
							<label class="wpa-switch">
								<input type="checkbox" id="wpa-assistant-quiet" <?php checked( Agent_WP_Assistant::quiet_enabled() ); ?> />
								<span class="wpa-switch__ui" aria-hidden="true"></span>
							</label>
						</div>
						<div class="wpa-tg-row">
							<span class="wpa-tg-row__label"><?php esc_html_e( 'اعلان مرورگر', 'agent-wp' ); ?></span>
							<label class="wpa-switch">
								<input type="checkbox" id="wpa-assistant-notify" <?php checked( Agent_WP_Assistant::notify_enabled() ); ?> />
								<span class="wpa-switch__ui" aria-hidden="true"></span>
							</label>
						</div>
						<div class="wpa-tg-row">
							<span class="wpa-tg-row__label"><?php esc_html_e( 'صدای پیشنهاد تازه', 'agent-wp' ); ?></span>
							<label class="wpa-switch">
								<input type="checkbox" id="wpa-assistant-sound" <?php checked( Agent_WP_Assistant::sound_enabled() ); ?> />
								<span class="wpa-switch__ui" aria-hidden="true"></span>
							</label>
						</div>
						<div class="wpa-tg-row">
							<span class="wpa-tg-row__label"><?php esc_html_e( 'ایمیل خلاصه هفتگی', 'agent-wp' ); ?></span>
							<label class="wpa-switch">
								<input type="checkbox" id="wpa-assistant-digest-mail" <?php checked( Agent_WP_Assistant::digest_mail_enabled() ); ?> />
								<span class="wpa-switch__ui" aria-hidden="true"></span>
							</label>
						</div>
						<div class="wpa-tg-row">
							<span class="wpa-tg-row__label"><?php esc_html_e( 'دیباگ سرور', 'agent-wp' ); ?></span>
							<label class="wpa-switch">
								<input type="checkbox" id="wpa-assistant-debug" <?php checked( Agent_WP_Debug::enabled() ); ?> />
								<span class="wpa-switch__ui" aria-hidden="true"></span>
							</label>
						</div>
						<div class="wpa-tg-row wpa-tg-row--stack">
							<span class="wpa-tg-row__label"><?php esc_html_e( 'سقف پیشنهادهای باز', 'agent-wp' ); ?></span>
							<input type="number" min="5" max="100" class="wpa-tg-input" id="wpa-assistant-max-open" value="<?php echo esc_attr( (string) Agent_WP_Assistant::get_max_open() ); ?>" />
						</div>
					</div>

					<div class="wpa-tg-section">
						<div class="wpa-tg-section__title"><?php esc_html_e( 'ماژول‌های اسکن', 'agent-wp' ); ?></div>
						<div class="wpa-assistant-modules" id="wpa-assistant-modules">
							<?php
							$mods   = Agent_WP_Assistant::get_modules();
							$labels = array(
								'content'   => 'محتوا',
								'design'    => 'دیزاین',
								'seo'       => 'سئو',
								'technical' => 'فنی',
								'growth'    => 'رشد',
								'cleanup'   => 'پاکسازی',
								'foresight' => 'آینده‌نگری',
								'quality'   => 'کیفیت',
							);
							foreach ( $labels as $key => $lab ) :
								?>
								<label class="wpa-check">
									<input type="checkbox" data-module="<?php echo esc_attr( $key ); ?>" <?php checked( ! empty( $mods[ $key ] ) ); ?> />
									<?php echo esc_html( $lab ); ?>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="wpa-settings__hint" style="padding:8px 4px 0"><?php echo esc_html( Agent_WP_Assistant::last_scan_label() ); ?> · Alt+A</p>
					</div>

					<div class="wpa-tg-actions">
						<button type="button" class="wpa-btn-primary" id="wpa-assistant-save"><?php esc_html_e( 'ذخیره', 'agent-wp' ); ?></button>
						<button type="button" class="wpa-btn-secondary" id="wpa-assistant-purge"><?php esc_html_e( 'پاکسازی پیشنهادهای قدیمی', 'agent-wp' ); ?></button>
						<button type="button" class="wpa-btn-secondary" id="wpa-assistant-health"><?php esc_html_e( 'بررسی سلامت', 'agent-wp' ); ?></button>
						<button type="button" class="wpa-btn-secondary" id="wpa-gapgpt-test"><?php esc_html_e( 'تست GapGPT', 'agent-wp' ); ?></button>
						<button type="button" class="wpa-btn-secondary" id="wpa-tool-stats"><?php esc_html_e( 'آمار Toolها', 'agent-wp' ); ?></button>
						<pre class="wpa-assistant-card__stats" id="wpa-tool-stats-box" hidden></pre>
						<p class="wpa-assistant-card__hint" id="wpa-assistant-hint" hidden></p>
					</div>
				</section>

				<!-- صفحه: افزودن مدل -->
				<section class="wpa-settings__page" data-page="add-model" id="wpa-settings-page-add-model" hidden>
					<p class="wpa-settings__hint"><?php esc_html_e( 'مدل را انتخاب کنید. کلید پیش‌فرض از تنظیمات «کلید API پیش‌فرض» خوانده می‌شود.', 'agent-wp' ); ?></p>
					<form class="wpa-form" id="wpa-model-form" autocomplete="off">
						<input type="hidden" name="provider" id="wpa-model-provider" value="gapgpt" />
						<input type="hidden" name="base_url" id="wpa-model-base" value="<?php echo esc_attr( Agent_WP_Models::get_gapgpt_base() ); ?>" />

						<div class="wpa-endpoint-switch" role="group" aria-label="<?php esc_attr_e( 'آدرس API', 'agent-wp' ); ?>">
							<span class="wpa-field__label"><?php esc_html_e( 'آدرس API گپ‌جی‌پی‌تی', 'agent-wp' ); ?></span>
							<label class="wpa-endpoint-switch__opt">
								<input type="radio" name="gapgpt_mode" value="direct" id="wpa-gapgpt-mode-direct" <?php checked( Agent_WP_Models::get_gapgpt_mode() !== 'cdn' ); ?> />
								<span><?php esc_html_e( 'مستقیم', 'agent-wp' ); ?></span>
								<code>api.gapgpt.app</code>
							</label>
							<label class="wpa-endpoint-switch__opt">
								<input type="radio" name="gapgpt_mode" value="cdn" id="wpa-gapgpt-mode-cdn" <?php checked( Agent_WP_Models::get_gapgpt_mode(), 'cdn' ); ?> />
								<span><?php esc_html_e( 'CDN', 'agent-wp' ); ?></span>
								<code>api.gapapi.com</code>
							</label>
						</div>

						<p class="wpa-provider-hint" id="wpa-provider-hint">
							<?php esc_html_e( 'کلید مشترک همه مدل‌ها را از «کلید API پیش‌فرض» عوض کنید.', 'agent-wp' ); ?>
							<button type="button" class="wpa-link-btn" data-open-page="api"><?php esc_html_e( 'تغییر کلید', 'agent-wp' ); ?></button>
						</p>
						<label class="wpa-field">
							<span class="wpa-field__label"><?php esc_html_e( 'مدل', 'agent-wp' ); ?></span>
							<select class="wpa-field__control" name="model_name" id="wpa-model-name">
								<?php foreach ( Agent_WP_Models::gapgpt_catalog_by_category() as $group ) : ?>
									<optgroup label="<?php echo esc_attr( $group['label'] ); ?>">
										<?php foreach ( $group['items'] as $item ) : ?>
											<option value="<?php echo esc_attr( $item['id'] ); ?>">
												<?php
												echo esc_html(
													$item['label'] . ' · ' . ( isset( $item['family'] ) ? $item['family'] : '' ) . ' (' . $item['id'] . ')'
												);
												?>
											</option>
										<?php endforeach; ?>
									</optgroup>
								<?php endforeach; ?>
							</select>
							<span class="wpa-field__help"><?php esc_html_e( 'مدل‌ها بر اساس کاربرد دسته‌بندی شده‌اند: کدنویسی، استدلال، مینی، ساخت تصویر و …', 'agent-wp' ); ?></span>
						</label>
						<button type="submit" class="wpa-btn-primary" id="wpa-model-save"><?php esc_html_e( 'افزودن / به‌روزرسانی مدل', 'agent-wp' ); ?></button>
						<button type="button" class="wpa-btn-secondary" id="wpa-sync-gapgpt-models"><?php esc_html_e( 'همگام‌سازی همه مدل‌ها از GapGPT', 'agent-wp' ); ?></button>
					</form>
					<ul class="wpa-saved-models" id="wpa-saved-models" aria-label="<?php esc_attr_e( 'مدل‌های ذخیره‌شده', 'agent-wp' ); ?>"></ul>
				</section>

				<section class="wpa-settings__page" data-page="action-log" id="wpa-settings-page-action-log" hidden>
					<p class="wpa-settings__hint"><?php esc_html_e( 'آخرین اکشن‌های اجراشده. اکشن‌های موفق را می‌توانید برگردانید (rollback).', 'agent-wp' ); ?></p>
					<div class="wpa-action-log" id="wpa-action-log" aria-live="polite"></div>
				</section>
			</div>
		</div>
	</div>

	<template id="wpa-bubble-template">
		<div class="wpa-row" data-message-id="">
			<div class="wpa-bubble">
				<div class="wpa-bubble__reply" hidden>
					<div class="wpa-bubble__reply-title"><?php esc_html_e( 'پاسخ', 'agent-wp' ); ?></div>
					<div class="wpa-bubble__reply-preview"></div>
				</div>
				<div class="wpa-bubble__text"></div>
				<div class="wpa-bubble__atts" hidden></div>
				<div class="wpa-tools" hidden></div>
				<div class="wpa-bubble__meta">
					<span class="wpa-bubble__route" hidden></span>
					<span class="wpa-bubble__tokens" hidden></span>
					<span class="wpa-bubble__edited" hidden><?php esc_html_e( 'ویرایش‌شده', 'agent-wp' ); ?></span>
					<span class="wpa-bubble__time"></span>
					<span class="wpa-bubble__checks" aria-hidden="true">
						<svg class="wpa-checks" viewBox="0 0 18 10" width="18" height="10"><path fill="currentColor" d="M6.5 9.5 1.8 4.8l1.4-1.4 3.3 3.3L14.2.9l1.4 1.4z"/><path fill="currentColor" d="M9.2 9.5 4.5 4.8l1.4-1.4 3.3 3.3 6.7-6.8 1.4 1.4z" opacity=".9"/></svg>
					</span>
				</div>
			</div>
		</div>
	</template>

	<template id="wpa-tool-card-template">
		<div class="wpa-tool-card">
			<div class="wpa-tool-card__head">
				<span class="wpa-tool-card__badge"></span>
				<span class="wpa-tool-card__status"></span>
			</div>
			<div class="wpa-tool-card__body"></div>
			<div class="wpa-tool-card__actions"></div>
		</div>
	</template>

	<div class="wpa-user-chip" hidden data-letter="<?php echo esc_attr( $avatar_letter ); ?>"></div>
</div>
