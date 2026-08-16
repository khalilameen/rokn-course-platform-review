import { copyFileSync, mkdirSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const select2Source = resolve(root, 'node_modules/legacy-select2/dist');
const select2Target = resolve(root, 'public/admin/assets/js/vendor/select2');

mkdirSync(select2Target, { recursive: true });
copyFileSync(resolve(select2Source, 'css/select2.min.css'), resolve(select2Target, 'select2.min.css'));
copyFileSync(resolve(select2Source, 'js/select2.min.js'), resolve(select2Target, 'select2.min.js'));

console.log('Published exact Select2 distribution assets.');
