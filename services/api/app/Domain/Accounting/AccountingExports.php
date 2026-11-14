<?php

namespace App\Domain\Accounting;

use App\Domain\Procurement\Records;
use App\Models\User;
use Brick\Math\BigInteger;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\DB;

/**
 * Exports of posted accounting data: trial balance, general ledger, journal (FEC-style columns) and the two
 * statements, as CSV (semicolon, UTF-8 with BOM for spreadsheet software), XLSX or PDF. Rows are written to a
 * temporary file as they are read, so large books do not fill memory. Text that a spreadsheet would run as a
 * formula is neutralised. Each export is traced with its parameters, row count and fingerprint.
 */
final class AccountingExports
{
    public const REPORTS = ['trial-balance', 'ledger', 'journal', 'income-statement', 'balance-sheet'];

    public const PDF_MAX_ROWS = 3000;

    public function export(object $book, string $report, string $format, array $data, User $user)
    {
        $year = app(AccountingBooks::class)->row('accounting_years', $data['year_id'], $book);
        if ($data['from'] < $year->starts_on || $data['to'] > $year->ends_on) {
            AccountingBooks::fail('dates', 'CALENDAR_INVALID');
        }
        $fr = app()->getLocale() === 'fr';
        [$title, $header, $rows, $notes] = match ($report) {
            'trial-balance' => $this->trialBalance($book, $data, $fr),
            'ledger' => $this->ledger($book, $year, $data, $fr),
            'journal' => $this->journal($book, $year, $data, $fr),
            default => $this->statement($book, str_replace('-', '_', $report), $data, $fr),
        };
        $path = tempnam(sys_get_temp_dir(), 'kora-export-');
        try {
            $count = match ($format) {
                'csv' => $this->csv($path, $header, $rows),
                'xlsx' => $this->xlsx($path, $header, $rows),
                'pdf' => $this->pdf($path, $book, $title, $header, $rows, $notes, $data, $fr),
            };
        } catch (\Throwable $e) {
            @unlink($path);

            throw $e;
        }
        $sha = hash_file('sha256', $path);
        DB::table('accounting_exports')->insert([
            'id' => Records::id(),
            'book_id' => $book->id,
            'report' => $report,
            'format' => $format,
            'parameters' => Records::json(array_intersect_key($data, array_flip(['year_id', 'from', 'to']))),
            'rows' => $count,
            'bytes' => filesize($path),
            'sha256' => $sha,
            'user_id' => $user->id,
            'created_at' => now(),
        ]);
        Records::audit($user, 'accounting.exported', $book->id, [
            'report' => $report,
            'format' => $format,
            'from' => $data['from'],
            'to' => $data['to'],
            'rows' => $count,
        ]);
        $types = [
            'csv' => 'text/csv; charset=UTF-8',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pdf' => 'application/pdf',
        ];

        return response()
            ->download(
                $path,
                'kora-'.
                    ($book->mode === 'demo' ? 'demo-' : '').
                    $report.
                    '-'.
                    $data['from'].
                    '-'.
                    $data['to'].
                    '.'.
                    $format,
                [
                    'Content-Type' => $types[$format],
                    'Cache-Control' => 'private, no-store',
                    'X-Content-Type-Options' => 'nosniff',
                    'X-Export-Sha256' => $sha,
                ],
            )
            ->deleteFileAfterSend();
    }

    private function trialBalance(object $book, array $data, bool $fr): array
    {
        $report = app(AccountingReports::class)->run($book, $data, false);
        $rows = (function () use ($report, $fr) {
            foreach ($report['rows'] as $r) {
                yield [
                    $r['account']['code'],
                    $this->label($r['account']['label'] ?? null, $fr),
                    $this->number($r['opening_balance']),
                    $this->number($r['debit']),
                    $this->number($r['credit']),
                    $this->number($r['closing_balance']),
                ];
            }
            yield [
                $fr ? 'TOTAL' : 'TOTAL',
                '',
                $this->number($report['opening_balance']),
                $this->number($report['debit']),
                $this->number($report['credit']),
                $this->number($report['closing_balance']),
            ];
        })();

        return [
            $fr ? 'Balance générale' : 'Trial balance',
            $fr
                ? ['Compte', 'Intitulé', 'Solde d\'ouverture', 'Débit', 'Crédit', 'Solde de clôture']
                : ['Account', 'Label', 'Opening balance', 'Debit', 'Credit', 'Closing balance'],
            $rows,
            $this->provisional($report['known_unposted_sources'], $fr),
        ];
    }

    /** All lines of the period by account, each account opened with its balance earlier in the same year. */
    private function ledger(object $book, object $year, array $data, bool $fr): array
    {
        $base = fn () => DB::table('accounting_entry_lines AS l')
            ->join('accounting_entries AS e', 'e.id', '=', 'l.entry_id')
            ->where('e.book_id', $book->id)
            ->where('e.year_id', $year->id)
            ->where('e.status', 'posted');
        $opening = [];
        foreach (
            $base()
                ->where('e.entry_date', '<', $data['from'])
                ->groupBy('l.account_id')
                ->selectRaw('l.account_id, SUM(l.debit) AS d, SUM(l.credit) AS c')
                ->get() as $r
        ) {
            $opening[$r->account_id] = BigInteger::of((string) $r->d)->minus(BigInteger::of((string) $r->c));
        }
        $journals = DB::table('accounting_journals')->where('book_id', $book->id)->pluck('code', 'id');
        // In account number order, as an accountant reads a ledger.
        $query = $base()
            ->join('accounting_accounts AS a', 'a.id', '=', 'l.account_id')
            ->whereBetween('e.entry_date', [$data['from'], $data['to']])
            ->orderBy('a.code')
            ->orderBy('l.account_id')
            ->orderBy('e.entry_date')
            ->orderBy('e.posted_at')
            ->orderBy('e.number')
            ->orderBy('l.position')
            ->select([
                'l.account_id',
                'l.account_snapshot',
                'l.party',
                'l.debit',
                'l.credit',
                'e.number',
                'e.entry_date',
                'e.reference',
                'e.journal_id',
            ]);
        $rows = (function () use ($query, $opening, $journals, $fr) {
            $current = null;
            $running = BigInteger::zero();
            foreach ($query->cursor() as $l) {
                $account = json_decode($l->account_snapshot, true);
                if ($l->account_id !== $current) {
                    $current = $l->account_id;
                    $running = $opening[$l->account_id] ?? BigInteger::zero();
                    yield [
                        $account['code'],
                        $this->label($account['label'] ?? null, $fr),
                        '',
                        '',
                        '',
                        $fr ? 'Solde au début de la période' : 'Balance at period start',
                        '',
                        '',
                        '',
                        $this->number((string) $running),
                    ];
                }
                $running = $running
                    ->plus(BigInteger::of((string) $l->debit))
                    ->minus(BigInteger::of((string) $l->credit));
                $party = $l->party ? json_decode($l->party, true) : null;
                yield [
                    $account['code'],
                    $this->label($account['label'] ?? null, $fr),
                    $l->entry_date,
                    $journals[$l->journal_id] ?? '',
                    $l->number,
                    $l->reference,
                    $party['name'] ?? '',
                    $this->number((string) $l->debit),
                    $this->number((string) $l->credit),
                    $this->number((string) $running),
                ];
            }
        })();

        return [
            $fr ? 'Grand livre' : 'General ledger',
            $fr
                ? [
                    'Compte',
                    'Intitulé',
                    'Date',
                    'Journal',
                    'N° écriture',
                    'Référence',
                    'Tiers',
                    'Débit',
                    'Crédit',
                    'Solde',
                ]
                : [
                    'Account',
                    'Label',
                    'Date',
                    'Journal',
                    'Entry no.',
                    'Reference',
                    'Party',
                    'Debit',
                    'Credit',
                    'Balance',
                ],
            $rows,
            $this->provisional(null, $fr),
        ];
    }

    /**
     * Journal with the column names of the French FEC (a common exchange layout for accountants). It is not a
     * regulatory file for Cameroon or OHADA; the accountant confirms which format the authorities expect.
     */
    private function journal(object $book, object $year, array $data, bool $fr): array
    {
        $journals = DB::table('accounting_journals')->where('book_id', $book->id)->get()->keyBy('id');
        $query = DB::table('accounting_entry_lines AS l')
            ->join('accounting_entries AS e', 'e.id', '=', 'l.entry_id')
            ->where('e.book_id', $book->id)
            ->where('e.year_id', $year->id)
            ->where('e.status', 'posted')
            ->whereBetween('e.entry_date', [$data['from'], $data['to']])
            ->orderBy('e.journal_id')
            ->orderBy('e.entry_date')
            ->orderBy('e.number')
            ->orderBy('l.position')
            ->select([
                'l.account_snapshot',
                'l.party',
                'l.debit',
                'l.credit',
                'e.number',
                'e.entry_date',
                'e.reference',
                'e.journal_id',
                'e.description',
                'e.posted_at',
            ]);
        $rows = (function () use ($query, $journals, $fr) {
            foreach ($query->cursor() as $l) {
                $account = json_decode($l->account_snapshot, true);
                $party = $l->party ? json_decode($l->party, true) : null;
                $journal = $journals[$l->journal_id] ?? null;
                yield [
                    $journal?->code ?? '',
                    $journal ? $this->label(json_decode($journal->label, true), $fr) : '',
                    $l->number,
                    str_replace('-', '', $l->entry_date),
                    $account['code'],
                    $this->label($account['label'] ?? null, $fr),
                    $party['id'] ?? '',
                    $party['name'] ?? '',
                    $l->reference,
                    str_replace('-', '', $l->entry_date),
                    $this->label(json_decode($l->description, true), $fr),
                    $this->number((string) $l->debit),
                    $this->number((string) $l->credit),
                    substr(str_replace('-', '', (string) $l->posted_at), 0, 8),
                    'XAF',
                ];
            }
        })();

        return [
            $fr ? 'Journal des écritures' : 'Journal entries',
            [
                'JournalCode',
                'JournalLib',
                'EcritureNum',
                'EcritureDate',
                'CompteNum',
                'CompteLib',
                'CompAuxNum',
                'CompAuxLib',
                'PieceRef',
                'PieceDate',
                'EcritureLib',
                'Debit',
                'Credit',
                'ValidDate',
                'Idevise',
            ],
            $rows,
            [
                $fr
                    ? 'Colonnes de type FEC ; format réglementaire à confirmer par le comptable.'
                    : 'FEC-style columns; regulatory format to be confirmed by the accountant.',
            ],
        ];
    }

    private function statement(object $book, string $kind, array $data, bool $fr): array
    {
        $s = app(AccountingStatements::class)->compute($book, $kind, $data);
        $rows = (function () use ($s, $fr) {
            foreach ($s['lines'] as $line) {
                yield [$line['code'], $this->label($line['label'], $fr), $this->number($line['amount'])];
            }
            foreach ($s['unassigned_accounts'] as $u) {
                yield [
                    $u['code'],
                    ($fr ? 'Compte non affecté : ' : 'Unassigned account: ').$this->label($u['label'], $fr),
                    $this->number($u['balance']),
                ];
            }
        })();
        $notes =
            $s['status'] === 'provisional'
                ? [
                    ($fr ? 'PROVISOIRE : ' : 'PROVISIONAL: ').
                    implode(', ', array_column($s['provisional_reasons'], 'code')),
                ]
                : [
                    $fr
                        ? 'Définitif pour la période (périodes closes, sources comptabilisées, rapprochements faits).'
                        : 'Final for the period (periods closed, sources posted, reconciliations done).',
                ];
        if ($s['sides']) {
            $notes[] =
                ($fr ? 'Actif ' : 'Assets ').
                $s['sides']['assets'].
                ' / '.
                ($fr ? 'Passif ' : 'Liabilities ').
                $s['sides']['liabilities'].
                ' / '.
                ($fr ? 'Écart ' : 'Difference ').
                $s['sides']['difference'];
        }

        return [
            $this->label($s['layout']['label'], $fr).' (v'.$s['layout']['version'].')',
            $fr ? ['Code', 'Rubrique', 'Montant'] : ['Code', 'Line', 'Amount'],
            $rows,
            $notes,
        ];
    }

    private function provisional(?int $unposted, bool $fr): array
    {
        return [
            ($fr ? 'Écritures validées seulement ; état provisoire' : 'Posted entries only; provisional').
            ($unposted
                ? ($fr
                    ? " ; $unposted source(s) non comptabilisée(s)"
                    : "; $unposted unposted source(s)")
                : '').
            '.',
        ];
    }

    private function label(mixed $label, bool $fr): string
    {
        return is_array($label)
            ? (string) ($label[$fr ? 'fr' : 'en'] ?? ($label['fr'] ?? ''))
            : (string) ($label ?? '');
    }

    /** Amounts are whole XAF, kept exact as text and written as numbers. */
    private function number(string $value): array
    {
        return ['n' => $value];
    }

    /** A cell a spreadsheet would read as a formula starts with an apostrophe instead. */
    private function text(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private function csv(string $path, array $header, iterable $rows): int
    {
        $out = fopen($path, 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(fn ($h) => $this->text($h), $header), ';', '"', '', "\r\n");
        $count = 0;
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($v) => is_array($v) ? $v['n'] : $this->text($v), $row), ';', '"', '', "\r\n");
            $count++;
        }
        fclose($out);

        return $count;
    }

    /** Minimal Office Open XML workbook: one sheet, inline strings, numbers as numbers. */
    private function xlsx(string $path, array $header, iterable $rows): int
    {
        $sheet = tempnam(sys_get_temp_dir(), 'kora-sheet-');
        $out = fopen($sheet, 'wb');
        fwrite(
            $out,
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>',
        );
        $write = function (array $cells) use ($out) {
            $xml = '<row>';
            foreach ($cells as $cell) {
                $xml .= is_array($cell)
                    ? '<c t="n"><v>'.$cell['n'].'</v></c>'
                    : '<c t="inlineStr"><is><t xml:space="preserve">'.
                        htmlspecialchars($this->text($cell), ENT_XML1 | ENT_QUOTES, 'UTF-8').
                        '</t></is></c>';
            }
            fwrite($out, $xml.'</row>');
        };
        $write($header);
        $count = 0;
        foreach ($rows as $row) {
            $write($row);
            $count++;
        }
        fwrite($out, '</sheetData></worksheet>');
        fclose($out);
        $zip = new \ZipArchive;
        @unlink($path);
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString(
            '[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
        );
        $zip->addFromString(
            '_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        );
        $zip->addFromString(
            'xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="KORA" sheetId="1" r:id="rId1"/></sheets></workbook>',
        );
        $zip->addFromString(
            'xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
        );
        $zip->addFile($sheet, 'xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($sheet);

        return $count;
    }

    /** For reading and filing; a large ledger or journal goes to CSV or XLSX instead. */
    private function pdf(
        string $path,
        object $book,
        string $title,
        array $header,
        iterable $rows,
        array $notes,
        array $data,
        bool $fr,
    ): int {
        $collected = [];
        foreach ($rows as $row) {
            if (count($collected) >= self::PDF_MAX_ROWS) {
                AccountingBooks::fail('format', 'EXPORT_TOO_LARGE_FOR_PDF');
            }
            // Grouped by thousands from the exact text, never through a float.
            $collected[] = array_map(
                fn ($v) => is_array($v) ? preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $v['n']) : (string) $v,
                $row,
            );
        }
        $html = view('documents.accounting-report', [
            'title' => $title,
            'book' => $this->label(json_decode($book->label, true), $fr),
            'demo' => $book->mode === 'demo',
            'period' => $data['from'].' → '.$data['to'],
            'header' => $header,
            'rows' => $collected,
            'notes' => $notes,
            'generated' => now('Africa/Douala')->format('Y-m-d H:i'),
            'fr' => $fr,
        ])->render();
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', count($header) > 6 ? 'landscape' : 'portrait');
        $pdf->render();
        file_put_contents($path, $pdf->output());

        return count($collected);
    }
}
