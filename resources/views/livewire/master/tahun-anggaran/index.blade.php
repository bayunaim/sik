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
                        @can('master.kelola')
                            <th class="text-end">Aksi</th>
                        @endcan
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
                            @can('master.kelola')
                                <td class="text-end text-nowrap">
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
                                </td>
                            @endcan
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
</div>
