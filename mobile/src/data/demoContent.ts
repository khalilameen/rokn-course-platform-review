import {ImageSourcePropType} from 'react-native';

export type CourseBadgeTone = 'neutral' | 'primary' | 'coin' | 'success';

/** Local catalogue used only when there is no authenticated session. */
export interface DemoCourse {
  id: string;
  title: string;
  description: string;
  instructor: string;
  image: ImageSourcePropType;
  label?: string;
  labelTone?: CourseBadgeTone;
  isMainCourse?: boolean;
  homeSortOrder?: number;
  homeRows?: Array<{id: string; title: string; order: number}>;
  coinPrice?: number;
  durationMinutes?: number;
  ratingAverage?: number;
  ratingsCount?: number;
  studentsCount?: number;
  progress?: number;
  category: 'freelance' | 'skills' | 'language' | 'values' | 'religious';
  owned?: boolean;
  published?: boolean;
}

export const demoCourses: DemoCourse[] = [
  {
    id: 'demo-freelance-course',
    title: 'من الصفر إلى أول عميل في العمل الحر',
    description:
      'ابنِ خدمتك وسعّرها واعرضها حتى تصل إلى أول عميل',
    instructor: 'كريم الشاذلي',
    image: require('../assets/images/demo-course/covers/course-01.jpg'),
    label: 'كورس كامل للتجربة',
    labelTone: 'success',
    isMainCourse: true,
    coinPrice: 4000,
    category: 'freelance',
    owned: false,
    published: true,
  },
  {
    id: 'demo-preview-content-marketing',
    title: 'التسويق بالمحتوى في ٣٠ يومًا',
    description:
      'خطة عملية لصناعة محتوى يجذب جمهورك ويحوّل الاهتمام إلى فرص',
    instructor: 'مروة خالد',
    image: require('../assets/images/demo-course/covers/course-08.jpg'),
    label: 'الأكثر مشاهدة',
    labelTone: 'primary',
    category: 'skills',
    published: false,
  },
  {
    id: 'demo-preview-design-basics',
    title: 'أساسيات التصميم لغير المصممين',
    description:
      'قواعد بصرية بسيطة لتقديم أفكارك بوضوح',
    instructor: 'ندى عادل',
    image: require('../assets/images/demo-course/covers/course-06.jpg'),
    label: 'مجاني عند الإطلاق',
    labelTone: 'success',
    category: 'skills',
    published: false,
  },
  {
    id: 'demo-preview-time-management',
    title: 'إدارة وقتك من دون ضغط',
    description:
      'نظام مرن لترتيب الأولويات وإنجاز المهم مع وقت للراحة',
    instructor: 'أحمد سامي',
    image: require('../assets/images/demo-course/covers/course-09.jpg'),
    label: 'جديد',
    labelTone: 'coin',
    category: 'values',
    published: false,
  },
  {
    id: 'demo-preview-mobile-video',
    title: 'صناعة الفيديو بالموبايل',
    description:
      'من الفكرة إلى المونتاج\nاصنع فيديو بالأدوات الموجودة في يدك',
    instructor: 'عمر حسن',
    image: require('../assets/images/demo-course/covers/course-10.jpg'),
    label: 'قريبًا',
    labelTone: 'neutral',
    category: 'skills',
    published: false,
  },
  {
    id: 'demo-preview-portfolio',
    title: 'ابنِ معرض أعمال يقنع العملاء',
    description:
      'حوّل مشاريعك وخبراتك إلى معرض أعمال يشرح قيمتك قبل أول مكالمة',
    instructor: 'سارة نبيل',
    image: require('../assets/images/demo-course/covers/course-20.jpg'),
    label: 'الأكثر مشاهدة',
    labelTone: 'primary',
    category: 'freelance',
    published: false,
  },
  {
    id: 'demo-preview-pricing',
    title: 'التسعير والتفاوض بثقة',
    description:
      'اختر سعرًا عادلًا وحدد نطاق العمل وتفاوض بوضوح',
    instructor: 'زياد فتحي',
    image: require('../assets/images/demo-course/covers/course-04.jpg'),
    label: 'جديد',
    labelTone: 'coin',
    category: 'freelance',
    published: false,
  },
  {
    id: 'demo-preview-digital-project',
    title: 'ابدأ مشروعك الرقمي الأول',
    description: 'اختبر فكرتك وابنِ نسخة أولى صغيرة يمكن للناس تجربتها',
    instructor: 'منة الله راضي',
    image: require('../assets/images/demo-course/covers/course-07.jpg'),
    label: 'مجاني عند الإطلاق',
    labelTone: 'success',
    category: 'freelance',
    published: false,
  },
  {
    id: 'demo-preview-winning-proposals',
    title: 'كتابة عروض عمل تفوز',
    description:
      'اكتب عرضًا مختصرًا يوضح للعميل أنك الشخص المناسب',
    instructor: 'حسام مصطفى',
    image: require('../assets/images/demo-course/covers/course-03.jpg'),
    category: 'freelance',
    published: false,
  },
  {
    id: 'demo-preview-ai-freelance',
    title: 'الذكاء الاصطناعي للعمل الحر',
    description:
      'استخدم الأدوات الذكية لتسريع البحث والتنفيذ مع الحفاظ على جودة عملك',
    instructor: 'لينا عماد',
    image: require('../assets/images/demo-course/covers/course-02.jpg'),
    label: 'قريبًا',
    labelTone: 'neutral',
    category: 'freelance',
    published: false,
  },
  {
    id: 'demo-preview-business-english',
    title: 'الإنجليزية لمحادثات العمل',
    description:
      'مواقف وجمل عملية للاجتماعات والمقابلات والتواصل مع العملاء',
    instructor: 'مريم منصور',
    image: require('../assets/images/demo-course/covers/course-11.jpg'),
    label: 'الأكثر مشاهدة',
    labelTone: 'primary',
    category: 'language',
    published: false,
  },
  {
    id: 'demo-preview-travel-english',
    title: 'الإنجليزية للسفر بثقة',
    description:
      'تدرّب على أهم الحوارات من المطار إلى الفندق والمواقف اليومية',
    instructor: 'نورهان علي',
    image: require('../assets/images/demo-course/covers/course-12.jpg'),
    label: 'مجاني عند الإطلاق',
    labelTone: 'success',
    category: 'language',
    published: false,
  },
  {
    id: 'demo-preview-german',
    title: 'تأسيس اللغة الألمانية',
    description:
      'ابدأ بالنطق والمفردات الأساسية وابنِ جملتك الأولى بالتدرج',
    instructor: 'هناء مجدي',
    image: require('../assets/images/demo-course/covers/course-15.jpg'),
    label: 'جديد',
    labelTone: 'coin',
    category: 'language',
    published: false,
  },
  {
    id: 'demo-preview-french',
    title: 'الفرنسية للحياة اليومية',
    description:
      'لغة بسيطة للمحادثات القصيرة والمواقف اليومية',
    instructor: 'ياسمين فؤاد',
    image: require('../assets/images/demo-course/covers/course-14.jpg'),
    category: 'language',
    published: false,
  },
  {
    id: 'demo-preview-public-speaking',
    title: 'التحدث أمام الجمهور',
    description: 'رتّب فكرتك واضبط نبرة صوتك وقدّم رسالتك بوضوح',
    instructor: 'إياد شريف',
    image: require('../assets/images/demo-course/covers/course-13.jpg'),
    label: 'قريبًا',
    labelTone: 'neutral',
    category: 'language',
    published: false,
  },
  {
    id: 'demo-preview-balanced-habits',
    title: 'عادات صغيرة لحياة أكثر اتزانًا',
    description: 'تغييرات يومية واقعية تساعدك على الاستمرار دون خطط مرهقة',
    instructor: 'الدكتورة سلمى محسن',
    image: require('../assets/images/demo-course/covers/course-18.jpg'),
    label: 'جديد',
    labelTone: 'coin',
    category: 'values',
    published: false,
  },
  {
    id: 'demo-preview-short-surahs',
    title: 'تدبّر قصار السور',
    description:
      'معان قريبة وتطبيقات يومية تساعدك على حضور القلب وفهم الآيات',
    instructor: 'الدكتور يوسف حلمي',
    image: require('../assets/images/demo-course/covers/course-16.jpg'),
    label: 'مجاني عند الإطلاق',
    labelTone: 'success',
    category: 'values',
    published: false,
  },
  {
    id: 'demo-preview-emotional-intelligence',
    title: 'الذكاء العاطفي في العلاقات',
    description:
      'افهم مشاعرك وعبّر عنها بوضوح وابنِ حوارًا أكثر هدوءًا',
    instructor: 'الدكتورة هالة مراد',
    image: require('../assets/images/demo-course/covers/course-05.jpg'),
    label: 'الأكثر مشاهدة',
    labelTone: 'primary',
    category: 'values',
    published: false,
  },
  {
    id: 'demo-preview-digital-parenting',
    title: 'تربية رقمية واعية للأسرة',
    description:
      'اتفاقات وحدود عملية لاستخدام الشاشات تحمي العلاقة داخل البيت',
    instructor: 'مي عبد الرحمن',
    image: require('../assets/images/demo-course/covers/course-19.jpg'),
    category: 'values',
    published: false,
  },
  {
    id: 'demo-preview-lasting-impact',
    title: 'اصنع أثرًا يدوم',
    description:
      'حدّد قيمك وحوّلها إلى مبادرات صغيرة يمكنك الاستمرار فيها',
    instructor: 'فريق ركن',
    image: require('../assets/images/demo-course/covers/course-17.jpg'),
    label: 'قريبًا',
    labelTone: 'neutral',
    category: 'values',
    published: false,
  },
];

export const featuredCourses = [demoCourses[0]];

export const courseSections = [
  {
    id: 'most-watched',
    title: 'الأكثر مشاهدة الآن',
    data: demoCourses.slice(0, 5),
  },
  {
    id: 'freelance-and-skills',
    title: 'العمل الحر والمهارات',
    data: demoCourses.slice(5, 10),
  },
  {
    id: 'languages',
    title: 'اللغات والتواصل',
    data: demoCourses.slice(10, 15),
  },
  {
    id: 'values-and-life',
    title: 'قيم وحياة',
    data: demoCourses.slice(15, 20),
  },
];
