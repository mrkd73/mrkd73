# Architecture — Agent WP 0.15

## اصل طلایی
برای هر افزونه/قالب جدید Tool اختصاصی ننویس.
با لایهٔ عمومی، هر قابلیتی که وردپرس یا افزونه از طریق CPT / meta / option / REST بدهد پوشش داده می‌شود.
برای کد: `list_files` / `read_file` / `write_file` روی کل قالب‌ها و افزونه‌ها.

## لایه‌ها
1. **UI تلگرامی** — پین/آرشیو/بازتولید/کپی
2. **دستیار زنده** — اسکن ماژولار، خلاصه‌محور، snooze
3. **Agent + LLM + Router** (GapGPT)
4. **Tool Registry** + Action Log (+ آمار) + Pending confirm
5. **Health / Debug / Uninstall**

## لایه دسترسی عمومی
| Tool | نقش |
|------|-----|
| `discover` | کشف (با کش کوتاه) |
| `wp_content` | CRUD هر post_type |
| `post_meta` | get/set متا |
| `rest` | REST با هویت ادمین |
| `update_option` | option با تأیید |
| فایل | خواندن/نوشتن `theme/` `parent/` `themes/` `plugin/` `mu-plugin/` `workspace/` |

## امنیت فایل
- بلاک مطلق: `wp-config`، `wp-admin`، `wp-includes`
- نوشتن قالب/افزونه: **اخطار + تأیید UI** (+ confirm دوم برای highRisk)
- قبل از نوشتن پرریسک: **نقطه بازگشت** در `uploads/agent-wp-workspace/restore-points/`
- rollback از تاریخچه اکشن محتوای قبلی را برمی‌گرداند

## دستیار
اسکن دوره‌ای → suggestions → ویجت خلاصه‌محور + auto-done / snooze.
