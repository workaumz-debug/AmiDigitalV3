<?php
/**
 * Bangun database/data/lamteknik.json dari dokumen LAMTEKNIK 2025.
 *
 * Layout data rows (bukan header):
 *   No    (cols 0-5)   Kriteria+Indikator (cols 2-40ish)   Score 4+ (cols 41+)
 *
 * Strategi: ambil cols 0-40 per baris, akumulasi lintas baris,
 * hilangkan nomor indikator di depan → teks deskripsi indikator.
 *
 * Output: jenjang -> seksi -> [ {kode, indikator} ]
 * Jalankan: php database/data/_lamteknik_build_json.php   (butuh pdftotext)
 */

$dir = __DIR__;

$pdfFiles = [
    'S1'          => 'matrik-penilaian-led-dan-lkps-sarjana-aps-akademik-dan-vokasi-teknik-2025-untuk-pasca-akreditasi-pertama-dan-unggul.pdf',
    'S1 Terapan'  => 'matrik-penilaian-led-dan-lkps-sarjana-terapan-aps-akademik-dan-vokasi-teknik-2025-untuk-pasca-akreditasi-pertama-dan-unggul.pdf',
    'S2'          => 'matrik-penilaian-led-dan-lkps-magister-aps-akademik-dan-vokasi-teknik-2025-untuk-pasca-akreditasi-pertama-dan-unggul.pdf',
    'S2 Terapan'  => 'matrik-penilaian-led-dan-lkps-magister-terapan-aps-akademik-dan-vokasi-teknik-2025-untuk-pasca-akreditasi-pertama-dan-unggul.pdf',
    'S3'          => 'matrik-penilaian-led-dan-lkps-doktor-aps-akademik-dan-vokasi-teknik-2025.pdf',
    'S3 Terapan'  => 'matrik-penilaian-led-dan-lkps-doktor-terapan-aps-akademik-dan-vokasi-teknik-2025-untuk-pasca-akreditasi-pertama-dan-unggul.pdf',
    'D3'          => 'matrik-penilaian-led-dan-lkps-diploma-iii-aps-akademik-dan-vokasi-teknik-2025.pdf',
    'D2'          => 'matrik-penilaian-led-dan-lkps-diploma-ii-aps-akademik-dan-vokasi-teknik-2025-untuk-pasca-akreditasi-pertama-dan-unggul.pdf',
    'D1'          => 'matrik-penilaian-led-dan-lkps-diploma-i-aps-akademik-dan-vokasi-teknik-2025untuk-pasca-akreditasi-pertama-dan-unggul.pdf',
    'PPI'         => 'matrik-penilaian-led-dan-lkps-program-profesi-insinyur-aps-akademik-dan-vokasi-teknik-2025-untuk-pasca-akreditasi-pertama-dan-unggul.pdf',
];

// Width to grab per data line (excludes most of the scoring columns)
const COL_CONTENT_WIDTH = 42;
// Width to check for indicator number (5 chars: catches "   9 " but rejects "    2" list items)
const COL_NO_WIDTH = 5;

function parseLamteknikPdf(string $path): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'lamteknik') . '.txt';
    shell_exec('pdftotext -layout ' . escapeshellarg($path) . ' ' . escapeshellarg($tmp));
    $raw = @file($tmp, FILE_IGNORE_NEW_LINES) ?: [];
    @unlink($tmp);

    $sections   = [];
    $curSection = null;
    $curNo      = null;
    $curText    = '';

    $saveCurrentIfAny = function () use (&$sections, &$curSection, &$curNo, &$curText): void {
        if ($curNo === null || $curSection === null) return;
        $clean = iconv('UTF-8', 'UTF-8//IGNORE', $curText) ?: '';
        $txt   = trim(preg_replace('/\s+/', ' ', $clean));
        if ($txt !== '') {
            $sections[$curSection][] = ['kode' => (string) $curNo, 'indikator' => $txt];
        }
        $curNo   = null;
        $curText = '';
    };

    foreach ($raw as $rawLine) {
        $line = str_replace("\f", "", rtrim($rawLine, "\r"));

        if (trim($line) === '') continue;
        // Skip page titles / table headers
        if (preg_match('/^MATRIKS\s+PENILAIAN\s+LAPORAN/i', $line)) continue;
        if (preg_match('/^Akreditasi\s+Program\s+Studi/i', $line))  continue;
        if (preg_match('/^No\s+Kriteria/i', $line))                  continue;
        if (preg_match('/^LEMBAGA\s+AKREDITASI/i', $line))          continue;

        // --- Section header: Roman numeral at col 0 (I. through XII.)
        if (preg_match('/^(I{1,4}|IV|VI{0,3}|VII|VIII|IX|XI{0,3}|XII)\.\s+\S/u', $line)) {
            $saveCurrentIfAny();
            // Remove scoring text that bleeds onto the same line (2+ consecutive spaces)
            $hdr = preg_replace('/\s{2,}.*$/u', '', mb_substr($line, 0, 80));
            $curSection = trim($hdr);
            continue;
        }

        // --- Decimal sub-section marker (e.g. "2.1 Tata Kelola") — not an indicator
        if (preg_match('/^\s*\d+\.\d+\s+\S/', $line)) continue;

        // --- Indicator row: pure integer in first COL_NO_WIDTH cols
        $noCol = mb_substr($line, 0, COL_NO_WIDTH);
        if ($curSection !== null && preg_match('/^\s*(\d{1,3})\s/', $noCol, $m)) {
            $no = (int) $m[1];
            if ($no >= 1 && $no <= 200) {
                $saveCurrentIfAny();
                $curNo = $no;
                // Content: strip leading No, take up to COL_CONTENT_WIDTH
                $content = mb_substr($line, 0, COL_CONTENT_WIDTH);
                $content = preg_replace('/^\s*\d{1,3}\s*/', '', $content);
                $curText = trim($content);
                continue;
            }
        }

        // --- Continuation line
        if ($curNo !== null) {
            $fragment = trim(mb_substr($line, 0, COL_CONTENT_WIDTH));
            // Skip page header remnants
            if ($fragment !== '' && !preg_match('/^Matriks\s+Penilaian/i', $fragment)) {
                $curText .= ' ' . $fragment;
            }
        }
    }

    $saveCurrentIfAny();
    return $sections;
}

$out = [];

foreach ($pdfFiles as $jenjang => $pdf) {
    $fullPath = $dir . '/' . $pdf;
    if (!is_file($fullPath)) {
        fwrite(STDERR, "skip $jenjang (file tidak ditemukan: $pdf)\n");
        continue;
    }

    $sections = parseLamteknikPdf($fullPath);

    $out[$jenjang] = $sections;
    $totInds = array_sum(array_map('count', $sections));
    fwrite(STDERR, sprintf("%-12s: %d indikator, %d seksi\n", $jenjang, $totInds, count($sections)));
    foreach ($sections as $sec => $inds) {
        fwrite(STDERR, sprintf("  %-60s: %d inds\n", $sec, count($inds)));
    }
}

$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($dir . '/lamteknik.json', (string) $json);
fwrite(STDERR, "Tersimpan: database/data/lamteknik.json\n");
