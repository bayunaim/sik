<div>
    <h4 class="mb-1">Portal Mahasiswa</h4>

    @unless ($mhs)
        <div class="alert alert-warning mt-3">
            Akun Anda belum terhubung dengan data mahasiswa. Hubungi Bagian Keuangan.
        </div>
    @else
        <div class="text-body-secondary mb-3">
            {{ $mhs->nama }} ({{ $mhs->nim }}) | {{ $mhs->programStudi?->nama }} | Angkatan {{ $mhs->angkatan }}
        </div>

        @foreach (['sukses' => 'success', 'gagal' => 'danger'] as $kunci => $warna)
            @if (session($kunci))
                <div class="alert alert-{{ $warna }} alert-dismissible fade show">
                    {{ session($kunci) }}
                    <button type="button" class="btn-close" data-coreui-dismiss="alert"></button>
                </div>
            @endif
        @endforeach

        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="card border-top-danger border-top-3 h-100">
                    <div class="card-body">
                        <div class="text-body-secondary small">Total tunggakan</div>
                        <div class="fs-4 fw-semibold {{ $tunggakan > 0 ? 'text-danger' : 'text-success' }}">Rp
                            {{ number_format($tunggakan, 0, ',', '.') }}</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <div class="card border-top-primary border-top-3 h-100">
                    <div class="card-body">
                        <div class="text-body-secondary small">Tagihan belum lunas</div>
                        <div class="fs-4 fw-semibold">{{ $belumLunas }}</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <div class="card border-top-warning border-top-3 h-100">
                    <div class="card-body">
                        <div class="text-body-secondary small">Menunggu verifikasi</div>
                        <div class="fs-4 fw-semibold">{{ $menunggu }}</div>
                    </div>
                </div>
            </div>
        </div>

        @if (!empty($bisaSuratLunas))
            <div class="alert alert-success d-flex justify-content-between align-items-center">
                <span>Seluruh tagihan Anda sudah lunas.</span>
                <a href="{{ route('portal.surat-lunas') }}" target="_blank" class="btn btn-sm btn-success">Unduh Surat
                    Keterangan Lunas</a>
            </div>
        @endif

        <div class="card mb-3">
            <div class="card-header fw-semibold">Tagihan</div>
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Tagihan</th>
                            <th>Periode</th>
                            <th class="text-end">Nominal</th>
                            <th class="text-end">Terbayar</th>
                            <th>Jatuh Tempo</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($tagihan as $t)
                            @php
                                $warna = match ($t->status) {
                                    'lunas' => 'success',
                                    'sebagian' => 'info',
                                    'pending' => 'warning',
                                    default => 'secondary',
                                };
                                $terlambat = $t->status !== 'lunas' && $t->jatuh_tempo->isPast();
                            @endphp
                            <tr wire:key="pt-{{ $t->id }}">
                                <td>{{ $t->komponen?->nama }}<div class="small text-body-secondary">{{ $t->nomor }}
                                    </div>
                                </td>
                                <td class="text-nowrap">{{ $t->semester }} {{ $t->tahun_ajaran }}</td>
                                <td class="text-end">Rp {{ number_format($t->nominal, 0, ',', '.') }}</td>
                                <td class="text-end">Rp {{ number_format($t->terbayar, 0, ',', '.') }}</td>
                                <td class="text-nowrap {{ $terlambat ? 'text-danger fw-semibold' : '' }}">
                                    {{ $t->jatuh_tempo->format('d/m/Y') }}</td>
                                <td><span
                                        class="badge bg-{{ $warna }} {{ $warna === 'warning' ? 'text-dark' : '' }}">{{ \App\Models\Tagihan::STATUS[$t->status] ?? $t->status }}</span>
                                </td>
                                <td class="text-end text-nowrap">
                                    <a href="{{ route('portal.tagihan', $t->id) }}" target="_blank"
                                        class="btn btn-sm btn-outline-secondary">Cetak</a>
                                    @if ($t->bisa_bayar)
                                        @can('portal.upload-bukti')
                                            <button class="btn btn-sm btn-primary"
                                                wire:click="bukaUpload({{ $t->id }})">Upload Bukti</button>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-body-secondary py-4">Belum ada tagihan.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold">Riwayat pembayaran</div>
            <div class="table-responsive">
                <table class="table mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Tanggal</th>
                            <th>Tagihan</th>
                            <th class="text-end">Nominal</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($riwayat as $p)
                            @php
                                $w = match ($p->status) {
                                    'terverifikasi' => 'success',
                                    'ditolak' => 'danger',
                                    default => 'warning',
                                };
                            @endphp
                            <tr wire:key="rw-{{ $p->id }}">
                                <td class="text-nowrap">{{ $p->tanggal_bayar->format('d/m/Y') }}</td>
                                <td>{{ $p->tagihan?->komponen?->nama }}<div class="small text-body-secondary">
                                        {{ $p->tagihan?->nomor }}</div>
                                </td>
                                <td class="text-end">Rp {{ number_format($p->nominal, 0, ',', '.') }}</td>
                                <td>
                                    <span
                                        class="badge bg-{{ $w }} {{ $w === 'warning' ? 'text-dark' : '' }}">{{ \App\Models\Pembayaran::STATUS[$p->status] ?? $p->status }}</span>
                                    @if ($p->alasan_tolak)
                                        <div class="small text-danger">{{ $p->alasan_tolak }}</div>
                                    @endif
                                </td>
                                <td class="text-end text-nowrap">
                                    @if ($p->bukti_path)
                                        <a href="{{ route('portal.bukti', $p->id) }}" target="_blank"
                                            class="btn btn-sm btn-outline-secondary">Bukti</a>
                                    @endif
                                    @if ($p->status === 'terverifikasi')
                                        <a href="{{ route('portal.kwitansi', $p->id) }}" target="_blank"
                                            class="btn btn-sm btn-outline-primary">Kwitansi</a>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-body-secondary py-4">Belum ada pembayaran.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endunless

    {{-- Modal upload bukti --}}
    @if ($uploadTagihanId)
        <div class="modal-backdrop fade show"></div>
        <div class="modal fade show d-block" tabindex="-1" style="overflow-y: auto;" wire:keydown.escape="tutupUpload">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" wire:submit="kirim">
                    <div class="modal-header">
                        <h5 class="modal-title">Upload Bukti Pembayaran</h5>
                        <button type="button" class="btn-close" wire:click="tutupUpload"></button>
                    </div>
                    <div class="modal-body">
                        @error('form')
                            <div class="alert alert-danger py-2">{{ $message }}</div>
                        @enderror

                        <div class="alert alert-info py-2 small">
                            Sisa yang bisa dibayar: <strong>Rp
                                {{ number_format((float) $sisa, 0, ',', '.') }}</strong>.
                            Pembayaran baru berlaku setelah diverifikasi Bagian Keuangan.
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
                                <label class="form-label">Tanggal bayar <span class="text-danger">*</span></label>
                                <input type="date" wire:model="tanggal_bayar" max="{{ now()->format('Y-m-d') }}"
                                    class="form-control @error('tanggal_bayar') is-invalid @enderror">
                                @error('tanggal_bayar')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Referensi / berita transfer</label>
                            <input type="text" wire:model="referensi"
                                class="form-control @error('referensi') is-invalid @enderror">
                            @error('referensi')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Bukti transfer (JPG, PNG, PDF, maks. 2 MB) <span
                                    class="text-danger">*</span></label>
                            <input type="file" wire:model="bukti" accept=".jpg,.jpeg,.png,.pdf"
                                class="form-control @error('bukti') is-invalid @enderror">
                            <div wire:loading wire:target="bukti" class="form-text">Mengunggah...</div>
                            @error('bukti')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="tutupUpload">Batal</button>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled"
                            wire:target="kirim,bukti">Kirim Bukti</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
