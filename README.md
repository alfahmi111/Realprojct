# حزمة Claude Code — منصة إدارة الأصول العقارية

بيئتك: Windows 11 + AMPPS + VS Code. تجهيز الجهاز مرة واحدة فقط.

## أولاً: ثبّت ما ينقص AMPPS

| البرنامج | لماذا | من أين |
|---|---|---|
| PostgreSQL 16 أو أحدث | قاعدة بيانات النظام (AMPPS لا يتضمنها) | postgresql.org/download/windows — احفظ كلمة مرور المستخدم `postgres` |
| Git for Windows | حفظ نسخ الكود، وطرفية Git Bash لـ Claude Code | git-scm.com |
| Composer | مدير حزم PHP. أثناء التثبيت اختر `php.exe` من مجلد AMPPS، فيضيفه إلى PATH | getcomposer.org |
| Node.js 20 LTS أو أحدث | بناء الواجهة | nodejs.org |
| إضافة Claude Code في VS Code | العامل الذي يبني النظام | من سوق الإضافات في VS Code |

**في AMPPS:** اختر PHP 8.3 أو أحدث، وفعّل هذه الامتدادات من إعدادات PHP:
`pdo_pgsql` `pgsql` `intl` `bcmath` `mbstring` `zip` `gd` `fileinfo` `curl` `openssl`

## ثانياً: أنشئ قاعدة البيانات

افتح pgAdmin (يأتي مع PostgreSQL) ونفّذ:
```sql
CREATE USER app WITH PASSWORD 'change_me';
CREATE DATABASE property_platform OWNER app;
ALTER DATABASE property_platform SET timezone TO 'UTC';
```

## ثالثاً: ضع المشروع وافحص

1. فك ضغط الحزمة داخل مجلد المشاريع في AMPPS (مثل `C:\Program Files\Ampps\www\property-platform`).
2. افتح المجلد في VS Code، ثم افتح الطرفية (Terminal ← New Terminal) واكتب:
```
git init
copy .env.example .env
php scripts/preflight.php
```
3. المطلوب أن يظهر في النهاية: `PREFLIGHT: PASS`.

## رابعاً: ابدأ

افتح لوحة Claude Code في VS Code واكتب: **اقرأ PROMPT.md كاملاً ونفّذه**

## المحتوى
| الملف | دوره |
|---|---|
| `PROMPT.md` | التلقينة: أمر العمل + خريطة الأقسام + سجل متطلبات يغطي كل سطر في الوثيقة |
| `CLAUDE.md` | قواعد دائمة يقرؤها Claude Code في كل جلسة |
| `docs/SPEC.md` | وثيقة شروط التأسيس |
| `docs/TRACEABILITY.md` · `docs/QUESTIONS.md` · `docs/PROGRESS.md` | التتبع · الأسئلة بدل التخمين · تقرير كل مرحلة |
| `docs/design/tokens.json` | ألوان السمات الثلاث وألوان الحالة |
| `scripts/preflight.php` | الفحص المسبق للبيئة |
| `.claude/settings.json` · `.claude/hooks/guard-preflight.php` | خطافات تمنع البناء قبل نجاح الفحص |
| `tools/trace_check.php` | يتحقق أن كل متطلب منجز له اختبار يذكر رقمه |
| `tools/build_register.py` · `tools/coverage_check.py` | توليد السجل وقياس تغطيته بعد أي تعديل على الوثيقة (Python) |
