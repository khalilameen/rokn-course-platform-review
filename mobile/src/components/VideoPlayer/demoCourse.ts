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
  'ابنِ مسارك المستقل بالتدرج',
];

const CAPTIONS = [
  'اكتب مهارة واحدة تستطيع تحويلها إلى نتيجة واضحة\nلا تبدأ بقائمة طويلة من كل ما تعرفه',
  'صف خدمتك بوضوح\nعميل محدد ونتيجة محددة ونطاق واضح',
  'العميل المناسب يشعر بالمشكلة الآن\nيملك قرار الشراء ويستفيد من النتيجة',
  'لا تقل إنك خبير فقط\nاشرح ما الذي سيتغير لدى العميل بعد العمل معك',
  'اصنع نموذجًا صغيرًا يثبت طريقة تفكيرك حتى لو لم يكن لديك عميل سابق',
  'ابدأ بسعر يحترم وقتك ويسهل قرار التجربة\nارفعه مع وضوح النتائج',
  'ابدأ البورتفوليو بالمشكلة والحل والنتيجة\nبلا مقدمات طويلة',
  'خصص أول سطر في رسالة التقديم لمشكلة العميل الحقيقية',
  'اسأل عن النتيجة والموعد والعائق قبل أن تتحدث عن نفسك',
  'اختم كل نقاش بفعل تال واضح\nعرض أو موعد أو قرار',
  'اعتراض السعر غالبًا على وضوح القيمة أو المخاطرة لا على الرقم وحده',
  'ثبت ما ستسلمه وعدد جولات التعديل وما لا يشمله الاتفاق',
  'قسّم العمل إلى مراحل قصيرة يراها العميل',
  'ضع حدًا واضحًا للتعديلات المجانية وسعرًا لأي توسع',
  'ابدأ بالسؤال الذي يزيل أكبر غموض في المشروع',
  'شارك ما أنجزته وما يحتاج قرارًا وما ستفعله بعد ذلك',
  'عند التأخير اذكر الحقيقة وأثرها والخطة الجديدة دون أعذار طويلة',
  'قدّم النسخة الأولى حلًا قابلًا للنقاش واشرح قراراتك',
  'اجمع الملاحظات في جولة واحدة مرتبطة بالأهداف',
  'التسليم المحترف يضم الملفات النهائية وطريقة الاستخدام وما يأتي بعده',
  'اطلب شهادة العميل بعد ظهور النتيجة وسهّلها بسؤالين محددين',
  'اكتب دراسة الحالة كقصة قرار\nلماذا اخترت الحل وما الذي نفذته وما أثره',
  'ارفع سعرك عندما يزيد الطلب أو تتضح النتيجة لا لمجرد مرور الوقت',
  'احتفظ بقائمة بسيطة للعميل وآخر تواصل والفعل التالي',
  'بعد التسليم اقترح متابعة مفيدة مرتبطة بالنتيجة',
  'اطلب ترشيحًا لشخص محدد يمكن أن يستفيد',
  'خصص وقتًا للبيع والتنفيذ والتعلم\nخلطها طوال اليوم يقتل التركيز',
  'راجع الدخل المتوقع والمؤكد والمصروفات مرة أسبوعيًا',
  'اختر هدفًا واحدًا للشهر واربطه بمحاولات يومية يمكنك التحكم فيها',
  'خطتك لا تحتاج إلى المثالية\nتحتاج إلى عرض واضح وتواصل وتنفيذ وتحسين',
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
    title: 'الوحدة الأولى  عرضك الأول',
    description: 'من اختيار الخدمة إلى عرض قابل للبيع',
    order: 1,
    isLocked: false,
    attachments: [],
    reels: createReels('demo-module-1', 1, 10),
    project: {
      id: 'demo-project-1',
      sectionId: 'demo-project-section-1',
      moduleId: 'demo-module-1',
      title: 'مشروع العبور  صِغ عرض خدمتك',
      requirements:
        'اكتب اسم الخدمة والعميل والنتيجة\nارفع صورة أو ملفًا يوضح محاولتك',
      status: 'not_submitted',
      isGraduationProject: false,
      feedbackLevel: 'pass_only',
      reportEnabled: false,
      attachments: [],
    },
  },
  {
    id: 'demo-module-2',
    title: 'الوحدة الثانية  تنفيذ احترافي',
    description: 'من الاتفاق إلى تسليم يجعل العميل يعود',
    order: 2,
    isLocked: true,
    attachments: [],
    reels: createReels('demo-module-2', 11, 20),
    project: {
      id: 'demo-project-2',
      sectionId: 'demo-project-section-2',
      moduleId: 'demo-module-2',
      title: 'مشروع العبور  جهّز خطة تسليم',
      requirements:
        'اختر مشروعًا افتراضيًا وحدد مراحله ومواعيده وما يحتاجه العميل\nارفع لقطة أو ملف الخطة',
      status: 'not_submitted',
      isGraduationProject: false,
      feedbackLevel: 'pass_only',
      reportEnabled: false,
      attachments: [],
    },
  },
  {
    id: 'demo-module-3',
    title: 'الوحدة الثالثة  نمو مستدام',
    description: 'حوّل كل مشروع إلى فرصة للمشروع التالي',
    order: 3,
    isLocked: true,
    attachments: [],
    reels: createReels('demo-module-3', 21, 30),
    project: {
      id: 'demo-project-3',
      sectionId: 'demo-project-section-3',
      moduleId: 'demo-module-3',
      title: 'مشروع التخرج  خطتك لأول ٣٠ يومًا',
      requirements:
        'ضع خطة لأول ٣٠ يومًا تشمل عرضك ونماذج أعمالك ورسائل التقديم الأسبوعية\nارفع الخطة بأي صيغة مناسبة',
      status: 'not_submitted',
      isGraduationProject: true,
      feedbackLevel: 'pass_only',
      reportEnabled: false,
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
