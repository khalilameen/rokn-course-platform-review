import {buildSettingRowAccessibility} from '../src/components/settingRowAccessibility';

describe('SettingRow accessibility semantics', () => {
  it('announces a switch label, context, value and checked state', () => {
    expect(
      buildSettingRowAccessibility({
        title: 'الإشعارات',
        subtitle: 'تنبيهات الدروس الجديدة',
        value: 'مفعلة',
        hasAction: false,
        toggleValue: true,
      }),
    ).toEqual({
      accessible: true,
      accessibilityLabel: 'الإشعارات، تنبيهات الدروس الجديدة، مفعلة',
      accessibilityRole: 'switch',
      accessibilityHint: 'اضغط مرتين لتغيير الإعداد',
      accessibilityState: {checked: true},
    });
  });

  it('uses button semantics only for rows with an action', () => {
    const props = buildSettingRowAccessibility({
      title: 'تعديل الحساب',
      hasAction: true,
    });

    expect(props.accessibilityRole).toBe('button');
    expect(props.accessibilityHint).toBe('اضغط مرتين للتنفيذ');
  });

  it('exposes static information as text rather than a disabled button', () => {
    expect(
      buildSettingRowAccessibility({
        title: 'الإصدار',
        value: '1.0.22',
        hasAction: false,
        disabled: true,
      }),
    ).toEqual({
      accessible: true,
      accessibilityLabel: 'الإصدار، 1.0.22',
      accessibilityRole: 'text',
    });
  });

  it('announces disabled state for an interactive row', () => {
    const props = buildSettingRowAccessibility({
      title: 'حذف الحساب',
      hasAction: true,
      disabled: true,
    });

    expect(props.accessibilityState).toEqual({disabled: true});
  });
});
