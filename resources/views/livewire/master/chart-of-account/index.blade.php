<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Chart of Accounts</h4>
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
            <input type="text" class="form-control" style="max-width: 280px;" placeholder="Cari kode atau nama..."
                wire:model.live.debounce.400ms="cari">
            <select class="form-select" style="max-width: 200px;" wire:model.live="filterTipe">
                <option value="">Semua tipe</option>
                @foreach (\App\Models\ChartOfAccount::TIPE as $nilai => $label)
                    <option value="{{ $nilai }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 140px;">Kode</th>
                        <th>Nama Akun</th>
                        <th>Tipe</th>
                        <th>Status</th>
                        @can('master.kelola')
                            <th class="text-end">Aksi</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data as $row)
                        <tr wire:key="coa-{{ $row->id }}">
                            <td class="fw-semibold">{{ $row->kode }}</td>
                            <td>
                                <span style="padding-left: {{ $row->kedalaman * 1.5 }}rem;"
                                    class="{{ $row->punya_anak ? 'fw-semibold' : '' }}">
                                    {{ $row->nama }}
                                </span>
                            </td>
                            <td>{{ \App\Models\ChartOfAccount::TIPE[$row->tipe] ?? $row->tipe }}</td>
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
                                        wire:confirm="Hapus akun {{ $row->kode }} - {{ $row->nama }}?">Hapus</button>
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-body-secondary py-4">Belum ada data.</td>
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
                        <h5 class="modal-title">{{ $editId ? 'Ubah' : 'Tambah' }} Akun</h5>
                        <button type="button" class="btn-close" wire:click="tutupForm"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Tipe <span class="text-danger">*</span></label>
                            <select wire:model.live="tipe" class="form-select @error('tipe') is-invalid @enderror">
                                @foreach (\App\Models\ChartOfAccount::TIPE as $nilai => $label)
                                    <option value="{{ $nilai }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('tipe')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Akun Induk</label>
                            <select wire:model="parent_id" class="form-select @error('parent_id') is-invalid @enderror">
                                <option value="">- Tidak ada (akun utama) -</option>
                                @foreach ($pilihanInduk as $induk)
                                    <option value="{{ $induk->id }}">{{ $induk->kode }} - {{ $induk->nama }}
                                    </option>
                                @endforeach
                            </select>
                            @error('parent_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Kode <span class="text-danger">*</span></label>
                            <input type="text" wire:model="kode" placeholder="contoh: 4110"
                                class="form-control @error('kode') is-invalid @enderror">
                            @error('kode')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Nama Akun <span class="text-danger">*</span></label>
                            <input type="text" wire:model="nama"
                                class="form-control @error('nama') is-invalid @enderror">
                            @error('nama')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="coaAktif" wire:model="is_active">
                            <label class="form-check-label" for="coaAktif">Aktif</label>
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
