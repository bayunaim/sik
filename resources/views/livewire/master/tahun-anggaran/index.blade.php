<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Tahun Anggaran</h4>
        @can('master.kelola')
            <button class="btn btn-primary" wire:click="tambah">
                <i class="cil-plus me-1"></i> Tambah
            </button>
        @endcan
    </div>

    @if (session('sukses'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('sukses') }}
            <button type="button" class="btn-close" data-coreui-dismiss="alert"></button>
        </div>
    @endif
    @if (session('gagal'))
        <div class="alert alert-danger alert-dismissible fade show">
            {{ session('gagal') }}
            <button type="button" class="btn-close" data-coreui-dismiss="alert"></button>
        </div>
    @endif

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Tahun</th>
                        <th>Periode</th>
                        <th>Status</th>
                        @canany(['master.kelola', 'tahun.tutup'])
                            <th class="text-end">Aksi</th>
                        @endcanany
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data as $row)
                        <tr wire:key="tahun-{{ $row->id }}">
                            <td class="fw-semibold">{{ $row->nama }}</td>
                            <td>{{ $row->tanggal_mulai->format('d/m/Y') }} -
                                {{ $row->tanggal_selesai->format('d/m/Y') }}</td>
                            <td>
                                @if ($row->status === 'aktif')
                                    <span class="badge bg-success">Aktif</span>
                                @elseif ($row->status === 'ditutup')
                                    <span class="badge bg-dark"><i class="cil-lock-locked me-1"></i>Ditutup</span>
                                @else
                                    <span class="badge bg-secondary">Draft</span>
                                @endif
                            </td>
                            @canany(['master.kelola', 'tahun.tutup'])
                                <td class="text-end text-nowrap">
                                    @can('master.kelola')
                                        @if ($row->status === 'draft')
                                            <button class="btn btn-sm btn-outline-success"
                                                wire:click="aktifkan({{ $row->id }})"
                                                wire:confirm="Aktifkan tahun anggaran {{ $row->nama }}? Tahun yang sedang aktif akan dikembalikan ke Draft.">
                                                Aktifkan
                                            </button>
                                        @endif
                                        @if ($row->status !== 'ditutup')
                                            <button class="btn btn-sm btn-outline-primary"
                                                wire:click="ubah({{ $row->id }})">Ubah</button>
                                        @endif
                                        @if ($row->status === 'draft')
                                            <button class="btn btn-sm btn-outline-danger"
                                                wire:click="hapus({{ $row->id }})"
                                                wire:confirm="Hapus tahun anggaran {{ $row->nama }}?">Hapus</button>
                                        @endif
                                    @endcan
                                    @can('tahun.tutup')
                                        @if ($row->status === 'aktif')
                                            <button class="btn btn-sm btn-outline-dark"
                                                wire:click="mulaiTutup({{ $row->id }})">
                                                <i class="cil-lock-locked me-1"></i>Tutup Tahun
                                            </button>
                                        @endif
                                    @endcan
                                </td>
                            @endcanany
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-body-secondary py-4">Belum ada data.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($tampilForm)
        <div class="modal-backdrop fade show"></div>
        <div class="modal fade show d-block" tabindex="-1" wire:keydown.escape="tutupForm">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" wire:submit="simpan">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $editId ? 'Ubah' : 'Tambah' }} Tahun Anggaran</h5>
                        <button type="button" class="btn-close" wire:click="tutupForm"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nama <span class="text-danger">*</span></label>
                            <input type="text" wire:model="nama" placeholder="contoh: 2026"
                                class="form-control @error('nama') is-invalid @enderror">
                            @error('nama')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Tanggal Mulai <span class="text-danger">*</span></label>
                                <input type="date" wire:model="tanggal_mulai"
                                    class="form-control @error('tanggal_mulai') is-invalid @enderror">
                                @error('tanggal_mulai')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Tanggal Selesai <span class="text-danger">*</span></label>
                                <input type="date" wire:model="tanggal_selesai"
                                    class="form-control @error('tanggal_selesai') is-invalid @enderror">
                                @error('tanggal_selesai')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="tutupForm">Batal</button>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled"
                            wire:target="simpan">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($langkahTutup > 0)
        <div class="modal-backdrop fade show"></div>
        <div class="modal fade show d-block" tabindex="-1" wire:keydown.escape="batalTutup">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Tutup Tahun Anggaran {{ $pratinjau['tahun'] ?? '' }}</h5>
                        <button type="button" class="btn-close" wire:click="batalTutup"></button>
                    </div>

                    @if ($langkahTutup === 1)
                        <div class="modal-body">
                            <p class="mb-2">Yang akan terjadi, dalam satu proses (gagal di tengah berarti tidak ada
                                yang berubah):</p>
                            <ul class="mb-3">
                                <li>Tahun {{ $pratinjau['tahun'] }} menjadi <strong>Ditutup</strong> dan tidak bisa
                                    diubah lagi.</li>
                                <li>Tahun {{ $pratinjau['tahun_berikut'] }}
                                    {{ $pratinjau['tahun_berikut_baru'] ? 'dibuat otomatis' : 'sudah ada (Draft)' }}
                                    dan menjadi <strong>Aktif</strong>.</li>
                                <li>{{ $pratinjau['anggaran_jumlah'] }} kode anggaran
                                    ({{ $pratinjau['anggaran_d'] }} penerimaan, {{ $pratinjau['anggaran_k'] }}
                                    pengeluaran)
                                    disalin ke tahun {{ $pratinjau['tahun_berikut'] }} berstatus <strong>Draft</strong>
                                    dan perlu disetujui ulang.</li>
                                <li>{{ $pratinjau['tagihan_jumlah'] }} tagihan belum lunas dengan total sisa
                                    <strong>Rp
                                        {{ number_format((float) $pratinjau['tagihan_sisa'], 0, ',', '.') }}</strong>
                                    dibawa ke tahun {{ $pratinjau['tahun_berikut'] }} (carry over).
                                </li>
                            </ul>

                            @if ($pratinjau['hambatan'] !== [])
                                <div class="alert alert-danger mb-0">
                                    <div class="fw-semibold mb-1">Belum bisa ditutup:</div>
                                    <ul class="mb-0">
                                        @foreach ($pratinjau['hambatan'] as $h)
                                            <li>{{ $h }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" wire:click="batalTutup">Batal</button>
                            <button type="button" class="btn btn-primary" wire:click="lanjutTutup"
                                @disabled($pratinjau['hambatan'] !== [])>Lanjut</button>
                        </div>
                    @else
                        <form wire:submit="jalankanTutup">
                            <div class="modal-body">
                                <div class="alert alert-warning">
                                    Penutupan tidak bisa dibatalkan dari aplikasi. Pastikan semua data tahun
                                    {{ $pratinjau['tahun'] }} sudah benar.
                                </div>
                                <label class="form-label">Ketik <strong>{{ $pratinjau['tahun'] }}</strong> untuk
                                    mengonfirmasi</label>
                                <input type="text" wire:model="konfirmasiNama" autocomplete="off"
                                    class="form-control @error('konfirmasiNama') is-invalid @enderror">
                                @error('konfirmasiNama')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary"
                                    wire:click="batalTutup">Batal</button>
                                <button type="submit" class="btn btn-danger" wire:loading.attr="disabled"
                                    wire:target="jalankanTutup">Tutup Tahun</button>
                            </div>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
