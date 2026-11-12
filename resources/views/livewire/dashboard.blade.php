<div>
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0">Dashboard</h4>
            <div class="text-body-secondary small">
                Halo, {{ auth()->user()->name }}.
                @if ($tahun)
                    Tahun anggaran aktif: <strong>{{ $tahun->nama }}</strong>.
                @endif
            </div>
        </div>
        <div class="text-body-secondary">{{ $bulanIni }}</div>
    </div>

    @unless ($tahun)
        <div class="alert alert-warning">Belum ada tahun anggaran aktif. Aktifkan satu di <strong>Data Master → Tahun
                Anggaran</strong>.</div>
    @endunless

    @php $rp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.'); @endphp

    <div class="row g-3 mb-3">
        @can('pembayaran.lihat')
            <div class="col-md-6 col-xl-3">
                <div class="card border-top-success border-top-3 h-100">
                    <div class="card-body">
                        <div class="text-body-secondary small">Pembayaran terverifikasi bulan ini
                            ({{ $bayarLunas->jumlah ?? 0 }})</div>
                        <div class="fs-5 fw-semibold">{{ $rp($bayarLunas->total ?? 0) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="card border-top-warning border-top-3 h-100">
                    <div class="card-body">
                        <div class="text-body-secondary small">Pembayaran pending bulan ini
                            ({{ $bayarPending->jumlah ?? 0 }})</div>
                        <div class="fs-5 fw-semibold">{{ $rp($bayarPending->total ?? 0) }}</div>
                    </div>
                </div>
            </div>
        @endcan

        @can('pengeluaran.lihat')
            <div class="col-md-6 col-xl-3">
                <div class="card border-top-danger border-top-3 h-100">
                    <div class="card-body">
                        <div class="text-body-secondary small">Pengeluaran disetujui bulan ini</div>
                        <div class="fs-5 fw-semibold">{{ $rp($keluarBulanIni) }}</div>
                    </div>
                </div>
            </div>
        @endcan

        @can('tagihan.lihat')
            <div class="col-md-6 col-xl-3">
                <div class="card border-top-primary border-top-3 h-100">
                    <div class="card-body">
                        <div class="text-body-secondary small">Total tagihan tahun aktif</div>
                        <div class="fs-5 fw-semibold">{{ $rp($totalTagihan) }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6 col-xl-3">
                <div class="card border-top-danger border-top-3 h-100">
                    <div class="card-body">
                        <div class="text-body-secondary small">Total tunggakan
                            ({{ number_format($jumlahTunggakan, 0, ',', '.') }} tagihan)</div>
                        <div class="fs-5 fw-semibold text-danger">{{ $rp($totalTunggakan) }}</div>
                    </div>
                </div>
            </div>
        @endcan

        @if (isset($anggaranK))
            <div class="col-md-12 col-xl-6">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <span class="text-body-secondary small">Penggunaan anggaran pengeluaran</span>
                            <span class="fw-semibold">{{ number_format($persenPakai, 1, ',', '.') }}%</span>
                        </div>
                        <div class="progress my-2" style="height: 10px;">
                            <div class="progress-bar {{ $persenPakai >= 90 ? 'bg-danger' : 'bg-primary' }}"
                                style="width: {{ $persenPakai }}%"></div>
                        </div>
                        <div class="small text-body-secondary">
                            Terpakai {{ $rp($terpakai) }} dari {{ $rp($anggaranK) }}
                            | Target penerimaan {{ $rp($anggaranD) }}
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- FR-102 --}}
    @if (isset($tren) || isset($tunggakanProdi))
        <div class="row g-3 mb-3">
            @isset($tren)
                <div class="{{ isset($tunggakanProdi) ? 'col-lg-7' : 'col-12' }}">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span class="fw-semibold">Tren penerimaan {{ count($tren['bulan']) }} bulan terakhir</span>
                            <span class="small text-body-secondary">Total {{ $rp($tren['total']) }}</span>
                        </div>
                        <div class="card-body">
                            @if ($tren['kosong'])
                                <div class="text-body-secondary py-4 text-center">Belum ada penerimaan pada periode ini.
                                </div>
                            @else
                                <div class="d-flex align-items-stretch gap-1" style="height: 210px;" role="img"
                                    aria-label="Grafik batang penerimaan per bulan">
                                    @foreach ($tren['bulan'] as $b)
                                        <div class="flex-fill d-flex flex-column text-center" style="min-width: 0;"
                                            title="{{ $b['label'] }}{{ $b['berjalan'] ? ' (bulan berjalan)' : '' }}: {{ $rp($b['nilai']) }}">
                                            <div class="text-body-secondary text-nowrap"
                                                style="font-size: .65rem; height: 16px;">{{ $b['singkat'] }}</div>
                                            <div class="flex-grow-1 d-flex align-items-end" style="min-height: 0;">
                                                <div class="w-100 bg-primary rounded-top {{ $b['berjalan'] ? 'opacity-75' : '' }}"
                                                    style="height: {{ $b['persen'] }}%;"></div>
                                            </div>
                                            <div class="text-body-secondary border-top text-nowrap"
                                                style="font-size: .65rem;">{{ $b['label'] }}</div>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="small text-body-secondary mt-2">
                                    Penerimaan terverifikasi dan non-mahasiswa. Bulan berjalan (warna lebih pudar) belum
                                    final. jt = juta, M = miliar.
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endisset

            @isset($tunggakanProdi)
                <div class="{{ isset($tren) ? 'col-lg-5' : 'col-12' }}">
                    <div class="card h-100">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span class="fw-semibold">Komposisi tunggakan per prodi</span>
                            <span class="small text-body-secondary">Total {{ $rp($tunggakanProdi['total']) }}</span>
                        </div>
                        <div class="card-body">
                            @forelse ($tunggakanProdi['item'] as $i)
                                <div class="mb-3" title="{{ $i['nama'] }}">
                                    <div class="d-flex justify-content-between small">
                                        <span><strong>{{ $i['kode'] }}</strong>
                                            <span
                                                class="text-body-secondary">{{ number_format($i['jumlah'], 0, ',', '.') }}
                                                tagihan</span></span>
                                        <span>{{ $rp($i['nilai']) }}
                                            ({{ number_format($i['persen'], 1, ',', '.') }}%)
                                        </span>
                                    </div>
                                    <div class="progress" style="height: 8px;" role="progressbar"
                                        aria-valuenow="{{ $i['persen'] }}" aria-valuemin="0" aria-valuemax="100"
                                        aria-label="Tunggakan {{ $i['kode'] }}">
                                        <div class="progress-bar bg-danger" style="width: {{ $i['persen'] }}%"></div>
                                    </div>
                                </div>
                            @empty
                                <div class="text-body-secondary py-4 text-center">Tidak ada tunggakan.</div>
                            @endforelse
                            @if (!$tunggakanProdi['kosong'])
                                <div class="small text-body-secondary">Tagihan belum lunas dan tidak dibatalkan. Persen
                                    terhadap total tunggakan.</div>
                            @endif
                        </div>
                    </div>
                </div>
            @endisset
        </div>
    @endif

    {{-- FR-101 --}}
    <div class="row g-3">
        @can('pembayaran.verifikasi')
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span class="fw-semibold">Menunggu verifikasi ({{ $totalMenungguVerifikasi }})</span>
                        <a href="{{ route('pembayaran.index') }}" class="small">Lihat semua</a>
                    </div>
                    <ul class="list-group list-group-flush">
                        @forelse ($menungguVerifikasi as $p)
                            <li class="list-group-item d-flex justify-content-between">
                                <span>{{ $p->mahasiswa?->nama }}
                                    <span class="small text-body-secondary d-block">{{ $p->tagihan?->komponen?->nama }} |
                                        {{ $p->tanggal_bayar->format('d/m/Y') }}</span>
                                </span>
                                <span class="fw-semibold">{{ $rp($p->nominal) }}</span>
                            </li>
                        @empty
                            <li class="list-group-item text-body-secondary">Tidak ada yang menunggu.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        @endcan

        @can('pengeluaran.approve')
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span class="fw-semibold">Menunggu persetujuan ({{ $totalMenungguApproval }})</span>
                        <a href="{{ route('pengeluaran.index', ['filterStatus' => 'pending']) }}" class="small">Lihat
                            semua</a>
                    </div>
                    <ul class="list-group list-group-flush">
                        @forelse ($menungguApproval as $p)
                            <li class="list-group-item d-flex justify-content-between">
                                <span>{{ $p->keperluan }}
                                    <span class="small text-body-secondary d-block">{{ $p->nomor }} |
                                        {{ $p->pengaju?->name }}</span>
                                </span>
                                <span class="fw-semibold">{{ $rp($p->nominal) }}</span>
                            </li>
                        @empty
                            <li class="list-group-item text-body-secondary">Tidak ada yang menunggu.</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        @endcan
    </div>

    @if (auth()->user()->hasRole('Mahasiswa'))
        <div class="alert alert-info">Portal tagihan dan pembayaran mahasiswa sedang disiapkan.</div>
    @endif
</div>
