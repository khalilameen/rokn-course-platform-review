module.exports = {
  project: {
    ios: {},
    android: {},
  },
  // Cairo is the only typeface used by the production UI. Linking the entire
  // fonts tree used to package eighteen unused Bitter files in every build.
  assets: ['./src/assets/fonts/Cairo'],
};
