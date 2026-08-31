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
  overrides: [
    {
      files: ['scripts/**/*.js'],
      env: {
        node: true,
        es2022: true,
      },
      globals: {
        AbortSignal: 'readonly',
        AggregateError: 'readonly',
        Buffer: 'readonly',
        Response: 'readonly',
        TextDecoder: 'readonly',
      },
      rules: {
        // Archive hashing and binary parsers intentionally use bitwise operations.
        'no-bitwise': 'off',
        // Legal-notice fixtures use aligned literal spaces in audited patterns.
        'no-regex-spaces': 'off',
      },
    },
  ],
};
