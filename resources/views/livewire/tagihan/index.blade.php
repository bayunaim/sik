<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Tagihan Mahasiswa</h4>
        @can('tagihan.buat')
            <button class="btn btn-primary" wire:click="tambah">
                <i class="cil-plus me-1"></i> Buat Tagihan
            </button>
        @endcan
    </div>

    @if (session('sukses'))
        <div class="alert alert-success alert-dismissible fade show">
            {{ session('sukses') }}
            <button type="button" class="btn-close" data-coreui-dismiss="alert"></button>
        </div>
    @endif

    <div class="card">
        <div class="card-header d-flex gap-2 flex-wrap">
            <input type="text" class="form-control" style="max-width: 260px;"
                placeholder="Cari nomor, NIM, atau nama..." wire:model.live.debounce.400ms="cari">
            <select class="form-select" style="max-width: 170px;" wire:model.live="filterStatus">
                <option value="">Semua status</option>
                @foreach (\App\Models\Tagihan::STATUS as $nilai => $label)
                    <option value="{{ $nilai }}">{{ $label }}</option>
                @endforeach
                <option value="batal">Dibatalkan</option>
            </select>
            <select class="form-select" style="max-width: 220px;" wire:model.live="filterKomponen">
                <option value="">Semua komponen</option>
                @foreach ($daftarKomponen as $k)
                    <option value="{{ $k->id }}">{{ $k->kode }} - {{ $k->nama }}</option>
                @endforeach
            </select>
            <select class="form-select" style="max-width: 170px;" wire:model.live="filterTahunAjaran">
                <option value="">Semua tahun ajaran</option>
                @foreach ($daftarTahunAjaran as $ta)
                    <option value="{{ $ta }}">{{ $ta }}</option>
                @endforeach
            </select>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Nomor</th>
                        <th>Mahasiswa</th>
                        <th>Komponen</th>
                        <th>Periode</th>
                        <th class="text-end">Nominal</th>
                        <th class="text-end">Terbayar</th>
                        <th>Jatuh Tempo</th>
                        <th>Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data as $row)
                        @php
                            $batal = $row->trashed();
                            $terlambat = !$batal && $row->status !== 'lunas' && $row->jatuh_tempo->isPast();
                            $warna = match ($row->status) {
                                'lunas' => 'success',
                                'sebagian' => 'info',
                                'pending' => 'warning',
                                default => 'secondary',
                            };
                        @endphp
                        <tr wire:key="tgh-{{ $row->id }}" class="{{ $batal ? 'text-body-secondary' : '' }}">
                            <td class="fw-semibold text-nowrap">{{ $row->nomor }}</td>
                            <td>
                                {{ $row->mahasiswa?->nama }}
                                <div class="small text-body-secondary">{{ $row->mahasiswa?->nim }}</div>
                            </td>
                            <td>{{ $row->komponen?->nama }}</td>
                            <td class="text-nowrap">{{ $row->semester }} {{ $row->tahun_ajaran }}</td>
                            <td class="text-end">Rp {{ number_format($row->nominal, 0, ',', '.') }}</td>
                            <td class="text-end">Rp {{ number_format($row->terbayar, 0, ',', '.') }}</td>
                            <td class="text-nowrap {{ $terlambat ? 'text-danger fw-semibold' : '' }}">
                                {{ $row->jatuh_tempo->format('d/m/Y') }}
                            </td>
                            <td>
                                @if ($batal)
                                    <span class="badge bg-dark" title="{{ $row->alasan_batal }}">Dibatalkan</span>
                                @else
                                    <span
                                        class="badge bg-{{ $warna }}">{{ \App\Models\Tagihan::STATUS[$row->status] ?? $row->status }}</span>
                                @endif
                            </td>
                            <td class="text-end text-nowrap">
                                @can('tagihan.batalkan')
                                    @if (!$batal && $row->status === 'belum_bayar')
                                        <button class="btn btn-sm btn-outline-danger"
                                            wire:click="konfirmasiBatal({{ $row->id }})">Batalkan</button>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-body-secondary py-4">Belum ada tagihan.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($data->hasPages())
            <div class="card-footer">{{ $data->links() }}</div>
        @endif
    </div>

    {{-- Modal buat tagihan --}}
    @if ($tampilForm)
        <div class="modal-backdrop fade show"></div>
        <div class="modal fade show d-block" tabindex="-1" style="overflow-y: auto;" wire:keydown.escape="tutupForm">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" wire:submit="simpan">
                    <div class="modal-header">
                        <h5 class="modal-title">Buat Tagihan</h5>
                        <button type="button" class="btn-close" wire:click="tutupForm"></button>
                    </div>

                    <div class="modal-body">
                        @error('form')
                            <div class="alert alert-danger py-2">{{ $message }}</div>
                        @enderror

                        {{-- Mahasiswa --}}
                        <div class="mb-3">
                            <label class="form-label">Mahasiswa <span class="text-danger">*</span></label>
                            @if ($mahasiswa_id !== '')
                                <div class="input-group">
                                    <input type="text" class="form-control" value="{{ $labelMahasiswa }}" readonly>
                                    <button type="button" class="btn btn-outline-secondary"
                                        wire:click="gantiMahasiswa">Ganti</button>
                                </div>
                            @else
                                <input type="text" wire:model.live.debounce.300ms="cariMhs" autocomplete="off"
                                    placeholder="Ketik NIM atau nama (min. 2 huruf)..."
                                    class="form-control @error('mahasiswa_id') is-invalid @enderror">
                                @if ($hasilMhs->isNotEmpty())
                                    <div class="list-group mt-1">
                                        @foreach ($hasilMhs as $m)
                                            <button type="button" class="list-group-item list-group-item-action"
                                                wire:click="pilihMahasiswa({{ $m->id }})"
                                                wire:key="cm-{{ $m->id }}">
                                                <span class="fw-semibold">{{ $m->nim }}</span> -
                                                {{ $m->nama }}
                                                <span class="small text-body-secondary">
                                                    ({{ $m->programStudi?->kode }}, {{ $m->angkatan }},
                                                    {{ $m->status_akademik }})
                                                </span>
                                            </button>
                                        @endforeach
                                    </div>
                                @elseif (strlen($cariMhs) >= 2)
                                    <div class="form-text">Tidak ada mahasiswa yang cocok.</div>
                                @endif
                                @error('mahasiswa_id')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            @endif
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Komponen Biaya <span class="text-danger">*</span></label>
                            <select wire:model.live="komponen_biaya_id"
                                class="form-select @error('komponen_biaya_id') is-invalid @enderror">
                                <option value="">- Pilih komponen -</option>
                                @foreach ($daftarKomponen as $k)
                                    <option value="{{ $k->id }}">{{ $k->kode }} - {{ $k->nama }}
                                    </option>
                                @endforeach
                            </select>
                            @error('komponen_biaya_id')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Kode Anggaran Penerimaan <span
                                    class="text-danger">*</span></label>
                            <select wire:model="anggaran_id"
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

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Semester <span class="text-danger">*</span></label>
                                <select wire:model="semester"
                                    class="form-select @error('semester') is-invalid @enderror">
                                    @foreach (\App\Models\Tagihan::SEMESTER as $s)
                                        <option value="{{ $s }}">{{ $s }}</option>
                                    @endforeach
                                </select>
                                @error('semester')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Tahun Ajaran <span class="text-danger">*</span></label>
                                <input type="text" wire:model="tahun_ajaran" placeholder="2025/2026"
                                    class="form-control @error('tahun_ajaran') is-invalid @enderror">
                                @error('tahun_ajaran')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Nominal (Rp)</label>
                            @can('tagihan.ubah-nominal')
                                <input type="number" step="0.01" min="0" wire:model="nominal"
                                    class="form-control @error('nominal') is-invalid @enderror">
                            @else
                                <input type="text" class="form-control" readonly
                                    value="{{ $nominal !== '' ? 'Rp ' . number_format((float) $nominal, 0, ',', '.') : '' }}">
                            @endcan
                            @error('nominal')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                            @if ($infoTarif)
                                <div class="form-text">{{ $infoTarif }}</div>
                            @endif
                        </div>

                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Tanggal Tagihan <span class="text-danger">*</span></label>
                                <input type="date" wire:model="tanggal_tagihan"
                                    class="form-control @error('tanggal_tagihan') is-invalid @enderror">
                                @error('tanggal_tagihan')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Jatuh Tempo <span class="text-danger">*</span></label>
                                <input type="date" wire:model="jatuh_tempo"
                                    class="form-control @error('jatuh_tempo') is-invalid @enderror">
                                @error('jatuh_tempo')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Metode Bayar</label>
                            <select wire:model="metode_bayar" class="form-select">
                                <option value="">- Tidak ditentukan -</option>
                                <option value="Transfer Bank">Transfer Bank</option>
                                <option value="Tunai">Tunai</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Keterangan</label>
                            <input type="text" wire:model="keterangan"
                                class="form-control @error('keterangan') is-invalid @enderror">
                            @error('keterangan')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="tutupForm">Batal</button>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled"
                            wire:target="simpan">Terbitkan</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Modal pembatalan --}}
    @if ($batalId)
        <div class="modal-backdrop fade show"></div>
        <div class="modal fade show d-block" tabindex="-1" wire:keydown.escape="tutupBatal">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" wire:submit="simpanBatal">
                    <div class="modal-header">
                        <h5 class="modal-title">Batalkan Tagihan</h5>
                        <button type="button" class="btn-close" wire:click="tutupBatal"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label">Alasan pembatalan <span class="text-danger">*</span></label>
                        <textarea wire:model="alasanBatal" rows="3" class="form-control @error('alasanBatal') is-invalid @enderror"></textarea>
                        @error('alasanBatal')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">Tagihan tidak dihapus permanen. Alasan tersimpan di audit log.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" wire:click="tutupBatal">Kembali</button>
                        <button type="submit" class="btn btn-danger" wire:loading.attr="disabled"
                            wire:target="simpanBatal">Batalkan Tagihan</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
