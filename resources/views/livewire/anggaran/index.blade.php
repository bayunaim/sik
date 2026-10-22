<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Anggaran</h4>
        @can('anggaran.buat')
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

    {{-- Ringkasan (hanya anggaran Approved) --}}
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card border-top-success border-top-3 h-100">
                <div class="card-body">
                    <div class="text-body-secondary small">Total Penerimaan</div>
                    <div class="fs-4 fw-semibold">Rp {{ number_format($penerimaan, 0, ',', '.') }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-top-danger border-top-3 h-100">
                <div class="card-body">
                    <div class="text-body-secondary small">Total Pengeluaran</div>
                    <div class="fs-4 fw-semibold">Rp {{ number_format($pengeluaran, 0, ',', '.') }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-top-primary border-top-3 h-100">
                <div class="card-body">
                    <div class="text-body-secondary small">Saldo (Penerimaan - Pengeluaran)</div>
                    <div class="fs-4 fw-semibold {{ $saldo < 0 ? 'text-danger' : '' }}">
                        Rp {{ number_format($saldo, 0, ',', '.') }}
                    </div>
                </div>
            </div>
        </div>
    </div>
    @if ($jumlahDraft > 0)
        <div class="small text-body-secondary mb-3">
            Ada {{ $jumlahDraft }} anggaran berstatus Draft yang belum dihitung di ringkasan.
        </div>
    @endif

    <div class="card">
        <div class="card-header d-flex gap-2 flex-wrap">
            <select class="form-select" style="max-width: 160px;" wire:model.live="filterTahun">
                @foreach ($daftarTahun as $t)
                    <option value="{{ $t->id }}">
                        {{ $t->nama }}@if ($t->status === 'aktif')
                            (aktif)
                        @elseif ($t->status === 'ditutup')
                            (ditutup)
                        @endif
                    </option>
                @endforeach
            </select>
            <select class="form-select" style="max-width: 170px;" wire:model.live="filterTipe">
                <option value="">Semua tipe</option>
                <option value="D">Penerimaan (D)</option>
                <option value="K">Pengeluaran (K)</option>
            </select>
            <select class="form-select" style="max-width: 150px;" wire:model.live="filterStatus">
                <option value="">Semua status</option>
                <option value="draft">Draft</option>
                <option value="approved">Approved</option>
            </select>
            <input type="text" class="form-control" style="max-width: 260px;"
                placeholder="Cari kode, nama, sub anggaran..." wire:model.live.debounce.400ms="cari">
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th class="text-end">Jumlah</th>
                        <th class="text-end">Tercapai / Terpakai</th>
                        <th style="width: 140px;">%</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data as $row)
                        @php
                            $real = (float) ($realisasi[$row->id] ?? 0);
                            $persen = (float) $row->jumlah > 0 ? min(100, ($real / (float) $row->jumlah) * 100) : 0;
                            $bolehUbah =
                                auth()->user()->can('anggaran.buat') &&
                                !$row->tahun->isDitutup() &&
                                (auth()->user()->can('anggaran.approve') ||
                                    (!$row->isApproved() && $row->dibuat_oleh === auth()->id()));
                        @endphp
                        <tr wire:key="agr-{{ $row->id }}">
                            <td class="fw-semibold">
                                <span
                                    class="badge {{ $row->tipe === 'D' ? 'bg-success' : 'bg-danger' }} me-1">{{ $row->tipe }}</span>
                                {{ $row->kode }}
                            </td>
                            <td>
                                {{ $row->nama }}
                                @if ($row->sub_anggaran)
                                    <div class="small text-body-secondary">{{ $row->sub_anggaran }}</div>
                                @endif
                            </td>
                            <td class="text-end">Rp {{ number_format($row->jumlah, 0, ',', '.') }}</td>
                            <td class="text-end">Rp {{ number_format($real, 0, ',', '.') }}</td>
                            <td>
                                <div class="progress" style="height: 8px;">
                                    <div class="progress-bar {{ $persen >= 100 ? 'bg-danger' : 'bg-primary' }}"
                                        style="width: {{ $persen }}%"></div>
                                </div>
                                <small class="text-body-secondary">{{ number_format($persen, 1, ',', '.') }}%</small>
                            </td>
                            <td>
                                @if ($row->isApproved())
                                    <span class="badge bg-success">Approved</span>
                                @else
                                    <span class="badge bg-secondary">Draft</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @can('anggaran.approve')
                                    @if (!$row->isApproved() && !$row->tahun->isDitutup())
                                        <button class="btn btn-sm btn-outline-success"
                                            wire:click="setujui({{ $row->id }})"
                                            wire:confirm="Setujui anggaran {{ $row->kode }} - {{ $row->nama }}?">Setujui</button>
                                    @endif
                                @endcan
                                @if ($bolehUbah)
                                    <button class="btn btn-sm btn-outline-primary"
                                        wire:click="ubah({{ $row->id }})">Ubah</button>
                                    @if (!$row->isApproved())
                                        <button class="btn btn-sm btn-outline-danger"
                                            wire:click="hapus({{ $row->id }})"
                                            wire:confirm="Hapus anggaran {{ $row->kode }}?">Hapus</button>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-body-secondary py-4">Belum ada anggaran untuk
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

    @if ($tampilForm)
        <div class="modal-backdrop fade show"></div>
        <div class="modal fade show d-block" tabindex="-1" wire:keydown.escape="tutupForm">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" wire:submit="simpan">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ $editId ? 'Ubah' : 'Tambah' }} Anggaran</h5>
                        <button type="button" class="btn-close" wire:click="tutupForm"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Tahun Anggaran <span class="text-danger">*</span></label>
                            <select wire:model="tahun_anggaran_id" @disabled($editId)
                                class="form-select @error('tahun_anggaran_id') is-invalid @enderror">
                                <option value="">- Pilih tahun -</option>
                                @foreach ($tahunForm as $t)
                                    <option value="{{ $t->id }}">{{ $t->nama }}</option>
                                @endforeach
                            </select>
                            @error('tahun_anggaran_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Kode <span class="text-danger">*</span></label>
                            <input type="text" wire:model="kode" placeholder="contoh: D1.1 atau K2.3"
                                class="form-control @error('kode') is-invalid @enderror">
                            @error('kode')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-text">D = penerimaan, K = pengeluaran.</div>
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
                            <label class="form-label">Sub Anggaran</label>
                            <input type="text" wire:model="sub_anggaran"
                                class="form-control @error('sub_anggaran') is-invalid @enderror">
                            @error('sub_anggaran')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Jumlah (Rp) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0" wire:model="jumlah"
                                class="form-control @error('jumlah') is-invalid @enderror">
                            @error('jumlah')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        @can('anggaran.approve')
                            <div class="mb-3">
                                <label class="form-label">Status</label>
                                <select wire:model="status" class="form-select @error('status') is-invalid @enderror">
                                    <option value="draft">Draft</option>
                                    <option value="approved">Approved</option>
                                </select>
                                @error('status')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        @else
                            <div class="alert alert-info py-2 small">
                                Anggaran akan disimpan sebagai <strong>Draft</strong> dan menunggu persetujuan Admin
                                Keuangan.
                            </div>
                        @endcan

                        <div class="mb-3">
                            <label class="form-label">Catatan</label>
                            <textarea wire:model="catatan" rows="2" class="form-control @error('catatan') is-invalid @enderror"></textarea>
                            @error('catatan')
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
