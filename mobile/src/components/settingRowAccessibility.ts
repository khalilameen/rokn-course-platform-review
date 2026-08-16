import type {AccessibilityRole, AccessibilityState} from 'react-native';

export type SettingRowAccessibilityInput = {
  title: string;
  subtitle?: string;
  value?: string;
  hasAction: boolean;
  toggleValue?: boolean;
  disabled?: boolean;
};

export type SettingRowAccessibilityProps = {
  accessible: true;
  accessibilityLabel: string;
  accessibilityRole: AccessibilityRole;
  accessibilityHint?: string;
  accessibilityState?: AccessibilityState;
};

const compactLabelPart = (value: string | undefined) =>
  value?.replace(/\s+/g, ' ').trim() || '';

/**
 * Maps the existing SettingRow shape to truthful screen-reader semantics.
 * Static information is text rather than a disabled button, while switches
 * expose their checked state and both action kinds expose disabled state.
 */
export const buildSettingRowAccessibility = ({
  title,
  subtitle,
  value,
  hasAction,
  toggleValue,
  disabled = false,
}: SettingRowAccessibilityInput): SettingRowAccessibilityProps => {
  const isToggle = typeof toggleValue === 'boolean';
  const isInteractive = isToggle || hasAction;
  const accessibilityRole: AccessibilityRole = isToggle
    ? 'switch'
    : hasAction
      ? 'button'
      : 'text';
  const accessibilityState: AccessibilityState | undefined = isToggle
    ? {checked: toggleValue, ...(disabled ? {disabled: true} : {})}
    : isInteractive && disabled
      ? {disabled: true}
      : undefined;

  return {
    accessible: true,
    accessibilityLabel: [title, subtitle, value]
      .map(compactLabelPart)
      .filter(Boolean)
      .join('، '),
    accessibilityRole,
    ...(isToggle
      ? {accessibilityHint: 'اضغط مرتين لتغيير الإعداد'}
      : hasAction
        ? {accessibilityHint: 'اضغط مرتين للتنفيذ'}
        : {}),
    ...(accessibilityState ? {accessibilityState} : {}),
  };
};
