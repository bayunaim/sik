<div class="card shadow-sm">
    <div class="card-body p-4">
        <h4 class="mb-1">Lupa Password</h4>
        <p class="text-muted small mb-4">Masukkan email akun staf Anda untuk menerima tautan atur ulang password.</p>

        @if ($terkirim)
            <div class="alert alert-success">
                Jika email terdaftar sebagai akun staf yang aktif, tautan atur ulang password sudah dikirim. Periksa
                kotak masuk Anda.
            </div>
        @else
            <form wire:submit="kirim">
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" wire:model="email" autofocus
                        class="form-control @error('email') is-invalid @enderror">
                    @error('email')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <button type="submit" class="btn btn-primary w-100" wire:loading.attr="disabled"
                    wire:target="kirim">Kirim Tautan</button>
            </form>
        @endif

        <div class="mt-3 small">
            <a href="{{ route('login') }}">Kembali ke halaman masuk</a>
            <div class="text-muted mt-2">Mahasiswa yang lupa password: hubungi Bagian Keuangan.</div>
        </div>
    </div>
</div>
