import React from 'react';
import ReactTestRenderer from 'react-test-renderer';

jest.mock('react-native-linear-gradient', () => 'LinearGradient');
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
  it.each<[DistributionChannel, boolean, boolean]>([
    ['direct', true, true],
    ['play', false, true],
    ['appstore', false, false],
  ])(
    'applies the expected checkout/redemption policy to %s',
    (channel, canStartExternalCheckout, canRedeemCourseAccessCode) => {
      expect(getDistributionCapabilities(channel)).toEqual({
        canStartExternalCheckout,
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
      accessibilityHint: 'يفتح نافذة آمنة لإدخال كود الوصول إلى هذا الكورس',
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
          selectedPlan={plans[0]}
          shortfall={0}
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
});
