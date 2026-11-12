<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Rekening Bank</h4>
        <div class="d-flex gap-2">
            <a href="{{ route('rekonsiliasi.index') }}" class="btn btn-outline-secondary">Pencocokan</a>
            <button class="btn btn-primary" wire:click="tambah">
                <i class="cil-plus me-1"></i> Tambah Rekening
            </button>
        </div>
    </div>

    @if (session('sukses'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('sukses') }}
            <button type="button" class="btn-close" data-coreui-dismiss="alert"></button>
        </div>
    @endif

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Bank</th>
                        <th>Nomor Rekening</th>
                        <th>Atas Nama</th>
                        <th class="text-end">Jumlah Mutasi</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data as $r)
                        <tr wire:key="rek-{{ $r->id }}">
                            <td>{{ $r->nama_bank }}</td>
                            <td>{{ $r->nomor }}</td>
                            <td>{{ $r->atas_nama }}</td>
                            <td class="text-end">{{ number_format($r->mutasi_count, 0, ',', '.') }}</td>
                            <td>
                                <span class="badge bg-{{ $r->is_active ? 'success' : 'secondary' }}">
                                    {{ $r->is_active ? 'Aktif' : 'Nonaktif' }}
                                </span>
                            </td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-sm btn-outline-primary"
                                    wire:click="ubah({{ $r->id }})">Ubah</button>
                                <button class="btn btn-sm btn-outline-{{ $r->is_active ? 'danger' : 'success' }}"
                                    wire:click="toggleAktif({{ $r->id }})"
                                    wire:confirm="{{ $r->is_active ? 'Nonaktifkan rekening ini? Mutasi lama tetap tersimpan, tetapi tidak bisa mengimpor mutasi baru.' : 'Aktifkan kembali rekening ini?' }}">
                                    {{ $r->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-body-secondary py-4">
                                Belum ada rekening. Tambahkan rekening dulu sebelum mengimpor mutasi.
                            </td>
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
                        <h5 class="modal-title">{{ $editId ? 'Ubah Rekening' : 'Tambah Rekening' }}</h5>
                        <button type="button" class="btn-close" wire:click="tutupForm"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nama Bank <span class="text-danger">*</span></label>
                            <input type="text" wire:model="nama_bank" placeholder="Contoh: BCA"
                                class="form-control @error('nama_bank') is-invalid @enderror">
                            @error('nama_bank')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Nomor Rekening <span class="text-danger">*</span></label>
                            <input type="text" wire:model="nomor" autocomplete="off"
                                class="form-control @error('nomor') is-invalid @enderror">
                            @error('nomor')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Atas Nama <span class="text-danger">*</span></label>
                            <input type="text" wire:model="atas_nama"
                                class="form-control @error('atas_nama') is-invalid @enderror">
                            @error('atas_nama')
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
