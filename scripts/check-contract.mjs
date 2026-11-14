import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const generated = path.join(root, 'packages/api-client/src/schema.ts');
const before = readFileSync(generated, 'utf8');
const script = path.join(root, 'node_modules/openapi-typescript/bin/cli.js');
const result = spawnSync(
  process.execPath,
  [script, path.join(root, 'docs/openapi.json'), '-o', generated],
  { cwd: root, stdio: 'inherit', windowsHide: true },
);
if (result.status !== 0) process.exit(result.status ?? 1);
if (before !== readFileSync(generated, 'utf8')) {
  writeFileSync(generated, before);
  console.error('Generated types differ from OpenAPI. Run npm run api:generate.');
  process.exit(1);
}
console.log('Generated API types match the contract.');
