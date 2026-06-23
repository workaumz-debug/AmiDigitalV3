<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\StandarAkreditasi;
use App\Models\Jenjang;
use App\Models\Standard;
use App\Models\Element;
use App\Models\Indikator;

/**
 * Kriteria akreditasi LAMSAMA (IAPS 3.1).
 * Data hasil ekstraksi PDF disimpan di database/data/lamsama.json
 * yang dibangun oleh database/data/_lamsama_build_json.php.
 *
 * Struktur: jenjang -> kriteria -> [ {kode, indikator} ]
 * Mapping: kriteria -> standards, tiap indikator -> 1 element + 1 indikator.
 */
class KriteriaLamsamaSeeder extends Seeder
{
    public function run(): void
    {
        $akreditasi = StandarAkreditasi::where('nama', 'LAMSAMA')->first();
        if (!$akreditasi) {
            $this->command?->warn('StandarAkreditasi "LAMSAMA" belum ada. Jalankan StandarAkreditasiSeeder dulu. Dilewati.');
            return;
        }

        $path = database_path('data/lamsama.json');
        if (!is_file($path)) {
            $this->command?->warn('database/data/lamsama.json tidak ada. Jalankan _lamsama_build_json.php dulu. Dilewati.');
            return;
        }

        $data = json_decode(file_get_contents($path), true) ?: [];

        foreach ($data as $jenjangNama => $kriteriaList) {
            $jenjang = Jenjang::firstOrCreate(['nama' => $jenjangNama]);
            $cStd = $cEl = $cInd = 0;

            foreach ($kriteriaList as $kriteria => $indikators) {
                $standard = Standard::firstOrCreate([
                    'standar_akreditasi_id' => $akreditasi->id,
                    'jenjang_id'            => $jenjang->id,
                    'nama'                  => $kriteria,
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
                            'kategori'       => $kriteria,
                        ]
                    );
                    if ($ind->wasRecentlyCreated) $cInd++;
                }
            }

            $this->command?->info("  LAMSAMA {$jenjangNama}: +{$cStd} standar, +{$cEl} elemen, +{$cInd} indikator");
        }
    }
}