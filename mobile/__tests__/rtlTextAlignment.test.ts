import fs from 'fs';
import path from 'path';

describe('Arabic text alignment', () => {
  it('uses the physical right edge as the shared Arabic text start', () => {
    const designSystem = fs.readFileSync(
      path.resolve(__dirname, '../src/constants/designSystem.ts'),
      'utf8',
    );

    expect(designSystem).toContain("rtlTextAlign = 'right' as const");
    expect(designSystem).not.toContain("rtlTextAlign = 'left' as const");
  });
});
