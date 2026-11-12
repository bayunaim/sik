<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Jurnal Umum</h4>
        @can('jurnal.penyesuaian')
            <button class="btn btn-primary" wire:click="tambah">
                <i class="cil-plus me-1"></i> Jurnal Penyesuaian
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
        <div class="card-header d-flex gap-2 flex-wrap align-items-center">
            <input type="text" class="form-control" style="max-width: 280px;"
                placeholder="Cari nomor atau keterangan..." wire:model.live.debounce.400ms="cari">
            <input type="date" class="form-control" style="max-width: 170px;" wire:model.live="dari"
                title="Dari tanggal">
            <input type="date" class="form-control" style="max-width: 170px;" wire:model.live="sampai"
                title="Sampai tanggal">
        </div>

        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 40px;"></th>
                        <th>Nomor</th>
                        <th>Tanggal</th>
                        <th>Keterangan</th>
                        <th>Jenis</th>
                        <th class="text-end">Debit</th>
                        <th class="text-end">Kredit</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($data as $row)
                        <tr wire:key="jr-{{ $row->id }}" wire:click="toggle({{ $row->id }})"
                            style="cursor: pointer;">
                            <td>{{ $bukaId === $row->id ? '▾' : '▸' }}</td>
                            <td class="fw-semibold text-nowrap">{{ $row->nomor }}</td>
                            <td class="text-nowrap">{{ $row->tanggal->format('d/m/Y') }}</td>
                            <td>{{ $row->keterangan }}</td>
                            <td>
                                <span
                                    class="badge {{ $row->jenis === 'pembalik' ? 'bg-warning text-dark' : ($row->jenis === 'penyesuaian' ? 'bg-info' : 'bg-secondary') }}">
                                    {{ ucfirst($row->jenis) }}
                                </span>
                            </td>
                            <td class="text-end">Rp {{ number_format($row->total_debit, 0, ',', '.') }}</td>
                            <td class="text-end">Rp {{ number_format($row->total_kredit, 0, ',', '.') }}</td>
                        </tr>
                        @if ($bukaId === $row->id)
                            <tr wire:key="jr-d-{{ $row->id }}" class="table-light">
                                <td></td>
                                <td colspan="6" class="p-0">
                                    <table class="table table-sm mb-0">
                                        <thead>
                                            <tr>
                                                <th>Akun</th>
                                                <th class="text-end">Debit</th>
                                                <th class="text-end">Kredit</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($row->detail as $d)
                                                <tr>
                                                    <td
                                                        style="padding-left: {{ (float) $d->kredit > 0 ? '2rem' : '0.5rem' }};">
                                                        {{ $d->akun?->kode }} - {{ $d->akun?->nama }}
                                                    </td>
                                                    <td class="text-end">
                                                        {{ (float) $d->debit > 0 ? 'Rp ' . number_format($d->debit, 0, ',', '.') : '' }}
                                                    </td>
                                                    <td class="text-end">
                                                        {{ (float) $d->kredit > 0 ? 'Rp ' . number_format($d->kredit, 0, ',', '.') : '' }}
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-body-secondary py-4">Belum ada jurnal.</td>
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
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <form class="modal-content" wire:submit="simpan">
                    <div class="modal-header">
                        <h5 class="modal-title">Jurnal Penyesuaian</h5>
                        <button type="button" class="btn-close" wire:click="tutupForm"></button>
                    </div>

                    <div class="modal-body">
                        @error('form')
                            <div class="alert alert-danger py-2">{{ $message }}</div>
                        @enderror

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                                <input type="date" wire:model="tanggal"
                                    class="form-control @error('tanggal') is-invalid @enderror">
                                @error('tanggal')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="col-md-8 mb-3">
                                <label class="form-label">Alasan penyesuaian <span class="text-danger">*</span></label>
                                <input type="text" wire:model="alasan" maxlength="200"
                                    class="form-control @error('alasan') is-invalid @enderror"
                                    placeholder="Mis. koreksi salah klasifikasi akun beban">
                                @error('alasan')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <table class="table table-sm align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Akun</th>
                                    <th style="width: 150px;" class="text-end">Debit</th>
                                    <th style="width: 150px;" class="text-end">Kredit</th>
                                    <th style="width: 40px;"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($baris as $i => $b)
                                    <tr wire:key="pb-{{ $i }}">
                                        <td>
                                            <select wire:model="baris.{{ $i }}.akun_id"
                                                class="form-select form-select-sm @error("baris.$i.akun_id") is-invalid @enderror">
                                                <option value="">- Pilih akun -</option>
                                                @foreach ($akun as $a)
                                                    <option value="{{ $a->id }}">{{ $a->kode }} -
                                                        {{ $a->nama }}</option>
                                                @endforeach
                                            </select>
                                            @error("baris.$i.akun_id")
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </td>
                                        <td>
                                            <input type="text" inputmode="decimal"
                                                wire:model.live.debounce.300ms="baris.{{ $i }}.debit"
                                                class="form-control form-control-sm text-end @error("baris.$i.debit") is-invalid @enderror">
                                            @error("baris.$i.debit")
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </td>
                                        <td>
                                            <input type="text" inputmode="decimal"
                                                wire:model.live.debounce.300ms="baris.{{ $i }}.kredit"
                                                class="form-control form-control-sm text-end @error("baris.$i.kredit") is-invalid @enderror">
                                            @error("baris.$i.kredit")
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </td>
                                        <td>
                                            @if (count($baris) > 2)
                                                <button type="button" class="btn btn-sm btn-link text-danger p-0"
                                                    wire:click="hapusBaris({{ $i }})"
                                                    title="Hapus baris">&times;</button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="fw-semibold">
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-secondary"
                                            wire:click="tambahBaris">+ Baris</button>
                                    </td>
                                    <td class="text-end">Rp
                                        {{ number_format((float) (string) $total['debit'], 0, ',', '.') }}</td>
                                    <td class="text-end">Rp
                                        {{ number_format((float) (string) $total['kredit'], 0, ',', '.') }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>

                        <div class="small {{ $total['balance'] ? 'text-success' : 'text-danger' }}">
                            {{ $total['balance'] ? 'Balance: debit sama dengan kredit.' : 'Belum balance: total debit harus sama dengan total kredit.' }}
                        </div>
                        <div class="small text-body-secondary mt-1">
                            Jurnal yang sudah disimpan tidak dapat diubah atau dihapus; koreksi lewat jurnal pembalik.
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary"
                            wire:click="tutupForm">Batal</button>
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">Simpan
                            Jurnal</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
