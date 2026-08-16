import {
  CourseLearningData,
  CourseLearningModule,
  CourseReel,
  VideoQuality,
} from './types';

// Public, codec-friendly fixtures used only by the local review course. Each
// primary has a fallback on another host so a single CDN outage cannot make
// the whole course look broken. Published courses still use the Bunny URLs
// returned by the API.
const SAMPLE_VIDEOS = [
  {
    primary:
      'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4',
    fallback: 'https://www.w3schools.com/html/mov_bbb.mp4',
  },
  {
    primary: 'https://www.w3schools.com/html/mov_bbb.mp4',
    fallback: 'https://media.w3.org/2010/05/sintel/trailer.mp4',
  },
  {
    primary: 'https://media.w3.org/2010/05/sintel/trailer.mp4',
    fallback:
      'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4',
  },
  {
    primary: 'https://media.w3.org/2010/05/bunny/trailer.mp4',
    fallback: 'https://test-streams.mux.dev/x36xhzz/x36xhzz.m3u8',
  },
  {
    primary: 'https://media.w3.org/2010/05/video/movie_300.mp4',
    fallback: 'https://www.w3schools.com/html/mov_bbb.mp4',
  },
];

const TITLES = [
  'حدد الخدمة التي ستبيعها',
  'حوّل خبرتك إلى عرض واضح',
  'اختر العميل المناسب لك',
  'اكتب وصفًا يجعل قيمتك مفهومة',
  'ابنِ أول نموذج لعرضك',
  'سعّر البداية بدون عشوائية',
  'اصنع بورتفوليو يقنع بسرعة',
  'اكتب رسالة تقديم لا تبدو منسوخة',
  'رتّب مكالمة اكتشاف قصيرة',
  'حوّل الحديث إلى اتفاق واضح',
  'افهم اعتراض السعر الحقيقي',
  'قدّم نطاق العمل باحتراف',
  'قسّم المشروع إلى مراحل',
  'احمِ وقتك من التعديلات المفتوحة',
  'ابدأ المشروع بسؤال صحيح',
  'شارك التقدم دون إزعاج العميل',
  'تعامل مع التأخير بهدوء',
  'قدّم النسخة الأولى بثقة',
  'اجمع الملاحظات في جولة واحدة',
  'سلّم المشروع كخبير',
  'اطلب شهادة عميل قوية',
  'حوّل المشروع إلى دراسة حالة',
  'ارفع سعرك في التوقيت المناسب',
  'ابنِ نظام متابعة للعملاء',
  'حافظ على عميلك بعد التسليم',
  'حوّل العميل إلى ترشيحات',
  'نظّم أسبوعك كمستقل',
  'راقب دخلك بلا تعقيد',
  'خطط لشهرك القادم',
  'ابنِ مسارك المستقل خطوة بخطوة',
];

const CAPTIONS = [
  'اكتب مهارة واحدة تستطيع تحويلها إلى نتيجة واضحة، ولا تبدأ بقائمة طويلة من كل ما تعرفه.',
  'صف خدمتك بهذه الصيغة: أساعد عميلًا محددًا على الوصول إلى نتيجة محددة خلال نطاق واضح.',
  'العميل المناسب يشعر بالمشكلة الآن، يملك قرار الشراء، ويستفيد مباشرة من النتيجة.',
  'لا تقل إنك خبير فقط؛ اشرح ما الذي سيتغير لدى العميل بعد العمل معك.',
  'اصنع نموذجًا صغيرًا يثبت طريقة تفكيرك حتى لو لم يكن لديك عميل سابق.',
  'ابدأ بسعر يحترم وقتك ويسهل قرار التجربة، ثم ارفعه مع وضوح النتائج.',
  'اجعل أول شاشة في البورتفوليو تقول: المشكلة، الحل، والنتيجة؛ بلا مقدمات طويلة.',
  'خصص أول سطر في رسالة التقديم لمشكلة العميل الحقيقية كي يعرف أنك قرأت طلبه.',
  'في مكالمة الاكتشاف اسأل عن النتيجة والموعد والعائق قبل أن تتحدث عن نفسك.',
  'اختم كل نقاش بخطوة تالية محددة: عرض، موعد، أو قرار؛ لا تترك المحادثة معلقة.',
  'اعتراض السعر غالبًا اعتراض على وضوح القيمة أو المخاطرة، وليس رقمًا فقط.',
  'ثبت ما ستسلمه، عدد جولات التعديل، وما لا يشمله الاتفاق قبل بدء التنفيذ.',
  'قسّم العمل إلى مراحل قصيرة يراها العميل بدل انتظار مفاجأة واحدة في النهاية.',
  'ضع حدًا واضحًا للتعديلات المجانية واكتب تكلفة أي توسع قبل تنفيذه.',
  'ابدأ بسؤال يزيل أكبر غموض في المشروع؛ السؤال الصحيح يوفر ساعات من التخمين.',
  'شارك تقدمًا له معنى: ما أنجزته، ما يحتاج قرارًا، والخطوة القادمة.',
  'عند التأخير اذكر الحقيقة مبكرًا، أثرها، والخطة الجديدة دون أعذار طويلة.',
  'قدّم النسخة الأولى باعتبارها حلًا قابلًا للنقاش، واشرح القرارات التي اتخذتها.',
  'اجمع الملاحظات في جولة واحدة مرتبطة بالأهداف حتى لا يتحول العمل إلى تعديلات عشوائية.',
  'التسليم المحترف يحتوي الملفات النهائية، طريقة الاستخدام، وما يحتاجه العميل لاحقًا.',
  'اطلب شهادة العميل مباشرة بعد ظهور النتيجة، وسهّل عليه بسؤالين محددين.',
  'اكتب دراسة الحالة كقصة قرار: لماذا اخترت الحل، ماذا نفذت، وما الأثر.',
  'ارفع سعرك عندما صار الطلب أعلى أو النتيجة أوضح، لا لمجرد مرور الوقت.',
  'احتفظ بقائمة بسيطة للعميل، آخر تواصل، والخطوة القادمة حتى لا تضيع الفرص.',
  'بعد التسليم اقترح متابعة مفيدة مرتبطة بالنتيجة بدل رسالة بيع عامة.',
  'اطلب ترشيحًا لشخص محدد يمكن أن يستفيد، وليس طلبًا عامًا ومحرجًا.',
  'خصص وقتًا للبيع والتنفيذ والتعلم؛ خلط الثلاثة طوال اليوم يقتل التركيز.',
  'راجع الدخل المتوقع والمؤكد والمصروفات مرة أسبوعيًا في جدول واحد.',
  'اختر هدفًا واحدًا للشهر واربطه بعدد محاولات يومية تستطيع التحكم فيها.',
  'خطتك ليست مثالية؛ المطلوب إيقاع ثابت من عرض واضح، تواصل، تنفيذ، وتحسين.',
];

// The public sample URLs are single MP4 files. Never advertise a resolution
// selector unless the source supplies real variants for those resolutions.
const QUALITIES: VideoQuality[] = ['auto'];

const createReels = (
  moduleId: string,
  start: number,
  end: number,
): CourseReel[] =>
  TITLES.slice(start - 1, end).map((title, localIndex) => {
    const reelNumber = start + localIndex;
    const video = SAMPLE_VIDEOS[(reelNumber - 1) % SAMPLE_VIDEOS.length];
    return {
      id: `demo-reel-${reelNumber}`,
      lessonId: `demo-lesson-${reelNumber}`,
      sectionId: `demo-section-${reelNumber}`,
      moduleId,
      title,
      caption: CAPTIONS[reelNumber - 1],
      videoUrl: video.primary,
      fallbackVideoUrl: video.fallback,
      thumbnailUrl: undefined,
      durationSeconds: 90,
      availableQualities: QUALITIES,
      isPreview: reelNumber <= 2,
      isLocked: false,
      isCompleted: false,
      reelNumber,
    };
  });

const createModules = (): CourseLearningModule[] => [
  {
    id: 'demo-module-1',
    title: 'الوحدة الأولى · عرضك الأول',
    description: 'من اختيار الخدمة إلى عرض قابل للبيع.',
    order: 1,
    isLocked: false,
    attachments: [
      {
        id: 'brief-template',
        title: 'قالب تحديد الخدمة — للكمبيوتر',
        url: 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf',
        fileType: 'pdf',
        fileSize: '13 KB',
        platform: 'computer',
      },
    ],
    reels: createReels('demo-module-1', 1, 10),
    project: {
      id: 'demo-project-1',
      sectionId: 'demo-project-section-1',
      moduleId: 'demo-module-1',
      title: 'مشروع العبور · صِغ عرض خدمتك',
      requirements:
        'اكتب اسم الخدمة، العميل الذي تخدمه، والنتيجة التي تعده بها. ارفع صورة أو ملفًا يوضح محاولتك؛ المطلوب مجهود حقيقي لا إجابة مثالية.',
      status: 'not_submitted',
      isGraduationProject: false,
      attachments: [],
    },
  },
  {
    id: 'demo-module-2',
    title: 'الوحدة الثانية · تنفيذ احترافي',
    description: 'من الاتفاق إلى تسليم يجعل العميل يعود.',
    order: 2,
    isLocked: true,
    attachments: [
      {
        id: 'scope-template',
        title: 'نموذج نطاق العمل',
        url: 'https://www.w3.org/WAI/ER/tests/xhtml/testfiles/resources/pdf/dummy.pdf',
        fileType: 'pdf',
        fileSize: '13 KB',
        platform: 'mobile',
      },
    ],
    reels: createReels('demo-module-2', 11, 20),
    project: {
      id: 'demo-project-2',
      sectionId: 'demo-project-section-2',
      moduleId: 'demo-module-2',
      title: 'مشروع العبور · جهّز خطة تسليم',
      requirements:
        'اختر مشروعًا افتراضيًا وحدد مراحله وموعد كل مرحلة وما يحتاجه العميل منك. ارفع لقطة أو ملف الخطة.',
      status: 'not_submitted',
      isGraduationProject: false,
      attachments: [],
    },
  },
  {
    id: 'demo-module-3',
    title: 'الوحدة الثالثة · نمو مستدام',
    description: 'حوّل كل مشروع إلى فرصة للمشروع التالي.',
    order: 3,
    isLocked: true,
    attachments: [],
    reels: createReels('demo-module-3', 21, 30),
    project: {
      id: 'demo-project-3',
      sectionId: 'demo-project-section-3',
      moduleId: 'demo-module-3',
      title: 'مشروع التخرج · خطتك لأول ٣٠ يومًا',
      requirements:
        'ضع خطة عملية لأول ٣٠ يومًا تشمل عرضك، نماذج أعمالك، وعدد رسائل التقديم الأسبوعية. ارفع الخطة بأي صيغة مناسبة.',
      status: 'not_submitted',
      isGraduationProject: true,
      attachments: [],
    },
  },
];

export const createDemoCourse = (options: {
  chatAvailable?: boolean;
  accessType?: string;
} = {}): CourseLearningData => ({
  id: 'demo-freelance-course',
  title: 'من أول مهارة إلى أول عميل',
  image: require('../../assets/images/demo-course/ui-freelance-cover.jpg'),
  totalReels: 30,
  modules: createModules(),
  isDemo: true,
  accessType: options.accessType || 'demo',
  chatAvailable: options.chatAvailable ?? true,
});
