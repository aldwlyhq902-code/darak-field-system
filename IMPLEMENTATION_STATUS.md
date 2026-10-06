# حالة تنفيذ منظومة دارك

**الإصدار:** 0.3.0 · **آخر مراجعة:** 2026-10-06 · **المرجع:** PRD v1.2 ومراجعة 2026-10-06 المستقلة.

تربط هذه الوثيقة كل متطلب بمكان تنفيذه في الكود وبالشاشة أو الأمر الذي يظهر فيه وبالاختبار الذي
يحرسه. لا تحمل أعداد اختبارات: النتيجة الملزمة هي ملخص تشغيل CI على commit الإصدار، ولقطة التحقق
المحلي لهذا الإصدار في [RELEASES/0.3.0.md](RELEASES/0.3.0.md). المسارات نسبية لـ`backend/app/`
ما لم يُذكر غير ذلك، والاختبارات في `backend/tests/Feature/`.

---

## 1. النواة الميدانية (PRD v1.2 §2)

| المتطلب | التنفيذ | أين يظهر | الاختبار |
|---|---|---|---|
| مزامنة بلا شبكة، آمنة لإعادة الإرسال، بترتيب تسلسل الجهاز | `Services/SyncService.php`، `Services/ClockGuard.php`؛ `mobile/lib/core/sync_engine.dart`، `lib/core/event_queue.dart` | `POST /api/v1/sync/events` | `OfflineSyncTest`، `SyncHardeningTest`، `mobile/test/sync_resilience_test.dart` |
| بيانات يوم العمل على الجهاز | `Http/Controllers/Api/SyncController.php`، `Http/Resources/Api/BootstrapVisitResource.php` | `GET /api/v1/sync/bootstrap` | `ApiSerializationAllowlistTest` |
| آلة حالات الزيارة (7 حالات، 409 للقفزة غير المسموحة) | `Services/VisitStateMachine.php`، `Models/Visit.php` (`TRANSITIONS`) | التطبيق، لوحة الزيارة | `VisitLifecycleTest`، `Unit/VisitTransitionContractTest`، `mobile/test/visit_transition_contract_test.dart` |
| SLA داخل نافذة الخدمة فقط | `Services/SlaCalculator.php` | لوحة اليوم، `darak:sla-sweep` كل 10 دقائق | `Unit/SlaCalculatorTest`، `SlaSweepAndAssignmentTest` |
| بوابة الإقفال تعيد كل النواقص | `Services/CloseGate.php` | التطبيق قبل الإقفال، `GET /visits/{visit}/close-blockers` | `VisitLifecycleTest` |
| كاشف زيارة الإعادة، والتجاوز للمالك فقط | `Services/ReworkDetector.php` | صفحة الزيارة، `POST /visits/{visit}/rework-override` | `VisitAccessFindingsTest` |
| رفع أدلة مستأنف ببصمة SHA-256 ونسخة مختومة | `Services/MediaUploadService.php`، `Services/PhotoStamper.php` | `api/v1/media/*` | `MediaUploadTest`، `MediaWriteAuthorizationTest` |
| تقرير عربي PDF «تقرير حالة فنية وسجل صيانة» | `Services/VisitReportBuilder.php` | صفحة الزيارة، `GET /visits/{visit}/report.pdf` | `VisitReportTest` |
| المخزون بحركات ورصيد لا يصير سالباً | `Services/InventoryService.php` | المخزون، `api/v1/inventory/*` | `InventoryTest` |
| الفوترة تُشترى: `fake` و`manual` | `Services/Invoicing/InvoiceProviderFactory.php`، `Services/Invoicing/ManualInvoiceProvider.php`، `Services/InvoiceService.php` | المالية ← الفواتير (`finance/invoices`) | `InvoiceProviderTest` |
| إشعارات outbox | `Services/NotificationService.php` | صندوق الإشعارات، `darak:notifications-run` كل 5 دقائق | `NotificationTest`، `NotificationVisibilityTest` |
| مقاولو الباطن بهامش قبل التأكيد | `Services/SubcontractorService.php` | صفحة المقاولين | `SubcontractorTest` |

## 2. التشغيل والمال

| المتطلب | التنفيذ | أين يظهر | الاختبار |
|---|---|---|---|
| حساب مالي دقيق (bcmath، تقريب نصفي، خانتان) | `Support/Money.php` في الربحية والمشتريات والعقود والعروض | كل المبالغ | `Unit/MoneyTest`، `MoneyPrecisionTest` |
| عروض بإصدارات، اعتماد العميل، تحويل لعقد | `Services/CommercialService.php` | العروض والتحصيل، بوابة العميل | `CommercialOperationsTest` |
| جدول دفعات: المقدم جزء من القيمة، الأقساط تساوي القيمة بالضبط، المخصص يساوي القيمة، الربع الجزئي بأشهره | `Services/CommercialService.php` + `Money` | جدول دفعات العقد | `ContractScheduleMoneyTest` |
| ضبط سعر العرض (حد، خصم مسموح، سعر قائمة) واعتماد ثنائي لغير المالك | `Services/QuotationPricingService.php`، `Services/FinancialApprovalService.php` | العروض، الاعتمادات المالية | `QuotationPriceControlFindingsTest` |
| سند قبض، متأخرات، تنبيهات استحقاق وتجديد | `Services/CommercialService.php`، `Services/ContractRenewalService.php` | `darak:commercial-alerts` 08:00، `darak:renewal-offers --days=45` 07:30 | `CommercialOperationsTest` |
| موافقة العميل على الأعمال الإضافية | `Services/AdditionalWorkService.php` | الزيارة، بوابة العميل، `POST /visits/{visit}/additional-work` | `CompletionPackTest` |
| ربحية العقد والعميل والزيارة والفني | `Services/ProfitabilityService.php` | لوحة الربحية | `MoneyPrecisionTest` |
| دورة العمولة: مستحقة ← مكتسبة بعد نافذة الاسترداد ← مدفوعة/معكوسة، والاسترداد بعد الصرف خصم من عمولات مستقبلية فقط | `Services/CommissionService.php` | `admin-operations/commissions`، `darak:commissions-generate` 23:30 | `CommissionLifecycleTest` |
| خطط الصيانة الوقائية | `Services/MaintenancePlanService.php` | الصيانة الوقائية، `darak:maintenance-generate` 05:00 | `MaintenanceRequestsFeedbackTest` |

## 3. المخزون والمشتريات

| المتطلب | التنفيذ | أين يظهر | الاختبار |
|---|---|---|---|
| موردون وأسعار وأوامر شراء واستلام جزئي، RFQ ومقارنة وترسية | `Services/ProcurementService.php`، `Services/RfqService.php` | المشتريات والحجوزات | `ProcurementAndApprovalsTest` |
| توريد تلقائي تحت حد الطلب | `Services/ReplenishmentService.php` | `darak:replenishment-scan` 06:00 | `GrowthOperationsTest` |
| حجز القطع للزيارة واقتراح تحميل السيارة | `Services/InventoryService.php`، `Services/VehicleLoadSuggestionService.php` | الزيارة، المخزون | `ProcurementAndApprovalsTest`، `ApiAuthorizationTest` |
| تحويل بين سيارتين بتسليم المرسل وقبول المستلم | `Services/ProcurementService.php` | التطبيق، `api/v1/inventory/vehicle-transfers*` | `ProcurementAndApprovalsTest` |
| جرد جوال QR/باركود، دفعة ورقم تسلسلي وضمان | `Services/StocktakeService.php` | التطبيق، `api/v1/inventory/stocktakes*` | `GrowthOperationsTest` |
| عهدة بتوقيع وقبول وإرجاع | `Http/Controllers/Api/CustodyController.php`، `Http/Controllers/Web/TeamController.php` | الفريق، التطبيق | `CommercialOperationsTest`، `IdempotencyKeyTest` |
| رفض الحركة بين شركتين | `InventoryService::record` | — | `TenancyGapsTest` |

## 4. الذكاء التشغيلي

| المتطلب | التنفيذ | أين يظهر | الاختبار |
|---|---|---|---|
| سجل الأصل ومؤشر صحته، تكرار الأعطال، فشل المورد/الدفعة | `Services/AssetIntelligenceService.php` | صفحة الذكاء | `TenancyGapsTest` |
| اقتراح التشخيص من قاعدة المعرفة والتاريخ | `AssetIntelligenceService::diagnosisSuggestions` | التطبيق، `GET /visits/{visit}/diagnosis-suggestions` | `GrowthOperationsTest` |
| نموذج انحدار لوجستي لعودة العطل خلال 90 يوماً، لكل شركة | `Services/FaultPredictionTrainer.php` | `darak:fault-model-train` الاثنين 03:30 **فقط مع** `DARAK_ENABLE_EXPERIMENTAL_ANALYTICS=true` | `TenancyGapsTest`، `SchedulerGatingTest` |
| حجب التنبؤ حتى 500 زيارة مكتملة و100 مشفرة | `AssetIntelligenceService::predictionReadiness`، `DARAK_PREDICTION_MINIMUM_SAMPLES` | — | `GrowthOperationsTest`، `CompletionPackTest` |

## 5. الجدولة وتجربة العميل

| المتطلب | التنفيذ | أين يظهر | الاختبار |
|---|---|---|---|
| تقويم واقتراح أفضل فنيين، والإسناد البشري بقيود صلبة | `Services/DispatchSuggestionService.php`، `Http/Controllers/Api/VisitController::assign` | الجدولة، `POST /visits/{visit}/assign` | `SlaSweepAndAssignmentTest` |
| الإسناد داخل شركة وفرع عميل الزيارة فقط | `Support/TenantAccess.php` (`userFitsVisit`) | الإسناد، إعادة التوزيع | `TenantIsolationFindingsTest` |
| سياسة عمل بإصدارات وعرض مخالفاتها عند الإسناد | `Services/WorkPolicyService.php`، `Models/WorkPolicy.php` | الموارد البشرية ← سياسات العمل (للمالك) | `WorkPolicyTest` |
| غياب الفني وتعطل السيارة وإعادة التوزيع الآلية | `Services/AutomaticReassignmentService.php` | `darak:reassign-unavailable` كل 15 دقيقة | `TenantIsolationFindingsTest`، `SchedulerGatingTest` |
| طريق تقريبي ووصول متوقع، Mapbox اختياري | `Services/RoutePlanningService.php`، `Services/TrafficRouteProvider.php` | `POST /visits/{visit}/location` | `CompletionPackTest` |
| موقع الفني للعميل مقرّب (~100 م) وأثناء الطريق فقط، والخريطة قابلة للإلغاء | `Services/ApproximateTechnicianLocation.php`، `DARAK_MAP_TILES_URL` | بوابة العميل | `ClientLocationPrivacyTest` |
| بوابة العميل، المفوضون بمواقع محددة، بلاغ QR بحد لكل موقع | `Http/Controllers/Web/ClientPortalController.php`، `Http/Controllers/Web/EmergencyReportController.php` | `/client`، ملصق QR | `ClientPortalAndEmergencyReportTest`، `ClientDelegateSiteFindingsTest`، `PublicSurfaceHardeningTest` |
| طلب موعد وتقييم بعد الإغلاق ومتابعة الشكوى | `Http/Controllers/Web/ClientServiceRequestController.php`، `Http/Controllers/Web/VisitFeedbackController.php` | البوابة، اللوحة | `MaintenanceRequestsFeedbackTest` |

## 6. تطبيق الفني (المسارات نسبية لـ`mobile/`)

| المتطلب | التنفيذ | الاختبار |
|---|---|---|
| قاعدة SQLCipher بمفتاح في Keystore، ومخطط v3 بترقية عمود بعمود | `lib/core/local_db.dart`، `lib/core/secure_token_store.dart` | `test/schema_upgrade_test.dart` |
| كتابة محلية وإدراج في الطابور ذرّيان | `lib/core/field_recorder.dart` | `test/field_recorder_test.dart` |
| صندوق إجراءات دائم بمفتاح `Idempotency-Key` لكل ضغطة | `lib/core/action_outbox.dart` | `test/action_outbox_test.dart` |
| الانقطاع و5xx و429 و401 ليست فشلاً؛ تراجع زمني؛ عزل الحدث المعطوب بالتنصيف؛ الإرسال قبل الجلب | `lib/core/sync_engine.dart`، `lib/core/backoff.dart` | `test/sync_resilience_test.dart`، `test/refresh_preserves_progress_test.dart` |
| ساعة موثوقة عبر `elapsedRealtime` و`BOOT_COUNT` | `lib/core/trusted_clock.dart`، `android/app/src/main/kotlin/com/darak/darak_field/MainActivity.kt` | `test/trusted_clock_test.dart` |
| GPS مع كل حدث، علامة الموقع المزيّف، سبب العمل خارج النطاق | `lib/core/location.dart`، `lib/core/geofence.dart` | `test/geofence_test.dart` |
| تشفير الأدلة AES-256-GCM وحذف نسخ الالتقاط المؤقتة | `lib/core/evidence_cipher.dart`، `lib/core/evidence_store.dart`، `lib/core/camera_capture.dart` | `test/evidence_encryption_test.dart`، `test/evidence_store_test.dart` |
| `FLAG_SECURE`، كشف الروت/المحاكي، تثبيت الشهادة الاختياري | `lib/core/device_platform.dart`، `lib/core/pinned_http.dart` | `test/pinned_http_test.dart` |
| المسح عن بُعد، ومنع الخروج مع عمل غير مُزامَن | `lib/core/device_wipe.dart`، `lib/core/login_session.dart` | `test/device_wipe_test.dart`، `test/auth_contract_test.dart` |
| حد أبعاد الصورة قبل الرفع | `lib/core/image_limits.dart` | `test/image_limits_test.dart` |
| سيناريو كامل بلا شبكة على جهاز | — | `integration_test/offline_scenario_test.dart` (محاكي Android 15) |

## 7. الأمن والمصادقة

| المتطلب | التنفيذ | أين يظهر | الاختبار |
|---|---|---|---|
| MFA/TOTP إلزامي للوحة ولرمز API الإداري، ورفض إعادة الخطوة | `Services/TwoFactorService.php` | `two-factor/*` | `TwoFactorAuthenticationTest` |
| قفل الحساب (كلمة المرور وMFA)، زمن ثابت ضد التعداد، حدود بالحساب وIP | `Services/AccountLockout.php`، `Services/PasswordLogin.php`، `Providers/SecurityServiceProvider.php` | الدخول، صندوق المالك | `AccountLockoutTest` |
| تغيير كلمة المرور واستعادتها، رموز استرداد بكلمة مرور ورمز حي، سر MFA بكلمة مرور مؤكدة | `Http/Controllers/Web/AccountSecurityController.php`، `Http/Controllers/Web/PasswordResetController.php`، `Services/AccountSecurity.php` | `account/security`، `forgot-password`، `client/forgot-password` | `AccountLifecycleTest` |
| اعتماد الجهاز الثاني، انتهاء الخمول، سلامة الجهاز ونسخة التطبيق | `Services/DeviceRegistry.php`، `Http/Controllers/Web/DeviceApprovalController.php`، `Http/Middleware/EnsureDeviceIsActive.php` | الفريق ← الأجهزة | `DeviceApprovalTest` |
| إلغاء رموز الإدارة بعد إصلاح MFA | `Console/Commands/RevokeBackofficeTokensCommand.php` | `darak:revoke-backoffice-tokens --force` (ينفذه `deploy/deploy.sh` مرة) | `BackofficeTokenRevocationTest` |
| `Idempotency-Key` على 9 مسارات | `Http/Middleware/EnsureIdempotency.php`، `Models/IdempotencyKey.php` | `api/v1` | `IdempotencyKeyTest` |
| ما يصل الهاتف بقائمة سماح (C5) | `Http/Resources/Api/*` | bootstrap، الزيارات، `auth/me` | `ApiSerializationAllowlistTest` |
| ربط الحدث بالحساب (C6) وجودة الموقع (C3) وتوحيد التوقيت | `SyncService`، `Support/ClientTime.php` | الخط الزمني للزيارة | `SyncHardeningTest` |
| الكتابة على الدليل للجهاز المسجِّل فقط، وفحص ترويسة الصورة | `Http/Controllers/Api/MediaController.php`، `PhotoStamper` | — | `MediaWriteAuthorizationTest` |
| عزل الشركات والفروع، السجلات المرجعية تابعة لشركة، النطاق يفشل مغلقاً | `Models/Scopes/OperatingBranchScope.php`، `Models/Concerns/ScopedToOperatingBranch.php`، `Support/TenantAccess.php`، هجرات `2026_10_07_*` | كل الشاشات | `TenantIsolationFindingsTest`، `TenancyGapsTest`، `BranchIsolationSecurityTest`، `TenantAuthorizationRegressionTest` |
| صلاحيات اللوحة: تغيير الفرع للمالك، الفراغ = لا صلاحيات | `Http/Controllers/Web/AdminOperationsController.php` | صفحة `admin-operations` | `PanelPrivilegeFindingsTest` |
| سجل تدقيق HMAC وحذف الشخصي لحظة الكتابة | `Services/AuditLogger.php`، `VerifyAuditChainCommand` | `darak:audit-verify` | `AuditHardeningTest` |
| SSRF في Web Push، حدود QR لكل موقع، نطاق Service Worker | `Services/SalesPushService.php`، `Http/Middleware/SecurityHeaders.php` | — | `PublicSurfaceHardeningTest`، `SecurityHeadersTest` |
| RLS ضد واجهات Supabase | هجرات `2026_08_28_*` | — | `SupabaseIsolationTest` |

## 8. التشغيل

| المتطلب | التنفيذ | أين يظهر | الاختبار |
|---|---|---|---|
| حاجز الإنتاج | `Console/Commands/PreflightCommand.php`، `Services/PreflightProbe.php` | `darak:preflight`، وفي `deploy/deploy.sh` قبل الترحيل | `PreflightCommandTest` |
| نسخ مشفر، تحقق، نسخ خارجي، تدوير، نبض، تنبيه | `Services/BackupService.php`، `Services/OffsiteBackupService.php`، `Services/OpsAlertService.php` | `darak:backup` 02:30، `darak:restore` | `BackupOperationsTest`، `BackupRestoreTest` |
| نبض المجدول وفحص الصحة | `Services/HeartbeatPinger.php`، `HealthCommand` | `darak:heartbeat` كل دقيقة، `darak:health --json` | `OperationalHealthTest` |
| أعلام الوحدات وبوابة التحليلات التجريبية | `routes/console.php`، `config/darak.php` (`modules`) | `php artisan schedule:list` | `SchedulerGatingTest` |
| الإتلاف وفق مدد الاحتفاظ (تقرير أولاً) | `Services/RetentionPurgeService.php` | `darak:retention-purge` | `RetentionPurgeTest` |
| قرص ملفات قابل للضبط | `Support/PrivateFiles.php`، `DARAK_FILES_DISK` | — | `FilesDiskTest` |
| ترقيم القوائم بدل القطع الصامت | متحكمات `Http/Controllers/Web/*` | كل القوائم | `ListPaginationTest` |
| تقوية منفذ Vercel التجريبي والوكلاء الموثوقين | `api/index.php`، `bootstrap/app.php`، `.vercelignore` | — | `DeploymentHardeningTest` |
| حزمة النشر | `deploy/` | [DEPLOYMENT.md](DEPLOYMENT.md) | `bash -n` فقط |
| كل صفحة GET تُعرض بعد الدمج | — | — | `PageSmokeTest` |

## 9. الإدارة والموارد البشرية والمبيعات

| المتطلب | التنفيذ | أين يظهر | الاختبار |
|---|---|---|---|
| بيانات المؤسسة والشعار، والهوية بشركة المستخدم | `Http/Controllers/Web/AdminOperationsController.php` | بيانات المؤسسة | `OrganizationProfileTest` |
| ملفات الموظفين ووثائقهم والإجازات | `Services/LeaveManagementService.php`، `Http/Controllers/Web/HumanResourcesController.php` | الموارد البشرية | `HrFleetManagementTest` |
| الأسطول: وثائق، صيانة، فحص سلامة يوقف السيارة | `Services/FleetManagementService.php`، `Http/Controllers/Api/FleetController.php` | الأسطول، التطبيق | `HrFleetManagementTest` |
| تنبيهات امتثال 90/60/30/7 يوماً | `Services/ComplianceAlertService.php` | `darak:compliance-alerts` 07:00 | `HrFleetManagementTest` |
| قياس الأداء بأوزان وأهداف لكل شركة، مجمّع في SQL | `Services/PerformanceScoreService.php` | الأداء والترتيب | `PerformanceDashboardTest`، `PerformanceScoreTenancyTest` |
| بوابة المسوق PWA، Push، مرفقات، أهداف | `Http/Controllers/Web/SalesPortalController.php`، `Services/SalesPushService.php` | `/sales`، `darak:sales-alerts` كل ساعة | `SalesPwaTest` |
| إشعارات المشرف تصل إدارة الشركة المعنية | `Services/NotificationService.php` | صندوق الإشعارات | `NotificationVisibilityTest` |

---

## مفتوح / قرار للمالك

ليست عيوباً منسية؛ كل بند قرار مؤجل بسبب، أو يحتاج طرفاً خارجياً أو المالك نفسه.

### مؤجل عمداً

| البند | لماذا |
|---|---|
| ترجمة اللوحة للإنجليزية ما زالت قاموس استبدال في DOM | المستخدمون عربيو اللغة أولاً؛ إعادة البناء على ملفات لغة مهمة لمطوّر بشري |
| قوالب Blade المكتوبة في سطر واحد لم يُعَد تنسيقها | خطر كسر عالٍ بلا أثر يراه المستخدم |
| تتبع النطاق الجغرافي في الخلفية، مؤقت متابعة 15 دقيقة لأعمال الغاز، ربط الهاتف بالسيارة | أفكار من جلسة 5 أغسطس وليست عيوباً؛ تحتاج إعلان موقع الخلفية في Play وخدمة أمامية. تُبنى بعد أن يثبت التشغيل الميداني الحاجة |
| ترقية `flutter_secure_storage` من 9 إلى 11 وترقيات الحزم الرئيسية الأخرى | خطر ترحيل مخزن المفاتيح على أجهزة فيها عمل غير مُزامَن |
| إزالة `libsqlite3.so` (اعتماد تطوير) من حزمة release | لا يمنع التشغيل ولا رفع Play؛ مؤجل |
| أسعار كتالوج القطع مشتركة بين الشركات (`purchase_cost`، `sale_price`) | قرار تصميم يخص التشغيل متعدد الشركات (محور) فقط؛ الـSKU والـQR عامّان عمداً |

### يحتاج مزوداً خارجياً

- بوابة دفع (Moyasar / Tabby / Tamara) وواجهة WhatsApp Business: تحتاج حسابات ومفاتيح مزود.
- تكامل ZATCA حقيقي: إلى أن يُختار مزود، يُستخدم `manual` مع أداة متوافقة خارج النظام.

### فجوات وُجدت أثناء توثيق 0.3.0 (لم تُصلح في هذا الإصدار)

| الفجوة | الأثر | الاتجاه |
|---|---|---|
| `MediaUploadService` يكتب أجزاء الرفع عبر مسار محلي (`$disk->path()` مع `fopen`/`flock`/`hash_file`) | مع `DARAK_FILES_DISK=s3` يتعطل رفع الأدلة المستأنف؛ `PhotoStamper` وبقية الملفات تعمل عبر واجهة القرص | ابقَ على `local` مع نسخ خارجي، أو انقل أجزاء الرفع إلى قرص محلي مؤقت قبل اعتماد S3 |
| قوائم التحويلات والجرد للفني (`vehicle-transfers`، `vehicle-transfer-releases`، `stocktakes`) ترجع النماذج كاملة | تصل الهاتف بيانات الفني الآخر (البريد، الجوال، تكلفة الساعة، الصلاحيات) وأسعار شراء القطع وبيعها — خارج قائمة السماح C5 | موارد API بقائمة سماح مثل الزيارات |
| رفض الأعمال في `StocktakeService` و`ProcurementService` (`RuntimeException`) | يظهر 500 بدل 4xx، فيعيد صندوق إجراءات التطبيق المحاولة بلا نهاية برسالة عامة | تحويلها إلى 409/422 برمز ثابت |

### إجراءات المالك قبل التشغيل

1. اختبار هاتف حقيقي كامل (القائمة في [RELEASES/0.3.0.md](RELEASES/0.3.0.md)).
2. خادم داخل المملكة يجتاز `darak:preflight` بلا `FAIL`.
3. حفظ مفتاح التوقيع، و`DARAK_AUDIT_HMAC_KEY`، و`APP_KEY`، وكلمة مرور النسخ في مدير أسرار وخزنة مستقلة.
4. اعتماد جدول الخصوصية والاحتفاظ مع المستشار ([PRIVACY_AND_RETENTION.md](PRIVACY_AND_RETENTION.md))،
   ومنه قرار صفوف التدقيق السابقة لـ0.3.0.
5. ضبط أسعار القائمة وحد السعر للعروض.
6. مزود بريد حقيقي، وحاوية نسخ خارج الخادم بقاعدة دورة حياة.
7. تأكيد نطاق الإنتاج وإعادة بناء التطبيق إن لم يكن `panel.darak.sa`.
8. ضبط الفرع الافتراضي في GitHub على `master` بعد الدمج.
