import fs from 'node:fs';
import path from 'node:path';

const screensRoot = path.resolve(__dirname, '../src/screens');

const sourceFiles = (directory: string): string[] =>
  fs.readdirSync(directory, {withFileTypes: true}).flatMap(entry => {
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) return sourceFiles(absolute);
    return /\.[jt]sx?$/.test(entry.name) ? [absolute] : [];
  });

describe('screen architecture boundary', () => {
  it('keeps transport, persistence and environment decisions out of screens', () => {
    const forbidden = [
      /@react-native-async-storage\/async-storage/,
      /expo-secure-store/,
      /from\s+['"]axios['"]/,
      /\bfetch\s*\(/,
      /process\.env/,
      /from\s+['"][^'"]*constants\/api['"]/,
    ];
    const violations = sourceFiles(screensRoot).flatMap(file => {
      const source = fs.readFileSync(file, 'utf8');
      return forbidden
        .filter(pattern => pattern.test(source))
        .map(pattern => `${path.relative(screensRoot, file)}: ${pattern}`);
    });

    expect(violations).toEqual([]);
  });
});
