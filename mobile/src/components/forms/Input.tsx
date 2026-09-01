import React, {useState} from 'react';
import {
  StyleProp,
  StyleSheet,
  Text,
  TextInput,
  TextInputProps,
  TextStyle,
  TouchableOpacity,
  View,
  ViewStyle,
  ViewProps,
} from 'react-native';

import {Colors, Fonts, PixelPerfect} from '../../constants/styleConstants';
import {PasswordEye, PasswordEyeSlash} from '../../assets/SVG';
import {
  fixedIconSlot,
  flexibleTextColumn,
  Palette,
  rtlRowStyle,
  textDirection,
} from '../../constants/designSystem';

interface Props {
  options?: TextInputProps;
  contentContainerStyle?: StyleProp<ViewStyle>;
  styleCon?: StyleProp<ViewStyle>;
  textInputContainer?: StyleProp<TextStyle>;
  leftContent?: () => React.ReactNode;
  rightContent?: () => React.ReactNode;
  erorrMessage?: string;
  onLayout?: ViewProps['onLayout'];
  password?: boolean | string;
  inputRef?: React.Ref<TextInput>;
  value?: string;
  onChangeText?: (text: string) => void;
}

const Input: React.FC<Props> = ({
  options,
  contentContainerStyle,
  textInputContainer,
  password,
  leftContent,
  rightContent,
  erorrMessage,
  styleCon,
  inputRef,
  onLayout,
  value,
  onChangeText,
}) => {
  const {style: optionStyle, ...inputOptions} = options ?? {};
  const [state, setstate] = useState({
    showPassword: false,
    currentFlag: '',
  });

  return (
    <>
      <View
        onLayout={onLayout}
        style={[
          styles.container,
          contentContainerStyle,
          // !!erorrMessage && {backgroundColor: '#FDD6D6'},
          styleCon,
        ]}>
        {rightContent && <View style={styles.iconRight}>{rightContent()}</View>}

        <TextInput
          ref={inputRef}
          accessibilityLabel={
            inputOptions.accessibilityLabel ?? inputOptions.placeholder
          }
          allowFontScaling
          accessibilityState={{disabled: inputOptions.editable === false}}
          selectionColor={Colors.mainColor}
          style={[styles.textInputContainer, textInputContainer, optionStyle]}
          placeholderTextColor={'#B0B0B0'}
          secureTextEntry={!!password && !state.showPassword}
          {...inputOptions}
          value={value ?? inputOptions.value}
          onChangeText={onChangeText ?? inputOptions.onChangeText}
        />

        {leftContent && <View style={styles.iconLeft}>{leftContent()}</View>}
        {password && (
          <TouchableOpacity
            accessibilityLabel={
              state.showPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'
            }
            accessibilityRole="button"
            accessibilityState={{checked: state.showPassword}}
            style={styles.iconLeft}
            onPress={() => {
              setstate(old => ({
                ...old,
                showPassword: !state.showPassword,
              }));
            }}>
            {state.showPassword ? <PasswordEyeSlash /> : <PasswordEye />}
          </TouchableOpacity>
        )}
      </View>
      {!!erorrMessage && (
        <View>
          <Text
            accessibilityLiveRegion="assertive"
            accessibilityRole="alert"
            style={[
              styles.errorMessage,
              {fontFamily: Fonts.regular},
              {color: erorrMessage ? Colors.warning : 'transparent'},
            ]}>
            {erorrMessage}
          </Text>
        </View>
      )}
    </>
  );
};

export default Input;

const styles = StyleSheet.create({
  container: {
    borderRadius: PixelPerfect(14),
    width: '100%',
    marginBottom: PixelPerfect(16),
    minHeight: PixelPerfect(54),
    alignItems: 'center',
    paddingHorizontal: PixelPerfect(10),
    ...rtlRowStyle,
    backgroundColor: Palette.surface,
    borderWidth: 1,
    borderColor: Palette.line,
  },
  textInputContainer: {
    ...flexibleTextColumn,
    ...textDirection,
    fontSize: PixelPerfect(15),
    fontFamily: Fonts.regular,
    color: Colors.white,
    minHeight: PixelPerfect(50),
    paddingHorizontal: PixelPerfect(4),
    textAlignVertical: 'center',
  },
  iconLeft: {
    ...fixedIconSlot,
    width: PixelPerfect(38),
    minWidth: PixelPerfect(38),
    minHeight: 48,
  },
  iconRight: {
    ...fixedIconSlot,
    width: PixelPerfect(38),
    minWidth: PixelPerfect(38),
    minHeight: 48,
  },
  errorMessage: {
    ...textDirection,
    fontSize: PixelPerfect(14),
    marginBottom: PixelPerfect(12),
  },
  flagImage: {
    height: PixelPerfect(24),
    width: PixelPerfect(24),
    borderRadius: PixelPerfect(12),
  },
  flagAndCodeCont: {
    ...rtlRowStyle,
    alignItems: 'center',
    height: '100%',
  },
  couponButtonCon: {},
  couponButton: {
    marginBottom: 0,
    backgroundColor: Colors.secondColor,
    height: PixelPerfect(32),
    width: PixelPerfect(79),
  },
  couponButtonTitle: {
    fontSize: PixelPerfect(12),
    color: Colors.white,
  },
});
