import React from 'react';
import ReactTestRenderer from 'react-test-renderer';

jest.mock('react-native-linear-gradient', () => 'LinearGradient');
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({bottom: 0, left: 0, right: 0, top: 0}),
}));
jest.mock('react-native/Libraries/Modal/Modal', () => ({
  __esModule: true,
  default: 'Modal',
}));

import {
  getDistributionCapabilities,
  type DistributionChannel,
} from '../src/constants/distribution';
import {
  CourseCodeRedemptionDialog,
  CoursePurchaseDialog,
} from '../src/screens/CourseDetails/details/PurchaseDialogs';
import {CourseCodeRedemptionAction} from '../src/screens/CourseDetails/details/CourseCodeRedemptionAction';
import type {CourseAccessPlan} from '../src/services/roknApi';

const plans: CourseAccessPlan[] = [
  {
    code: 'basic',
    name: 'التعلّم',
    priceCoins: 300,
    chatEnabled: false,
    chatMessageLimit: 0,
    projectFeedbackLevel: 'pass_only',
    projectReportEnabled: false,
    projectOutputEnabled: false,
    certificateEnabled: true,
  },
  {
    code: 'guided',
    name: 'التعلّم بإرشاد',
    priceCoins: 500,
    chatEnabled: true,
    chatMessageLimit: 25,
    projectFeedbackLevel: 'report',
    projectReportEnabled: true,
    projectOutputEnabled: false,
    certificateEnabled: true,
  },
  {
    code: 'mentor',
    name: 'التعلّم بمتابعة',
    priceCoins: 700,
    chatEnabled: true,
    chatMessageLimit: 80,
    projectFeedbackLevel: 'enhanced',
    projectReportEnabled: true,
    projectOutputEnabled: true,
    certificateEnabled: true,
  },
];

describe('course-code distribution boundary', () => {
  it.each<[DistributionChannel, boolean, boolean, boolean]>([
    ['direct', true, false, true],
    ['play', false, true, true],
    ['appstore', false, true, false],
  ])(
    'applies the expected checkout/redemption policy to %s',
    (
      channel,
      canStartExternalCheckout,
      canStartNativeCheckout,
      canRedeemCourseAccessCode,
    ) => {
      expect(getDistributionCapabilities(channel)).toEqual({
        canStartExternalCheckout,
        canStartNativeCheckout,
        canRedeemCourseAccessCode,
      });
    },
  );
});

describe('course-code redemption UI', () => {
  it('exposes a separate labelled action without a payment call to action', async () => {
    const onPress = jest.fn();
    let renderer: ReactTestRenderer.ReactTestRenderer;

    await ReactTestRenderer.act(() => {
      renderer = ReactTestRenderer.create(
        <CourseCodeRedemptionAction onPress={onPress} visible />,
      );
    });

    const action = renderer!.root.find(
      node => node.props.accessibilityLabel === 'تفعيل كود جهة تعليمية',
    );
    expect(action.props).toMatchObject({
      accessibilityRole: 'button',
      accessibilityHint: 'يفتح إدخال كود الوصول إلى هذا الكورس',
    });
    expect(JSON.stringify(renderer!.toJSON())).not.toMatch(
      /شراء|دفع الآن|رابط/,
    );

    await ReactTestRenderer.act(() => action.props.onPress());
    expect(onPress).toHaveBeenCalledTimes(1);
    await ReactTestRenderer.act(() => renderer!.unmount());
  });

  it('labels the code input, submit action, progress state, and close action', async () => {
    const onClose = jest.fn();
    const onChange = jest.fn();
    const onRedeem = jest.fn();
    let renderer: ReactTestRenderer.ReactTestRenderer;

    await ReactTestRenderer.act(() => {
      renderer = ReactTestRenderer.create(
        <CourseCodeRedemptionDialog
          bottomInset={0}
          codeBusy={false}
          courseCode="GRANT-42"
          isTablet={false}
          notice=""
          onClose={onClose}
          onCourseCodeChange={onChange}
          onRedeemCourseCode={onRedeem}
          visible
        />,
      );
    });

    const input = renderer!.root.find(
      node => node.props.accessibilityLabel === 'كود الوصول إلى الكورس',
    );
    const submit = renderer!.root.find(
      node => node.props.accessibilityLabel === 'تفعيل كود الوصول',
    );
    const closeActions = renderer!.root.findAll(
      node =>
        node.props.accessibilityLabel === 'إغلاق نافذة تفعيل الكود' ||
        node.props.accessibilityLabel === 'إغلاق نافذة تفعيل كود الكورس',
    );

    expect(input.props).toMatchObject({
      editable: true,
      value: 'GRANT-42',
    });
    expect(submit.props).toMatchObject({
      accessibilityRole: 'button',
      accessibilityState: {busy: false, disabled: false},
      disabled: false,
    });
    expect(
      new Set(closeActions.map(node => node.props.accessibilityLabel)),
    ).toEqual(
      new Set(['إغلاق نافذة تفعيل الكود', 'إغلاق نافذة تفعيل كود الكورس']),
    );

    await ReactTestRenderer.act(() => input.props.onChangeText('NEW-CODE'));
    await ReactTestRenderer.act(() => submit.props.onPress());
    expect(onChange).toHaveBeenCalledWith('NEW-CODE');
    expect(onRedeem).toHaveBeenCalledTimes(1);

    await ReactTestRenderer.act(() => {
      renderer!.update(
        <CourseCodeRedemptionDialog
          bottomInset={0}
          codeBusy
          courseCode="GRANT-42"
          isTablet={false}
          notice="جارٍ التفعيل"
          onClose={onClose}
          onCourseCodeChange={onChange}
          onRedeemCourseCode={onRedeem}
          visible
        />,
      );
    });
    expect(
      renderer!.root.find(
        node => node.props.accessibilityLabel === 'كود الوصول إلى الكورس',
      ).props.editable,
    ).toBe(false);
    expect(
      renderer!.root.find(
        node => node.props.accessibilityLabel === 'تفعيل كود الوصول',
      ).props,
    ).toMatchObject({
      accessibilityState: {busy: true, disabled: true},
      disabled: true,
    });
    await ReactTestRenderer.act(() => renderer!.unmount());
  });

  it('keeps all three direct-checkout plans while excluding code entry from the purchase dialog', async () => {
    const onSelectPlan = jest.fn();
    let renderer: ReactTestRenderer.ReactTestRenderer;

    await ReactTestRenderer.act(() => {
      renderer = ReactTestRenderer.create(
        <CoursePurchaseDialog
          accessPlans={plans}
          balance={1000}
          bottomInset={0}
          busy={false}
          courseTitle="كورس الإنتاج"
          dialogStep="plans"
          grantActivated={false}
          isTablet={false}
          notice=""
          onBuyCoins={jest.fn()}
          onClose={jest.fn()}
          onConfirmPurchase={jest.fn()}
          onSelectPlan={onSelectPlan}
          onSuccessStart={jest.fn()}
          packages={[]}
          purchasePrice={300}
          rewardContributionLimit={300}
          rewardContributionPercent={100}
          selectedPlan={plans[0]}
          shortfall={0}
          usableCurrentBalance={300}
        />,
      );
    });

    const tree = JSON.stringify(renderer!.toJSON());
    for (const plan of plans) expect(tree).toContain(plan.name);
    expect(tree).not.toContain('اكتب الكود');
    expect(
      renderer!.root.findAll(
        node => node.props.accessibilityLabel === 'تفعيل كود الوصول',
      ),
    ).toHaveLength(0);

    await ReactTestRenderer.act(() => renderer!.unmount());
  });

  it('shows the same sufficient package choices inline without opening the wallet page', async () => {
    const onBuyCoins = jest.fn();
    let renderer: ReactTestRenderer.ReactTestRenderer;

    await ReactTestRenderer.act(() => {
      renderer = ReactTestRenderer.create(
        <CoursePurchaseDialog
          accessPlans={plans}
          balance={100}
          bottomInset={0}
          busy={false}
          courseTitle="كورس الإنتاج"
          dialogStep="topup"
          grantActivated={false}
          isTablet={false}
          notice=""
          onBuyCoins={onBuyCoins}
          onClose={jest.fn()}
          onConfirmPurchase={jest.fn()}
          onSelectPlan={jest.fn()}
          onSuccessStart={jest.fn()}
          packages={[
            {
              id: 'coins-1000',
              coins: 1000,
              price: 99,
              label: 'رصيد مدفوع',
            },
            {
              id: 'coins-1500',
              coins: 1500,
              price: 139,
              label: 'رصيد مدفوع',
            },
          ]}
          purchasePrice={700}
          rewardContributionLimit={300}
          rewardContributionPercent={42}
          selectedPlan={plans[2]}
          shortfall={600}
          sufficientPackage={{
            id: 'coins-1000',
            coins: 1000,
            price: 99,
            label: 'رصيد مدفوع',
          }}
          usableCurrentBalance={100}
        />,
      );
    });

    const tree = JSON.stringify(renderer!.toJSON());
    expect(tree).toContain('الاختيار السريع');
    expect(tree).toContain('٩٩');
    expect(tree).toContain('١٣٩');
    expect(tree).toContain('جنيه');
    expect(tree).toContain('يتبقى ');
    expect(tree).toContain('٤٠٠');
    expect(tree).not.toContain('اختيار الباقة');
    const actions = renderer!.root.findAll(
      node =>
        node.props.accessibilityRole === 'button' &&
        typeof node.props.onPress === 'function' &&
        node.props.disabled === false,
    );
    const paymentAction = actions.find(node =>
      String(node.props.accessibilityLabel || '').includes('٩٩'),
    );
    expect(paymentAction).toBeDefined();
    await ReactTestRenderer.act(() => paymentAction!.props.onPress());
    expect(onBuyCoins).toHaveBeenCalledTimes(1);
    expect(onBuyCoins).toHaveBeenCalledWith(
      expect.objectContaining({id: 'coins-1000'}),
    );

    await ReactTestRenderer.act(() => renderer!.unmount());
  });
});
