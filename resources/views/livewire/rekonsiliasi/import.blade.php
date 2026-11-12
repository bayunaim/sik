<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Import Mutasi Bank</h4>
        <div class="d-flex gap-2">
            <a href="{{ route('rekonsiliasi.index') }}" class="btn btn-outline-secondary">Pencocokan</a>
            @if ($langkah > 1 && $langkah < 4)
                <button class="btn btn-outline-secondary" wire:click="ulang"
                    wire:confirm="Batalkan import ini?">Batalkan</button>
            @endif
        </div>
    </div>

    @if (session('sukses'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('sukses') }}
            <button type="button" class="btn-close" data-coreui-dismiss="alert"></button>
        </div>
    @endif

    <div class="mb-3 small text-body-secondary">
        @foreach (['1. Unggah', '2. Pemetaan kolom', '3. Pratinjau', '4. Selesai'] as $i => $s)
            <span
                class="{{ $langkah === $i + 1 ? 'fw-bold text-body' : '' }}">{{ $s }}</span>{{ $i < 3 ? ' > ' : '' }}
        @endforeach
        @if ($rekeningDipakai && $langkah > 1)
            <span class="ms-3">Rekening: <strong>{{ $rekeningDipakai->nama_bank }} {{ $rekeningDipakai->nomor }}</strong></span>
        @endif
    </div>

    @error('proses')
        <div class="alert alert-danger">{{ $message }}</div>
    @enderror

    {{-- 1. Unggah --}}
    @if ($langkah === 1)
        <div class="card mb-3">
            <div class="card-body">
                @if ($daftarRekening->isEmpty())
                    <div class="alert alert-warning mb-0">
                        Belum ada rekening aktif. <a href="{{ route('rekonsiliasi.rekening') }}">Tambahkan rekening</a> dulu.
                    </div>
                @else
                    <label class="form-label">Rekening bank <span class="text-danger">*</span></label>
                    <select wire:model="pilihRekening" class="form-select mb-3 @error('pilihRekening') is-invalid @enderror">
                        <option value="">- Pilih rekening -</option>
                        @foreach ($daftarRekening as $r)
                            <option value="{{ $r->id }}">{{ $r->label }}</option>
                        @endforeach
                    </select>
                    @error('pilihRekening')
                        <div class="invalid-feedback d-block mb-2">{{ $message }}</div>
                    @enderror

                    <label class="form-label">File mutasi (XLSX, XLS, atau CSV, maks. 10 MB)</label>
                    <input type="file" wire:model="berkas" accept=".xlsx,.xls,.csv,.txt"
                        class="form-control @error('berkas') is-invalid @enderror">
                    @error('berkas')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                    <div wire:loading wire:target="berkas" class="form-text">Mengunggah...</div>
                    <div class="form-text">Baris judul kolom dicari otomatis, jadi baris info rekening di atas tabel
                        tidak masalah. File yang tumpang tindih dengan import sebelumnya aman: baris yang sudah ada
                        dilewati.</div>
                    <button class="btn btn-primary mt-3" wire:click="unggah" wire:loading.attr="disabled"
                        wire:target="unggah,berkas">
                        <span wire:loading.remove wire:target="unggah">Baca File</span>
                        <span wire:loading wire:target="unggah">Membaca...</span>
                    </button>
                @endif
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
                <div class="form-text mb-3">Petakan Debit dan Kredit jika bank memakai dua kolom. Jika hanya ada satu
                    kolom Jumlah, petakan Jumlah dan (kalau ada) kolom Jenis DB/CR; tanpa kolom Jenis, angka negatif
                    dianggap uang keluar.</div>
                <div class="row g-3">
                    @foreach ($field as $kunci => $def)
                        <div class="col-md-6">
                            <label class="form-label">{{ $def['label'] }} @if ($def['wajib'])
                                    <span class="text-danger">*</span>
                                @endif
                            </label>
                            <select wire:model="mapping.{{ $kunci }}" class="form-select">
                                <option value="">- {{ $def['wajib'] ? 'Pilih kolom' : 'Tidak dipakai' }} -</option>
                                @foreach ($headers as $h)
                                    <option value="{{ $h }}">{{ $h }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>

                <div class="form-check mt-3">
                    <input type="checkbox" class="form-check-input" id="tpl" wire:model="simpanTemplate">
                    <label class="form-check-label" for="tpl">Simpan pemetaan ini sebagai template untuk rekening ini</label>
                </div>
                <div class="form-check mt-2">
                    <input type="checkbox" class="form-check-input" id="auto" wire:model="cocokSetelah">
                    <label class="form-check-label" for="auto">Cocokkan otomatis setelah import selesai</label>
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
            @foreach ([['Baru', $pratinjau['baru'], 'success'], ['Sudah ada (dilewati)', $pratinjau['duplikat'], 'secondary'], ['Tidak valid', $pratinjau['jumlah_error'], 'danger']] as [$l, $n, $w])
                <div class="col-6 col-lg-3">
                    <div class="card border-top-{{ $w }} border-top-3 h-100">
                        <div class="card-body">
                            <div class="text-body-secondary small">{{ $l }}</div>
                            <div class="fs-4 fw-semibold">{{ number_format($n, 0, ',', '.') }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
            <div class="col-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body small">
                        <div class="text-body-secondary">Mutasi baru</div>
                        <div>Masuk Rp {{ number_format((float) $pratinjau['total_kredit'], 0, ',', '.') }}</div>
                        <div>Keluar Rp {{ number_format((float) $pratinjau['total_debit'], 0, ',', '.') }}</div>
                        @if ($pratinjau['tanggal_awal'])
                            <div class="text-body-secondary mt-1">
                                {{ \Carbon\Carbon::parse($pratinjau['tanggal_awal'])->format('d/m/Y') }} -
                                {{ \Carbon\Carbon::parse($pratinjau['tanggal_akhir'])->format('d/m/Y') }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        @if ($pratinjau['jumlah_error'] > 0)
            <div class="card mb-3">
                <div class="card-header">Baris tidak valid (dilewati){{ $pratinjau['jumlah_error'] > 100 ? ', 100 pertama' : '' }}.
                    Daftar lengkap bisa diunduh setelah import disimpan.</div>
                <div class="table-responsive" style="max-height: 260px;">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>Baris</th><th>Tanggal</th><th>Keterangan</th><th>Alasan</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($pratinjau['error'] as $e)
                                <tr>
                                    <td>{{ $e['baris'] }}</td>
                                    <td>{{ $e['tanggal'] }}</td>
                                    <td>{{ \Illuminate\Support\Str::limit($e['keterangan'], 60) }}</td>
                                    <td class="text-danger">{{ $e['alasan'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if ($pratinjau['contoh'])
            <div class="card mb-3">
                <div class="card-header">Contoh mutasi baru (10 pertama)</div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>Tanggal</th><th>Keterangan</th><th class="text-end">Debit</th><th class="text-end">Kredit</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($pratinjau['contoh'] as $c)
                                <tr>
                                    <td>{{ \Carbon\Carbon::parse($c['tanggal'])->format('d/m/Y') }}</td>
                                    <td>{{ \Illuminate\Support\Str::limit($c['keterangan'], 70) }}</td>
                                    <td class="text-end">{{ (float) $c['debit'] > 0 ? number_format((float) $c['debit'], 0, ',', '.') : '' }}</td>
                                    <td class="text-end">{{ (float) $c['kredit'] > 0 ? number_format((float) $c['kredit'], 0, ',', '.') : '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="d-flex gap-2">
            <button class="btn btn-outline-secondary" wire:click="kembali">Kembali</button>
            <button class="btn btn-primary" wire:click="proses" wire:loading.attr="disabled" wire:target="proses"
                @disabled($pratinjau['baru'] === 0)
                wire:confirm="Simpan {{ number_format($pratinjau['baru'], 0, ',', '.') }} mutasi baru ke rekening ini?">
                <span wire:loading.remove wire:target="proses">Simpan Import</span>
                <span wire:loading wire:target="proses">Menyimpan...</span>
            </button>
        </div>
    @endif

    {{-- 4. Selesai --}}
    @if ($langkah === 4 && $hasil)
        <div class="alert alert-success">
            Import <strong>{{ $hasil['nama_file'] }}</strong> selesai: {{ number_format($hasil['jumlah_baru'], 0, ',', '.') }}
            mutasi baru, {{ number_format($hasil['jumlah_duplikat'], 0, ',', '.') }} sudah ada (dilewati),
            {{ number_format($hasil['jumlah_gagal'], 0, ',', '.') }} baris tidak valid.
            @if ($hasil['error_path'])
                <a href="{{ route('rekonsiliasi.import.error', $hasil['id']) }}">Unduh baris tidak valid (CSV)</a>
            @endif
        </div>
        @if ($hasilOtomatis)
            <div class="alert alert-info">
                Pencocokan otomatis: <strong>{{ number_format($hasilOtomatis['cocok'], 0, ',', '.') }} cocok</strong>,
                {{ number_format($hasilOtomatis['ambigu'], 0, ',', '.') }} ambigu,
                {{ number_format($hasilOtomatis['tanpa_kandidat'], 0, ',', '.') }} tanpa kandidat.
            </div>
        @endif
        <div class="d-flex gap-2">
            <a href="{{ route('rekonsiliasi.index', ['rekeningId' => $rekeningId]) }}" class="btn btn-primary">Lihat Pencocokan</a>
            <button class="btn btn-outline-secondary" wire:click="ulang">Import Lagi</button>
        </div>
    @endif

    {{-- Riwayat --}}
    <div class="card mt-4">
        <div class="card-header fw-semibold">Riwayat import</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Waktu</th><th>Rekening</th><th>File</th><th>Oleh</th>
                        <th class="text-end">Baru</th><th class="text-end">Dilewati</th><th class="text-end">Gagal</th>
                        <th>Status</th><th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($riwayat as $r)
                        <tr wire:key="riw-{{ $r->id }}">
                            <td class="text-nowrap">{{ $r->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $r->rekening?->nama_bank }} {{ $r->rekening?->nomor }}</td>
                            <td>{{ $r->nama_file }}</td>
                            <td>{{ $r->user?->name }}</td>
                            <td class="text-end">{{ number_format($r->jumlah_baru, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format($r->jumlah_duplikat, 0, ',', '.') }}</td>
                            <td class="text-end">{{ number_format($r->jumlah_gagal, 0, ',', '.') }}</td>
                            <td>
                                @php $w = ['selesai' => 'success', 'gagal' => 'danger', 'dibatalkan' => 'secondary'][$r->status] ?? 'warning'; @endphp
                                <span class="badge bg-{{ $w }} {{ $w === 'warning' ? 'text-dark' : '' }}" @if ($r->catatan) title="{{ $r->catatan }}" @endif>{{ ucfirst($r->status) }}</span>
                            </td>
                            <td class="text-end text-nowrap">
                                @if ($r->error_path)
                                    <a href="{{ route('rekonsiliasi.import.error', $r->id) }}" class="btn btn-sm btn-outline-secondary">Error</a>
                                @endif
                                @if ($r->status === 'selesai')
                                    <button class="btn btn-sm btn-outline-danger" wire:click="konfirmasiBatal({{ $r->id }})">Batalkan</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-body-secondary py-3">Belum ada import.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($batalId)
        <div class="modal-backdrop fade show"></div>
        <div class="modal fade show d-block" tabindex="-1" wire:keydown.escape="tutupBatal">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" wire:submit="simpanBatal">
                    <div class="modal-header">
                        <h5 class="modal-title">Batalkan Import</h5>
                        <button type="button" class="btn-close" wire:click="tutupBatal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning py-2 small">
                            Semua mutasi dari import ini dihapus (soft delete). Hanya bisa jika belum ada yang
                            dicocokkan atau diabaikan. Berguna kalau salah memilih rekening.
                        </div>
                        <label class="form-label">Alasan pembatalan <span class="text-danger">*</span></label>
                        <textarea wire:model="alasanBatal" rows="3" class="form-control @error('alasanBatal') is-invalid @enderror"></textarea>
                        @error('alasanBatal')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="tutupBatal">Kembali</button>
                        <button type="submit" class="btn btn-danger" wire:loading.attr="disabled"
                            wire:target="simpanBatal">Batalkan Import</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
