@php use App\Services\LaporanService as L; @endphp
<div>
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0">{{ $lap['judul'] }}</h4>
            <div class="text-body-secondary small">{{ $lap['periode'] }}</div>
        </div>
        @can('laporan.ekspor')
            <div class="d-flex gap-2">
                <a class="btn btn-outline-danger" target="_blank"
                    href="{{ route('laporan.ekspor', ['jenis' => $jenis, 'format' => 'pdf'] + $filter) }}">Ekspor PDF</a>
                <a class="btn btn-outline-success"
                    href="{{ route('laporan.ekspor', ['jenis' => $jenis, 'format' => 'xlsx'] + $filter) }}">Ekspor Excel</a>
            </div>
        @endcan
    </div>

    {{-- Filter --}}
    <div class="card mb-3">
        <div class="card-body d-flex gap-3 flex-wrap align-items-end">
            @if ($jenis === 'capaian')
                <div>
                    <label class="form-label small mb-1">Tahun anggaran</label>
                    <select class="form-select" wire:model.live="tahun_id">
                        @foreach ($daftarTahun as $t)
                            <option value="{{ $t->id }}">{{ $t->nama }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label small mb-1">Jenis</label>
                    <select class="form-select" wire:model.live="tipe">
                        <option value="">Semua</option>
                        <option value="D">Penerimaan (D)</option>
                        <option value="K">Pengeluaran (K)</option>
                    </select>
                </div>
            @endif

            @if ($jenis === 'tunggakan')
                <div>
                    <label class="form-label small mb-1">Program studi</label>
                    <select class="form-select" wire:model.live="prodi">
                        <option value="">Semua</option>
                        @foreach ($daftarProdi as $p)
                            <option value="{{ $p->id }}">{{ $p->kode }} - {{ $p->nama }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label small mb-1">Angkatan</label>
                    <select class="form-select" wire:model.live="angkatan">
                        <option value="">Semua</option>
                        @foreach ($daftarAngkatan as $a)
                            <option value="{{ $a }}">{{ $a }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label small mb-1">Semester</label>
                    <select class="form-select" wire:model.live="semester">
                        <option value="">Semua</option>
                        @foreach (\App\Models\Tagihan::SEMESTER as $s)
                            <option value="{{ $s }}">{{ $s }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label small mb-1">Tahun ajaran</label>
                    <select class="form-select" wire:model.live="tahun_ajaran">
                        <option value="">Semua</option>
                        @foreach ($daftarTahunAjaran as $ta)
                            <option value="{{ $ta }}">{{ $ta }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div>
                <label
                    class="form-label small mb-1">{{ $jenis === 'tunggakan' ? 'Jatuh tempo dari' : 'Dari tanggal' }}</label>
                <input type="date" class="form-control" wire:model.live="dari">
            </div>
            <div>
                <label
                    class="form-label small mb-1">{{ $jenis === 'tunggakan' ? 'Jatuh tempo sampai' : 'Sampai tanggal' }}</label>
                <input type="date" class="form-control" wire:model.live="sampai">
            </div>
        </div>
    </div>

    @if ($lap['info'])
        <div
            class="alert {{ str_starts_with($lap['info'], 'PERINGATAN') ? 'alert-danger' : 'alert-info' }} py-2 small">
            {{ $lap['info'] }}</div>
    @endif

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        @foreach ($lap['kolom'] as $k)
                            <th class="{{ in_array($k['tipe'], ['uang', 'angka', 'persen']) ? 'text-end' : '' }}">
                                {{ $k['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($halaman as $b)
                        <tr wire:key="lap-{{ $halaman->currentPage() }}-{{ $loop->index }}">
                            @foreach ($lap['kolom'] as $k)
                                <td class="{{ in_array($k['tipe'], ['uang', 'angka', 'persen']) ? 'text-end' : '' }}">
                                    {{ L::format($k['tipe'], $b[$k['key']] ?? null) }}
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($lap['kolom']) }}" class="text-center text-body-secondary py-4">Tidak
                                ada data untuk filter ini.</td>
                        </tr>
                    @endforelse
                </tbody>
                @if (count($lap['total']))
                    <tfoot class="table-light fw-semibold">
                        @foreach ($lap['total'] as $t)
                            <tr>
                                @foreach ($lap['kolom'] as $i => $k)
                                    <td
                                        class="{{ in_array($k['tipe'], ['uang', 'angka', 'persen']) ? 'text-end' : '' }}">
                                        {{ $i === 0 ? $t['label'] : L::format($k['tipe'], $t['nilai'][$k['key']] ?? null) }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tfoot>
                @endif
            </table>
        </div>
        @if ($halaman->hasPages())
            <div class="card-footer">
                {{ $halaman->links() }}
                <div class="small text-body-secondary mt-1">Total di atas mencakup seluruh data, bukan hanya halaman
                    ini.</div>
            </div>
        @endif
    </div>
</div>
