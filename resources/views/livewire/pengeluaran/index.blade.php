<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Pengeluaran</h4>
        @can('pengeluaran.ajukan')
            <button class="btn btn-primary" wire:click="tambah">
                <i class="cil-plus me-1"></i> Ajukan Pengeluaran
            </button>
        @endcan
    </div>

    @foreach (['sukses' => 'success', 'peringatan' => 'warning', 'gagal' => 'danger'] as $kunci => $warna)
        @if (session($kunci))
            <div class="alert alert-{{ $warna }} alert-dismissible fade show">
                {{ session($kunci) }}
                <button type="button" class="btn-close" data-coreui-dismiss="alert"></button>
            </div>
        @endif
    @endforeach

    {{-- Ringkasan (FR-065) --}}
    @php
        $jumlah = fn($s) => (int) ($ringkasan[$s]->jumlah ?? 0);
        $total = fn($s) => (float) ($ringkasan[$s]->total ?? 0);
        $semuaJumlah = $jumlah('pending') + $jumlah('disetujui') + $jumlah('ditolak');
        $semuaTotal = $total('pending') + $total('disetujui') + $total('ditolak');
    @endphp
    <div class="row g-3 mb-3">
        @foreach ([['Total pengajuan', $semuaJumlah, $semuaTotal, 'primary'], ['Pending', $jumlah('pending'), $total('pending'), 'warning'], ['Disetujui', $jumlah('disetujui'), $total('disetujui'), 'success'], ['Ditolak', $jumlah('ditolak'), $total('ditolak'), 'danger']] as [$label, $n, $rp, $warna])
            <div class="col-6 col-lg-3">
                <div class="card border-top-{{ $warna }} border-top-3 h-100">
                    <div class="card-body">
                        <div class="text-body-secondary small">{{ $label }} ({{ $n }})</div>
                        <div class="fs-5 fw-semibold">Rp {{ number_format($rp, 0, ',', '.') }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-header d-flex gap-2 flex-wrap">
            <select class="form-select" style="max-width: 150px;" wire:model.live="filterStatus">
                <option value="">Semua status</option>
                @foreach (\App\Models\Pengeluaran::STATUS as $nilai => $label)
                    <option value="{{ $nilai }}">{{ $label }}</option>
                @endforeach
            </select>
            <select class="form-select" style="max-width: 230px;" wire:model.live="filterAnggaran">
                <option value="">Semua kategori (kode anggaran)</option>
                @foreach ($daftarFilterAnggaran as $a)
                    <option value="{{ $a->id }}">{{ $a->kode }} - {{ $a->nama }}
                        ({{ $a->tahun->nama }})</option>
                @endforeach
            </select>
            <select class="form-select" style="max-width: 140px;" wire:model.live="filterBulan">
                <option value="">Semua bulan</option>
                @foreach (['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'] as $i => $b)
                    <option value="{{ $i + 1 }}">{{ $b }}</option>
                @endforeach
            </select>
            <select class="form-select" style="max-width: 120px;" wire:model.live="filterTahun">
                <option value="">Semua tahun</option>
                @foreach ($daftarTahun as $t)
                    <option value="{{ $t }}">{{ $t }}</option>
                @endforeach
            </select>
            <input type="text" class="form-control" style="max-width: 220px;"
                placeholder="Cari nomor atau keperluan..." wire:model.live.debounce.400ms="cari">
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Nomor</th>
                        <th>Tanggal</th>
                        <th>Keperluan</th>
                        <th>Kode / Akun</th>
                        <th class="text-end">Nominal</th>
                        <th>Pengaju</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data as $row)
                        @php
                            $warna = match ($row->status) {
                                'disetujui' => 'success',
                                'ditolak' => 'danger',
                                default => 'warning',
                            };
                        @endphp
                        <tr wire:key="png-{{ $row->id }}">
                            <td class="fw-semibold text-nowrap">{{ $row->nomor }}</td>
                            <td class="text-nowrap">{{ $row->tanggal->format('d/m/Y') }}</td>
                            <td>
                                {{ $row->keperluan }}
                                @if ($row->lampiran_path)
                                    <a href="{{ route('pengeluaran.lampiran', $row->id) }}" target="_blank"
                                        class="small ms-1">[lampiran]</a>
                                @endif
                            </td>
                            <td>
                                {{ $row->anggaran?->kode }}
                                <div class="small text-body-secondary">{{ $row->akunBeban?->kode }} -
                                    {{ $row->akunBeban?->nama }}</div>
                            </td>
                            <td class="text-end fw-semibold">Rp {{ number_format($row->nominal, 0, ',', '.') }}</td>
                            <td>{{ $row->pengaju?->name ?? '-' }}</td>
                            <td>
                                <span
                                    class="badge bg-{{ $warna }} {{ $warna === 'warning' ? 'text-dark' : '' }}"
                                    @if ($row->alasan_tolak) title="{{ $row->alasan_tolak }}" @endif>
                                    {{ \App\Models\Pengeluaran::STATUS[$row->status] ?? $row->status }}
                                </span>
                                @if ($row->pemroses)
                                    <div class="small text-body-secondary">oleh {{ $row->pemroses->name }}</div>
                                @endif
                                @if ($row->melebihi)
                                    <div class="small text-danger">Melebihi sisa anggaran</div>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @if ($row->status === 'pending')
                                    @if ($row->alasan_blokir === null)
                                        <button class="btn btn-sm btn-success"
                                            wire:click="setujui({{ $row->id }})" wire:loading.attr="disabled"
                                            wire:confirm="Setujui {{ $row->nomor }} sebesar Rp {{ number_format($row->nominal, 0, ',', '.') }}?{{ $row->melebihi ? ' PERHATIAN: nominal ini melebihi sisa anggaran.' : '' }}">
                                            Setujui
                                        </button>
                                    @endif
                                    @if (auth()->user()->can('pengeluaran.approve') && $row->diajukan_oleh !== auth()->id())
                                        <button class="btn btn-sm btn-outline-danger"
                                            wire:click="konfirmasiTolak({{ $row->id }})">Tolak</button>
                                    @endif
                                    @if ($row->diajukan_oleh === auth()->id())
                                        <button class="btn btn-sm btn-outline-secondary"
                                            wire:click="batalkan({{ $row->id }})"
                                            wire:confirm="Batalkan pengajuan {{ $row->nomor }}?">Batalkan</button>
                                    @endif
                                    @if ($row->alasan_blokir && auth()->user()->can('pengeluaran.approve'))
                                        <div class="small text-body-secondary mt-1"
                                            style="max-width: 240px; white-space: normal; margin-left: auto;">
                                            {{ $row->alasan_blokir }}
                                        </div>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-body-secondary py-4">Belum ada pengeluaran.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($data->hasPages())
            <div class="card-footer">{{ $data->links() }}</div>
        @endif
    </div>

    {{-- Modal pengajuan --}}
    @if ($tampilForm)
        <div class="modal-backdrop fade show"></div>
        <div class="modal fade show d-block" tabindex="-1" style="overflow-y: auto;" wire:keydown.escape="tutupForm">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" wire:submit="simpan">
                    <div class="modal-header">
                        <h5 class="modal-title">Ajukan Pengeluaran</h5>
                        <button type="button" class="btn-close" wire:click="tutupForm"></button>
                    </div>

                    <div class="modal-body">
                        @error('form')
                            <div class="alert alert-danger py-2">{{ $message }}</div>
                        @enderror

                        <div class="mb-3">
                            <label class="form-label">Kode Anggaran Pengeluaran <span
                                    class="text-danger">*</span></label>
                            <select wire:model.live="anggaran_id"
                                class="form-select @error('anggaran_id') is-invalid @enderror">
                                <option value="">- Pilih kode anggaran -</option>
                                @foreach ($daftarAnggaran as $a)
                                    <option value="{{ $a->id }}">{{ $a->kode }} - {{ $a->nama }}
                                        ({{ $a->tahun->nama }})</option>
                                @endforeach
                            </select>
                            @error('anggaran_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Akun Beban <span class="text-danger">*</span></label>
                            <select wire:model="akun_beban_id"
                                class="form-select @error('akun_beban_id') is-invalid @enderror">
                                <option value="">- Pilih akun beban -</option>
                                @foreach ($daftarAkun as $akun)
                                    <option value="{{ $akun->id }}">{{ $akun->kode }} - {{ $akun->nama }}
                                    </option>
                                @endforeach
                            </select>
                            @error('akun_beban_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Keperluan <span class="text-danger">*</span></label>
                            <input type="text" wire:model="keperluan"
                                class="form-control @error('keperluan') is-invalid @enderror">
                            @error('keperluan')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                                <input type="date" wire:model="tanggal" max="{{ now()->format('Y-m-d') }}"
                                    class="form-control @error('tanggal') is-invalid @enderror">
                                @error('tanggal')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Nominal (Rp) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0"
                                    wire:model.live.debounce.500ms="nominal"
                                    class="form-control @error('nominal') is-invalid @enderror">
                                @error('nominal')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        @if ($peringatan)
                            <div
                                class="alert {{ $peringatan['melebihi'] ? 'alert-warning' : 'alert-info' }} py-2 small">
                                Sisa anggaran (setelah dikurangi pengeluaran yang sudah disetujui):
                                <strong>Rp {{ number_format((float) $peringatan['sisa'], 0, ',', '.') }}</strong>.
                                @if ($peringatan['melebihi'])
                                    <br><strong>Peringatan:</strong> nominal ini melebihi sisa anggaran. Pengajuan tetap
                                    bisa dikirim, tetapi approver akan diberi peringatan.
                                @endif
                            </div>
                        @endif

                        <div class="mb-3">
                            <label class="form-label">Dibayar lewat <span class="text-danger">*</span></label>
                            <select wire:model="metode" class="form-select">
                                @foreach (\App\Models\Pengeluaran::METODE as $m)
                                    <option value="{{ $m }}">{{ $m }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Lampiran (JPG, PNG, atau PDF, maks. 2 MB)</label>
                            <input type="file" wire:model="lampiran" accept=".jpg,.jpeg,.png,.pdf"
                                class="form-control @error('lampiran') is-invalid @enderror">
                            <div wire:loading wire:target="lampiran" class="form-text">Mengunggah...</div>
                            @error('lampiran')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="tutupForm">Batal</button>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled"
                            wire:target="simpan,lampiran">Kirim Pengajuan</button>
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
                        <h5 class="modal-title">Tolak Pengeluaran</h5>
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
                            wire:target="simpanTolak">Tolak Pengeluaran</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
