import { readFileSync, writeFileSync } from 'node:fs';
const readReport = (name) => {
  const xml = readFileSync('docs/preuves/' + name, 'utf8');
  const first = xml.match(
    /<testsuite\b[^>]*\btests="(\d+)"[^>]*\bassertions="(\d+)"[^>]*\berrors="(\d+)"[^>]*\bfailures="(\d+)"[^>]*\bskipped="(\d+)"/,
  );
  if (!first || first[3] !== '0' || first[4] !== '0')
    throw new Error('Rapport absent ou en échec : ' + name);
  return { tests: +first[1], assertions: +first[2], skipped: +first[5] };
};
const pg = readReport('backend-postgresql.xml'),
  sqlite = readReport('backend-sqlite.xml');
const fmt = (n) =>
  new Intl.NumberFormat('fr-FR', { useGrouping: true }).format(n).replace(/\u202f/g, ' ');
for (const name of [
  'AVANCEMENT.md',
  'PLAN.md',
  'VALIDATION.md',
  'INCREMENT_APPROVISIONNEMENT.md',
]) {
  const p = 'docs/' + name;
  let s = readFileSync(p, 'utf8');
  s = s
    .replace(
      /PostgreSQL : \d+ tests, [\d ]+ assertions/g,
      'PostgreSQL : ' + pg.tests + ' tests, ' + fmt(pg.assertions) + ' assertions',
    )
    .replace(
      /PHP PostgreSQL \| \d+ tests, [\d ]+ assertions/g,
      'PHP PostgreSQL | ' + pg.tests + ' tests, ' + fmt(pg.assertions) + ' assertions',
    )
    .replace(
      /\d+ (tests recensés|recensés), \d+ exécutés, \d+ réservés à PostgreSQL ; [\d ]+ assertions/g,
      (_, label) =>
        sqlite.tests +
        ' ' +
        label +
        ', ' +
        (sqlite.tests - sqlite.skipped) +
        ' exécutés, ' +
        sqlite.skipped +
        ' réservés à PostgreSQL ; ' +
        fmt(sqlite.assertions) +
        ' assertions',
    )
    .replace(/SQLite : \d+ exécutés/g, 'SQLite : ' + (sqlite.tests - sqlite.skipped) + ' exécutés')
    .replace(
      /(SQLite :[^\n]*?), \d+ tests spécifiques réservés à PostgreSQL/g,
      '$1, ' + sqlite.skipped + ' tests spécifiques réservés à PostgreSQL',
    )
    .replace(
      /(SQLite :[^\n]*?), (quatre|cinq|six|sept|\d+) spécifiques réservés à PostgreSQL/g,
      '$1, ' + sqlite.skipped + ' spécifiques réservés à PostgreSQL',
    )
    .replace(
      /(SQLite :[^\n]*réservés à PostgreSQL, )[\d ]+ assertions/g,
      '$1' + fmt(sqlite.assertions) + ' assertions',
    )
    .replace(/\d+ tests ajoutés/, pg.tests - 54 + ' tests ajoutés');
  writeFileSync(p, s);
}
console.log(JSON.stringify({ postgresql: pg, sqlite }));
