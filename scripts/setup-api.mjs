import { existsSync, copyFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const api = path.join(root, 'services', 'api');
const env = path.join(api, '.env');
if (!existsSync(env)) {
  copyFileSync(path.join(api, '.env.example'), env);
  const key = spawnSync('php', ['artisan', 'key:generate', '--no-interaction'], {
    cwd: api,
    stdio: 'inherit',
    windowsHide: true,
  });
  if (key.status !== 0) process.exit(key.status ?? 1);
}
const migration = spawnSync('php', ['artisan', 'migrate', '--no-interaction'], {
  cwd: api,
  stdio: 'inherit',
  windowsHide: true,
});
process.exit(migration.status ?? 1);
