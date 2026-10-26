<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Pembayaran Mahasiswa</h4>
        @can('pembayaran.input')
            <button class="btn btn-primary" wire:click="tambah">
                <i class="cil-plus me-1"></i> Catat Pembayaran
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
        <div class="card-header d-flex gap-2 flex-wrap align-items-center">
            <select class="form-select" style="max-width: 200px;" wire:model.live="filterStatus">
                <option value="">Semua status</option>
                @foreach (\App\Models\Pembayaran::STATUS as $nilai => $label)
                    <option value="{{ $nilai }}">{{ $label }}</option>
                @endforeach
            </select>
            <input type="text" class="form-control" style="max-width: 280px;"
                placeholder="Cari NIM, nama, kwitansi, referensi..." wire:model.live.debounce.400ms="cari">
            @if ($jumlahPending > 0)
                <span class="badge bg-warning text-dark ms-auto">{{ $jumlahPending }} menunggu verifikasi</span>
            @endif
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Tanggal</th>
                        <th>Mahasiswa</th>
                        <th>Tagihan</th>
                        <th class="text-end">Nominal</th>
                        <th>Metode</th>
                        <th>Bukti</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data as $row)
                        @php
                            $warna = match ($row->status) {
                                'terverifikasi' => 'success',
                                'ditolak' => 'danger',
                                default => 'warning',
                            };
                        @endphp
                        <tr wire:key="byr-{{ $row->id }}">
                            <td class="text-nowrap">{{ $row->tanggal_bayar->format('d/m/Y') }}</td>
                            <td>
                                {{ $row->mahasiswa?->nama }}
                                <div class="small text-body-secondary">{{ $row->mahasiswa?->nim }}</div>
                            </td>
                            <td>
                                {{ $row->tagihan?->nomor }}
                                <div class="small text-body-secondary">{{ $row->tagihan?->komponen?->nama }}</div>
                            </td>
                            <td class="text-end fw-semibold">Rp {{ number_format($row->nominal, 0, ',', '.') }}</td>
                            <td>
                                {{ $row->metode }}
                                @if ($row->referensi)
                                    <div class="small text-body-secondary">{{ $row->referensi }}</div>
                                @endif
                            </td>
                            <td>
                                @if ($row->bukti_path)
                                    <a href="{{ route('pembayaran.bukti', $row->id) }}" target="_blank"
                                        class="btn btn-sm btn-outline-secondary">Lihat</a>
                                @else
                                    <span class="text-body-secondary">-</span>
                                @endif
                            </td>
                            <td>
                                <span
                                    class="badge bg-{{ $warna }} {{ $warna === 'warning' ? 'text-dark' : '' }}"
                                    @if ($row->alasan_tolak) title="{{ $row->alasan_tolak }}" @endif>
                                    {{ \App\Models\Pembayaran::STATUS[$row->status] ?? $row->status }}
                                </span>
                                @if ($row->nomor_kwitansi)
                                    <div class="small text-body-secondary">{{ $row->nomor_kwitansi }}</div>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @if ($row->status === 'terverifikasi')
                                    <a href="{{ route('pembayaran.kwitansi', $row->id) }}" target="_blank"
                                        class="btn btn-sm btn-outline-primary">Kwitansi</a>
                                @endif
                                @can('pembayaran.verifikasi')
                                    @if ($row->status === 'pending')
                                        <button class="btn btn-sm btn-success" wire:click="verifikasi({{ $row->id }})"
                                            wire:loading.attr="disabled"
                                            wire:confirm="Verifikasi pembayaran Rp {{ number_format($row->nominal, 0, ',', '.') }} dari {{ $row->mahasiswa?->nama }}?">
                                            Verifikasi
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger"
                                            wire:click="konfirmasiTolak({{ $row->id }})">Tolak</button>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-body-secondary py-4">Tidak ada pembayaran.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($data->hasPages())
            <div class="card-footer">{{ $data->links() }}</div>
        @endif
    </div>

    {{-- Modal catat pembayaran --}}
    @if ($tampilForm)
        <div class="modal-backdrop fade show"></div>
        <div class="modal fade show d-block" tabindex="-1" style="overflow-y: auto;" wire:keydown.escape="tutupForm">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" wire:submit="simpan">
                    <div class="modal-header">
                        <h5 class="modal-title">Catat Pembayaran</h5>
                        <button type="button" class="btn-close" wire:click="tutupForm"></button>
                    </div>

                    <div class="modal-body">
                        @error('form')
                            <div class="alert alert-danger py-2">{{ $message }}</div>
                        @enderror

                        <div class="mb-3">
                            <label class="form-label">Tagihan <span class="text-danger">*</span></label>
                            @if ($tagihan_id !== '')
                                <div class="input-group">
                                    <input type="text" class="form-control" value="{{ $labelTagihan }}" readonly>
                                    <button type="button" class="btn btn-outline-secondary"
                                        wire:click="gantiTagihan">Ganti</button>
                                </div>
                                <div class="form-text">Sisa yang bisa dibayar: <strong>Rp
                                        {{ number_format((float) $sisa, 0, ',', '.') }}</strong></div>
                            @else
                                <input type="text" wire:model.live.debounce.300ms="cariTagihan" autocomplete="off"
                                    placeholder="Ketik NIM, nama, atau nomor tagihan..."
                                    class="form-control @error('tagihan_id') is-invalid @enderror">
                                @if ($hasilTagihan->isNotEmpty())
                                    <div class="list-group mt-1">
                                        @foreach ($hasilTagihan as $t)
                                            <button type="button" class="list-group-item list-group-item-action"
                                                wire:click="pilihTagihan({{ $t->id }})"
                                                wire:key="ct-{{ $t->id }}">
                                                <span class="fw-semibold">{{ $t->nomor }}</span> -
                                                {{ $t->mahasiswa?->nama }}
                                                <div class="small text-body-secondary">
                                                    {{ $t->komponen?->nama }}, {{ $t->semester }}
                                                    {{ $t->tahun_ajaran }} |
                                                    Rp {{ number_format($t->nominal, 0, ',', '.') }} |
                                                    {{ \App\Models\Tagihan::STATUS[$t->status] }}
                                                </div>
                                            </button>
                                        @endforeach
                                    </div>
                                @elseif (strlen($cariTagihan) >= 2)
                                    <div class="form-text">Tidak ada tagihan terbuka yang cocok.</div>
                                @endif
                                @error('tagihan_id')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            @endif
                        </div>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Nominal (Rp) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0" wire:model="nominal"
                                    class="form-control @error('nominal') is-invalid @enderror">
                                @error('nominal')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Tanggal Bayar <span class="text-danger">*</span></label>
                                <input type="date" wire:model="tanggal_bayar" max="{{ now()->format('Y-m-d') }}"
                                    class="form-control @error('tanggal_bayar') is-invalid @enderror">
                                @error('tanggal_bayar')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Metode <span class="text-danger">*</span></label>
                            <select wire:model="metode" class="form-select @error('metode') is-invalid @enderror">
                                @foreach (\App\Models\Pembayaran::METODE as $m)
                                    <option value="{{ $m }}">{{ $m }}</option>
                                @endforeach
                            </select>
                            @error('metode')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Referensi / Berita Transfer</label>
                            <input type="text" wire:model="referensi"
                                class="form-control @error('referensi') is-invalid @enderror">
                            @error('referensi')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Bukti Bayar (JPG, PNG, atau PDF, maks. 2 MB)</label>
                            <input type="file" wire:model="bukti" accept=".jpg,.jpeg,.png,.pdf"
                                class="form-control @error('bukti') is-invalid @enderror">
                            <div wire:loading wire:target="bukti" class="form-text">Mengunggah...</div>
                            @error('bukti')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="tutupForm">Batal</button>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled"
                            wire:target="simpan,bukti">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Modal tolak --}}
    @if ($tolakId)
        <div class="modal-backdrop fade show"></div>
        <div class="modal fade show d-block" tabindex="-1" wire:keydown.escape="tutupTolak">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" wire:submit="simpanTolak">
                    <div class="modal-header">
                        <h5 class="modal-title">Tolak Pembayaran</h5>
                        <button type="button" class="btn-close" wire:click="tutupTolak"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label">Alasan penolakan <span class="text-danger">*</span></label>
                        <textarea wire:model="alasanTolak" rows="3" class="form-control @error('alasanTolak') is-invalid @enderror"></textarea>
                        @error('alasanTolak')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="tutupTolak">Kembali</button>
                        <button type="submit" class="btn btn-danger" wire:loading.attr="disabled"
                            wire:target="simpanTolak">Tolak Pembayaran</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
