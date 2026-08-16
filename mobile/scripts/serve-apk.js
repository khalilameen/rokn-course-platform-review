const http = require('http');
const fs = require('fs');
const path = require('path');

const port = Number(process.env.ROKN_APK_PORT || 8088);
const artifactRoot = path.resolve(__dirname, '..', 'artifacts');
const requestedPath = process.env.ROKN_APK_PATH;
const candidates = requestedPath
  ? [path.resolve(requestedPath)]
  : [
      path.join(artifactRoot, 'Rokn-test.apk'),
      path.join(artifactRoot, 'Rokn-direct.apk'),
    ];
const apkPath = candidates.find(candidate => fs.existsSync(candidate));

if (!apkPath) {
  console.error('No APK found. Run `npm run apk:test` or set ROKN_APK_PATH.');
  process.exit(1);
}
const downloadName = path.basename(apkPath);

const server = http.createServer((request, response) => {
  if (request.url !== '/' && request.url !== `/${downloadName}`) {
    response.writeHead(404, { 'Content-Type': 'text/plain; charset=utf-8' });
    response.end('Not found');
    return;
  }

  if (request.url === '/') {
    response.writeHead(302, {Location: `/${downloadName}`});
    response.end();
    return;
  }

  const stat = fs.statSync(apkPath);
  response.writeHead(200, {
    'Content-Type': 'application/vnd.android.package-archive',
    'Content-Disposition': `attachment; filename="${downloadName}"`,
    'Content-Length': stat.size,
    'Cache-Control': 'no-store',
  });
  fs.createReadStream(apkPath).pipe(response);
});

server.listen(port, '0.0.0.0');
