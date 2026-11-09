<div class="card mt-4">
    <div class="card-header">Akun Mahasiswa</div>
    <div class="card-body">
        <p class="mb-2">
            <strong>{{ number_format($sisa, 0, ',', '.') }}</strong> mahasiswa (Aktif/Cuti) belum punya akun login.
            Username = NIM, password awal = NIM, dan wajib diganti saat login pertama.
        </p>

        @if ($hasil)
            <div class="alert alert-success py-2">
                {{ $hasil['dibuat'] }} akun dibuat@if ($hasil['dihubungkan'])
                    , {{ $hasil['dihubungkan'] }} akun lama dihubungkan
                @endif.
            </div>
        @endif

        @if ($sisa > 0)
            <button class="btn btn-primary" wire:click="buat" wire:loading.attr="disabled" wire:target="buat">
                <span wire:loading.remove wire:target="buat">Buat Akun (maks. 100 per klik)</span>
                <span wire:loading wire:target="buat">Membuat akun, mohon tunggu...</span>
            </button>
            <div class="form-text">Klik berulang sampai sisanya 0. Pembuatan akun sengaja dibatasi per batch agar tidak
                timeout.</div>
        @endif
    </div>
</div>
