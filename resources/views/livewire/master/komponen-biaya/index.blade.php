<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Komponen Biaya</h4>
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
        <div class="card-header">
            <input type="text" class="form-control" style="max-width: 320px;" placeholder="Cari kode atau nama..."
                wire:model.live.debounce.400ms="cari">
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Jenis</th>
                        <th>Akun Pendapatan</th>
                        <th class="text-center">Tarif</th>
                        <th>Status</th>
                        @can('master.kelola')
                            <th class="text-end">Aksi</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data as $row)
                        <tr wire:key="komp-{{ $row->id }}">
                            <td class="fw-semibold">{{ $row->kode }}</td>
                            <td>{{ $row->nama }}</td>
                            <td>{{ \App\Models\KomponenBiaya::JENIS[$row->jenis] ?? $row->jenis }}</td>
                            <td>{{ $row->akunPendapatan ? $row->akunPendapatan->kode . ' - ' . $row->akunPendapatan->nama : '-' }}
                            </td>
                            <td class="text-center">{{ $row->tarif_count }}</td>
                            <td>
                                @if ($row->is_active)
                                    <span class="badge bg-success">Aktif</span>
                                @else
                                    <span class="badge bg-secondary">Nonaktif</span>
                                @endif
                            </td>
                            @can('master.kelola')
                                <td class="text-end text-nowrap">
                                    <button class="btn btn-sm btn-outline-primary"
                                        wire:click="ubah({{ $row->id }})">Ubah</button>
                                    <button class="btn btn-sm btn-outline-danger" wire:click="hapus({{ $row->id }})"
                                        wire:confirm="Hapus komponen {{ $row->kode }}?">Hapus</button>
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-body-secondary py-4">Belum ada data.</td>
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
                        <h5 class="modal-title">{{ $editId ? 'Ubah' : 'Tambah' }} Komponen Biaya</h5>
                        <button type="button" class="btn-close" wire:click="tutupForm"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Kode <span class="text-danger">*</span></label>
                            <input type="text" wire:model="kode" placeholder="contoh: SPP"
                                class="form-control @error('kode') is-invalid @enderror">
                            @error('kode')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Nama <span class="text-danger">*</span></label>
                            <input type="text" wire:model="nama"
                                class="form-control @error('nama') is-invalid @enderror">
                            @error('nama')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Jenis <span class="text-danger">*</span></label>
                            <select wire:model="jenis" class="form-select @error('jenis') is-invalid @enderror">
                                @foreach (\App\Models\KomponenBiaya::JENIS as $nilai => $label)
                                    <option value="{{ $nilai }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('jenis')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Akun Pendapatan <span class="text-danger">*</span></label>
                            <select wire:model="akun_pendapatan_id"
                                class="form-select @error('akun_pendapatan_id') is-invalid @enderror">
                                <option value="">- Pilih akun -</option>
                                @foreach ($akunPendapatan as $akun)
                                    <option value="{{ $akun->id }}">{{ $akun->kode }} - {{ $akun->nama }}
                                    </option>
                                @endforeach
                            </select>
                            @error('akun_pendapatan_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="kompAktif" wire:model="is_active">
                            <label class="form-check-label" for="kompAktif">Aktif</label>
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
