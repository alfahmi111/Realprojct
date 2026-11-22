# الحزم المعتمدة (REQ-0527)

كل حزمة إضافية تُضاف بقرار مسجّل يذكر سببها، مع `composer audit` نظيف.

| الحزمة | الإصدار المثبّت | السبب | المرجع |
|---|---|---|---|
| laravel/framework | 13.43.0 | الإطار المعتمد | REQ-0515 |
| livewire/livewire | 4.4.9 | واجهة الويب | REQ-0516 |
| filament/filament | 5.12.0 | لوحات الأدوار والمصادقة الثنائية | REQ-0517 |
| laravel/tinker | (من القالب) | أداة تطوير مع الإطار | قالب Laravel |

لا حزم أخرى في `require`. حزم `require-dev` هي حزم قالب Laravel الافتراضية للاختبار (phpunit، collision، pint، mockery، faker، pail).
