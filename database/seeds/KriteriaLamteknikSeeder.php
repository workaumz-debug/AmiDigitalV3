<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\StandarAkreditasi;
use App\Models\Jenjang;
use App\Models\Standard;
use App\Models\Element;
use App\Models\Indikator;

/**
 * Kriteria akreditasi LAMTEKNIK (Matriks Penilaian LED dan LKPS 2025).
 * Data hasil ekstraksi PDF disimpan di database/data/lamteknik.json
 * yang dibangun oleh database/data/_lamteknik_build_json.php.
 *
 * Struktur: jenjang -> seksi (I-VII) -> [ {kode, indikator} ]
 * Mapping: seksi -> Standard, indikator -> Element + Indikator.
 */
class KriteriaLamteknikSeeder extends Seeder
{
    public function run(): void
    {
        $akreditasi = StandarAkreditasi::where('nama', 'LAMTEKNIK')->first();
        if (!$akreditasi) {
            $this->command?->warn('StandarAkreditasi "LAMTEKNIK" belum ada. Jalankan StandarAkreditasiSeeder dulu. Dilewati.');
            return;
        }

        $path = database_path('data/lamteknik.json');
        if (!is_file($path)) {
            $this->command?->warn('database/data/lamteknik.json tidak ada. Jalankan _lamteknik_build_json.php dulu. Dilewati.');
            return;
        }

        $data = json_decode(file_get_contents($path), true) ?: [];

        foreach ($data as $jenjangNama => $seksiList) {
            $jenjang = Jenjang::firstOrCreate(['nama' => $jenjangNama]);
            $cStd = $cEl = $cInd = 0;

            foreach ($seksiList as $seksi => $indikators) {
                $standard = Standard::firstOrCreate([
                    'standar_akreditasi_id' => $akreditasi->id,
                    'jenjang_id'            => $jenjang->id,
                    'nama'                  => $seksi,
                ]);
                if ($standard->wasRecentlyCreated) $cStd++;

                foreach ($indikators as $it) {
                    $teks = trim($it['indikator'] ?? '');
                    if ($teks === '') continue;

                    $element = Element::firstOrCreate([
                        'standard_id' => $standard->id,
                        'nama'        => $teks,
                    ]);
                    if ($element->wasRecentlyCreated) $cEl++;

                    $ind = Indikator::updateOrCreate(
                        ['elemen_id' => $element->id, 'nama_indikator' => $teks],
                        [
                            'indikator_kode' => $it['kode'] ?? null,
                            'kategori'       => $seksi,
                        ]
                    );
                    if ($ind->wasRecentlyCreated) $cInd++;
                }
            }

            $this->command?->info("  LAMTEKNIK {$jenjangNama}: +{$cStd} standar, +{$cEl} elemen, +{$cInd} indikator");
        }
    }
}
