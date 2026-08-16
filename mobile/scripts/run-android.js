const fs = require('fs');
const path = require('path');
const {spawnSync} = require('child_process');

const env = {...process.env};
const projectRoot = path.resolve(__dirname, '..');

function readJavaMajor(javaHome) {
  try {
    const release = fs.readFileSync(path.join(javaHome, 'release'), 'utf8');
    const match = release.match(/JAVA_VERSION\s*=\s*"(\d+)/);
    return match ? Number(match[1]) : null;
  } catch {
    return null;
  }
}

if (process.platform === 'win32') {
  const bundledJdkRoot = path.join(projectRoot, '.jdk17');
  const bundledJdks = fs.existsSync(bundledJdkRoot)
    ? fs
        .readdirSync(bundledJdkRoot, {withFileTypes: true})
        .filter(entry => entry.isDirectory())
        .map(entry => path.join(bundledJdkRoot, entry.name))
    : [];
  const javaCandidates = [
    ...bundledJdks,
    env.JAVA_HOME,
    path.join(
      env.ProgramFiles || 'C:\\Program Files',
      'Android',
      'Android Studio',
      'jbr',
    ),
  ].filter(Boolean);

  const javaHome = javaCandidates.find(
    candidate =>
      fs.existsSync(path.join(candidate, 'bin', 'java.exe')) &&
      readJavaMajor(candidate) === 17,
  );

  if (!javaHome) {
    console.error(
      'JDK 17 was not found. Set JAVA_HOME to JDK 17 or place it below .jdk17.',
    );
    process.exit(1);
  }

  env.JAVA_HOME = javaHome;

  if (!env.ANDROID_HOME && env.LOCALAPPDATA) {
    env.ANDROID_HOME = path.join(env.LOCALAPPDATA, 'Android', 'Sdk');
  }

  const pathKey =
    Object.keys(env).find(key => key.toLowerCase() === 'path') || 'Path';
  const pathEntries = [path.join(javaHome, 'bin')];

  if (env.ANDROID_HOME) {
    pathEntries.push(path.join(env.ANDROID_HOME, 'platform-tools'));
  }

  env[pathKey] = [...pathEntries, env[pathKey] || ''].join(path.delimiter);
}

const expoCli = require.resolve('expo/bin/cli');
const result = spawnSync(
  process.execPath,
  [expoCli, 'run:android', ...process.argv.slice(2)],
  {cwd: projectRoot, env, stdio: 'inherit'},
);

if (result.error) {
  console.error(result.error.message);
}

process.exit(result.status ?? 1);
