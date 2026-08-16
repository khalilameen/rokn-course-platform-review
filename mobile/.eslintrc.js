module.exports = {
  root: true,
  extends: '@react-native',
  rules: {
    // Fire-and-forget promises use an explicit `void` marker.
    'no-void': 'off',
    // Responsive values and pressed states are data-driven.
    'react-native/no-inline-styles': 'off',
    // Header slots are render props.
    'react/no-unstable-nested-components': ['error', {allowAsProps: true}],
    eqeqeq: 'error',
  },
};
