import {useNavigation} from '@react-navigation/native';
import type {RootNavigation} from '../navigation/types';
import React, {useEffect} from 'react';
import {I18nManager} from 'react-native';
import RNRestart from 'react-native-restart';
import {useDispatch} from 'react-redux';
import {AsyncKeys, saveItem} from '../constants/helpers';
import {setLanguage} from '../store/reducers/settings';
import Splash from './Splash';

export default function LanguageSelect() {
  const navigation = useNavigation<RootNavigation>();
  const dispatch = useDispatch();

  useEffect(() => {
    let active = true;

    (async () => {
      await saveItem(AsyncKeys.LANGUAGE, 'ar');
      if (!active) return;

      dispatch(setLanguage('ar'));
      I18nManager.allowRTL(true);
      if (!I18nManager.isRTL) {
        I18nManager.forceRTL(true);
        // Yoga reads the native direction only while the application is
        // starting. A navigation reset leaves the first session in LTR.
        RNRestart.Restart();
        return;
      }

      navigation.reset({
        index: 0,
        routes: [{name: 'Home'}],
      });
    })();

    return () => {
      active = false;
    };
  }, [dispatch, navigation]);

  return <Splash />;
}
