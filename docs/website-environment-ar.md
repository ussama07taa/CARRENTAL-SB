# بيئة تطوير الموقع — 2026-10-27

المستخدم طلب تجهيز بيئة الموقع للحركة والفورموليرات والـ3D. تم تجهيز apps/site وليس dashboard أو قاعدة البيانات.

- Next.js / React الموجودان محفوظان.
- Motion 14.3.0: banner بدخول خفيف واحترام reduced motion.
- React Hook Form 7.91.0 + Zod 4.6.7 + resolvers 6.0.1: حقول وأخطاء شرطية للفندق.
- React Day Picker 10.0.2: اختيار فترة، wrapper يدعم fr/ar/en/es وRTL. التقويم يبدأ في المتصفح لمنع اختلاف اليوم مع SSR.
- Lucide 1.59.0: أيقونات موحدة.
- Three 0.186.1 + React Three Fiber 9.8.2: مشهد تجريبي مجرد، تحميل ديناميكي بعد النقر، demand rendering وDPR محدود.
- /design-lab مختبر FR للتطوير فقط، يرجع notFound في production. لا إرسال معلومات ولا حفظ.
- لا shadcn CLI مثبت ولا ندعي أن المكونات الحالية shadcn؛ هذه مكونات محلية قابلة لإعادة الاستعمال.

التشغيل: من الجذر مع Node 24 في PATH، `corepack pnpm --filter @bensebih/site exec next dev --hostname localhost --port 3106` ثم http://localhost:3106/design-lab . استعمل localhost بنفس اسم origin لتفادي حظر ملفات تطوير Next.

الفحص: typecheck ناجح، نموذج فارغ يظهر أخطاء، نموذج صحيح يظهر نجاحاً محلياً، نطاق 29–31 أكتوبر ظاهر، والمشهد 3D يفتح بالطلب. ليس اختبار إنتاج أو أداء على هاتف حقيقي.

ما تبقى: banner نهائي بصور أو موديل مرخص، تحويل صفحات mockup للمكونات، محتوى أربع لغات، اختبارات responsive وaccessibility والربط المصرح به لاحقاً. حركات الموقع تفاعلات React وليست فيديو HyperFrames.
