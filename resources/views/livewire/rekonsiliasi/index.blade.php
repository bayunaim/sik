<div>
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0">Rekonsiliasi Bank</h4>
        <div class="d-flex gap-2 flex-wrap">
            <a href="{{ route('rekonsiliasi.rekening') }}" class="btn btn-outline-secondary">Rekening Bank</a>
            <a href="{{ route('rekonsiliasi.import') }}" class="btn btn-outline-primary">
                <i class="cil-cloud-upload me-1"></i> Import Mutasi
            </a>
            <button class="btn btn-primary" wire:click="cocokOtomatis" wire:loading.attr="disabled"
                wire:target="cocokOtomatis"
                wire:confirm="Cocokkan otomatis semua mutasi Belum Cocok pada rekening dan periode yang dipilih?">
                <span wire:loading.remove wire:target="cocokOtomatis"><i class="cil-sync me-1"></i> Cocokkan Otomatis</span>
                <span wire:loading wire:target="cocokOtomatis">Memproses...</span>
            </button>
        </div>
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
    @if ($hasilOtomatis)
        <div class="alert alert-info alert-dismissible fade show">
            {{ number_format($hasilOtomatis['diproses'], 0, ',', '.') }} mutasi diproses:
            <strong>{{ number_format($hasilOtomatis['cocok'], 0, ',', '.') }} cocok otomatis</strong>,
            {{ number_format($hasilOtomatis['ambigu'], 0, ',', '.') }} ambigu (lebih dari satu kandidat, cocokkan manual),
            {{ number_format($hasilOtomatis['tanpa_kandidat'], 0, ',', '.') }} tanpa kandidat.
            <button type="button" class="btn-close" data-coreui-dismiss="alert"></button>
        </div>
    @endif

    {{-- Ringkasan --}}
    <div class="row g-3 mb-3">
        @foreach ([
            ['Belum Cocok', $ringkasan['belum_cocok'], 'warning'],
            ['Cocok', $ringkasan['cocok'], 'success'],
            ['Diabaikan', $ringkasan['diabaikan'], 'secondary'],
        ] as [$label, $r, $warna])
            <div class="col-6 col-lg-3">
                <div class="card border-top-{{ $warna }} border-top-3 h-100">
                    <div class="card-body">
                        <div class="text-body-secondary small">{{ $label }}</div>
                        <div class="fs-4 fw-semibold">{{ number_format($r['n'], 0, ',', '.') }}</div>
                        <div class="small text-body-secondary">
                            Masuk Rp {{ number_format((float) $r['kredit'], 0, ',', '.') }}<br>
                            Keluar Rp {{ number_format((float) $r['debit'], 0, ',', '.') }}
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
        <div class="col-6 col-lg-3">
            <div class="card border-top-danger border-top-3 h-100">
                <div class="card-body">
                    <div class="text-body-secondary small">Cocok bermasalah</div>
                    <div class="fs-4 fw-semibold">{{ number_format($ringkasan['bermasalah'], 0, ',', '.') }}</div>
                    <div class="small text-body-secondary">Transaksinya sudah dibatalkan atau dihapus. Lepas dan cocokkan ulang.</div>
                </div>
            </div>
        </div>
    </div>

    @if ($sistem['masuk']['n'] > 0 || $sistem['keluar']['n'] > 0)
        <div class="alert alert-light border small">
            Transaksi sistem (non-tunai) yang belum ditemukan di mutasi mana pun pada periode ini:
            <strong>{{ number_format($sistem['masuk']['n'], 0, ',', '.') }} uang masuk</strong>
            (Rp {{ number_format((float) $sistem['masuk']['total'], 0, ',', '.') }}) dan
            <strong>{{ number_format($sistem['keluar']['n'], 0, ',', '.') }} uang keluar</strong>
            (Rp {{ number_format((float) $sistem['keluar']['total'], 0, ',', '.') }}).
            Angka ini mencakup semua rekening karena transaksi belum mencatat rekening tujuannya.
        </div>
    @endif

    <div class="card">
        <div class="card-header d-flex gap-2 flex-wrap align-items-center">
            <select class="form-select" style="max-width: 260px;" wire:model.live="rekeningId">
                <option value="">Semua rekening</option>
                @foreach ($daftarRekening as $r)
                    <option value="{{ $r->id }}">{{ $r->nama_bank }} - {{ $r->nomor }}{{ $r->is_active ? '' : ' (nonaktif)' }}</option>
                @endforeach
            </select>
            <select class="form-select" style="max-width: 170px;" wire:model.live="filterStatus">
                <option value="">Semua status</option>
                @foreach (\App\Models\MutasiBank::STATUS as $nilai => $label)
                    <option value="{{ $nilai }}">{{ $label }}</option>
                @endforeach
                <option value="bermasalah">Cocok bermasalah</option>
            </select>
            <input type="date" class="form-control" style="max-width: 160px;" wire:model.live="dari" title="Dari tanggal">
            <input type="date" class="form-control" style="max-width: 160px;" wire:model.live="sampai" title="Sampai tanggal">
            <input type="text" class="form-control" style="max-width: 240px;" placeholder="Cari keterangan atau referensi..."
                wire:model.live.debounce.400ms="cari">
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Tanggal</th>
                        <th>Rekening</th>
                        <th>Keterangan</th>
                        <th class="text-end">Debit (keluar)</th>
                        <th class="text-end">Kredit (masuk)</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data as $row)
                        @php $masalah = $row->cocokBermasalah(); @endphp
                        <tr wire:key="mut-{{ $row->id }}">
                            <td class="text-nowrap">{{ $row->tanggal->format('d/m/Y') }}</td>
                            <td class="small">{{ $row->rekening?->nama_bank }}<div class="text-body-secondary">{{ $row->rekening?->nomor }}</div></td>
                            <td style="max-width: 360px;">
                                {{ $row->keterangan }}
                                @if ($row->referensi)
                                    <div class="small text-body-secondary">Ref: {{ $row->referensi }}</div>
                                @endif
                            </td>
                            <td class="text-end">{{ $row->isMasuk() ? '' : 'Rp '.number_format((float) $row->debit, 0, ',', '.') }}</td>
                            <td class="text-end">{{ $row->isMasuk() ? 'Rp '.number_format((float) $row->kredit, 0, ',', '.') : '' }}</td>
                            <td>
                                @if ($row->status === 'cocok')
                                    <span class="badge bg-{{ $masalah ? 'danger' : 'success' }}">{{ $masalah ? 'Cocok, bermasalah' : 'Cocok' }}</span>
                                    <div class="small text-body-secondary">
                                        @if ($row->cocok)
                                            {{ $svc->label($row->cocok) }}
                                        @else
                                            Transaksi sudah dihapus
                                        @endif
                                        ({{ $row->cara_cocok }})
                                    </div>
                                    @if ($masalah && $row->cocok)
                                        <div class="small text-danger">Transaksi ini sudah tidak berlaku (dibatalkan).</div>
                                    @endif
                                @elseif ($row->status === 'diabaikan')
                                    <span class="badge bg-secondary">Diabaikan</span>
                                    <div class="small text-body-secondary">{{ $row->alasan_abaikan }}</div>
                                @else
                                    <span class="badge bg-warning text-dark">Belum Cocok</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @if ($row->status === 'belum_cocok')
                                    <button class="btn btn-sm btn-primary" wire:click="bukaCocok({{ $row->id }})">Cocokkan</button>
                                    <button class="btn btn-sm btn-outline-secondary" wire:click="konfirmasiAbaikan({{ $row->id }})">Abaikan</button>
                                @elseif ($row->status === 'cocok')
                                    <button class="btn btn-sm btn-outline-danger" wire:click="lepas({{ $row->id }})"
                                        wire:confirm="Lepas pencocokan mutasi ini?">Lepas</button>
                                @elseif ($row->status === 'diabaikan')
                                    <button class="btn btn-sm btn-outline-secondary" wire:click="batalAbaikan({{ $row->id }})">Batalkan abaikan</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-body-secondary py-4">
                                Tidak ada mutasi. Impor mutasi bank lewat tombol Import Mutasi.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($data->hasPages())
            <div class="card-footer">{{ $data->links() }}</div>
        @endif
    </div>

    {{-- Modal cocokkan manual --}}
    @if ($mutasiCocok)
        <div class="modal-backdrop fade show"></div>
        <div class="modal fade show d-block" tabindex="-1" style="overflow-y: auto;" wire:keydown.escape="tutupCocok">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Cocokkan Mutasi</h5>
                        <button type="button" class="btn-close" wire:click="tutupCocok"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-light border py-2">
                            <div class="small text-body-secondary">
                                {{ $mutasiCocok->tanggal->format('d/m/Y') }} | {{ $mutasiCocok->rekening?->nama_bank }}
                                {{ $mutasiCocok->rekening?->nomor }}
                            </div>
                            <div>{{ $mutasiCocok->keterangan }}</div>
                            <div class="fw-semibold">
                                {{ $mutasiCocok->isMasuk() ? 'Uang masuk' : 'Uang keluar' }}
                                Rp {{ number_format((float) $mutasiCocok->nominal, 0, ',', '.') }}
                            </div>
                        </div>

                        @error('cocok')
                            <div class="alert alert-danger py-2">{{ $message }}</div>
                        @enderror

                        <input type="text" class="form-control mb-2" wire:model.live.debounce.300ms="cariKandidat"
                            placeholder="Cari nama, NIM, nomor kwitansi, atau keperluan...">
                        <div class="form-text mb-2">Hanya transaksi non-tunai dengan nominal yang sama persis dan belum
                            dicocokkan. Diurutkan dari tanggal terdekat.</div>

                        @forelse ($kandidat as $k)
                            <div class="d-flex justify-content-between align-items-center border rounded p-2 mb-2"
                                wire:key="kand-{{ $k['type'] }}-{{ $k['id'] }}">
                                <div>
                                    <div>{{ $k['label'] }}</div>
                                    <div class="small text-body-secondary">{{ $k['tanggal']->format('d/m/Y') }}</div>
                                </div>
                                <button class="btn btn-sm btn-primary"
                                    wire:click="pilih(@js($k['type']), {{ $k['id'] }})"
                                    wire:loading.attr="disabled">Pilih</button>
                            </div>
                        @empty
                            <div class="text-body-secondary text-center py-3">
                                Tidak ada transaksi dengan nominal yang sama. Jika selisihnya biaya admin atau bunga,
                                gunakan Abaikan dan sebutkan alasannya.
                            </div>
                        @endforelse
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="tutupCocok">Tutup</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal abaikan --}}
    @if ($abaikanId)
        <div class="modal-backdrop fade show"></div>
        <div class="modal fade show d-block" tabindex="-1" wire:keydown.escape="tutupAbaikan">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" wire:submit="simpanAbaikan">
                    <div class="modal-header">
                        <h5 class="modal-title">Abaikan Mutasi</h5>
                        <button type="button" class="btn-close" wire:click="tutupAbaikan"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning py-2 small">
                            Gunakan untuk mutasi yang memang tidak punya padanan di sistem, misalnya biaya admin bank atau
                            bunga. Mutasi ini tidak membuat jurnal. Jika perlu dibukukan, buat jurnal penyesuaian terpisah.
                        </div>
                        <label class="form-label">Alasan <span class="text-danger">*</span></label>
                        <textarea wire:model="alasanAbaikan" rows="3" class="form-control @error('alasanAbaikan') is-invalid @enderror"></textarea>
                        @error('alasanAbaikan')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="tutupAbaikan">Kembali</button>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled"
                            wire:target="simpanAbaikan">Abaikan Mutasi</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
