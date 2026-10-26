<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Penerimaan Dana</h4>
        @can('penerimaan.input')
            <button class="btn btn-primary" wire:click="tambah">
                <i class="cil-plus me-1"></i> Catat Penerimaan Non-Mahasiswa
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
            <select class="form-select" style="max-width: 200px;" wire:model.live="filterSumber">
                <option value="">Semua sumber</option>
                <option value="mahasiswa">Mahasiswa</option>
                @foreach (\App\Livewire\Penerimaan\Index::SUMBER as $nilai => $label)
                    <option value="{{ $nilai }}">{{ $label }}</option>
                @endforeach
            </select>
            <input type="date" class="form-control" style="max-width: 170px;" wire:model.live="dari"
                title="Dari tanggal">
            <input type="date" class="form-control" style="max-width: 170px;" wire:model.live="sampai"
                title="Sampai tanggal">
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Tanggal</th>
                        <th>Sumber</th>
                        <th>Kode Anggaran</th>
                        <th>Keterangan</th>
                        <th class="text-end">Nominal</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data as $row)
                        <tr wire:key="pn-{{ $row->id }}">
                            <td class="text-nowrap">{{ $row->tanggal->format('d/m/Y') }}</td>
                            <td>
                                <span
                                    class="badge {{ $row->sumber === 'mahasiswa' ? 'bg-primary' : 'bg-info' }}">{{ ucfirst($row->sumber) }}</span>
                            </td>
                            <td>{{ $row->anggaran?->kode }} - {{ $row->anggaran?->nama }}</td>
                            <td>
                                {{ $row->keterangan }}
                                @if ($row->pembayaran?->mahasiswa)
                                    <div class="small text-body-secondary">{{ $row->pembayaran->mahasiswa->nama }}
                                        ({{ $row->pembayaran->mahasiswa->nim }})</div>
                                @endif
                            </td>
                            <td class="text-end fw-semibold">Rp {{ number_format($row->nominal, 0, ',', '.') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-body-secondary py-4">Belum ada penerimaan.</td>
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
        <div class="modal fade show d-block" tabindex="-1" style="overflow-y: auto;" wire:keydown.escape="tutupForm">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" wire:submit="simpan">
                    <div class="modal-header">
                        <h5 class="modal-title">Catat Penerimaan Non-Mahasiswa</h5>
                        <button type="button" class="btn-close" wire:click="tutupForm"></button>
                    </div>

                    <div class="modal-body">
                        @error('form')
                            <div class="alert alert-danger py-2">{{ $message }}</div>
                        @enderror

                        <div class="mb-3">
                            <label class="form-label">Sumber <span class="text-danger">*</span></label>
                            <select wire:model="sumber" class="form-select @error('sumber') is-invalid @enderror">
                                @foreach (\App\Livewire\Penerimaan\Index::SUMBER as $nilai => $label)
                                    <option value="{{ $nilai }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('sumber')
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
                                <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                                <input type="date" wire:model="tanggal" max="{{ now()->format('Y-m-d') }}"
                                    class="form-control @error('tanggal') is-invalid @enderror">
                                @error('tanggal')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Nominal (Rp) <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0" wire:model="nominal"
                                    class="form-control @error('nominal') is-invalid @enderror">
                                @error('nominal')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Diterima lewat <span class="text-danger">*</span></label>
                            <select wire:model="metode" class="form-select">
                                <option value="Transfer Bank">Transfer Bank</option>
                                <option value="Tunai">Tunai</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Keterangan <span class="text-danger">*</span></label>
                            <input type="text" wire:model="keterangan" placeholder="Contoh: Hibah dari PT Maju Jaya"
                                class="form-control @error('keterangan') is-invalid @enderror">
                            @error('keterangan')
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
