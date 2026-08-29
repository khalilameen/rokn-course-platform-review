import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { basename, extname } from 'node:path';

const textExtensions = new Set([
    '.config', '.css', '.htm', '.html', '.js', '.json', '.map', '.md',
    '.php', '.scss', '.svg', '.txt', '.webmanifest', '.xml',
]);

const textBasenames = new Set(['.gitignore', '.htaccess']);

export function canonicalPublicAssetBytes(path, bytes) {
    const name = basename(path).toLowerCase();

    if (!textBasenames.has(name) && !textExtensions.has(extname(name))) {
        return bytes;
    }

    return Buffer.from(bytes.toString('utf8').replace(/\r\n/g, '\n'), 'utf8');
}

export function publicAssetBytes(path) {
    return canonicalPublicAssetBytes(path, readFileSync(path));
}

export function hashPublicAsset(path) {
    return createHash('sha256').update(publicAssetBytes(path)).digest('hex');
}
