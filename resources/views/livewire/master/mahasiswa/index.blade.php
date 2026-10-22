<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Data Mahasiswa</h4>
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
            <input type="text" class="form-control" style="max-width: 260px;" placeholder="Cari NIM atau nama..."
                wire:model.live.debounce.400ms="cari">
            <select class="form-select" style="max-width: 220px;" wire:model.live="filterProdi">
                <option value="">Semua prodi</option>
                @foreach ($daftarProdi as $p)
                    <option value="{{ $p->id }}">{{ $p->kode }} - {{ $p->nama }}</option>
                @endforeach
            </select>
            <select class="form-select" style="max-width: 160px;" wire:model.live="filterAngkatan">
                <option value="">Semua angkatan</option>
                @foreach ($daftarAngkatan as $a)
                    <option value="{{ $a }}">{{ $a }}</option>
                @endforeach
            </select>
            <select class="form-select" style="max-width: 200px;" wire:model.live="filterStatus">
                <option value="">Semua status</option>
                @foreach ($daftarStatus as $s)
                    <option value="{{ $s }}">{{ $s }}</option>
                @endforeach
            </select>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>NIM</th>
                        <th>Nama</th>
                        <th>Program Studi</th>
                        <th>Angkatan</th>
                        <th>Semester</th>
                        <th>Status</th>
                        @can('master.kelola')
                            <th class="text-end">Aksi</th>
                        @endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data as $row)
                        <tr wire:key="mhs-{{ $row->id }}">
                            <td class="fw-semibold">{{ $row->nim }}</td>
                            <td>{{ $row->nama }}</td>
                            <td>{{ $row->programStudi?->kode }}</td>
                            <td>{{ $row->angkatan }}</td>
                            <td>{{ $row->semester_aktif ?? '-' }}</td>
                            <td>
                                @php
                                    $warna = match ($row->status_akademik) {
                                        'Aktif' => 'success',
                                        'Cuti' => 'warning',
                                        'Lulus' => 'info',
                                        default => 'secondary',
                                    };
                                @endphp
                                <span class="badge bg-{{ $warna }}">{{ $row->status_akademik }}</span>
                            </td>
                            @can('master.kelola')
                                <td class="text-end text-nowrap">
                                    <button class="btn btn-sm btn-outline-primary"
                                        wire:click="ubah({{ $row->id }})">Ubah</button>
                                    <button class="btn btn-sm btn-outline-danger" wire:click="hapus({{ $row->id }})"
                                        wire:confirm="Hapus mahasiswa {{ $row->nim }} - {{ $row->nama }}?">Hapus</button>
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
                        <h5 class="modal-title">{{ $editId ? 'Ubah' : 'Tambah' }} Mahasiswa</h5>
                        <button type="button" class="btn-close" wire:click="tutupForm"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">NIM <span class="text-danger">*</span></label>
                            <input type="text" wire:model="nim"
                                class="form-control @error('nim') is-invalid @enderror">
                            @error('nim')
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
                            <label class="form-label">Program Studi <span class="text-danger">*</span></label>
                            <select wire:model="program_studi_id"
                                class="form-select @error('program_studi_id') is-invalid @enderror">
                                <option value="">- Pilih prodi -</option>
                                @foreach ($daftarProdi as $p)
                                    <option value="{{ $p->id }}">{{ $p->kode }} - {{ $p->nama }}
                                    </option>
                                @endforeach
                            </select>
                            @error('program_studi_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Angkatan <span class="text-danger">*</span></label>
                                <input type="number" wire:model="angkatan" placeholder="contoh: 2025"
                                    class="form-control @error('angkatan') is-invalid @enderror">
                                @error('angkatan')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Semester Aktif</label>
                                <input type="text" wire:model="semester_aktif" placeholder="contoh: 3"
                                    class="form-control @error('semester_aktif') is-invalid @enderror">
                                @error('semester_aktif')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Status Akademik <span class="text-danger">*</span></label>
                            <select wire:model="status_akademik"
                                class="form-select @error('status_akademik') is-invalid @enderror">
                                @foreach (\App\Models\Mahasiswa::STATUS as $s)
                                    <option value="{{ $s }}">{{ $s }}</option>
                                @endforeach
                            </select>
                            @error('status_akademik')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">ID SIA (opsional)</label>
                            <input type="text" wire:model="sia_id"
                                class="form-control @error('sia_id') is-invalid @enderror">
                            @error('sia_id')
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
