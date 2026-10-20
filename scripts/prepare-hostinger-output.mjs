import { copyFileSync, existsSync, mkdirSync } from 'node:fs';
import { resolve } from 'node:path';

// Hostinger publishes the Vite output as the web root.  Vite deliberately
// ignores dotfiles, so copy the Laravel entry point and rewrite rules after
// every build to keep API and SPA routes working after a deployment.
const output = resolve('dist');
mkdirSync(output, { recursive: true });

for (const file of ['index.php', '.htaccess', 'favicon.ico', 'robots.txt']) {
  const source = resolve('public', file);
  if (existsSync(source)) {
    copyFileSync(source, resolve(output, file));
  }
}
