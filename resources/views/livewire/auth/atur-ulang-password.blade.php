<div class="card shadow-sm">
    <div class="card-body p-4">
        <h4 class="mb-1">Atur Ulang Password</h4>
        <p class="text-muted small mb-4">Buat password baru untuk akun Anda.</p>

        <form wire:submit="simpan">
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" wire:model="email" class="form-control @error('email') is-invalid @enderror"
                    readonly>
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Password baru (minimal 8 karakter)</label>
                <input type="password" wire:model="password" autocomplete="new-password" autofocus
                    class="form-control @error('password') is-invalid @enderror">
                @error('password')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Ulangi password baru</label>
                <input type="password" wire:model="password_confirmation" autocomplete="new-password"
                    class="form-control">
            </div>
            <button type="submit" class="btn btn-primary w-100" wire:loading.attr="disabled"
                wire:target="simpan">Simpan Password</button>
        </form>

        @error('email')
            <div class="mt-3 small"><a href="{{ route('password.request') }}">Minta tautan baru</a></div>
        @enderror
    </div>
</div>
