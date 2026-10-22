<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Tarif Biaya</h4>
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
        <div class="card-header d-flex gap-2 flex-wrap">
            <select class="form-select" style="max-width: 260px;" wire:model.live="filterKomponen">
                <option value="">Semua komponen</option>
                @foreach ($daftarKomponen as $k)
                    <option value="{{ $k->id }}">{{ $k->kode }} - {{ $k->nama }}</option>
                @endforeach
            </select>
            <select class="form-select" style="max-width: 260px;" wire:model.live="filterProdi">
                <option value="">Semua prodi</option>
                @foreach ($daftarProdi as $p)
                    <option value="{{ $p->id }}">{{ $p->kode }} - {{ $p->nama }}</option>
                @endforeach
            </select>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Komponen</th>
                        <th>Program Studi</th>
                        <th>Angkatan</th>
                        <th class="text-end">Nominal</th>
                        @can('master.kelola')
                            <th class="text-end">Aksi</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data as $row)
                        <tr wire:key="tarif-{{ $row->id }}">
                            <td>
                                <span class="fw-semibold">{{ $row->komponen?->kode }}</span>
                                <span class="text-body-secondary">{{ $row->komponen?->nama }}</span>
                            </td>
                            <td>{{ $row->programStudi ? $row->programStudi->kode . ' - ' . $row->programStudi->nama : 'Semua prodi' }}
                            </td>
                            <td>{{ $row->angkatan ?? 'Semua angkatan' }}</td>
                            <td class="text-end fw-semibold">Rp {{ number_format($row->nominal, 0, ',', '.') }}</td>
                            @can('master.kelola')
                                <td class="text-end text-nowrap">
                                    <button class="btn btn-sm btn-outline-primary"
                                        wire:click="ubah({{ $row->id }})">Ubah</button>
                                    <button class="btn btn-sm btn-outline-danger" wire:click="hapus({{ $row->id }})"
                                        wire:confirm="Hapus tarif ini?">Hapus</button>
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-body-secondary py-4">Belum ada tarif.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($data->hasPages())
            <div class="card-footer">{{ $data->links() }}</div>
        @endif
    </div>

    @if ($tampilForm)
        <div class="modal-backdrop fade show"></div>
        <div class="modal fade show d-block" tabindex="-1" wire:keydown.escape="tutupForm">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" wire:submit="simpan">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $editId ? 'Ubah' : 'Tambah' }} Tarif</h5>
                        <button type="button" class="btn-close" wire:click="tutupForm"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Komponen Biaya <span class="text-danger">*</span></label>
                            <select wire:model="komponen_biaya_id"
                                class="form-select @error('komponen_biaya_id') is-invalid @enderror">
                                <option value="">- Pilih komponen -</option>
                                @foreach ($daftarKomponen as $k)
                                    <option value="{{ $k->id }}">{{ $k->kode }} - {{ $k->nama }}
                                    </option>
                                @endforeach
                            </select>
                            @error('komponen_biaya_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Program Studi</label>
                            <select wire:model="program_studi_id"
                                class="form-select @error('program_studi_id') is-invalid @enderror">
                                <option value="">Semua prodi</option>
                                @foreach ($daftarProdi as $p)
                                    <option value="{{ $p->id }}">{{ $p->kode }} - {{ $p->nama }}
                                    </option>
                                @endforeach
                            </select>
                            @error('program_studi_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Angkatan</label>
                            <input type="number" wire:model="angkatan" placeholder="Kosongkan = semua angkatan"
                                class="form-control @error('angkatan') is-invalid @enderror">
                            @error('angkatan')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Nominal (Rp) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0" wire:model="nominal"
                                placeholder="contoh: 3500000"
                                class="form-control @error('nominal') is-invalid @enderror">
                            @error('nominal')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
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
