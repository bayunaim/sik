<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Tagihan Massal</h4>
        <a href="{{ route('tagihan.index') }}" class="btn btn-outline-secondary">Kembali ke Tagihan</a>
    </div>

    @error('form')
        <div class="alert alert-danger">{{ $message }}</div>
    @enderror

    {{-- Hasil penerbitan --}}
    @if ($hasil)
        <div class="alert alert-success">
            <div class="fw-semibold">
                {{ number_format($hasil['dibuat'], 0, ',', '.') }} tagihan berhasil diterbitkan
                (total Rp {{ number_format((float) $hasil['total'], 0, ',', '.') }}) dalam {{ $hasil['durasi'] }} detik.
            </div>
            @if ($hasil['jumlah_lewati'] > 0)
                <div class="mt-1">{{ number_format($hasil['jumlah_lewati'], 0, ',', '.') }} mahasiswa dilewati:</div>
                <ul class="mb-0">
                    @foreach ($hasil['ringkasan_alasan'] as $alasan => $jumlah)
                        <li>{{ $alasan }}: {{ $jumlah }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
        @if ($hasil['jumlah_lewati'] > 0)
            <div class="card mb-3">
                <div class="card-header">Mahasiswa yang dilewati
                    @if ($hasil['jumlah_lewati'] > 200)
                        <span class="text-body-secondary small">(200 pertama)</span>
                    @endif
                </div>
                <div class="table-responsive" style="max-height: 280px;">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>NIM</th>
                                <th>Nama</th>
                                <th>Alasan</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($hasil['lewati_tampil'] as $l)
                                <tr>
                                    <td>{{ $l['nim'] }}</td>
                                    <td>{{ $l['nama'] }}</td>
                                    <td>{{ $l['alasan'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif

    {{-- 1. Pengaturan tagihan --}}
    <div class="card mb-3">
        <div class="card-header fw-semibold">1. Pengaturan Tagihan</div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Komponen Biaya <span class="text-danger">*</span></label>
                    <select wire:model="komponen_biaya_id"
                        class="form-select @error('komponen_biaya_id') is-invalid @enderror">
                        <option value="">- Pilih komponen -</option>
                        @foreach ($daftarKomponen as $k)
                            <option value="{{ $k->id }}">{{ $k->kode }} - {{ $k->nama }}</option>
                        @endforeach
                    </select>
                    @error('komponen_biaya_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Kode Anggaran Penerimaan <span class="text-danger">*</span></label>
                    <select wire:model="anggaran_id" class="form-select @error('anggaran_id') is-invalid @enderror">
                        <option value="">- Pilih kode anggaran -</option>
                        @foreach ($daftarAnggaran as $a)
                            <option value="{{ $a->id }}">{{ $a->kode }} - {{ $a->nama }}
                                ({{ $a->tahun->nama }})</option>
                        @endforeach
                    </select>
                    @error('anggaran_id')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">Semester <span class="text-danger">*</span></label>
                    <select wire:model="semester" class="form-select">
                        @foreach (\App\Models\Tagihan::SEMESTER as $s)
                            <option value="{{ $s }}">{{ $s }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tahun Ajaran <span class="text-danger">*</span></label>
                    <input type="text" wire:model="tahun_ajaran" placeholder="2025/2026"
                        class="form-control @error('tahun_ajaran') is-invalid @enderror">
                    @error('tahun_ajaran')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">Tanggal Tagihan <span class="text-danger">*</span></label>
                    <input type="date" wire:model="tanggal_tagihan"
                        class="form-control @error('tanggal_tagihan') is-invalid @enderror">
                    @error('tanggal_tagihan')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">Jatuh Tempo <span class="text-danger">*</span></label>
                    <input type="date" wire:model="jatuh_tempo"
                        class="form-control @error('jatuh_tempo') is-invalid @enderror">
                    @error('jatuh_tempo')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Nominal (Rp)</label>
                    @can('tagihan.ubah-nominal')
                        <input type="number" step="0.01" min="0" wire:model="nominal"
                            placeholder="Kosong = pakai tarif tiap mahasiswa"
                            class="form-control @error('nominal') is-invalid @enderror">
                        @error('nominal')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">Jika diisi, nominal ini berlaku untuk semua mahasiswa terpilih.</div>
                    @else
                        <input type="text" class="form-control" readonly value="Otomatis dari tarif">
                    @endcan
                </div>
                <div class="col-md-4">
                    <label class="form-label">Metode Bayar</label>
                    <select wire:model="metode_bayar" class="form-select">
                        <option value="">- Tidak ditentukan -</option>
                        <option value="Transfer Bank">Transfer Bank</option>
                        <option value="Tunai">Tunai</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Keterangan</label>
                    <input type="text" wire:model="keterangan"
                        class="form-control @error('keterangan') is-invalid @enderror">
                    @error('keterangan')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
            </div>
        </div>
    </div>

    {{-- 2. Pilih mahasiswa --}}
    <div class="card mb-3">
        <div class="card-header fw-semibold">2. Pilih Mahasiswa</div>
        <div class="card-body border-bottom d-flex gap-2 flex-wrap">
            <input type="text" class="form-control" style="max-width: 230px;" placeholder="Cari NIM atau nama..."
                wire:model.live.debounce.400ms="cari">
            <select class="form-select" style="max-width: 210px;" wire:model.live="filterProdi">
                <option value="">Semua prodi</option>
                @foreach ($daftarProdi as $p)
                    <option value="{{ $p->id }}">{{ $p->kode }} - {{ $p->nama }}</option>
                @endforeach
            </select>
            <select class="form-select" style="max-width: 150px;" wire:model.live="filterAngkatan">
                <option value="">Semua angkatan</option>
                @foreach ($daftarAngkatan as $a)
                    <option value="{{ $a }}">{{ $a }}</option>
                @endforeach
            </select>
            <select class="form-select" style="max-width: 190px;" wire:model.live="filterStatus">
                <option value="">Semua status</option>
                @foreach ($daftarStatus as $s)
                    <option value="{{ $s }}">{{ $s }}</option>
                @endforeach
            </select>
        </div>

        <div class="card-body py-2 d-flex gap-2 align-items-center flex-wrap">
            <button type="button" class="btn btn-sm btn-outline-primary" wire:click="pilihSemua"
                wire:loading.attr="disabled">
                Pilih semua hasil filter ({{ number_format($totalFilter, 0, ',', '.') }})
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" wire:click="batalPilih">Batal pilih
                semua</button>
            <span class="ms-auto fw-semibold">Terpilih: {{ number_format(count($terpilih), 0, ',', '.') }}</span>
        </div>
        @error('terpilih')
            <div class="px-3 pb-2 text-danger small">{{ $message }}</div>
        @enderror

        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 50px;"></th>
                        <th>NIM</th>
                        <th>Nama</th>
                        <th>Prodi</th>
                        <th>Angkatan</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data as $row)
                        <tr wire:key="pm-{{ $row->id }}">
                            <td>
                                <input type="checkbox" class="form-check-input" value="{{ $row->id }}"
                                    wire:model.live="terpilih">
                            </td>
                            <td class="fw-semibold">{{ $row->nim }}</td>
                            <td>{{ $row->nama }}</td>
                            <td>{{ $row->programStudi?->kode }}</td>
                            <td>{{ $row->angkatan }}</td>
                            <td>{{ $row->status_akademik }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-body-secondary py-4">Tidak ada mahasiswa untuk
                                filter ini.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($data->hasPages())
            <div class="card-footer">{{ $data->links() }}</div>
        @endif
    </div>

    {{-- 3. Pratinjau dan terbitkan --}}
    <div class="card mb-4">
        <div class="card-header fw-semibold">3. Pratinjau dan Terbitkan</div>
        <div class="card-body">
            @if ($pratinjau)
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="border rounded p-3">
                            <div class="text-body-secondary small">Siap diterbitkan</div>
                            <div class="fs-4 fw-semibold text-success">
                                {{ number_format($pratinjau['siap'], 0, ',', '.') }}</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3">
                            <div class="text-body-secondary small">Total nominal</div>
                            <div class="fs-4 fw-semibold">Rp
                                {{ number_format((float) $pratinjau['total'], 0, ',', '.') }}</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3">
                            <div class="text-body-secondary small">Akan dilewati</div>
                            <div class="fs-4 fw-semibold {{ $pratinjau['jumlah_lewati'] > 0 ? 'text-warning' : '' }}">
                                {{ number_format($pratinjau['jumlah_lewati'], 0, ',', '.') }}
                            </div>
                        </div>
                    </div>
                </div>

                @if ($pratinjau['jumlah_lewati'] > 0)
                    <div class="mb-2">
                        @foreach ($pratinjau['ringkasan_alasan'] as $alasan => $jumlah)
                            <span class="badge bg-warning text-dark me-1">{{ $alasan }}:
                                {{ $jumlah }}</span>
                        @endforeach
                    </div>
                    <div class="table-responsive mb-3" style="max-height: 240px;">
                        <table class="table table-sm mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>NIM</th>
                                    <th>Nama</th>
                                    <th>Alasan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($pratinjau['lewati_tampil'] as $l)
                                    <tr>
                                        <td>{{ $l['nim'] }}</td>
                                        <td>{{ $l['nama'] }}</td>
                                        <td>{{ $l['alasan'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($pratinjau['jumlah_lewati'] > 200)
                        <div class="small text-body-secondary mb-3">Menampilkan 200 pertama dari
                            {{ $pratinjau['jumlah_lewati'] }}.</div>
                    @endif
                @endif
            @else
                <p class="text-body-secondary mb-3">
                    Lengkapi pengaturan, pilih mahasiswa, lalu klik <strong>Buat Pratinjau</strong> untuk melihat
                    dampaknya sebelum tagihan terbit.
                </p>
            @endif

            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary" wire:click="buatPratinjau"
                    wire:loading.attr="disabled" wire:target="buatPratinjau">
                    <span wire:loading.remove wire:target="buatPratinjau">Buat Pratinjau</span>
                    <span wire:loading wire:target="buatPratinjau">Menghitung...</span>
                </button>

                @if ($pratinjau && $pratinjau['siap'] > 0)
                    <button type="button" class="btn btn-primary" wire:click="terbitkan"
                        wire:loading.attr="disabled" wire:target="terbitkan"
                        wire:confirm="Terbitkan {{ $pratinjau['siap'] }} tagihan sekarang? Tindakan ini tidak bisa dibatalkan sekaligus.">
                        <span wire:loading.remove wire:target="terbitkan">Terbitkan
                            {{ number_format($pratinjau['siap'], 0, ',', '.') }} Tagihan</span>
                        <span wire:loading wire:target="terbitkan">Menerbitkan...</span>
                    </button>
                @endif
            </div>
        </div>
    </div>
</div>
