'use strict';

/* Static checks complement the TalkBack and VoiceOver staging checklist. */
const fs = require('fs');
const path = require('path');
const ts = require('typescript');

const root = path.resolve(__dirname, '..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');
const failures = [];
const requireText = (file, pattern, explanation) => {
  if (!pattern.test(read(file))) failures.push(`${file}: ${explanation}`);
};

requireText(
  'src/constants/designSystem.ts',
  /minTouchTarget:\s*Math\.max\(48,\s*PixelPerfect\(48\)\)/,
  'the shared minimum touch target must remain at least 48dp.',
);
requireText(
  'src/constants/designSystem.ts',
  /textDirection[\s\S]*direction:\s*'rtl'[\s\S]*writingDirection:\s*'rtl'/,
  'the RTL text-direction contract is missing.',
);
for (const component of [
  'src/components/touchables/Button.tsx',
  'src/components/TabBar.tsx',
]) {
  requireText(
    component,
    /accessibilityLabel=/,
    'interactive controls need an accessibility label.',
  );
  requireText(
    component,
    /accessibilityRole=/,
    'interactive controls need an accessibility role.',
  );
  requireText(
    component,
    /Accessibility\.minTouchTarget/,
    'interactive controls must use the shared touch target.',
  );
}

const sourceRoot = path.join(root, 'src');
const files = [];
const collect = directory => {
  for (const entry of fs.readdirSync(directory, {withFileTypes: true})) {
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) collect(absolute);
    else if (/\.(?:tsx?|jsx?)$/.test(entry.name)) files.push(absolute);
  }
};
collect(sourceRoot);

const fontScaleExceptions = new Set([
  // Certificates are rendered/printed as a fixed-layout legal artifact. The
  // screen itself exposes a scalable surrounding UI and does not use these
  // nodes as controls.
  'src/screens/Profile/Certificates.tsx',
]);
for (const absolute of files) {
  const relative = path.relative(root, absolute).replace(/\\/g, '/');
  const content = fs.readFileSync(absolute, 'utf8');
  if (
    /allowFontScaling\s*=\s*\{false\}/.test(content) &&
    !fontScaleExceptions.has(relative)
  ) {
    failures.push(
      `${relative}: do not disable font scaling; use responsive layout instead.`,
    );
  }

  const source = ts.createSourceFile(
    relative,
    content,
    ts.ScriptTarget.Latest,
    true,
    relative.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
  );
  const interactiveTags = new Set([
    'Pressable',
    'TouchableHighlight',
    'TouchableOpacity',
    'TouchableWithoutFeedback',
  ]);
  const openingName = opening => opening.tagName.getText(source);
  const hasAccessibilityName = opening =>
    opening.attributes.properties.some(
      attribute =>
        ts.isJsxAttribute(attribute) &&
        ['accessibilityLabel', 'accessibilityLabelledBy'].includes(
          attribute.name.getText(source),
        ),
    );
  const inspectControl = node => {
    if (!ts.isJsxElement(node)) return;
    const opening = node.openingElement;
    if (!interactiveTags.has(openingName(opening))) return;

    let containsText = false;
    let containsIcon = false;
    const inspectChild = child => {
      if (ts.isJsxText(child) && child.getText(source).trim()) {
        containsText = true;
      }
      if (ts.isJsxElement(child) || ts.isJsxSelfClosingElement(child)) {
        const childOpening = ts.isJsxElement(child)
          ? child.openingElement
          : child;
        const childName = openingName(childOpening);
        if (childName === 'Text') containsText = true;
        if (/(?:Icon|Eye|Arrow|Chevron|Close|Search|SVG)$/i.test(childName)) {
          containsIcon = true;
        }
      }
      ts.forEachChild(child, inspectChild);
    };
    node.children.forEach(inspectChild);
    if (containsIcon && !containsText && !hasAccessibilityName(opening)) {
      const line = source.getLineAndCharacterOfPosition(opening.getStart(source)).line + 1;
      failures.push(
        `${relative}:${line}: icon-only controls need an accessibility label.`,
      );
    }
  };
  const visit = node => {
    inspectControl(node);
    ts.forEachChild(node, visit);
  };
  visit(source);
}

if (failures.length) {
  console.error(`Accessibility static audit failed (${failures.length}):`);
  failures.forEach(failure => console.error(`- ${failure}`));
  process.exit(1);
}

console.log(
  `Accessibility static audit passed (${files.length} source files scanned).`,
);
