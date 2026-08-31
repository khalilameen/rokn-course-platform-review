import {sha256} from 'js-sha256';

const BASE64_ALPHABET =
  'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

const bytesToBase64Url = (bytes: number[]) => {
  let encoded = '';
  for (let index = 0; index < bytes.length; index += 3) {
    const first = bytes[index] ?? 0;
    const hasSecond = index + 1 < bytes.length;
    const hasThird = index + 2 < bytes.length;
    const second = bytes[index + 1] ?? 0;
    const third = bytes[index + 2] ?? 0;
    encoded += BASE64_ALPHABET[Math.floor(first / 4)];
    encoded +=
      BASE64_ALPHABET[(first % 4) * 16 + Math.floor(second / 16)];
    encoded += hasSecond
      ? BASE64_ALPHABET[(second % 16) * 4 + Math.floor(third / 64)]
      : '=';
    encoded += hasThird ? BASE64_ALPHABET[third % 64] : '=';
  }
  return encoded.replace(/\+/g, '-').split('/').join('_').split('=')[0];
};

export const sha256Hex = (value: string) => sha256(value);

export const sha256Base64Url = (value: string) =>
  bytesToBase64Url(sha256.array(value));
