# پیکربندی TWA اندروید

- شناسهٔ ثابت اپ: `ir.rointansupplement.wpstoremanager`
- نشانی شروع مؤثر: `https://rointansupplement.ir/wp-content/plugins/wp-store-manager/wsm-app/index.html`
- Bubblewrap مسیر شروع را نسبت به `host` می‌گیرد؛ در `twa-manifest.json` این دو مقدار با هم همان URL دقیق را می‌سازند.
- `display` در پیکربندی Bubblewrap روی `fullscreen` است؛ وب‌مانیفست همچنان `standalone` دارد و `display_override` حالت تمام‌صفحه را هم معرفی می‌کند.
- حداقل نسخهٔ اندروید API 24 (Android 7) است؛ کتابخانهٔ فعلی Android Browser Helper به API 24 نیاز دارد.
- `assetlinks.json` را پس از ساخت APK و استفاده از همین کلید، در ریشهٔ سایت در `/.well-known/assetlinks.json` منتشر کنید. تا آن زمان Chrome ممکن است Custom Tab و نوار مرورگر نشان دهد.
- APK امضاشدهٔ نسخهٔ 1.0.0 با Bubblewrap 1.27.0 ساخته شده و خروجی آن `app-release-signed.apk` است. گواهی APK باید با اثرانگشت SHA-256 بالا و `assetlinks.json` تطبیق داشته باشد.
- فایل کلید امضای خصوصی داخل این پروژه/ZIP نیست؛ در ساخت‌های بعدی `release-key.jks` را جداگانه و امن فراهم کنید و رمزها را فقط از متغیرهای محیطی `BUBBLEWRAP_KEYSTORE_PASSWORD` و `BUBBLEWRAP_KEY_PASSWORD` تزریق کنید. کلید و رمزها را به مخزن عمومی یا ZIP افزونه اضافه نکنید.
- برای ساخت بعدی از JDK 17، Android SDK/Build Tools 36 و Bubblewrap استفاده کنید: `bubblewrap build --manifest=./twa-manifest.json --signingKeyPath=/path/to/release-key.jks`.
- TWA محتوای وب را از سایت بارگذاری می‌کند؛ آفلاین‌بودن به Service Worker منتشرشده روی سایت وابسته است. نخستین بازشدن و انتشار به‌روزرسانی‌های وب همچنان به اتصال شبکه نیاز دارند.
