# SEO الموقع — تجهيز قبل النشر

- Titles وdescriptions مترجمة FR/AR/EN/ES للرئيسية، الأسطول، التواصل، من نحن، FAQ، الشروط والخصوصية؛ metadata للطلب والتأكيد والتتبع دون تفاصيل الزبون أو رابط تتبع خاص.
- تفاصيل السيارات: الاسم والموديل والسنة من endpoint الأسطول العام فقط. عند تعذر API يستعمل وصف الأسطول العام.
- canonical وhreflang وx-default وOpen Graph وTwitter summary للصفحات العامة. لا إدراج فلاتر أو تواريخ أو tokens في canonical.
- `/sitemap.xml`: الصفحات العامة بأربع لغات والسيارات المنشورة من API العام. لا حسابات، طلبات، تأكيد، تتبع، design-lab أو query strings. عند فشل API تبقى الصفحات الثابتة متاحة ويعاد جلب السيارات في الطلب التالي. لا lastModified تخميني.
- `/robots.txt`: Disallow: / لكل crawlers. Meta robots وX-Robots-Tag كلاهما noindex, nofollow حتى في NODE_ENV=production. لم يتفعّل indexing.
- المصدر الوحيد للدومين `SITE_PUBLIC_URL` (server-only)، مثل https://الدومين-الحقيقي؛ origin فقط، دون مسار أو query أو credentials. إلى حين تحديد الدومين يبقى fallback محلياً http://localhost:3106. لا نعتمد Host القادم من الطلب لإنشاء روابط sitemap.
- عند النشر وبإذن المستخدم: ضبط SITE_PUBLIC_URL، تأكيد بيانات الأسطول والصفحات، ثم تغيير سياسة الفهرسة للصفحات العامة فقط. التتبع والتأكيد والطلب تبقى noindex؛ design-lab و404 والروابط الخاصة لا تنشر في sitemap. تفعيل الزحف يتطلب مراجعة robots.ts وproxy.ts وmetadata وليس NODE_ENV وحده.
- لا تغييرات قاعدة بيانات أو 3108. لا رفع على السيرفر ولا إرسال sitemap إلى Google في هذه المرحلة.

## التحقق المنجز
- build إنتاج وTypeScript نجحا. فحص HTTP على 44 صفحة بأربع لغات: 44 عنواناً مختلفاً ووصف موجود؛ canonical بلا query strings و5 بدائل لغة للصفحات العامة فقط.
- sitemap في بيئة الاختبار احتوت 48 رابطاً (28 ثابتاً و20 لخمس سيارات منشورة)، دون تكرار أو صفحات خاصة.
- robots.txt يحظر الزحف، وكل الصفحات المختبرة تحمل noindex في metadata وX-Robots-Tag حتى في production. انتهى الاختبار دون أي حفظ معاملات أو فتح 3108؛ خادم التحقق المؤقت أُوقف.
