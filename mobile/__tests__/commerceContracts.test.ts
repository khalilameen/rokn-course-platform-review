jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn(), post: jest.fn()},
}));

import {publicRequest} from '../src/constants/api';
import {purchaseCourse} from '../src/services/api/access';
import {getCourseDetails} from '../src/services/api/courses';
import {getWallet} from '../src/services/api/economy';

const mockGet = publicRequest.get as jest.Mock;
const mockPost = publicRequest.post as jest.Mock;

describe('commerce API contracts', () => {
  beforeEach(() => {
    jest.clearAllMocks();
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

  it('maps the three server plans in product order with their benefits', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: {
          id: 64,
          title: 'Course',
          price: 100,
          access_plans: [
            {
              code: 'mentor',
              name_ar: 'متابعة',
              price_coins: 900,
              chat_enabled: true,
              chat_message_limit: 40,
              project_feedback_level: 'detailed',
              project_report_enabled: true,
              project_output_enabled: true,
              certificate_enabled: true,
            },
            {
              code: 'basic',
              name_ar: 'تعلم',
              price_coins: 300,
              chat_enabled: false,
              certificate_enabled: true,
            },
            {
              code: 'guided',
              name_ar: 'إرشاد',
              price_coins: 600,
              chat_enabled: true,
              chat_message_limit: 10,
              project_feedback_level: 'summary',
              project_report_enabled: true,
              certificate_enabled: true,
            },
          ],
        },
      },
    });

    const details = await getCourseDetails('64');

    expect(mockGet).toHaveBeenCalledWith('courses/64/details');
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
          projectFeedbackLevel: 'summary',
          projectReportEnabled: true,
        }),
        expect.objectContaining({
          code: 'mentor',
          priceCoins: 900,
          projectOutputEnabled: true,
        }),
      ]),
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
        },
      },
    });

    await expect(purchaseCourse('64', 'guided')).resolves.toEqual({
      kind: 'success',
      balance: 500,
      spendableBalance: 450,
      paidBalance: 300,
      rewardBalance: 200,
    });
    expect(mockPost).toHaveBeenNthCalledWith(1, 'courses/authorize', {
      course_id: 64,
      access_plan_code: 'guided',
    });

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
              {id: 7, coins: 600, price: 49, name_ar: 'باقة مناسبة'},
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
        {id: '7', coins: 600, price: 49, label: 'باقة مناسبة'},
      ],
    });
  });
});
