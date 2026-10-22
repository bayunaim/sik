<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Pengguna & Role</h4>
        <button class="btn btn-primary" wire:click="tambah">
            <i class="cil-plus me-1"></i> Tambah
        </button>
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
            <input type="text" class="form-control" style="max-width: 280px;" placeholder="Cari nama atau email..."
                wire:model.live.debounce.400ms="cari">
            <select class="form-select" style="max-width: 200px;" wire:model.live="filterRole">
                <option value="">Semua role</option>
                @foreach ($daftarRole as $r)
                    <option value="{{ $r }}">{{ $r }}</option>
                @endforeach
            </select>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Nama</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th class="text-end">Batas Persetujuan</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data as $row)
                        @php $namaRole = $row->roles->first()?->name; @endphp
                        <tr wire:key="user-{{ $row->id }}">
                            <td class="fw-semibold">{{ $row->name }}</td>
                            <td>{{ $row->email }}</td>
                            <td><span class="badge bg-info">{{ $namaRole ?? '-' }}</span></td>
                            <td class="text-end">
                                @if ($namaRole === 'SuperAdmin')
                                    <span class="text-body-secondary">Tak terbatas</span>
                                @elseif ($row->batas_persetujuan !== null)
                                    Rp {{ number_format($row->batas_persetujuan, 0, ',', '.') }}
                                @else
                                    <span class="text-body-secondary">-</span>
                                @endif
                            </td>
                            <td>
                                @if ($row->is_active)
                                    <span class="badge bg-success">Aktif</span>
                                @else
                                    <span class="badge bg-secondary">Nonaktif</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                <button class="btn btn-sm btn-outline-primary"
                                    wire:click="ubah({{ $row->id }})">Ubah</button>
                                @if ($row->id !== auth()->id())
                                    <button
                                        class="btn btn-sm {{ $row->is_active ? 'btn-outline-warning' : 'btn-outline-success' }}"
                                        wire:click="toggleAktif({{ $row->id }})"
                                        wire:confirm="{{ $row->is_active ? 'Nonaktifkan' : 'Aktifkan' }} user {{ $row->name }}?">
                                        {{ $row->is_active ? 'Nonaktifkan' : 'Aktifkan' }}
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-body-secondary py-4">Belum ada data.</td>
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
                        <h5 class="modal-title">{{ $editId ? 'Ubah' : 'Tambah' }} User</h5>
                        <button type="button" class="btn-close" wire:click="tutupForm"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nama <span class="text-danger">*</span></label>
                            <input type="text" wire:model="name"
                                class="form-control @error('name') is-invalid @enderror">
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" wire:model="email"
                                class="form-control @error('email') is-invalid @enderror">
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">
                                Password @if (!$editId)
                                    <span class="text-danger">*</span>
                                @endif
                            </label>
                            <input type="password" wire:model="password" autocomplete="new-password"
                                placeholder="{{ $editId ? 'Kosongkan jika tidak diubah' : 'Minimal 8 karakter' }}"
                                class="form-control @error('password') is-invalid @enderror">
                            @error('password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Role <span class="text-danger">*</span></label>
                            <select wire:model="role" class="form-select @error('role') is-invalid @enderror">
                                <option value="">- Pilih role -</option>
                                @foreach ($daftarRole as $r)
                                    <option value="{{ $r }}">{{ $r }}</option>
                                @endforeach
                            </select>
                            @error('role')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Batas Persetujuan (Rp)</label>
                            <input type="number" step="0.01" min="0" wire:model="batas_persetujuan"
                                placeholder="Kosong = tidak boleh approve pengeluaran"
                                class="form-control @error('batas_persetujuan') is-invalid @enderror">
                            @error('batas_persetujuan')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-text">Nominal pengeluaran terbesar yang boleh disetujui user ini.
                                SuperAdmin tidak terbatas.</div>
                        </div>

                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="userAktif" wire:model="is_active">
                            <label class="form-check-label" for="userAktif">Aktif</label>
                            @error('is_active')
                                <div class="text-danger small">{{ $message }}</div>
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
