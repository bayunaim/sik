<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Jurnal Umum</h4>
    </div>

    <div class="card">
        <div class="card-header d-flex gap-2 flex-wrap align-items-center">
            <input type="text" class="form-control" style="max-width: 280px;" placeholder="Cari nomor atau keterangan..."
                wire:model.live.debounce.400ms="cari">
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
</div>
