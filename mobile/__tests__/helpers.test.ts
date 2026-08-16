jest.mock('@react-native-async-storage/async-storage', () => ({
  getItem: jest.fn(),
  setItem: jest.fn(),
  removeItem: jest.fn(),
  clear: jest.fn(),
}));

import {
  SecondsToMinutes,
  extractApiToken,
  extractUserProfile,
  normalizeText,
} from '../src/constants/helpers';

describe('session envelope compatibility', () => {
  test.each([
    [{api_token: ' direct-token '}, 'direct-token'],
    [{data: {api_token: 'nested-token'}}, 'nested-token'],
    [{data: {data: {api_token: 'deep-token'}}}, 'deep-token'],
    [{user: {api_token: 'user-token'}}, 'user-token'],
  ])('extracts an API token without changing its envelope', (input, token) => {
    expect(extractApiToken(input)).toBe(token);
  });

  it('does not treat an empty token as an authenticated session', () => {
    expect(extractApiToken({data: {api_token: '  '}})).toBeNull();
  });

  it('extracts the profile from the social-auth response shape', () => {
    const user = {id: 17, name: 'Rokn learner'};
    expect(extractUserProfile({data: {user}})).toBe(user);
  });
});

describe('duration formatting', () => {
  test.each([
    [0, '0:00'],
    [9, '0:09'],
    [60, '1:00'],
    [125, '2:05'],
    [-4, '0:00'],
  ])('formats %p seconds as %s', (seconds, expected) => {
    expect(SecondsToMinutes(seconds)).toBe(expected);
  });
});

describe('search normalization', () => {
  it('normalizes every Arabic digit occurrence for consistent matching', () => {
    expect(normalizeText('كورس ٢٠٢٦ خطوة ٣٠')).toBe('كورس 2026 خطوه 30');
  });
});
