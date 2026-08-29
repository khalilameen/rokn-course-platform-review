'use strict';

// Native bundles are compiled with NODE_ENV=production, but Jest must load the
// React test renderer and app safeguards in their test variants. Keep this
// boundary inside the command so local, CI and EAS verification behave alike.
process.env.NODE_ENV = 'test';
process.env.BABEL_ENV = 'test';

const {run} = require('jest');

run(['--runInBand', '--ci', '--detectOpenHandles']).catch(error => {
  console.error(error);
  process.exitCode = 1;
});
