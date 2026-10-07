<?php

/*
 * Arabic labels for stored codes — visit states and events, asset types,
 * specialties, packages, notification types, statuses. The ONE map every
 * screen and PDF reads (panel, client portal, sales app, reports), through
 * App\Support\Labels. Codes stay in the database and the API; people see
 * these. English comes from resources/lang/en/ui.php, which translates these
 * Arabic labels like the rest of the interface.
 */

return [
    'visit_state' => [
        'scheduled' => 'مجدولة',
        'en_route' => 'في الطريق',
        'started' => 'قيد التنفيذ',
        'paused' => 'متوقفة',
        'awaiting_close' => 'بانتظار الإقفال',
        'completed' => 'مكتملة',
        'reopened' => 'أُعيد فتحها',
    ],

    // `state.<from>.to.<to>` events are composed from visit_state.
    'visit_event' => [
        'visit.transition' => 'طلب تغيير الحالة من التطبيق',
        'checklist.upsert' => 'تسجيل حالة أصل',
        'parts.declaration' => 'تصريح القطع المستخدمة',
        'part.issue' => 'صرف قطعة غيار',
        'part.return' => 'إرجاع قطعة غيار',
        'media.register' => 'تسجيل صورة أو ملف',
        'signature.captured' => 'توقيع مسؤول الموقع',
        'site.scanned' => 'مسح ملصق الموقع',
        'asset.scanned' => 'مسح ملصق الأصل',
        'geofence.ping' => 'تحقق من الموقع الجغرافي',
        'note.added' => 'ملاحظة',
        'assignment.changed' => 'تغيير الفني المسند',
    ],

    'work_order_type' => [
        'preventive' => 'وقائية',
        'reactive' => 'بلاغ',
        'out_of_contract' => 'خارج العقد',
    ],

    'asset_type' => [
        'split_ac' => 'مكيف سبليت',
        'package_ac' => 'مكيف مركزي',
        'chiller' => 'ثلاجة تبريد',
        'freezer' => 'فريزر تجميد',
        'hood' => 'هود مطبخ',
        'exhaust_fan' => 'مروحة شفط',
        'gas' => 'نظام غاز',
        'electrical' => 'كهرباء',
        'plumbing' => 'سباكة',
    ],

    // Technicians and subcontractors.
    'specialty' => [
        'split_ac' => 'تكييف سبليت',
        'package_ac' => 'تكييف مركزي',
        'hvac' => 'تكييف',
        'chiller' => 'تبريد',
        'freezer' => 'تجميد',
        'electrical' => 'كهرباء',
        'plumbing' => 'سباكة',
        'gas' => 'غاز',
        'hood' => 'هود',
    ],

    'package' => [
        'basic' => 'الباقة الأساسية',
        'comprehensive' => 'الباقة الشاملة',
    ],

    'contract_status' => [
        'draft' => 'مسودة',
        'active' => 'ساري',
        'suspended' => 'موقوف',
        'ended' => 'منتهٍ',
        'cancelled' => 'ملغى',
        'terminated' => 'مفسوخ',
    ],

    'notification_type' => [
        'visit.assigned' => 'إسناد زيارة',
        'sla.at_risk' => 'خطر تجاوز SLA',
        'report.ready' => 'التقرير جاهز',
        'emergency.reported' => 'بلاغ طارئ',
        'installment.due' => 'قسط مستحق',
        'contract.expiring' => 'عقد يقترب من الانتهاء',
        'feedback.alert' => 'تنبيه تقييم العميل',
        'employee_document.expiring' => 'وثيقة موظف',
        'vehicle_document.expiring' => 'وثيقة سيارة',
        'vehicle.maintenance_due' => 'صيانة سيارة',
        'sales.alert' => 'تنبيه مبيعات',
        'security.alert' => 'تنبيه أمني',
        'invoice.manual_pending' => 'فاتورة بانتظار الإصدار اليدوي',
    ],

    'notification_channel' => [
        'in_app' => 'داخل التطبيق',
        'whatsapp_manual' => 'واتساب يدوي',
        'mail' => 'بريد إلكتروني',
    ],

    'user_role' => [
        'owner_supervisor' => 'مالك/مشرف',
        'admin' => 'إداري',
        'technician' => 'فني',
    ],

    'dispute_status' => [
        'new' => 'جديد',
        'reviewed' => 'قيد المراجعة',
        'resolved' => 'تمت المعالجة',
        'rejected' => 'مرفوض',
    ],

    'dispute_category' => [
        'work' => 'جودة العمل',
        'parts' => 'القطع',
        'time' => 'الوقت',
        'report' => 'التقرير',
        'other' => 'أخرى',
    ],

    'payment_method' => [
        'bank_transfer' => 'تحويل بنكي',
        'cash' => 'نقدًا',
        'card' => 'بطاقة',
        'online' => 'دفع إلكتروني',
    ],

    'approval_status' => [
        'pending_first' => 'بانتظار الاعتماد الأول',
        'pending_second' => 'بانتظار الاعتماد الثاني',
        'approved' => 'معتمد',
        'rejected' => 'مرفوض',
    ],

    'approval_subject' => [
        'quotation' => 'عرض سعر',
        'installment' => 'قسط',
    ],

    'lead_stage' => [
        'new' => 'جديد',
        'qualified' => 'مؤهل',
        'proposal' => 'عرض سعر',
        'negotiation' => 'تفاوض',
        'won' => 'مكتسب',
        'lost' => 'مفقود',
    ],

    'lead_source' => [
        'direct' => 'مباشر',
        'referral' => 'توصية',
        'google' => 'جوجل',
        'social' => 'شبكات اجتماعية',
        'campaign' => 'حملة',
        'field' => 'زيارة ميدانية',
        'partner' => 'شريك',
    ],

    'sales_activity' => [
        'call' => 'اتصال',
        'email' => 'بريد',
        'meeting' => 'اجتماع',
        'note' => 'ملاحظة',
        'proposal' => 'عرض',
    ],

    'expense_category' => [
        'fuel' => 'وقود',
        'maintenance' => 'صيانة',
        'fine' => 'مخالفة',
        'insurance' => 'تأمين',
        'registration' => 'تسجيل واستمارة',
        'inspection' => 'فحص دوري',
        'other' => 'أخرى',
    ],

    'rfq_status' => [
        'sent' => 'مرسل للموردين',
        'quotes_received' => 'وصلت العروض',
        'awarded' => 'تمت الترسية',
        'cancelled' => 'ملغى',
    ],

    'supplier_quote_status' => [
        'received' => 'مستلم',
        'awarded' => 'فائز',
        'not_selected' => 'لم يُختر',
    ],
];
