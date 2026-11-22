# سجل التتبع (Traceability)

مولّد آلياً من docs/SPEC.md عبر tools/build_register.py. يحدّث Claude Code الأعمدة الأربعة الأخيرة فقط.
الحالات: TODO → IN-PROGRESS → DONE (بكود واختبار) · READ (سياق) · OWNER (مهمة لصاحب المشروع) · PLAN (بند خارطة طريق) · BINDING (قرار ملزم) · SEE-§14 (قرار مفتوح/محسوم) · BLOCKED (ينتظر قراراً).

| REQ | القسم | النوع | المرحلة | الحالة | الكود | الاختبارات | ملاحظات |
|---|---|---|---|---|---|---|---|
| REQ-0001 | §0 | META | META | READ |  |  |  |
| REQ-0002 | §0 | META | META | READ |  |  |  |
| REQ-0003 | §1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0004 | §1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0005 | §1.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0006 | §1.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0007 | §1.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0008 | §1.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0009 | §1.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0010 | §1.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0011 | §1.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0012 | §1.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0013 | §1.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0014 | §1.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0015 | §1.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0016 | §2.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0017 | §2.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0018 | §2.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0019 | §2.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0020 | §2.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0021 | §2.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0022 | §2.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0023 | §2.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0024 | §2.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0025 | §2.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0026 | §2.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0027 | §2.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0028 | §2.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0029 | §2.1 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0030 | §2.2 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0031 | §2.2 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0032 | §2.2 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0033 | §2.2 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0034 | §2.2 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0035 | §2.2 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0036 | §2.3 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0037 | §2.3 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0038 | §2.3 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0039 | §2.3 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0040 | §2.3 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0041 | §2.3 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0042 | §2.3 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0043 | §2.3 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0044 | §2.3 | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0045 | §2.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0046 | §2.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0047 | §2.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0048 | §2.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0049 | §2.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0050 | §2.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0051 | §2.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0052 | §2.4 | BUILD | MVP | DONE | app/Approvals/DualApproval.php | tests/Feature/Foundation/DualApprovalTest.php |  |
| REQ-0053 | §2.4 | BUILD | MVP | BLOCKED |  |  | يحيل إلى 9.2 (REQ-0425) المصنّف P2 §12 L967 — قرار Q5: يُنفَّذ مع P2 |
| REQ-0054 | §2.4 | BUILD | MVP | BLOCKED |  |  | يحيل إلى 6.7 (P2) و§12 L964 — قرار Q8: يُنفَّذ مع P2 |
| REQ-0055 | §2.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0056 | §2.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0057 | §3 | BUILD | MVP | DONE | app/Authorization/Role.php, app/Models/User.php | tests/Feature/Foundation/PortalAccessTest.php |  |
| REQ-0058 | §3 | BUILD | MVP | DONE | app/Authorization/Role.php, app/Models/User.php | tests/Feature/Foundation/PortalAccessTest.php |  |
| REQ-0059 | §3 | BUILD | MVP | DONE | app/Authorization/Role.php, app/Models/User.php | tests/Feature/Foundation/PortalAccessTest.php |  |
| REQ-0060 | §3 | BUILD | MVP | DONE | app/Authorization/Role.php, app/Models/User.php | tests/Feature/Foundation/PortalAccessTest.php |  |
| REQ-0061 | §3 | BUILD | MVP | DONE | app/Authorization/Role.php, app/Models/User.php | tests/Feature/Foundation/PortalAccessTest.php |  |
| REQ-0062 | §3 | BUILD | MVP | DONE | app/Authorization/Role.php, app/Models/User.php | tests/Feature/Foundation/PortalAccessTest.php |  |
| REQ-0063 | §3 | BUILD | MVP | DONE | app/Authorization/Role.php, app/Models/User.php | tests/Feature/Foundation/PortalAccessTest.php |  |
| REQ-0064 | §3 | BUILD | MVP | TODO |  |  |  |
| REQ-0065 | §3 | BUILD | MVP | DONE | app/Authorization/Role.php, app/Models/User.php | tests/Feature/Foundation/PortalAccessTest.php |  |
| REQ-0066 | §3.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0067 | §3.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0068 | §3.1 | BUILD | MVP | IN-PROGRESS | app/Audit/, app/Support/Database/Rls.php | tests/Feature/Foundation/AuditLogTest.php | الآلية جاهزة؛ تُطبَّق على كل كيان مالي وتعاقدي عند إنشائه |
| REQ-0069 | §3.1 | BUILD | MVP | DONE | app/Approvals/DualApproval.php | tests/Feature/Foundation/DualApprovalTest.php |  |
| REQ-0070 | §4.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0071 | §4.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0072 | §4.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0073 | §4.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0074 | §4.2 | BUILD | MVP | TODO |  |  |  |
| REQ-0075 | §4.2 | BUILD | MVP | TODO |  |  |  |
| REQ-0076 | §4.2 | BUILD | MVP | TODO |  |  |  |
| REQ-0077 | §4.2 | BUILD | MVP | TODO |  |  |  |
| REQ-0078 | §4.2 | BUILD | MVP | TODO |  |  |  |
| REQ-0079 | §4.2 | BUILD | MVP | TODO |  |  |  |
| REQ-0080 | §4.2 | BUILD | MVP | TODO |  |  |  |
| REQ-0081 | §4.2 | BUILD | MVP | TODO |  |  |  |
| REQ-0082 | §4.2 | BUILD | MVP | TODO |  |  |  |
| REQ-0083 | §4.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0084 | §4.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0085 | §4.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0086 | §4.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0087 | §4.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0088 | §4.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0089 | §4.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0090 | §4.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0091 | §4.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0092 | §4.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0093 | §4.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0094 | §4.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0095 | §4.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0096 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0097 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0098 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0099 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0100 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0101 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0102 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0103 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0104 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0105 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0106 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0107 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0108 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0109 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0110 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0111 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0112 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0113 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0114 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0115 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0116 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0117 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0118 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0119 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0120 | §4.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0121 | §4.5 | BUILD | MVP | TODO |  |  |  |
| REQ-0122 | §4.5 | BUILD | MVP | TODO |  |  |  |
| REQ-0123 | §4.5 | BUILD | MVP | TODO |  |  |  |
| REQ-0124 | §4.5 | BUILD | MVP | TODO |  |  |  |
| REQ-0125 | §4.5 | BUILD | MVP | TODO |  |  |  |
| REQ-0126 | §4.5 | BUILD | MVP | TODO |  |  |  |
| REQ-0127 | §4.5 | BUILD | MVP | TODO |  |  |  |
| REQ-0128 | §4.5 | BUILD | MVP | TODO |  |  |  |
| REQ-0129 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0130 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0131 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0132 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0133 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0134 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0135 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0136 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0137 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0138 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0139 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0140 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0141 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0142 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0143 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0144 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0145 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0146 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0147 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0148 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0149 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0150 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0151 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0152 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0153 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0154 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0155 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0156 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0157 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0158 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0159 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0160 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0161 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0162 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0163 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0164 | §4.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0165 | §4.7 | BUILD | MVP | DONE | app/Support/Money/ | tests/Unit/MoneyTest.php |  |
| REQ-0166 | §4.7 | BUILD | MVP | DONE | app/Support/Dates/DateService.php | tests/Unit/DateServiceTest.php |  |
| REQ-0167 | §4.7 | BUILD | MVP | TODO |  |  |  |
| REQ-0168 | §4.7 | BUILD | MVP | IN-PROGRESS | app/Support/Database/Rls.php (archiveOnly) | tests/Feature/Foundation/AuditLogTest.php | الآلية جاهزة؛ تُطبَّق على الجداول المالية والعقود عند إنشائها |
| REQ-0169 | §4.7 | BUILD | MVP | DONE | app/Support/Database/Rls.php, app/Tenancy/ | tests/Feature/Foundation/TenantIsolationTest.php |  |
| REQ-0170 | §5.1 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L939 |
| REQ-0171 | §5.1 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L939 |
| REQ-0172 | §5.1 | BUILD | P3 | TODO |  |  | MIXED→P3: §12 L971 |
| REQ-0173 | §5.1 | BUILD | P3 | TODO |  |  | MIXED→P3: §12 L971 |
| REQ-0174 | §5.1 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L939 |
| REQ-0175 | §5.1 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L939 |
| REQ-0176 | §5.1 | BUILD | MVP | TODO |  |  | MIXED→MVP: أ.8 D6: العلَم وشرط الترخيص جزء من نموذج الوحدة؛ فتح التأجير اليومي P3 §12 L971 — قرار Q1 |
| REQ-0177 | §5.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L939 (سعر الشهر والسنة)؛ سعر اليوم/الأسبوع مع P3 §12 L971 |
| REQ-0178 | §5.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0179 | §5.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0180 | §5.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0181 | §5.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0182 | §5.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0183 | §5.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0184 | §5.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0185 | §5.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0186 | §5.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0187 | §5.5 | BUILD | MVP | TODO |  |  |  |
| REQ-0188 | §5.5 | BUILD | MVP | TODO |  |  |  |
| REQ-0189 | §5.5 | BUILD | MVP | TODO |  |  |  |
| REQ-0190 | §5.5 | BUILD | MVP | TODO |  |  |  |
| REQ-0191 | §5.6 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L939؛ افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0192 | §5.6 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L958 |
| REQ-0193 | §5.6 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L958 |
| REQ-0194 | §5.6 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975)؛ التصنيف حسب النية يعتمد على الاستطلاع P2 §12 L958 |
| REQ-0195 | §5.6 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L939؛ افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0196 | §5.7 | BUILD | MVP | TODO |  |  |  |
| REQ-0197 | §5.7 | BUILD | MVP | TODO |  |  |  |
| REQ-0198 | §5.7 | BUILD | MVP | TODO |  |  |  |
| REQ-0199 | §5.7 | BUILD | MVP | TODO |  |  |  |
| REQ-0200 | §5.7 | BUILD | MVP | TODO |  |  |  |
| REQ-0201 | §5.7 | BUILD | MVP | TODO |  |  |  |
| REQ-0202 | §5.7 | BUILD | MVP | TODO |  |  |  |
| REQ-0203 | §5.7 | BUILD | MVP | TODO |  |  |  |
| REQ-0204 | §5.7 | BUILD | MVP | TODO |  |  |  |
| REQ-0205 | §5.8 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L966 |
| REQ-0206 | §5.8 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L950 |
| REQ-0207 | §5.8 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L950 |
| REQ-0208 | §5.8 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L966 |
| REQ-0209 | §5.8 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L966 |
| REQ-0210 | §5.8 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L966 |
| REQ-0211 | §6.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0212 | §6.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0213 | §6.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0214 | §6.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0215 | §6.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0216 | §6.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0217 | §6.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0218 | §6.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0219 | §6.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0220 | §6.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0221 | §6.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L942، L943 |
| REQ-0222 | §6.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L942 |
| REQ-0223 | §6.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L943 |
| REQ-0224 | §6.2 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L964 (يعتمد على خزنة المستندات) |
| REQ-0225 | §6.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0226 | §6.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L942 |
| REQ-0227 | §6.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0228 | §6.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0229 | §6.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0230 | §6.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0231 | §6.4 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L956 |
| REQ-0232 | §6.4 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0233 | §6.4 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L942؛ افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0234 | §6.4 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L956 |
| REQ-0235 | §6.4 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L943 |
| REQ-0236 | §6.4 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L942 |
| REQ-0237 | §6.4 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0238 | §6.5 | BUILD | P2 | TODO |  |  |  |
| REQ-0239 | §6.5 | BUILD | P2 | TODO |  |  |  |
| REQ-0240 | §6.6 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L942 |
| REQ-0241 | §6.6 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L942 |
| REQ-0242 | §6.6 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L942 |
| REQ-0243 | §6.6 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L942 |
| REQ-0244 | §6.6 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L963، L957 |
| REQ-0245 | §6.6 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L963 |
| REQ-0246 | §6.6 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L963 |
| REQ-0247 | §6.6 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L963 |
| REQ-0248 | §6.6 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L942 يحصر MVP في «جرد التأسيس»، والجرد الدوري P2 §12 L963 — قرار Q2 |
| REQ-0249 | §6.6 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L942 |
| REQ-0250 | §6.6 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L942 |
| REQ-0251 | §6.6 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L942 |
| REQ-0252 | §6.6 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L942 |
| REQ-0253 | §6.6 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L942 |
| REQ-0254 | §6.6 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L942 |
| REQ-0255 | §6.6 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L942 يحصر MVP في «جرد التأسيس»، والجرد الدوري P2 §12 L963 — قرار Q3 |
| REQ-0256 | §6.6 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L942 يحصر MVP في «جرد التأسيس»، والجرد الدوري P2 §12 L963 — قرار Q3 |
| REQ-0257 | §6.6 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L942 يحصر MVP في «جرد التأسيس»، والجرد الدوري P2 §12 L963 — قرار Q3 |
| REQ-0258 | §6.6 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L942 يحصر MVP في «جرد التأسيس»، والجرد الدوري P2 §12 L963 — قرار Q3 |
| REQ-0259 | §6.6 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L942 يحصر MVP في «جرد التأسيس»، والجرد الدوري P2 §12 L963 — قرار Q3 |
| REQ-0260 | §6.6 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L942 يحصر MVP في «جرد التأسيس»، والجرد الدوري P2 §12 L963 — قرار Q3 |
| REQ-0261 | §6.6 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L942 يحصر MVP في «جرد التأسيس»، والجرد الدوري P2 §12 L963 — قرار Q3 |
| REQ-0262 | §6.6 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0263 | §6.6 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L942 |
| REQ-0264 | §6.6 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L963 |
| REQ-0265 | §6.7 | BUILD | P2 | TODO |  |  |  |
| REQ-0266 | §6.7 | BUILD | P2 | TODO |  |  |  |
| REQ-0267 | §6.7 | BUILD | P2 | TODO |  |  |  |
| REQ-0268 | §6.7 | BUILD | P2 | TODO |  |  |  |
| REQ-0269 | §6.7 | BUILD | P2 | TODO |  |  |  |
| REQ-0270 | §6.7 | BUILD | P2 | TODO |  |  |  |
| REQ-0271 | §7 | BUILD | MVP | IN-PROGRESS | app/Providers/Filament/, app/Http/Controllers/ManifestController.php | tests/Feature/Foundation/PortalAccessTest.php | واجهة لكل دور وملف manifest؛ يبقى عامل الخدمة (M6) |
| REQ-0272 | §7.1 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L948 |
| REQ-0273 | §7.1 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L948 |
| REQ-0274 | §7.1 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L948؛ الدفع عبر سداد P2 §12 L962 |
| REQ-0275 | §7.1 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L948 |
| REQ-0276 | §7.1 | BUILD | P3 | TODO |  |  | MIXED→P3: §12 L974 |
| REQ-0277 | §7.1 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L958 |
| REQ-0278 | §7.1 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0279 | §7.1 | BUILD | P3 | TODO |  |  | MIXED→P3: §12 L973 |
| REQ-0280 | §7.1 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0281 | §7.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L949 |
| REQ-0282 | §7.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L949 |
| REQ-0283 | §7.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L949 |
| REQ-0284 | §7.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L949 |
| REQ-0285 | §7.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L949 |
| REQ-0286 | §7.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L949 |
| REQ-0287 | §7.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0288 | §7.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0289 | §7.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L949 |
| REQ-0290 | §7.2 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L956 |
| REQ-0291 | §7.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0292 | §7.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0293 | §7.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L951 |
| REQ-0294 | §7.2 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L942 يحصر MVP في «جرد التأسيس»، والجرد الدوري P2 §12 L963 — قرار Q3 |
| REQ-0295 | §7.2 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L968 |
| REQ-0296 | §7.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L949 |
| REQ-0297 | §7.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L949 |
| REQ-0298 | §7.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L949؛ «المحتمل مغادرتهم» يعتمد على المؤشر P2 §12 L958 |
| REQ-0299 | §7.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L951 |
| REQ-0300 | §7.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L940 |
| REQ-0301 | §7.2 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L964، L966 |
| REQ-0302 | §7.2 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L968 |
| REQ-0303 | §7.2 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L968 |
| REQ-0304 | §7.3 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L949 |
| REQ-0305 | §7.3 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L941، L949 |
| REQ-0306 | §7.3 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L949 |
| REQ-0307 | §7.3 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L949 |
| REQ-0308 | §7.3 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L959 |
| REQ-0309 | §7.3 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L951 |
| REQ-0310 | §7.3 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L968 |
| REQ-0311 | §7.3 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L964 — قرار Q4 |
| REQ-0312 | §7.3 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L968 |
| REQ-0313 | §7.4 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L942؛ قراءات العدادات P2 §12 L968، ومهام الجرد غير التأسيسي P2 §12 L963 |
| REQ-0314 | §7.5 | BUILD | MVP | TODO |  |  |  |
| REQ-0315 | §7.5 | BUILD | MVP | TODO |  |  |  |
| REQ-0316 | §7.5 | BUILD | MVP | TODO |  |  |  |
| REQ-0317 | §7.5 | BUILD | MVP | TODO |  |  |  |
| REQ-0318 | §7.5 | BUILD | MVP | TODO |  |  |  |
| REQ-0319 | §7.5 | BUILD | MVP | TODO |  |  |  |
| REQ-0320 | §7.5 | BUILD | MVP | TODO |  |  |  |
| REQ-0321 | §7.6 | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0322 | §7.6 | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0323 | §7.6 | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0324 | §7.6 | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0325 | §7.6 | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0326 | §7.6 | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0327 | §7.6أ | BUILD | MVP | DONE | app/Theming/ThemeService.php, app/Filament/Support/ConfiguresPanel.php | tests/Feature/Foundation/ThemeTest.php |  |
| REQ-0328 | §7.6أ | BUILD | MVP | DONE | app/Theming/ThemeService.php, app/Filament/Support/ConfiguresPanel.php | tests/Feature/Foundation/ThemeTest.php |  |
| REQ-0329 | §7.6أ | BUILD | MVP | DONE | app/Theming/ThemeService.php, app/Filament/Support/ConfiguresPanel.php | tests/Feature/Foundation/ThemeTest.php |  |
| REQ-0330 | §7.6أ | BUILD | MVP | DONE | app/Theming/ThemeService.php, app/Filament/Support/ConfiguresPanel.php | tests/Feature/Foundation/ThemeTest.php |  |
| REQ-0331 | §7.6أ | BUILD | MVP | IN-PROGRESS | app/Http/Controllers/ManifestController.php | tests/Feature/Foundation/PortalAccessTest.php |  |
| REQ-0332 | §7.6أ | BUILD | MVP | DONE | app/Theming/Customization.php | tests/Feature/Foundation/CustomizationTest.php |  |
| REQ-0333 | §7.6أ | BUILD | MVP | DONE | app/Theming/Customization.php | tests/Feature/Foundation/CustomizationTest.php |  |
| REQ-0334 | §7.6أ | BUILD | MVP | DONE | app/Theming/Customization.php | tests/Feature/Foundation/CustomizationTest.php |  |
| REQ-0335 | §7.6أ | BUILD | MVP | DONE | app/Theming/Customization.php | tests/Feature/Foundation/CustomizationTest.php |  |
| REQ-0336 | §7.6أ | BUILD | MVP | DONE | app/Theming/Customization.php | tests/Feature/Foundation/CustomizationTest.php |  |
| REQ-0337 | §7.6أ | BUILD | MVP | DONE | app/Theming/Customization.php | tests/Feature/Foundation/CustomizationTest.php |  |
| REQ-0338 | §7.6ب | BUILD | MVP | DONE | app/Theming/ThemeService.php, app/Filament/Support/ConfiguresPanel.php | tests/Feature/Foundation/ThemeTest.php |  |
| REQ-0339 | §7.6ب | BUILD | MVP | DONE | app/Theming/ThemeService.php, app/Filament/Support/ConfiguresPanel.php | tests/Feature/Foundation/ThemeTest.php |  |
| REQ-0340 | §7.6ب | BUILD | MVP | DONE | app/Theming/ThemeService.php, app/Filament/Support/ConfiguresPanel.php | tests/Feature/Foundation/ThemeTest.php |  |
| REQ-0341 | §7.6ب | BUILD | MVP | DONE | app/Theming/Customization.php | tests/Feature/Foundation/CustomizationTest.php |  |
| REQ-0342 | §7.6ج | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0343 | §7.6ج | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0344 | §7.6ج | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0345 | §7.6ج | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0346 | §7.6ج | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0347 | §7.6ج | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0348 | §7.6ج | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0349 | §7.6ج | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0350 | §7.6ج | BUILD | MVP | DONE | app/Support/Dates/DateService.php | tests/Unit/DateServiceTest.php |  |
| REQ-0351 | §7.6ج | BUILD | MVP | DONE | app/Support/Dates/DateService.php | tests/Unit/DateServiceTest.php |  |
| REQ-0352 | §7.6ج | BUILD | MVP | DONE | app/Support/Dates/DateService.php | tests/Unit/DateServiceTest.php |  |
| REQ-0353 | §7.6د | BUILD | MVP | TODO |  |  |  |
| REQ-0354 | §7.6د | BUILD | MVP | TODO |  |  |  |
| REQ-0355 | §7.6د | BUILD | MVP | TODO |  |  |  |
| REQ-0356 | §7.6د | BUILD | MVP | TODO |  |  |  |
| REQ-0357 | §7.6د | BUILD | MVP | TODO |  |  |  |
| REQ-0358 | §7.6د | BUILD | MVP | TODO |  |  |  |
| REQ-0359 | §7.6د | BUILD | MVP | TODO |  |  |  |
| REQ-0360 | §7.6د | BUILD | MVP | TODO |  |  |  |
| REQ-0361 | §7.6د | BUILD | MVP | TODO |  |  |  |
| REQ-0362 | §7.6هـ | BUILD | MVP | DONE | app/Support/Dates/NumberFormatter.php, app/Settings/SettingsRegistry.php | tests/Feature/Foundation/SettingsTest.php, tests/Unit/MoneyTest.php |  |
| REQ-0363 | §7.6هـ | BUILD | MVP | DONE | app/Support/Dates/NumberFormatter.php, app/Settings/SettingsRegistry.php | tests/Unit/MoneyTest.php |  |
| REQ-0364 | §7.6هـ | BUILD | MVP | DONE | app/Support/Dates/NumberFormatter.php, app/Settings/SettingsRegistry.php | tests/Unit/MoneyTest.php |  |
| REQ-0365 | §7.6و | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0366 | §7.6و | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0367 | §7.6و | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0368 | §7.6و | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0369 | §7.6و | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0370 | §7.6و | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0371 | §7.6و | BUILD | MVP | IN-PROGRESS | app/Settings/SettingsRegistry.php | tests/Feature/Foundation/SettingsTest.php | الإعداد موجود؛ تطبيقه في الواجهة لاحقاً |
| REQ-0372 | §7.6و | BUILD | MVP | DONE | app/Integrations/, config/integrations.php | tests/Feature/Foundation/IntegrationsAndProviderLimitsTest.php |  |
| REQ-0373 | §7.6ز | BUILD | MVP | IN-PROGRESS | app/Settings/SettingsRegistry.php | tests/Feature/Foundation/SettingsTest.php | الإعداد موجود؛ تطبيقه في الواجهة لاحقاً |
| REQ-0374 | §7.6ح | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0375 | §7.6ح | BUILD | MVP | DONE | app/Theming/ThemeService.php, app/Filament/Support/ConfiguresPanel.php | tests/Feature/Foundation/ThemeTest.php |  |
| REQ-0376 | §7.6ح | BUILD | MVP | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0377 | §8.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0378 | §8.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0379 | §8.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0380 | §8.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0381 | §8.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0382 | §8.2 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L961 |
| REQ-0383 | §8.2 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L961؛ أ.8 D3: لا رسوم |
| REQ-0384 | §8.2 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L961 |
| REQ-0385 | §8.2 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L963 |
| REQ-0386 | §8.2 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L965؛ أ.8 D4 |
| REQ-0387 | §8.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0388 | §8.3 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L951 |
| REQ-0389 | §8.3 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L951 |
| REQ-0390 | §8.3 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L961 |
| REQ-0391 | §8.3 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L951 |
| REQ-0392 | §8.3 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L951 |
| REQ-0393 | §8.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0394 | §8.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0395 | §8.4 | BUILD | MVP | TODO |  |  |  |
| REQ-0396 | §8.5 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L951 |
| REQ-0397 | §8.5 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L951 |
| REQ-0398 | §8.5 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L951 |
| REQ-0399 | §8.5 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L951 |
| REQ-0400 | §8.5 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L965 |
| REQ-0401 | §8.5 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L958 (يعتمد على الاستطلاع والمؤشر) |
| REQ-0402 | §8.5 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L965 |
| REQ-0403 | §8.5 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L965 |
| REQ-0404 | §8.5 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L951 |
| REQ-0405 | §8.5 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0406 | §8.5 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0407 | §8.5 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L951 |
| REQ-0408 | §8.5 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0409 | §8.5 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0410 | §8.5 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L951 (EXPECTED_VACANCY)؛ PROSPECT_MATCH وVIEWING_SLOT P2 §12 L965 |
| REQ-0411 | §8.5 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L961 |
| REQ-0412 | §9.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0413 | §9.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0414 | §9.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0415 | §9.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0416 | §9.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0417 | §9.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0418 | §9.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0419 | §9.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0420 | §9.1 | BUILD | MVP | TODO |  |  |  |
| REQ-0421 | §9.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L940 |
| REQ-0422 | §9.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L940 |
| REQ-0423 | §9.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L940 |
| REQ-0424 | §9.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L940 |
| REQ-0425 | §9.2 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L967 — قرار Q5 |
| REQ-0426 | §9.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0427 | §9.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0428 | §9.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0429 | §9.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0430 | §9.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0431 | §9.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0432 | §9.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0433 | §9.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0434 | §9.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0435 | §9.3 | BUILD | MVP | TODO |  |  |  |
| REQ-0436 | §9.4 | BUILD | MVP | DONE | app/Integrations/, config/integrations.php | tests/Feature/Foundation/IntegrationsAndProviderLimitsTest.php |  |
| REQ-0437 | §9.5 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0438 | §9.5 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L939 |
| REQ-0439 | §9.5 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L949 |
| REQ-0440 | §9.5 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L951 |
| REQ-0441 | §9.5 | BUILD | P3 | TODO |  |  | MIXED→P3: §12 L971 |
| REQ-0442 | §9.5 | BUILD | P3 | TODO |  |  | MIXED→P3: §12 L972 |
| REQ-0443 | §9.5 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L940 |
| REQ-0444 | §9.5 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0445 | §9.5 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L962 |
| REQ-0446 | §9.5 | BUILD | P2 | TODO |  |  | MIXED→P2: §10.2 L784؛ غائب عن §12 — قرار Q6 |
| REQ-0447 | §9.5 | BUILD | P2 | TODO |  |  | MIXED→P2: §10.2 L784؛ غائب عن §12 — قرار Q6 |
| REQ-0448 | §9.5 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L940 |
| REQ-0449 | §9.6 | BUILD | MVP | DONE | app/Integrations/, config/integrations.php | tests/Feature/Foundation/IntegrationsAndProviderLimitsTest.php |  |
| REQ-0450 | §9.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0451 | §9.6 | BUILD | MVP | DONE | app/Integrations/, config/integrations.php | tests/Feature/Foundation/IntegrationsAndProviderLimitsTest.php |  |
| REQ-0452 | §9.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0453 | §9.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0454 | §9.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0455 | §9.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0456 | §9.6 | BUILD | MVP | TODO |  |  |  |
| REQ-0457 | §10.1 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L939 |
| REQ-0458 | §10.1 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L939 |
| REQ-0459 | §10.1 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L939 |
| REQ-0460 | §10.1 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L939 |
| REQ-0461 | §10.1 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L939 |
| REQ-0462 | §10.1 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L960 |
| REQ-0463 | §10.1 | BUILD | MVP | DONE | app/Integrations/, config/integrations.php | tests/Feature/Foundation/IntegrationsAndProviderLimitsTest.php | MIXED→MVP: §12 L939 |
| REQ-0464 | §10.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0465 | §10.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L940 |
| REQ-0466 | §10.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L940 |
| REQ-0467 | §10.2 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L962 |
| REQ-0468 | §10.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L950 |
| REQ-0469 | §10.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L938 |
| REQ-0470 | §10.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975)؛ §10.2 L782 = MVP |
| REQ-0471 | §10.2 | BUILD | P2 | TODO |  |  | MIXED→P2: §12 L962 |
| REQ-0472 | §10.2 | BUILD | P2 | TODO |  |  | MIXED→P2: §10.2 L784؛ غائب عن §12 — قرار Q6 |
| REQ-0473 | §10.2 | BUILD | MVP | TODO |  |  | MIXED→MVP: §12 L946 |
| REQ-0474 | §10.2 | BUILD | P3 | TODO |  |  | MIXED→P3: §10.2 L786؛ غائب عن §12.P3 — قرار Q7 |
| REQ-0475 | §10.2 | BUILD | P3 | TODO |  |  | MIXED→P3: §10.2 L787؛ غائب عن §12.P3 — قرار Q7 |
| REQ-0476 | §11 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0477 | §11.1 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0478 | §11.1 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0479 | §11.1 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0480 | §11.1 | BUILD | FOUNDATION | IN-PROGRESS | app/Providers/Filament/, app/Http/Controllers/ManifestController.php | tests/Feature/Foundation/PortalAccessTest.php | واجهة لكل دور وملف manifest؛ يبقى عامل الخدمة (M6) |
| REQ-0481 | §11.1 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0482 | §11.1 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0483 | §11.1 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0484 | §11.1 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0485 | §11.1 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0486 | §11.2 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0487 | §11.2 | BUILD | FOUNDATION | DONE | app/Support/Database/Rls.php, app/Tenancy/ | tests/Feature/Foundation/TenantIsolationTest.php |  |
| REQ-0488 | §11.2 | BUILD | FOUNDATION | DONE | app/Support/Money/ | tests/Unit/MoneyTest.php |  |
| REQ-0489 | §11.2 | BUILD | FOUNDATION | DONE | app/Integrations/, config/integrations.php | tests/Feature/Foundation/IntegrationsAndProviderLimitsTest.php |  |
| REQ-0490 | §11.2 | BUILD | FOUNDATION | DONE | config/app.php, app/Filament/Support/ConfiguresPanel.php | tests/Feature/Foundation/PlatformVersionsTest.php, tests/Feature/Foundation/PortalAccessTest.php |  |
| REQ-0491 | §11.2 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0492 | §11.2 | BUILD | FOUNDATION | DONE | app/Theming/ThemeService.php, app/Filament/Support/ConfiguresPanel.php | tests/Feature/Foundation/ThemeTest.php |  |
| REQ-0493 | §11.2 | BUILD | FOUNDATION | DONE | app/Settings/ | tests/Feature/Foundation/SettingsTest.php |  |
| REQ-0494 | §11.2 | BUILD | FOUNDATION | IN-PROGRESS | app/Providers/Filament/, app/Http/Controllers/ManifestController.php | tests/Feature/Foundation/PortalAccessTest.php | واجهة لكل دور وملف manifest؛ يبقى عامل الخدمة (M6) |
| REQ-0495 | §11.2 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0496 | §11.2 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0497 | §11.2 | BUILD | FOUNDATION | IN-PROGRESS | app/Audit/, app/Support/Database/Rls.php | tests/Feature/Foundation/AuditLogTest.php | الآلية جاهزة؛ تُطبَّق على كل كيان مالي وتعاقدي عند إنشائه |
| REQ-0498 | §11.2 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0499 | §11.3 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0500 | §11.3 | BUILD | FOUNDATION | DONE | app/Console/Commands/LaunchCheck.php, composer.json, phpunit.xml, docs/OPERATIONS.md | tests/Feature/Foundation/OperationsTest.php |  |
| REQ-0501 | §11.3 | BUILD | FOUNDATION | BLOCKED | docs/OPERATIONS.md |  | Q11: مزوّد الاستضافة/المراقبة |
| REQ-0502 | §11.3 | BUILD | FOUNDATION | DONE | app/Console/Commands/LaunchCheck.php, composer.json, phpunit.xml, docs/OPERATIONS.md | tests/Feature/Foundation/OperationsTest.php |  |
| REQ-0503 | §11.3 | BUILD | FOUNDATION | BLOCKED | docs/OPERATIONS.md |  | Q11: مزوّد الاستضافة/المراقبة |
| REQ-0504 | §11.3 | BUILD | FOUNDATION | DONE | app/Console/Commands/LaunchCheck.php, composer.json, phpunit.xml, docs/OPERATIONS.md | tests/Feature/Foundation/OperationsTest.php |  |
| REQ-0505 | §11.4 | BUILD | FOUNDATION | DONE | app/Filament/Support/ConfiguresPanel.php | tests/Feature/Foundation/PortalAccessTest.php |  |
| REQ-0506 | §11.4 | BUILD | FOUNDATION | IN-PROGRESS | app/Http/Middleware/EnsureTwoFactorForRole.php | tests/Feature/Foundation/PortalAccessTest.php | المصادقة الثنائية وتشفير الأسرار منجزان؛ TLS عند النشر |
| REQ-0507 | §11.4 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0508 | §11.4 | BUILD | FOUNDATION | DONE | app/Console/Commands/LaunchCheck.php, composer.json, phpunit.xml, docs/OPERATIONS.md | tests/Feature/Foundation/OperationsTest.php |  |
| REQ-0509 | §11.5 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0510 | §11.5 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0511 | §11.5 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0512 | §11.5 | BUILD | FOUNDATION | DONE | CLAUDE.md, PROMPT.md, tools/ | tests/Feature/Foundation/PreflightGateTest.php |  |
| REQ-0513 | §11.6 | BUILD | FOUNDATION | DONE | composer.lock | tests/Feature/Foundation/PlatformVersionsTest.php |  |
| REQ-0514 | §11.6 | BUILD | FOUNDATION | DONE | composer.json, composer.lock, package-lock.json | tests/Feature/Foundation/PlatformVersionsTest.php |  |
| REQ-0515 | §11.6 | BUILD | FOUNDATION | DONE | composer.json, composer.lock, package-lock.json | tests/Feature/Foundation/PlatformVersionsTest.php |  |
| REQ-0516 | §11.6 | BUILD | FOUNDATION | DONE | composer.json, composer.lock, package-lock.json | tests/Feature/Foundation/PlatformVersionsTest.php |  |
| REQ-0517 | §11.6 | BUILD | FOUNDATION | DONE | composer.json, composer.lock, package-lock.json | tests/Feature/Foundation/PlatformVersionsTest.php |  |
| REQ-0518 | §11.6 | BUILD | FOUNDATION | DONE | composer.json, composer.lock, package-lock.json | tests/Feature/Foundation/PlatformVersionsTest.php |  |
| REQ-0519 | §11.6 | BUILD | FOUNDATION | DONE | composer.json, composer.lock, package-lock.json | tests/Feature/Foundation/PlatformVersionsTest.php |  |
| REQ-0520 | §11.6 | BUILD | FOUNDATION | DONE | app/Console/Commands/LaunchCheck.php, .env.example | tests/Feature/Foundation/OperationsTest.php, tests/Feature/Foundation/PlatformVersionsTest.php |  |
| REQ-0521 | §11.6 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0522 | §11.6 | BUILD | FOUNDATION | DONE | composer.json, composer.lock, package-lock.json | tests/Feature/Foundation/PlatformVersionsTest.php |  |
| REQ-0523 | §11.6 | BUILD | FOUNDATION | DONE | config/queue.php, config/cache.php, config/filesystems.php, .env.example | tests/Feature/Foundation/PlatformVersionsTest.php |  |
| REQ-0524 | §11.6 | BUILD | FOUNDATION | DONE | composer.lock | tests/Feature/Foundation/PlatformVersionsTest.php |  |
| REQ-0525 | §11.6 | BUILD | FOUNDATION | DONE | composer.json, composer.lock, package-lock.json | tests/Feature/Foundation/PlatformVersionsTest.php |  |
| REQ-0526 | §11.6 | BUILD | FOUNDATION | DONE | composer.json | tests/Feature/Foundation/PlatformVersionsTest.php | حُلّت الثلاثة معاً: Laravel 13.43.0 + Livewire 4.4.9 + Filament 5.12.0 |
| REQ-0527 | §11.6 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0528 | §11.6 | BUILD | FOUNDATION | DONE | composer.json, composer.lock, package-lock.json | tests/Feature/Foundation/PlatformVersionsTest.php |  |
| REQ-0529 | §11.6 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0530 | §11.6 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0531 | §11.6 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0532 | §11.6 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0533 | §11.6 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0534 | §11.6 | BUILD | FOUNDATION | DONE | config/queue.php, config/cache.php, config/filesystems.php, .env.example | tests/Feature/Foundation/PlatformVersionsTest.php |  |
| REQ-0535 | §11.6 | BUILD | FOUNDATION | DONE | config/queue.php, config/cache.php, config/filesystems.php, .env.example | tests/Feature/Foundation/PlatformVersionsTest.php |  |
| REQ-0536 | §11.6 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0537 | §11.6 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0538 | §11.6 | BUILD | FOUNDATION | DONE | config/queue.php, config/cache.php, config/filesystems.php, .env.example | tests/Feature/Foundation/PlatformVersionsTest.php |  |
| REQ-0539 | §11.7 | BUILD | FOUNDATION | DONE | .claude/settings.json, .claude/hooks/guard-preflight.php | tests/Feature/Foundation/PreflightGateTest.php |  |
| REQ-0540 | §11.7 | BUILD | FOUNDATION | DONE | scripts/preflight.php | tests/Feature/Foundation/PreflightGateTest.php |  |
| REQ-0541 | §11.7 | BUILD | FOUNDATION | DONE | scripts/preflight.php | tests/Feature/Foundation/PreflightGateTest.php |  |
| REQ-0542 | §11.7 | BUILD | FOUNDATION | DONE | scripts/preflight.php | tests/Feature/Foundation/PreflightGateTest.php |  |
| REQ-0543 | §11.7 | BUILD | FOUNDATION | DONE | scripts/preflight.php | tests/Feature/Foundation/PreflightGateTest.php |  |
| REQ-0544 | §11.7 | BUILD | FOUNDATION | DONE | scripts/preflight.php | tests/Feature/Foundation/PreflightGateTest.php |  |
| REQ-0545 | §11.7 | BUILD | FOUNDATION | DONE | scripts/preflight.php | tests/Feature/Foundation/PreflightGateTest.php |  |
| REQ-0546 | §11.7 | BUILD | FOUNDATION | DONE | config/app.php, scripts/preflight.php | tests/Feature/Foundation/PlatformVersionsTest.php |  |
| REQ-0547 | §11.7 | BUILD | FOUNDATION | DONE | scripts/preflight.php | tests/Feature/Foundation/PreflightGateTest.php |  |
| REQ-0548 | §11.7 | BUILD | FOUNDATION | DONE | scripts/preflight.php | tests/Feature/Foundation/PreflightGateTest.php |  |
| REQ-0549 | §11.7 | BUILD | FOUNDATION | DONE | scripts/preflight.php | tests/Feature/Foundation/PreflightGateTest.php |  |
| REQ-0550 | §11.7 | BUILD | FOUNDATION | DONE | scripts/preflight.php | tests/Feature/Foundation/PreflightGateTest.php |  |
| REQ-0551 | §11.7 | BUILD | FOUNDATION | DONE | .claude/settings.json, .claude/hooks/guard-preflight.php | tests/Feature/Foundation/PreflightGateTest.php |  |
| REQ-0552 | §11.7 | BUILD | FOUNDATION | DONE | .claude/settings.json, .claude/hooks/guard-preflight.php | tests/Feature/Foundation/PreflightGateTest.php |  |
| REQ-0553 | §11.7 | BUILD | FOUNDATION | DONE | .claude/settings.json, .claude/hooks/guard-preflight.php | tests/Feature/Foundation/PreflightGateTest.php |  |
| REQ-0554 | §11.7 | BUILD | FOUNDATION | DONE | .claude/settings.json, .claude/hooks/guard-preflight.php | tests/Feature/Foundation/PreflightGateTest.php |  |
| REQ-0555 | §11.7 | BUILD | FOUNDATION | DONE | .claude/settings.json, .claude/hooks/guard-preflight.php | tests/Feature/Foundation/PreflightGateTest.php |  |
| REQ-0556 | §11.7 | BUILD | FOUNDATION | DONE | .claude/settings.json, .claude/hooks/guard-preflight.php | tests/Feature/Foundation/PreflightGateTest.php |  |
| REQ-0557 | §11.7 | BUILD | FOUNDATION | TODO |  |  |  |
| REQ-0558 | §11.7 | BUILD | FOUNDATION | DONE | composer.lock, package-lock.json, phpunit.xml | tests/Feature/Foundation/PlatformVersionsTest.php, tests/Feature/Foundation/PreflightGateTest.php | تأسيس + اختبارات فارغة ناجحة |
| REQ-0559 | §12.P0 | OWNER | OWNER | OWNER |  |  |  |
| REQ-0560 | §12.P0 | OWNER | OWNER | OWNER |  |  |  |
| REQ-0561 | §12.P0 | OWNER | OWNER | OWNER |  |  |  |
| REQ-0562 | §12.P0 | OWNER | OWNER | OWNER |  |  |  |
| REQ-0563 | §12.P0 | OWNER | OWNER | OWNER |  |  |  |
| REQ-0564 | §12.P0 | OWNER | OWNER | OWNER |  |  |  |
| REQ-0565 | §12.P0 | OWNER | OWNER | OWNER |  |  |  |
| REQ-0566 | §12.P0 | OWNER | OWNER | OWNER |  |  |  |
| REQ-0567 | §12.P0 | OWNER | OWNER | OWNER |  |  |  |
| REQ-0568 | §12.P0 | OWNER | OWNER | OWNER |  |  |  |
| REQ-0569 | §12.P0 | OWNER | OWNER | OWNER |  |  |  |
| REQ-0570 | §12.P1 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0571 | §12.P1 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0572 | §12.P1 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0573 | §12.P1 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0574 | §12.P1 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0575 | §12.P1 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0576 | §12.P1 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0577 | §12.P1 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0578 | §12.P1 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0579 | §12.P1 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0580 | §12.P1 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0581 | §12.P1 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0582 | §12.P1 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0583 | §12.P1 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0584 | §12.P1 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0585 | §12.P1 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0586 | §12.P1 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0587 | §12.P1 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0588 | §12.P2 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0589 | §12.P2 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0590 | §12.P2 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0591 | §12.P2 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0592 | §12.P2 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0593 | §12.P2 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0594 | §12.P2 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0595 | §12.P2 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0596 | §12.P2 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0597 | §12.P2 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0598 | §12.P2 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0599 | §12.P2 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0600 | §12.P2 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0601 | §12.P3 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0602 | §12.P3 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0603 | §12.P3 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0604 | §12.P3 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0605 | §12.P3 | PLAN | PLAN | PLAN |  |  |  |
| REQ-0606 | §12.R | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0607 | §12.R | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0608 | §12.R | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0609 | §12.R | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0610 | §12.R | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0611 | §12.R | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0612 | §12.R | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0613 | §12.R | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0614 | §12.R | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0615 | §12.R | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0616 | §12.R | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0617 | §12.R | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0618 | §12.R | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0619 | §12.R | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0620 | §12.R | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0621 | §12.R | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0622 | §12.R | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0623 | §12.R | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0624 | §12.R | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0625 | §12.R | CONTEXT | CONTEXT | READ |  |  |  |
| REQ-0626 | §13 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0627 | §13 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0628 | §13 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0629 | §13 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0630 | §13 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0631 | §13 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0632 | §13 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0633 | §13 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0634 | §13 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0635 | §13 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0636 | §13 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975)؛ تعدد الملاك ضمنها P2 §12 L959 |
| REQ-0637 | §13 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0638 | §13 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975)؛ اللوحات P2 §12 L961، الملصقات P2 §12 L963، الضيافة P3 §12 L971 |
| REQ-0639 | §13 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0640 | §13 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0641 | §13 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0642 | §13 | BUILD | MVP | TODO |  |  | MIXED→MVP: افتراضي: لا تذكره §12.P2/P3 (L956–L975) |
| REQ-0643 | §14 | DECISION | DECISIONS | SEE-§14 |  |  |  |
| REQ-0644 | §14 | DECISION | DECISIONS | SEE-§14 | app/Integrations/ | tests/Feature/Foundation/IntegrationsAndProviderLimitsTest.php |  |
| REQ-0645 | §14 | DECISION | DECISIONS | SEE-§14 |  |  |  |
| REQ-0646 | §14 | DECISION | DECISIONS | SEE-§14 |  |  |  |
| REQ-0647 | §14 | DECISION | DECISIONS | SEE-§14 | app/Integrations/ | tests/Feature/Foundation/IntegrationsAndProviderLimitsTest.php |  |
| REQ-0648 | §14 | DECISION | DECISIONS | SEE-§14 |  |  |  |
| REQ-0649 | §14 | DECISION | DECISIONS | SEE-§14 |  |  |  |
| REQ-0650 | §14 | DECISION | DECISIONS | SEE-§14 |  |  |  |
| REQ-0651 | §14 | DECISION | DECISIONS | SEE-§14 |  |  |  |
| REQ-0652 | §14 | DECISION | DECISIONS | SEE-§14 | app/Approvals/DualApproval.php | tests/Feature/Foundation/DualApprovalTest.php | PENDING-D10: إلزامية من مكتب، اختيارية في مالك |
| REQ-0653 | §14 | DECISION | DECISIONS | SEE-§14 |  |  |  |
| REQ-0654 | §14 | DECISION | DECISIONS | SEE-§14 | app/Theming/ThemeService.php, app/Console/Commands/LaunchCheck.php | tests/Feature/Foundation/ThemeTest.php | القرار 11: لا افتراضي في الكود؛ فحص الإطلاق يفشل حتى يُضبط |
| REQ-0655 | §14 | DECISION | DECISIONS | SEE-§14 |  |  |  |
| REQ-0656 | §14 | DECISION | DECISIONS | DONE | app/Support/Dates/DateService.php | tests/Unit/DateServiceTest.php |  |
| REQ-0657 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0658 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0659 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0660 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0661 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0662 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0663 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0664 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0665 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0666 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0667 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0668 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0669 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0670 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0671 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0672 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0673 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0674 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0675 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0676 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0677 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0678 | §15 | DECIDED | BINDING | BINDING |  |  |  |
| REQ-0679 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0680 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0681 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0682 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0683 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0684 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0685 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0686 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0687 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0688 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0689 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0690 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0691 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0692 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0693 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0694 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0695 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0696 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0697 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0698 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0699 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0700 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0701 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0702 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0703 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0704 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0705 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0706 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0707 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0708 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0709 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0710 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0711 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0712 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0713 | §16 | SOURCE | SOURCES | READ |  |  |  |
| REQ-0714 | §16 | SOURCE | SOURCES | READ |  |  |  |
