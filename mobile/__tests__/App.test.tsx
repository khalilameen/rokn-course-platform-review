/**
 * @format
 */

import React from 'react';
import ReactTestRenderer from 'react-test-renderer';

jest.mock('../src/screens/AppInitializer', () => () => null);
jest.mock('../src/localization/i18n.config', () => ({
  __esModule: true,
  default: {changeLanguage: jest.fn()},
}));
jest.mock('../src/services/productAnalytics', () => ({
  flushProductEvents: jest.fn().mockResolvedValue(undefined),
  trackProductEvent: jest.fn().mockResolvedValue(undefined),
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  bootstrapOperationalDiagnostics: jest.fn().mockResolvedValue(undefined),
}));
jest.mock('../src/services/productFeatures', () => ({
  bootstrapProductFeatures: jest.fn().mockResolvedValue(undefined),
}));
jest.mock('react-redux', () => ({
  useSelector: (selector: (state: unknown) => unknown) =>
    selector({settings: {language: 'ar'}}),
}));

import App from '../App';

test('renders correctly', async () => {
  let renderer: ReactTestRenderer.ReactTestRenderer;
  await ReactTestRenderer.act(() => {
    renderer = ReactTestRenderer.create(<App />);
  });
  await ReactTestRenderer.act(() => {
    renderer.unmount();
  });
});
