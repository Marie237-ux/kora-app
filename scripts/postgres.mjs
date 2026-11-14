import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const data = path.join(root, '.local', 'postgres');
const binary = (name) =>
  process.env.PG_BIN
    ? path.join(process.env.PG_BIN, name + (process.platform === 'win32' ? '.exe' : ''))
    : process.platform === 'win32'
      ? path.join('C:/Program Files/PostgreSQL/17/bin', name + '.exe')
      : name;
function command(name, args, quiet = false, detached = false) {
  // Le serveur démarré par pg_ctl hérite des tubes de sortie : sous Windows, spawnSync attendrait
  // alors sa fin indéfiniment. Ses messages vont déjà dans .local/postgres.log.
  const stdio = detached ? 'ignore' : 'pipe';
  const r = spawnSync(binary(name), args, {
    cwd: root,
    encoding: 'utf8',
    windowsHide: true,
    stdio,
  });
  if (!quiet) {
    if (r.stdout) process.stdout.write(r.stdout);
    if (r.stderr) process.stderr.write(r.stderr);
  }
  if (r.error) throw r.error;
  return r;
}
const action = process.argv[2] ?? 'start';
if (!['start', 'stop', 'status'].includes(action)) throw Error('Use start, stop or status');
if (action === 'stop' || action === 'status') {
  if (!existsSync(data)) {
    console.log('No project database cluster.');
    process.exit(0);
  }
  const r = command('pg_ctl', [
    '-D',
    data,
    ...(action === 'stop' ? ['-m', 'fast', '-w', 'stop'] : ['status']),
  ]);
  process.exit(r.status ?? 1);
}
mkdirSync(path.join(root, '.local'), { recursive: true });
if (!existsSync(path.join(data, 'PG_VERSION'))) {
  const r = command('initdb', [
    '-D',
    data,
    '-U',
    'kora_dev',
    '--auth=trust',
    '--encoding=UTF8',
    '--locale=C',
  ]);
  if (r.status !== 0) process.exit(r.status ?? 1);
}
if (command('pg_ctl', ['-D', data, 'status'], true).status !== 0) {
  // Un bac à sable Windows peut lire la base sans pouvoir inspecter le processus d'un autre utilisateur.
  // Vérifier le répertoire du serveur avant toute tentative de second démarrage.
  process.env.PGCONNECT_TIMEOUT = '2';
  const running = command(
    'psql',
    [
      '-h',
      '127.0.0.1',
      '-p',
      '55432',
      '-U',
      'kora_dev',
      '-d',
      'postgres',
      '-tAc',
      'SHOW data_directory',
    ],
    true,
  );
  const canonical = (value) => {
    const resolved = path.resolve(value.trim());
    return process.platform === 'win32' ? resolved.toLowerCase() : resolved;
  };
  if (running.status === 0) {
    if (canonical(running.stdout) !== canonical(data))
      throw Error('Port 55432 belongs to a different cluster; refusing to modify it.');
  } else {
    const r = command(
      'pg_ctl',
      [
        '-D',
        data,
        '-l',
        path.join(root, '.local', 'postgres.log'),
        '-o',
        '-h 127.0.0.1 -p 55432',
        '-w',
        'start',
      ],
      false,
      true,
    );
    if (r.status !== 0) process.exit(r.status ?? 1);
  }
}
for (const database of ['kora', 'kora_test']) {
  const args = ['-h', '127.0.0.1', '-p', '55432', '-U', 'kora_dev'];
  const check = command(
    'psql',
    [
      ...args,
      '-d',
      'postgres',
      '-tAc',
      "SELECT 1 FROM pg_database WHERE datname = '" + database + "'",
    ],
    true,
  );
  if (check.status !== 0) {
    process.stderr.write(check.stderr ?? '');
    process.exit(1);
  }
  if (check.stdout.trim() !== '1') {
    const r = command('createdb', [...args, database]);
    if (r.status !== 0) process.exit(r.status ?? 1);
  }
}
console.log(
  'Project PostgreSQL ready: 127.0.0.1:55432, kora and kora_test. Local development only.',
);
