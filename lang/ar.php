<?php

declare(strict_types=1);

/**
 * The storefront's wording, in Arabic.
 *
 * ONE FILE PER LANGUAGE, NOT ONE FILE WITH EVERY LANGUAGE IN IT
 *
 * This replaces config/lang.php, which held 'ar' and 'en' side by side in one
 * array. That shape works for eight strings and stops working at eighty: every
 * translator opening the file sees both languages, a missing key in one is
 * invisible next to a present key in the other, and the file has to be edited by
 * two people at once to add a single string. Adding English later is now copying
 * this file to lang/en.php and translating the right-hand side -- and Lang falls
 * back to this one key by key, so a half-finished lang/en.php shows English where
 * it has been written and Arabic where it has not, rather than a blank page or a
 * raw key.
 *
 * WHAT BELONGS HERE
 *
 * Words the site chrome and the sections built in this round put on screen. What
 * is NOT here yet is the Arabic written inline in the older pages -- the cart, the
 * checkout, the product page, the account screens, the editor, the tracking page.
 * Those are Arabic already and nothing is broken by leaving them; moving them is a
 * mechanical job of its own and doing it halfway would leave the codebase with two
 * conventions and no way to tell which page follows which.
 *
 * PLACEHOLDERS
 *
 * :name, filled by the second argument to Lang::get(). There are no plural rules:
 * Arabic has six and implementing them for the two strings that would use them is
 * not worth the machinery, so those two are phrased to read correctly for any
 * number ("منتج" with the count beside it rather than agreeing with it).
 */

return [
    /* ------------------------------------------------------------- navigation */

    // `short` is what the link reads on screen; `full` is its accessible name,
    // so shortening the visible text costs a screen reader nothing.
    'nav.plain.short'   => 'سادة',
    'nav.plain.full'    => 'منتجات سادة',
    'nav.printed.short' => 'مطبوع',
    'nav.printed.full'  => 'منتجات مطبوعة',
    'nav.about.short'   => 'عن الورشة',
    'nav.about.full'    => 'عن الورشة',

    'nav.menu'          => 'القائمة',
    'nav.main'          => 'التنقّل الرئيسي',
    'nav.cart'          => 'السلة',
    'nav.cart.count'    => ':count قطعة في السلة',
    'nav.signin'        => 'تسجيل الدخول',
    'nav.account'       => 'حسابي',

    /* ------------------------------------------------------------------ hero */

    'hero.cta'          => 'صمّم قميصك',
    'hero.slides'       => 'اختر الشريحة',
    'hero.featured'     => 'منتجات مختارة',
    'hero.empty.title'  => 'لا توجد شرائح منشورة',
    'hero.empty.text'   => 'أضف شريحة من لوحة التحكم، أو فعّل شريحة معطّلة.',

    /* ------------------------------------------------------------- home page */

    'home.cats.eyebrow' => 'الكتالوج',
    'home.cats.title'   => 'قسمان، لا أكثر',
    'home.cats.lede'    => 'اختر قصّة: مطبوعة جاهزة، أو سادة تبني عليها تصميمك.',

    'home.printed.title' => 'الملابس المطبوعة',
    'home.printed.text'  => 'تصاميم مطبوعة بحبر DTF يتغلغل في النسيج، على قصّة فاخرة أثقل قليلًا.',
    'home.plain.title'   => 'الملابس السادة',
    'home.plain.text'    => 'قطن ممشّط ٢٤٠ غم بلا طباعة، أو أساسًا لتصميم تصنعه بنفسك.',
    'home.cats.cta'      => 'تسوّق الآن',

    'home.trending.eyebrow' => 'مختارة بعناية',
    'home.trending.title'   => 'المنتجات الرائجة',
    'home.trending.more'    => 'عرض المزيد',

    'home.promo.eyebrow' => 'محرّر ثلاثي الأبعاد',

    /*
     * The workshop section under the hero. Three claims about how the shop makes
     * what it sells, and every one of them is a fact the rest of the site relies
     * on: the gsm is the hero's own badge, the fifty washes is the DTF slide's
     * line, and the resolution check is what the upload page actually does.
     */
    'home.about.title'   => 'من القماش إلى الطرد، في مكان واحد',
    'home.about.1.title' => 'قماش مختار بعناية',
    'home.about.1.text'  => 'قطن ممشّط ٢٤٠ غم، مغسول قبل القص فلا ينكمش بعد أول غسلة.',
    'home.about.2.title' => 'طباعة تندمج مع النسيج',
    'home.about.2.text'  => 'حبر DTF يتغلغل في الألياف بدل أن يجلس فوقها، فيبقى تصميمك واضحًا بعد خمسين غسلة.',
    'home.about.3.title' => 'ملف طباعة جاهز للإنتاج',
    'home.about.3.text'  => 'نفحص دقّة تصميمك قبل الشراء، ثم نجهّز ملف PDF بمقاس مضبوط للإنتاج.',

    /* --------------------------------------------------------- product cards */

    'card.soldout'  => 'نفدت الكمية',
    'card.colors'   => 'الألوان المتوفّرة',
    'card.design.title' => 'صمّم بنفسك',
    'card.design.text'  => 'اختر قصّة، ارفع تصميمك وضعه على القميص قبل الطلب.',

    /* ------------------------------------------------------- products listing */

    'products.printed'     => 'الملابس المطبوعة',
    'products.plain'       => 'الملابس السادة',
    'products.tab.printed' => 'مطبوعة',
    'products.tab.plain'   => 'سادة',
    'products.tabs'        => 'أقسام الكتالوج',
    'products.crumbs'      => 'مسار التنقّل',
    'products.home'        => 'الرئيسية',

    // Phrased so the number reads correctly beside it whatever it is.
    'products.count'         => ':count منتج',
    'products.count.matched' => ':count منتج مطابق',

    'products.search'        => 'ابحث في هذا القسم',
    'products.search.clear'  => 'امسح البحث',
    'products.color'         => 'اللون',
    'products.color.all'     => 'كل الألوان',
    'products.size'          => 'المقاس',
    'products.size.all'      => 'كل المقاسات',
    'products.sort'          => 'الترتيب',
    'products.apply'         => 'تطبيق',
    'products.clear'         => 'إزالة التصفية',

    'products.sort.newest'      => 'الأحدث',
    'products.sort.bestselling' => 'الأكثر مبيعًا',
    'products.sort.price_asc'   => 'السعر: من الأقل',
    'products.sort.price_desc'  => 'السعر: من الأعلى',

    'products.pages'    => 'صفحات النتائج',
    'products.prev'     => 'السابق',
    'products.next'     => 'التالي',

    'products.empty.search.title' => 'لا نتائج لـ ":term"',
    'products.empty.search.text'  => 'جرّب كلمة أخرى، أو تصفّح القسم كاملًا.',
    'products.empty.search.cta'   => 'امسح البحث',
    'products.empty.narrow.title' => 'لا منتجات بهذه المواصفات',
    'products.empty.narrow.text'  => 'جرّب لونًا أو مقاسًا آخر، أو اعرض القسم كاملًا.',
    'products.empty.narrow.cta'   => 'اعرض القسم كاملًا',
    'products.empty.none.title'   => 'لم يُنشر شيء في هذا القسم بعد',
    'products.empty.none.text'    => 'نحضّر القطع الأولى. حتى ذلك الحين، تفضّل بالقسم الآخر أو ابدأ تصميمك.',
    'products.empty.none.plain'   => 'تصفّح السادة',
    'products.empty.none.printed' => 'تصفّح المطبوعة',
    'products.empty.none.design'  => 'صمّم بنفسك',

    /* ---------------------------------------------------------------- footer */

    'footer.about' => 'ورشة أردنية تفصّل التيشيرتات وتطبعها في مكان واحد: قطن ممشّط ٢٤٠ غم، '
                    . 'وطباعة DTF لا تبهت بعد الغسيل، وتصميم تصنعه بنفسك قبل أن تطلبه.',

    'footer.links'        => 'روابط سريعة',
    'footer.links.home'   => 'الرئيسية',
    'footer.links.design' => 'صمّم بنفسك',

    'footer.service'         => 'خدمة العملاء',
    'footer.service.shipping' => 'الشحن والتوصيل',
    'footer.service.returns'  => 'الاستبدال والإرجاع',
    'footer.service.faq'      => 'الأسئلة الشائعة',
    'footer.service.track'    => 'تتبّع طلبك',
    'footer.service.contact'  => 'اتصل بنا',

    'footer.contact'          => 'تواصل معنا',
    'footer.contact.whatsapp' => 'واتساب',
    'footer.contact.form'     => 'نموذج التواصل',
    'footer.social'           => 'حسابات التواصل الاجتماعي',
    'footer.social.instagram' => 'إنستغرام',
    'footer.social.facebook'  => 'فيسبوك',
    'footer.social.tiktok'    => 'تيك توك',

    'footer.rights' => '© :year BADOOSAT — جميع الحقوق محفوظة.',

    /* ------------------------------------------------------------ help page */

    'help.title' => 'المساعدة',
    'help.lede'  => 'الشحن، الإرجاع، الأسئلة الشائعة، وكيف تصل إلينا.',

    'help.shipping.title' => 'الشحن والتوصيل',
    'help.shipping.flat'  => 'أجرة توصيل ثابتة :amount إلى جميع المحافظات.',
    'help.shipping.free'  => 'والتوصيل مجاني للطلبات التي يزيد مجموعها على :amount بعد أي خصم كمية.',
    'help.shipping.text'  => 'القطع الجاهزة تُشحن من المخزون. القطع المخصّصة تُطبع عند الطلب، '
                           . 'فتُشحن بعد انتهاء الإنتاج.',
    'help.shipping.track' => 'صفحة تتبّع الطلب',
    'help.shipping.after' => 'تتابع الحالتين من :link برقم الطلب ورقم هاتفك.',

    'help.returns.title' => 'الاستبدال والإرجاع',
    'help.returns.ready' => 'القطع الجاهزة تُستبدل أو تُرجع خلال أربعة عشر يومًا من الاستلام، '
                          . 'بشرط أن تكون غير مستعملة وبعلامتها الأصلية. تواصل معنا أولًا لنرتّب الاستلام.',
    'help.returns.custom' => 'القطعة المخصّصة تُصنع لك وحدك، ولا يمكن إعادة بيعها، فهي غير قابلة للإرجاع '
                           . 'بعد بدء الإنتاج. أما إن وصلتك بعيب في الطباعة أو القماش فنحن نعيد إنتاجها على حسابنا.',

    'help.faq.title' => 'الأسئلة الشائعة',
    'help.faq.1.q' => 'هل تبهت الطباعة بعد الغسيل؟',
    'help.faq.1.a' => 'نطبع بتقنية DTF: الحبر يتغلغل في ألياف القطن بدل أن يجلس فوقها، '
                     . 'فيبقى التصميم كما هو بعد خمسين غسلة. اغسل القطعة مقلوبة بماء بارد لأطول عمر.',
    'help.faq.2.q' => 'ما مقاس منطقة الطباعة؟',
    'help.faq.2.a' => 'مساحة الصدر ٢٨×٣٥ سم، ونطبع بدقّة ٣٠٠ نقطة في البوصة. '
                     . 'نفحص دقّة ملفك قبل الشراء ونخبرك إن كانت أقل من اللازم.',
    'help.faq.3.q' => 'كم تستغرق القطعة المخصّصة؟',
    'help.faq.3.a' => 'القطع المخصّصة تُطبع عند الطلب، فتحتاج وقتًا أطول من القطع الجاهزة. '
                     . 'يصلك رقم طلب تتابعه من صفحة تتبّع الطلب في أي وقت.',
    'help.faq.4.q' => 'هل أستطيع الطباعة على الوجهين؟',
    'help.faq.4.a' => 'نعم. وجهان يعنيان طبعتين ومكبسين، فيُحسب سعرهما أعلى من وجه واحد، '
                     . 'ويظهر الفرق في السلة قبل الدفع.',

    'help.faq.qty.q' => 'هل هناك خصم للكميات؟',
    'help.faq.qty.a' => 'نعم، ويُحسب تلقائيًا على كل سطر في السلة: :tiers.',
    'help.faq.qty.tier' => 'من :from قطعة: :percent٪',

    'help.faq.custom.q' => 'كم يضيف تصميمي الخاص إلى السعر؟',
    'help.faq.custom.a' => 'طباعة تصميمك على القطعة تضيف :amount للقطعة الواحدة، '
                          . 'ويظهر المبلغ في السلة قبل الدفع.',

    'help.contact.title' => 'اتصل بنا',
    'help.contact.text'  => 'أسرع طريقة للوصول إلينا هي الرسائل المباشرة. نجيب خلال ساعات العمل.',
    'help.contact.whatsapp' => 'راسلنا على واتساب',
    'help.contact.track'    => 'تتبّع طلبًا',
    'help.contact.missing'  => 'لم يُضف رقم واتساب بعد. يُضاف من config/local.php ضمن shop.contact.whatsapp.',

    /* ------------------------------------------------------------- languages */

    // This language's name in itself, for the dashboard's language switch.
    'lang.native' => 'العربية',

    /* -------------------------------------------------------- order statuses */

    // Shared by the storefront (order page, account, tracking) and the
    // dashboard. The words are the ones OrderService::label() always returned.
    'order.status.pending'       => 'بانتظار التأكيد',
    'order.status.paid'          => 'مدفوع',
    'order.status.in_production' => 'قيد الإنتاج',
    'order.status.shipped'       => 'تم الشحن',
    'order.status.delivered'     => 'تم التسليم',
    'order.status.cancelled'     => 'ملغى',
    'order.status.refunded'      => 'مُسترجَع',

    /* -------------------------------------------------------------- dashboard */

    /*
     * The admin panel's wording, moved here page by page as each screen is
     * rebuilt. Keys in {n, plural, ...} form are ICU messages, formatted by
     * App\Support\Format::plural() -- ICU knows Arabic's six plural forms,
     * which is what lets these agree with their number instead of standing
     * beside it.
     */
    'admin.title'          => 'لوحة التحكم',
    'admin.skip'           => 'تخطَّ إلى المحتوى',
    'admin.list_sep'       => '، ',

    'admin.nav.label'      => 'أقسام اللوحة',
    'admin.nav.slides'     => 'الشرائح',
    'admin.nav.garments'   => 'الأنواع',
    'admin.nav.products'   => 'المنتجات',
    'admin.nav.catalog'    => 'الكتالوج',
    'admin.nav.home'       => 'الواجهة',
    'admin.nav.orders'     => 'الطلبات',
    'admin.nav.system'     => 'حالة النظام',
    'admin.nav.view_site'  => 'عرض الموقع في نافذة جديدة',
    'admin.nav.logout'     => 'تسجيل الخروج',
    'admin.lang.label'     => 'لغة اللوحة',

    'admin.action.back'         => 'رجوع',
    'admin.action.close'        => 'إغلاق',
    'admin.action.back_to_list' => 'العودة إلى القائمة',

    'admin.state.error_title' => 'تعذّر التحميل',
    'admin.state.error_card'  => 'تعذّر حساب هذا الرقم. حدّث الصفحة.',
    'admin.state.error_list'  => 'تعذّر تحميل الطلبات.',
    'admin.state.error_hint'  => 'حدّث الصفحة. إن تكرر الخطأ فراجع سجل الأخطاء في storage/logs.',

    'admin.filters.label'       => 'تصفية الطلبات',
    'admin.filters.active'      => 'عوامل التصفية',
    'admin.filters.apply'       => 'تطبيق',
    'admin.filters.clear'       => 'مسح التصفية',
    'admin.filters.from'        => 'من تاريخ',
    'admin.filters.to'          => 'إلى تاريخ',
    'admin.filters.search'      => 'بحث',
    'admin.filters.search_hint' => 'رقم الطلب، الاسم، البريد أو الهاتف',

    'admin.time.today'     => 'اليوم',
    'admin.time.yesterday' => 'أمس',
    'admin.time.days_ago'  => '{n, plural, one{منذ يوم} two{منذ يومين} few{منذ # أيام} many{منذ # يومًا} other{منذ # يوم}}',
    'admin.count.orders'   => '{n, plural, zero{لا طلبات} one{طلب واحد} two{طلبان} few{# طلبات} many{# طلبًا} other{# طلب}}',

    'admin.trend.vs_last_month' => ':pct مقارنة بالشهر الماضي',
    'admin.trend.no_base'       => 'لا طلبات في الشهر الماضي للمقارنة',

    'admin.stat.waiting'      => 'طلبات جديدة',
    'admin.stat.waiting_none' => 'لا شيء بانتظار الإنتاج',
    'admin.stat.oldest'       => 'أقدمها :age',
    'admin.stat.printing'     => 'قيد الطباعة',
    'admin.stat.print_files_pending' => '{n, plural, zero{كل ملفات الطباعة جاهزة} one{ملف طباعة واحد بانتظار التجهيز} two{ملفا طباعة بانتظار التجهيز} few{# ملفات طباعة بانتظار التجهيز} many{# ملفًا بانتظار التجهيز} other{# ملف طباعة بانتظار التجهيز}}',
    'admin.stat.orders_month' => 'طلبات هذا الشهر',
    'admin.stat.value_month'  => 'قيمة طلبات هذا الشهر',
    'admin.stat.daily_label'  => 'الطلبات يوميًا خلال آخر 14 يومًا',

    'admin.orders.title'             => 'الطلبات',
    'admin.orders.subtitle'          => 'تابع الطلبات من التأكيد إلى الشحن، وحمّل ملفات الطباعة.',
    'admin.orders.summary'           => 'ملخّص الطلبات',
    'admin.orders.export_production' => 'صدّر أوامر الإنتاج',
    'admin.orders.updated'           => 'تم تحديث حالة الطلب.',
    'admin.orders.tabs_label'        => 'حالة الطلبات',
    'admin.orders.tab.all'           => 'كل الطلبات',
    'admin.orders.tab.new'           => 'جديدة',
    'admin.orders.tab.printing'      => 'قيد الطباعة',
    'admin.orders.tab.shipped'       => 'تم الشحن',
    'admin.orders.empty'             => 'لا توجد طلبات هنا بعد.',
    'admin.orders.empty_filtered'    => 'لا طلبات تطابق التصفية.',
    'admin.orders.not_found'         => 'لم يُعثر على هذا الطلب.',
    'admin.orders.detail'            => 'تفاصيل الطلب',
    'admin.orders.placed'            => 'تاريخ الطلب',
    'admin.orders.customer'          => 'العميل',
    'admin.orders.ship_to'           => 'الشحن إلى',
    'admin.orders.note'              => 'ملاحظة العميل',
    'admin.orders.items'             => 'قطع الطلب',
    'admin.orders.colour'            => 'اللون',
    'admin.orders.size'              => 'المقاس',
    'admin.orders.quantity'          => 'الكمية',
    'admin.orders.export_sheet'      => 'صدّر ملف الإنتاج',
    'admin.orders.subtotal'          => 'المجموع',
    'admin.orders.discount'          => 'الخصم',
    'admin.orders.shipping'          => 'الشحن',
    'admin.orders.total'             => 'الإجمالي',
    'admin.orders.art_alt.front'     => 'تصميم الصدر على :name',
    'admin.orders.art_alt.back'      => 'تصميم الظهر على :name',
    'admin.orders.move.paid'          => 'تأكيد الدفع',
    'admin.orders.move.in_production' => 'بدء الطباعة',
    'admin.orders.move.shipped'       => 'تأكيد الشحن',
    'admin.orders.move.delivered'     => 'تأكيد التسليم',
    'admin.orders.move.cancelled'     => 'إلغاء الطلب',
    'admin.orders.move.refunded'      => 'استرجاع المبلغ',
    'admin.orders.confirm.cancelled'  => 'إلغاء الطلب :number؟ لا يمكن التراجع عن ذلك.',
    'admin.orders.confirm.refunded'   => 'استرجاع مبلغ الطلب :number؟ لا يمكن التراجع عن ذلك.',

    'admin.payment.cod'             => 'الدفع عند الاستلام',
    'admin.payment.status.unpaid'   => 'غير مدفوع',
    'admin.payment.status.paid'     => 'مدفوع',
    'admin.payment.status.refunded' => 'مُسترجَع',

    'admin.side.front' => 'الصدر',
    'admin.side.back'  => 'الظهر',

    'admin.print.ready'         => 'تصميم جاهز: :name',
    'admin.print.custom_editor' => 'تصميم العميل (المحرّر)',
    'admin.print.custom_upload' => 'تصميم العميل (رفع مباشر)',
    'admin.print.blank'         => 'بلا طباعة',
    'admin.print.not_needed'    => 'لا يلزم ملف طباعة',
    'admin.print.preparing'     => 'ملف الطباعة قيد التجهيز',
    'admin.print.download'      => 'حمّل ملف الطباعة',
    'admin.print.preview'       => 'معاينة ملف الطباعة',
    'admin.print.download_front' => 'حمّل ملف طباعة الصدر',
    'admin.print.download_back'  => 'حمّل ملف طباعة الظهر',
    'admin.print.preview_front'  => 'معاينة ملف طباعة الصدر',
    'admin.print.preview_back'   => 'معاينة ملف طباعة الظهر',

    /* ---------------------------------------- form system, table, studio, nav */
    'admin.form.save'          => 'احفظ التغييرات',
    'admin.form.saved'         => 'حُفظت التغييرات.',
    'admin.form.unsaved'       => 'تغييرات غير محفوظة',
    'admin.form.discard'       => 'تجاهلها',
    'admin.form.cancel'        => 'إلغاء',
    'admin.form.required'      => 'املأ هذا الحقل.',
    'admin.form.errors_title'  => '{n, plural, one{أمر واحد يحتاج إلى إصلاح قبل الحفظ} two{أمران يحتاجان إلى إصلاح قبل الحفظ} few{# أمور تحتاج إلى إصلاح قبل الحفظ} many{# أمرًا يحتاج إلى إصلاح قبل الحفظ} other{# أمر يحتاج إلى إصلاح قبل الحفظ}}',
    'admin.form.errors_hint'   => 'لم يُحفظ شيء. تظهر كل مشكلة أيضًا بجانب حقلها.',
    'admin.form.matrix_colour' => 'اللون',
    'admin.form.matrix_total'  => 'المجموع',
    'admin.form.matrix_none'   => 'هذه القصّة لا تُصنع بهذا اللون وهذا المقاس.',
    'admin.form.matrix_cell'   => 'المخزون، :colour، مقاس :size',
    'admin.form.drop_here'     => 'أفلت صورة هنا أو الصقها أو اختر ملفًا',
    'admin.form.drop_choose'   => 'اختر صورة',
    'admin.form.drop_replace'  => 'استبدل الصورة',
    'admin.form.drop_remove'   => 'احذف الصورة الحالية عند الحفظ',
    'admin.form.drop_min'      => 'لا تقل عن :w × :h بكسل.',
    'admin.form.drop_small'    => 'هذه الصورة أصغر من :w × :h بكسل وقد تظهر غير حادّة.',
    'admin.form.drop_big'      => 'حجم الملف أكبر من :mb ميغابايت، وهو حدّ الرفع.',
    'admin.form.drop_type'     => 'استخدم صورة JPEG أو PNG أو WebP.',
    'admin.form.drop_chosen'   => 'جاهزة للرفع: :name',
    'admin.toast.close'        => 'إغلاق',

    'admin.table.selected'        => 'المحدَّد',
    'admin.table.clear_selection' => 'ألغِ التحديد',
    'admin.table.select_all'      => 'حدّد كل الصفوف',
    'admin.table.select_row'      => 'حدّد :name',
    'admin.table.actions'         => 'الإجراءات',
    'admin.table.row_actions'     => 'إجراءات :name',
    'admin.table.empty'           => 'لا شيء هنا بعد.',
    'admin.table.pages'           => 'الصفحات',
    'admin.table.prev'            => 'الصفحة السابقة',
    'admin.table.next'            => 'الصفحة التالية',

    'admin.studio.toolbar'       => 'أدوات المحرّر',
    'admin.studio.layers'        => 'الأقسام',
    'admin.studio.properties'    => 'الخصائص',
    'admin.studio.toggle_layers' => 'أظهر لوحة الأقسام أو أخفها',
    'admin.studio.toggle_panel'  => 'أظهر لوحة الخصائص أو أخفها',
    'admin.studio.device'        => 'عرض المعاينة',
    'admin.studio.preview_lang'  => 'لغة المعاينة',
    'admin.studio.undo'          => 'تراجع',
    'admin.studio.redo'          => 'إعادة',
    'admin.studio.zoom'          => 'التكبير',
    'admin.studio.zoom_fit'      => 'ملاءمة',

    'admin.nav.menu'             => 'القائمة',
    'admin.nav.tabs_label'       => 'صفحات هذا القسم',
    'admin.nav.group.overview'   => 'نظرة عامة',
    'admin.nav.group.orders'     => 'الطلبات',
    'admin.nav.group.catalog'    => 'الكتالوج',
    'admin.nav.group.storefront' => 'واجهة المتجر',
    'admin.nav.group.system'     => 'النظام',
    'admin.nav.tab.orders'       => 'كل الطلبات',
    'admin.nav.tab.products'     => 'المنتجات',
    'admin.nav.tab.garments'     => 'أنواع القطع',
    'admin.nav.tab.stock'        => 'المخزون',
    'admin.nav.tab.designs'      => 'التصاميم',
    'admin.nav.tab.slides'       => 'شرائح الهيرو',
    'admin.nav.tab.home'         => 'الصفحة الرئيسية',
    'admin.nav.tab.system'       => 'الحالة',
];
