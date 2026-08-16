import {
  CommonActions,
  createNavigationContainerRef,
} from '@react-navigation/native';
import {safeLoginReturnToFromRoute} from './authReturn';
import type {LoginReturnTo, RootNavigation, RootStackParamList} from './types';

type NavigationParams = Record<string, unknown> | undefined;

export const navigationRef = createNavigationContainerRef<RootStackParamList>();

export function navigate(
  name: keyof RootStackParamList,
  params?: NavigationParams,
) {
  if (!navigationRef.isReady()) return;
  navigationRef.dispatch(CommonActions.navigate(name, params));
}

export function getLoginReturnToSnapshot(): LoginReturnTo | undefined {
  if (!navigationRef.isReady()) return undefined;
  return safeLoginReturnToFromRoute(navigationRef.getCurrentRoute());
}

export function goBack() {
  if (navigationRef.isReady() && navigationRef.canGoBack()) {
    navigationRef.goBack();
  }
}

export function goBackOrHome(
  navigation: Pick<RootNavigation, 'canGoBack' | 'goBack' | 'reset'>,
) {
  if (navigation.canGoBack()) {
    navigation.goBack();
    return;
  }
  navigation.reset({index: 0, routes: [{name: 'Home'}]});
}

export function reset(
  index: number,
  routes: Array<{name: string; params?: NavigationParams}>,
) {
  if (!navigationRef.isReady()) return;
  navigationRef.dispatch(CommonActions.reset({index, routes}));
}

type NestedRoute = {
  name?: string;
  params?: {screen?: string};
  state?: {
    index: number;
    routes: NestedRoute[];
  };
};

export const getPreviousRouteFromState = (
  route: NestedRoute,
): NestedRoute | null => {
  const state = route.state;
  if (!state || state.index < 0 || !state.routes.length) return null;

  const activeRoute = state.routes[state.index];
  if (activeRoute?.state?.routes?.length) {
    return getPreviousRouteFromState(activeRoute);
  }

  return state.routes[state.index - 1] ?? activeRoute ?? null;
};

export const getActiveRouteName = (route: NestedRoute): string => {
  const state = route.state;
  if (state?.routes?.length) {
    return state.routes[state.index]?.name ?? 'Home';
  }
  return route.params?.screen ?? route.name ?? 'Home';
};
