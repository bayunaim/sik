<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Import Mahasiswa dari GoFeeder</h4>
        @if ($langkah > 1 && $langkah < 4)
            <button class="btn btn-outline-secondary" wire:click="ulang"
                wire:confirm="Batalkan import ini?">Batalkan</button>
        @endif
    </div>

    <div class="mb-3 small text-body-secondary">
        @foreach (['1. Unggah', '2. Pemetaan kolom', '3. Pratinjau', '4. Selesai'] as $i => $s)
            <span
                class="{{ $langkah === $i + 1 ? 'fw-bold text-body' : '' }}">{{ $s }}</span>{{ $i < 3 ? ' > ' : '' }}
        @endforeach
    </div>

    @error('proses')
        <div class="alert alert-danger">{{ $message }}</div>
    @enderror

    {{-- 1. Unggah --}}
    @if ($langkah === 1)
        <div class="card mb-3">
            <div class="card-body">
                <label class="form-label">File ekspor GoFeeder (XLSX, XLS, atau CSV, maks. 10 MB)</label>
                <input type="file" wire:model="berkas" accept=".xlsx,.xls,.csv,.txt"
                    class="form-control @error('berkas') is-invalid @enderror">
                @error('berkas')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
                <div wire:loading wire:target="berkas" class="form-text">Mengunggah...</div>
                <div class="form-text">Baris pertama harus berisi nama kolom. Kolom NIM yang berawalan nol harus
                    berformat teks di Excel.</div>
                <button class="btn btn-primary mt-3" wire:click="unggah" wire:loading.attr="disabled"
                    wire:target="unggah,berkas">
                    <span wire:loading.remove wire:target="unggah">Baca File</span>
                    <span wire:loading wire:target="unggah">Membaca...</span>
                </button>
            </div>
        </div>
    @endif

    {{-- 2. Pemetaan --}}
    @if ($langkah === 2)
        <div class="card mb-3">
            <div class="card-header fw-semibold">Pemetaan kolom</div>
            <div class="card-body">
                @error('mapping')
                    <div class="alert alert-danger py-2">{{ $message }}</div>
                @enderror
                <div class="row g-3">
                    @foreach ($field as $kunci => $def)
                        <div class="col-md-6">
                            <label class="form-label">{{ $def['label'] }} @if ($def['wajib'])
                                    <span class="text-danger">*</span>
                                @endif
                            </label>
                            <select wire:model="mapping.{{ $kunci }}" class="form-select">
                                <option value="">- {{ $def['wajib'] ? 'Pilih kolom' : 'Tidak dipakai' }} -
                                </option>
                                @foreach ($headers as $h)
                                    <option value="{{ $h }}">{{ $h }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>

                <div class="form-check mt-3">
                    <input type="checkbox" class="form-check-input" id="tpl" wire:model="simpanTemplate">
                    <label class="form-check-label" for="tpl">Simpan pemetaan ini sebagai template (dipakai
                        otomatis di import berikutnya)</label>
                </div>
                <div class="form-check mt-2">
                    <input type="checkbox" class="form-check-input" id="hilang" wire:model="tandaiHilang">
                    <label class="form-check-label" for="hilang">
                        Tandai mahasiswa Aktif/Cuti yang <strong>tidak ada di file</strong> sebagai "Tidak Ada di
                        Import"
                    </label>
                    <div class="form-text">Aktifkan hanya jika file ini memuat seluruh mahasiswa. Data tidak dihapus,
                        hanya statusnya diubah.</div>
                </div>

                <button class="btn btn-primary mt-3" wire:click="buatPratinjau" wire:loading.attr="disabled"
                    wire:target="buatPratinjau">
                    <span wire:loading.remove wire:target="buatPratinjau">Buat Pratinjau</span>
                    <span wire:loading wire:target="buatPratinjau">Menganalisis...</span>
                </button>
            </div>
        </div>
    @endif

    {{-- 3. Pratinjau --}}
    @if ($langkah === 3 && $pratinjau)
        <div class="row g-3 mb-3">
            @foreach ([['Baru', $pratinjau['baru'], 'success'], ['Berubah', $pratinjau['berubah'], 'primary'], ['Sama (dilewati)', $pratinjau['sama'], 'secondary'], ['Tidak valid', $pratinjau['jumlah_error'], 'danger']] as [$l, $n, $w])
                <div class="col-6 col-lg-3">
                    <div class="card border-top-{{ $w }} border-top-3 h-100">
                        <div class="card-body">
                            <div class="text-body-secondary small">{{ $l }}</div>
                            <div class="fs-4 fw-semibold">{{ number_format($n, 0, ',', '.') }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($pratinjau['prodi_baru'])
            <div class="alert alert-info py-2">
                {{ count($pratinjau['prodi_baru']) }} program studi baru akan dibuat otomatis:
                {{ implode(', ', array_keys($pratinjau['prodi_baru'])) }}
            </div>
        @endif
        @if ($pratinjau['hilang'] > 0)
            <div class="alert alert-warning py-2">{{ number_format($pratinjau['hilang'], 0, ',', '.') }} mahasiswa
                tidak ada di file dan akan ditandai "Tidak Ada di Import".</div>
        @endif

        @if ($pratinjau['jumlah_error'] > 0)
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between">
                    <span>Baris tidak valid
                        (dilewati){{ $pratinjau['jumlah_error'] > 100 ? ', 100 pertama' : '' }}</span>
                    <a href="{{ route('import.error', $logId) }}">Unduh semua (CSV)</a>
                </div>
                <div class="table-responsive" style="max-height: 260px;">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Baris</th>
                                <th>NIM</th>
                                <th>Nama</th>
                                <th>Alasan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pratinjau['error'] as $e)
                                <tr>
                                    <td>{{ $e['baris'] }}</td>
                                    <td>{{ $e['nim'] }}</td>
                                    <td>{{ $e['nama'] }}</td>
                                    <td>{{ $e['alasan'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if ($pratinjau['contoh'])
            <div class="card mb-3">
                <div class="card-header">Contoh data yang akan disimpan (10 pertama)</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>NIM</th>
                                <th>Nama</th>
                                <th>Prodi</th>
                                <th>Angkatan</th>
                                <th>Jenis</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pratinjau['contoh'] as $c)
                                <tr>
                                    <td>{{ $c['nim'] }}</td>
                                    <td>{{ $c['nama'] }}</td>
                                    <td>{{ $c['kode_prodi'] }}</td>
                                    <td>{{ $c['angkatan'] }}</td>
                                    <td><span
                                            class="badge {{ $c['jenis'] === 'baru' ? 'bg-success' : 'bg-primary' }}">{{ ucfirst($c['jenis']) }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="d-flex gap-2">
            <button class="btn btn-outline-secondary" wire:click="kembali">Ubah Pemetaan</button>
            @if ($pratinjau['baru'] + $pratinjau['berubah'] + $pratinjau['hilang'] > 0)
                <button class="btn btn-primary" wire:click="proses" wire:loading.attr="disabled" wire:target="proses"
                    wire:confirm="Simpan {{ $pratinjau['baru'] }} mahasiswa baru dan {{ $pratinjau['berubah'] }} perubahan?">
                    <span wire:loading.remove wire:target="proses">Simpan ke Database</span>
                    <span wire:loading wire:target="proses">Menyimpan...</span>
                </button>
            @else
                <span class="align-self-center text-body-secondary">Tidak ada perubahan untuk disimpan.</span>
            @endif
        </div>
    @endif

    {{-- 4. Selesai --}}
    @if ($langkah === 4 && $hasil)
        <div class="alert alert-success">
            <div class="fw-semibold">Import {{ $hasil['nama_file'] }} selesai.</div>
            <ul class="mb-0">
                <li>{{ $hasil['jumlah_baru'] }} mahasiswa baru, {{ $hasil['jumlah_berubah'] }} diperbarui,
                    {{ $hasil['jumlah_sama'] }} tidak berubah</li>
                <li>{{ $hasil['jumlah_prodi_baru'] }} program studi baru dibuat</li>
                @if ($hasil['jumlah_hilang'])
                    <li>{{ $hasil['jumlah_hilang'] }} mahasiswa ditandai "Tidak Ada di Import"</li>
                @endif
                <li>{{ $hasil['jumlah_gagal'] }} baris dilewati
                    @if ($hasil['error_path'])
                        (<a href="{{ route('import.error', $hasil['id']) }}">unduh CSV</a>)
                    @endif
                </li>
            </ul>
        </div>
        <button class="btn btn-primary" wire:click="ulang">Import File Lain</button>
    @endif

    {{-- Riwayat (FR-144) --}}
    <div class="card mt-4">
        <div class="card-header">Riwayat import</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Waktu</th>
                        <th>Oleh</th>
                        <th>File</th>
                        <th class="text-end">Baru</th>
                        <th class="text-end">Berubah</th>
                        <th class="text-end">Gagal</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($riwayat as $r)
                        <tr wire:key="log-{{ $r->id }}">
                            <td class="text-nowrap">{{ $r->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $r->user?->name }}</td>
                            <td>{{ $r->nama_file }}</td>
                            <td class="text-end">{{ $r->jumlah_baru }}</td>
                            <td class="text-end">{{ $r->jumlah_berubah }}</td>
                            <td class="text-end">{{ $r->jumlah_gagal }}</td>
                            <td><span
                                    class="badge {{ $r->status === 'selesai' ? 'bg-success' : ($r->status === 'gagal' ? 'bg-danger' : 'bg-warning text-dark') }}">{{ ucfirst($r->status) }}</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-body-secondary py-3">Belum ada import.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <livewire:import.akun-mahasiswa />
</div>
