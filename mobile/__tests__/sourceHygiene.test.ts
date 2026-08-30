import fs from 'fs';
import path from 'path';
import ts from 'typescript';

const sourceRoot = path.resolve(__dirname, '../src');

const sourceFiles = (directory: string): string[] =>
  fs.readdirSync(directory, {withFileTypes: true}).flatMap(entry => {
    const target = path.join(directory, entry.name);
    if (entry.isDirectory()) return sourceFiles(target);
    return /\.tsx?$/.test(entry.name) && !entry.name.endsWith('.d.ts')
      ? [target]
      : [];
  });

const relative = (file: string) =>
  path.relative(sourceRoot, file).replace(/\\/g, '/');

describe('source hygiene contracts', () => {
  test('application source has no explicit any or TypeScript suppressions', () => {
    const explicitAny: string[] = [];
    const suppressions: string[] = [];

    for (const file of sourceFiles(sourceRoot)) {
      const text = fs.readFileSync(file, 'utf8');
      const source = ts.createSourceFile(
        file,
        text,
        ts.ScriptTarget.Latest,
        true,
        file.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
      );
      const visit = (node: ts.Node) => {
        if (node.kind === ts.SyntaxKind.AnyKeyword) {
          const position = source.getLineAndCharacterOfPosition(
            node.getStart(source),
          );
          explicitAny.push(`${relative(file)}:${position.line + 1}`);
        }
        ts.forEachChild(node, visit);
      };
      visit(source);
      if (/@ts-(?:ignore|expect-error|nocheck)/.test(text)) {
        suppressions.push(relative(file));
      }
    }

    expect(explicitAny).toEqual([]);
    expect(suppressions).toEqual([]);
  });

  test('network client imports stay behind the service boundary', () => {
    const violations = sourceFiles(sourceRoot)
      .filter(file => /\bpublicRequest\b/.test(fs.readFileSync(file, 'utf8')))
      .map(relative)
      .filter(
        file =>
          file !== 'constants/api.ts' &&
          !file.startsWith('services/') &&
          !file.startsWith('components/VideoPlayer/courseLearning/'),
      );

    expect(violations).toEqual([]);
  });

  test('brand colour literals stay in the single token source', () => {
    const tokenFile = path.join(sourceRoot, 'constants/brandTokens.ts');
    const tokenSource = fs.readFileSync(tokenFile, 'utf8');
    const protectedTokens = [
      '#070A10',
      '#111620',
      '#252C38',
      '#2C69DB',
      '#245CC7',
      '#D8A63C',
    ];
    const violations: string[] = [];

    for (const file of sourceFiles(sourceRoot)) {
      if (file === tokenFile) continue;
      const text = fs.readFileSync(file, 'utf8');
      for (const token of protectedTokens) {
        if (text.toLowerCase().includes(token.toLowerCase())) {
          violations.push(`${relative(file)} repeats ${token}`);
        }
      }
    }

    expect(tokenSource).toEqual(expect.stringContaining('#2C69DB'));
    expect(violations).toEqual([]);
  });
});
