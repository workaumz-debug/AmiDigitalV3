<?php
/**
 * Bangun database/data/lamsama.json dari dokumen LAMSAMA IAPS 3.1.
 * Hanya file "Terakreditasi" (bukan Unggul) yang dipakai sebagai sumber.
 *
 * Output: jenjang -> kriteria -> [ {kode, indikator} ]
 * Jalankan: php database/data/_lamsama_build_json.php   (butuh pdftotext)
 */

$dir = __DIR__;

// Pemetaan: nama file PDF -> nama jenjang di tabel jenjangs
$pdfFiles = [
    'S1'          => 'S1-Matriks-Penilaian-Terakreditasi-IAPS-LAMSAMA-3.1.pdf',
    'S2'          => 'M-Matriks-Penilaian-Terakreditasi-IAPS-LAMSAMA-3.1.pdf',
    'S3'          => 'D-Matriks-Penilaian-Terakreditasi-IAPS-LAMSAMA-3.1.pdf',
    'D3'          => 'D3-Matriks-Penilaian-Terakreditasi-IAPS-LAMSAMA-3.1.pdf',
    'S1 Terapan'  => 'STr-Matriks-Penilaian-Terakreditasi-IAPS-LAMSAMA-3.1.pdf',
];

// Pemetaan tetap: nomor indikator -> huruf seksi (berdasarkan dokumen S1)
// 1-6 = A, 7-15 = B, 16-19 = C, 20-22 = D, 23 = E, 24 = F
function sectionLetterForNo(int $no): string
{
    if ($no <= 6)  return 'A';
    if ($no <= 15) return 'B';
    if ($no <= 19) return 'C';
    if ($no <= 22) return 'D';
    if ($no === 23) return 'E';
    return 'F'; // 24 or higher (e.g. STr PDF uses 34 for this indicator)
}

$sectionNames = [
    'A' => 'Tata Kelola dan Penjaminan Mutu',
    'B' => 'Pendidikan dan Pengajaran',
    'C' => 'Penelitian',
    'D' => 'Pengabdian Kepada Masyarakat',
    'E' => 'Capaian dan Luaran',
    'F' => 'Analisis dan Penetapan Program Pengembangan',
];

/**
 * Parse satu PDF dan kembalikan array indikator:
 *   [ ['no' => int, 'indikator' => string], ... ]
 *
 * Strategi:
 * 1. pdftotext -layout mempertahankan posisi karakter antar kolom.
 * 2. Batas kolom INDIKATOR dideteksi dari baris header ("BAIK" pertama).
 * 3. Baris pertama setiap indikator dibersihkan dari fragmen skor
 *    (" 1) …" atau "   [kata]") yang bocor dari kolom BAIK SEKALI.
 * 4. Baris lanjutan hanya di-trim saja (batas header cukup akurat).
 */
function parseLamsamaPdf(string $path): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'lamsama') . '.txt';
    shell_exec('pdftotext -layout ' . escapeshellarg($path) . ' ' . escapeshellarg($tmp));
    $raw = @file($tmp, FILE_IGNORE_NEW_LINES) ?: [];
    @unlink($tmp);

    // Deteksi batas kolom INDIKATOR dari posisi "BAIK" di baris header.
    $indBoundary = 35;
    foreach ($raw as $rawLine) {
        $line = rtrim($rawLine, "\r");
        if (preg_match('/\b(?:INDIKATOR|DESKRIPSI)\b/i', $line) && preg_match('/\bBAIK\b/i', $line)) {
            if (preg_match('/\bBAIK\b/i', $line, $hm, PREG_OFFSET_CAPTURE)) {
                $pos = $hm[0][1];
                if ($pos > 10) $indBoundary = $pos;
            }
            break;
        }
    }

    /**
     * Bersihkan teks baris pertama indikator dari fragmen kolom scoring.
     * Kolom BAIK SEKALI biasanya dimulai "1) …" atau teks kapital setelah
     * beberapa spasi — keduanya dicabut dari ujung teks.
     */
    $cleanFirstLine = function (string $s): string {
        // Hapus " 1) [teks]" di ujung (kolom BAIK SEKALI dimulai "1)")
        $s = preg_replace('/\s+1\)\s+.*$/u', '', $s);
        // Hapus "  [1-4 karakter]" sisa di ujung (satu kata pendek sisa)
        $s = preg_replace('/\s{2,}\S{1,4}\s*$/u', '', $s);
        return rtrim($s);
    };

    $indicators = [];
    $currentNo  = null;
    $currentTxt = '';
    $isFirstLine = false;

    $saveCurrentIfAny = function () use (&$indicators, &$currentNo, &$currentTxt): void {
        if ($currentNo === null) return;
        // Clean invalid UTF-8 before regex so /u flag doesn't silently return ''
        $clean = iconv('UTF-8', 'UTF-8//IGNORE', $currentTxt) ?: '';
        $txt = trim(preg_replace('/\s+/u', ' ', $clean));
        if ($txt !== '') {
            $indicators[] = ['no' => $currentNo, 'indikator' => $txt];
        }
        $currentNo  = null;
        $currentTxt = '';
    };

    foreach ($raw as $rawLine) {
        // Strip form-feed (page separator) so page-header lines are handled correctly
        $line = str_replace("\f", "", rtrim($rawLine, "\r"));

        if (preg_match('/\b(?:INDIKATOR|DESKRIPSI)\b.*\bBAIK\b/i', $line)) continue;
        if (preg_match('/^NO\s/i', $line)) continue;
        if (trim($line) === '') continue;

        $indCol = mb_substr($line, 0, $indBoundary);

        // Baris baru indikator
        if (preg_match('/^\s*(\d{1,2})\s+([A-Z].*)/', $indCol, $m)) {
            $no = (int) $m[1];
            if ($no >= 1 && $no <= 40) { // STr PDF uses 34 for what is ind 24
                $saveCurrentIfAny();
                $currentNo   = $no;
                $currentTxt  = $cleanFirstLine($m[2]);
                $isFirstLine = false; // baris berikutnya adalah continuation
                continue;
            }
        }

        // Baris lanjutan
        if ($currentNo !== null) {
            $cont = trim($indCol);
            if ($cont !== '' && !preg_match('/^Matriks\s+Penilaian/i', $cont)) {
                $currentTxt .= ' ' . $cont;
            }
        }
    }

    $saveCurrentIfAny();

    return $indicators;
}

$out = [];

foreach ($pdfFiles as $jenjang => $pdf) {
    $fullPath = $dir . '/' . $pdf;
    if (!is_file($fullPath)) {
        fwrite(STDERR, "skip $jenjang (file tidak ditemukan: $pdf)\n");
        continue;
    }

    $indicators = parseLamsamaPdf($fullPath);

    // Kelompokkan berdasarkan seksi menggunakan pemetaan tetap
    $grouped = [];
    foreach ($indicators as $ind) {
        $normNo   = min($ind['no'], 24); // normalize STr's no=34 → 24
        $letter   = sectionLetterForNo($normNo);
        $section  = $sectionNames[$letter];
        $grouped[$section][] = [
            'kode'      => (string) $normNo,
            'indikator' => $ind['indikator'],
        ];
    }

    $out[$jenjang] = $grouped;
    $tot = array_sum(array_map('count', $grouped));
    fwrite(STDERR, sprintf("%-12s: %d indikator, %d kriteria\n", $jenjang, $tot, count($grouped)));
}

$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($dir . '/lamsama.json', (string) $json);
fwrite(STDERR, "Tersimpan: database/data/lamsama.json\n");