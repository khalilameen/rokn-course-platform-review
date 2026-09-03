import fs from 'fs';
import path from 'path';

jest.mock('expo-crypto', () => ({
  CryptoDigestAlgorithm: {SHA256: 'SHA-256'},
  digestStringAsync: jest.fn(async () => 'a'.repeat(64)),
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
}));

jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn()},
}));

import {publicRequest} from '../src/constants/api';
import {purchaseCourse} from '../src/services/api/access';
import {
  getCourseDetails,
  getLearningCourses,
} from '../src/services/api/courses';
import {getCoinTasks, getWallet} from '../src/services/api/economy';

const mockGet = publicRequest.get as jest.Mock;
const mockPost = publicRequest.post as jest.Mock;

describe('commerce API contracts', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('keeps final-sale language in policy surfaces, not checkout decisions', () => {
    const wallet = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/Wallet.tsx'),
      'utf8',
    );
    const courseCheckout = fs.readFileSync(
      path.resolve(
        __dirname,
        '../src/screens/CourseDetails/details/PurchaseDialogs.tsx',
      ),
      'utf8',
    );
    const terms = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/Informations/TermsOfUse.tsx'),
      'utf8',
    );

    expect(wallet).not.toContain('شراء العملات نهائي');
    expect(courseCheckout).not.toContain('شراء العملات نهائي');
    expect(terms).toContain('شراء العملات نهائي بعد تأكيد الدفع');
  });

  it('keeps paid, reward, and course-spendable balances separate', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: {
          total_balance: 1500,
          paid_balance: 900,
          reward_balance: 600,
          course_spendable_balance: 1200,
          reward_contribution_cap_per_course: 300,
          spend_policy: 'reward_first_then_paid',
          recent_transactions: [
            {id: 1, amount: 200, direction: 'credit', category: 'purchase'},
            {id: 2, amount: 75, direction: 'debit', category: 'course'},
          ],
        },
      },
    });

    await expect(getWallet()).resolves.toMatchObject({
      balance: 1500,
      paidBalance: 900,
      rewardBalance: 600,
      spendableBalance: 1200,
      rewardContributionCap: 300,
      spendPolicy: 'reward_first_then_paid',
      transactions: [
        {id: '1', amount: 200},
        {id: '2', amount: -75},
      ],
    });
    expect(mockGet).toHaveBeenCalledWith('wallet');
  });

  it('presents reward goals without exposing the visit-and-claim implementation', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: [
          {
            id: 11,
            action_key: 'follow_instagram',
            title_ar: 'افتح الصفحة ثم عد',
            coins_amount: 75,
            task_state: 'available',
            action_url: 'https://instagram.com/rokn.app',
            requires_external_visit: true,
          },
          {
            id: 12,
            action_key: 'link_whatsapp',
            title_ar: 'أرسل الرسالة الجاهزة',
            coins_amount: 15,
            task_state: 'available',
            requires_external_visit: true,
          },
        ],
      },
    });

    await expect(getCoinTasks()).resolves.toEqual([
      expect.objectContaining({
        title: 'تابع ركن على Instagram',
        description: '',
      }),
      expect.objectContaining({
        title: 'اربط واتسابك بركن',
        description: 'تواصل مع ركن من واتساب',
      }),
    ]);
  });

  it('renders coin packages as a horizontal rail with another card visible', () => {
    const wallet = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/Wallet.tsx'),
      'utf8',
    );
    const coin = fs.readFileSync(
      path.resolve(__dirname, '../src/components/ui/RoknCoin.tsx'),
      'utf8',
    );
    const packageCard = fs.readFileSync(
      path.resolve(__dirname, '../src/components/view/Package.tsx'),
      'utf8',
    );

    expect(wallet).toContain('width={packageCardWidth}');
    expect(wallet).toContain('snapToInterval={packageCardWidth + Spacing.sm}');
    expect(wallet).toContain('const packageCardWidth = Math.floor(railCardWidth)');
    expect(wallet).not.toContain('packageColumns');
    expect(coin).toContain('id="coinMark"');
    expect(coin).not.toContain('#FFF1A9');
    expect(packageCard).not.toContain('<RoknCoin');
    expect(packageCard.match(/<CoinAmount/g)).toHaveLength(1);
  });

  it('uses mobile-first devices artwork without a desktop stand', () => {
    const icons = fs.readFileSync(
      path.resolve(__dirname, '../src/assets/SVG.tsx'),
      'utf8',
    );
    const devicesIcon = icons.match(
      /export const SettingsDevicesIcon[\s\S]*?\n\);/,
    )?.[0];

    expect(devicesIcon).toContain('width={11.5} height={18}');
    expect(devicesIcon).toContain('width={4.5} height={12}');
    expect(devicesIcon).not.toContain('M7 21h8');
  });

  it('maps the three server plans in product order with their benefits', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: {
          id: 64,
          title: 'Course',
          price: 100,
          is_coming_soon: false,
          ratings_count: 0,
          average_rating: null,
          published_revision: 1,
          metadata: {students_count: 0, duration_minutes: 1},
          modules: [
            {
              id: 1,
              title: 'وحدة',
              sections: [
                {id: 1, content_id: 1, title: 'درس', type: 'lesson'},
                {id: 2, project_id: 20, title: 'مشروع أول', type: 'project'},
                {id: 3, project_id: 21, title: 'مشروع ثان', type: 'project'},
              ],
            },
          ],
          access_type: 'scholarship',
          access_plans: [
            {
              code: 'mentor',
              name_ar: 'متابعة',
              price_coins: 900,
              minimum_paid_coins: 0,
              chat_enabled: true,
              chat_message_limit: 40,
              project_feedback_level: 'enhanced',
              project_report_enabled: true,
              project_thread_reply_enabled: true,
              project_output_enabled: true,
              certificate_enabled: true,
            },
            {
              code: 'basic',
              name_ar: 'تعلم',
              price_coins: 300,
              minimum_paid_coins: 0,
              chat_enabled: false,
              project_feedback_level: 'pass_only',
              project_report_enabled: false,
              project_thread_reply_enabled: false,
              project_output_enabled: false,
              certificate_enabled: true,
            },
            {
              code: 'guided',
              name_ar: 'إرشاد',
              price_coins: 600,
              minimum_paid_coins: 0,
              chat_enabled: true,
              chat_message_limit: 10,
              project_feedback_level: 'report',
              project_report_enabled: true,
              project_thread_reply_enabled: false,
              project_output_enabled: true,
              certificate_enabled: true,
            },
          ],
        },
      },
    });

    const details = await getCourseDetails('64');

    expect(mockGet).toHaveBeenCalledWith(
      'courses/64/details',
      expect.objectContaining({
        optionalAuthorization: true,
        signal: undefined,
        roknNetworkRetryDeadlineAt: expect.any(Number),
      }),
    );
    expect(details.owned).toBe(true);
    expect(details.modules[0]).toMatchObject({
      projectCount: 2,
      items: expect.arrayContaining([
        expect.objectContaining({id: '2', type: 'project'}),
        expect.objectContaining({id: '3', type: 'project'}),
      ]),
    });
    expect(details.accessPlans.map(plan => plan.code)).toEqual([
      'basic',
      'guided',
      'mentor',
    ]);
    expect(details.accessPlans).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          code: 'guided',
          priceCoins: 600,
          chatEnabled: true,
          chatMessageLimit: 10,
          projectFeedbackLevel: 'report',
          projectReportEnabled: true,
        }),
        expect.objectContaining({
          code: 'mentor',
          priceCoins: 900,
          projectFeedbackLevel: 'enhanced',
          projectOutputEnabled: true,
        }),
      ]),
    );
  });

  it('never treats a public preview as course ownership', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: {
          id: 65,
          title: 'Course',
          is_coming_soon: false,
          ratings_count: 0,
          average_rating: null,
          published_revision: 1,
          metadata: {students_count: 0, duration_minutes: 1},
          access_type: 'preview',
          // A stale compatibility enrollment must never override the
          // authoritative access snapshot returned by the course read API.
          enrollment: {is_active: true},
          access_plans: [
            {
              code: 'basic',
              name_ar: 'تعلم',
              price_coins: 300,
              minimum_paid_coins: 0,
              chat_enabled: false,
              project_feedback_level: 'pass_only',
              project_report_enabled: false,
              project_thread_reply_enabled: false,
              project_output_enabled: false,
              certificate_enabled: true,
            },
            {
              code: 'guided',
              name_ar: 'إرشاد',
              price_coins: 600,
              minimum_paid_coins: 0,
              chat_enabled: true,
              chat_message_limit: 10,
              project_feedback_level: 'report',
              project_report_enabled: true,
              project_thread_reply_enabled: false,
              project_output_enabled: true,
              certificate_enabled: true,
            },
            {
              code: 'mentor',
              name_ar: 'متابعة',
              price_coins: 900,
              minimum_paid_coins: 0,
              chat_enabled: true,
              chat_message_limit: 40,
              project_feedback_level: 'enhanced',
              project_report_enabled: true,
              project_thread_reply_enabled: true,
              project_output_enabled: true,
              certificate_enabled: true,
            },
          ],
          modules: [
            {
              id: 1,
              title: 'وحدة',
              sections: [
                {
                  id: 1,
                  content_id: 1,
                  title: 'درس',
                  type: 'lesson',
                  is_preview: true,
                },
              ],
            },
          ],
        },
      },
    });

    await expect(getCourseDetails('65')).resolves.toMatchObject({
      owned: false,
    });
  });

  it('rejects preview rows from the owned learning dashboard', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: {
          items: [
            {
              course_id: 65,
              title: 'Course',
              progress_percentage: 0,
              completed_sections: 0,
              total_sections: 1,
              access_type: 'preview',
              chat_available: false,
              certificate_available: false,
              resume: {available: false},
              next_section: null,
            },
          ],
        },
      },
    });

    await expect(getLearningCourses()).rejects.toThrow(
      'LEARNING_COURSES_CONTRACT_INVALID',
    );
  });

  it('sends the selected plan and preserves insufficient-balance details', async () => {
    mockPost.mockResolvedValueOnce({
      data: {
        data: {
          total_balance: 500,
          spendable_balance: 450,
          purchased_balance: 300,
          reward_balance: 200,
          original_price: 600,
          discount_amount: 0,
        },
      },
    });

    await expect(purchaseCourse('64', 'guided')).resolves.toEqual({
      kind: 'success',
      balance: 500,
      spendableBalance: 450,
      paidBalance: 300,
      rewardBalance: 200,
      originalPrice: 600,
      discountAmount: 0,
    });
    expect(mockPost).toHaveBeenNthCalledWith(
      1,
      'courses/authorize',
      expect.objectContaining({
        course_id: 64,
        access_plan_code: 'guided',
        idempotency_key: expect.stringMatching(
          /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,
        ),
      }),
    );

    mockPost.mockRejectedValueOnce({
      response: {
        data: {
          code: 'insufficient_coins',
          data: {
            total_balance: 100,
            spendable_balance: 80,
            purchased_balance: 60,
            reward_balance: 40,
            deficit: 520,
            recommended_packages: [
              {
                id: 7,
                coins: 600,
                price: 49,
                direct_price: 44.1,
                name_ar: 'باقة مناسبة',
                channels: {direct: true, google: true, apple: true},
                store_products: {
                  google: 'rokn.coins.600',
                  apple: 'rokn.coins.600',
                },
              },
            ],
          },
        },
      },
    });

    await expect(purchaseCourse('64', 'mentor')).resolves.toEqual({
      kind: 'insufficient',
      balance: 100,
      spendableBalance: 80,
      paidBalance: 60,
      rewardBalance: 40,
      deficit: 520,
      packages: [
        {
          id: '7',
          coins: 600,
          price: 49,
          label: 'باقة مناسبة',
          recommended: false,
          storeProductIds: {
            google: 'rokn.coins.600',
            apple: 'rokn.coins.600',
          },
        },
      ],
    });
  });
});
