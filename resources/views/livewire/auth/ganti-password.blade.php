<div style="max-width: 460px;">
    <h4 class="mb-3">Ganti Password</h4>

    @if (auth()->user()->must_change_password)
        <div class="alert alert-warning">Untuk keamanan akun, ganti password awal Anda sebelum melanjutkan.</div>
    @endif

    <div class="card">
        <form class="card-body" wire:submit="simpan">
            <div class="mb-3">
                <label class="form-label">Password saat ini</label>
                <input type="password" wire:model="password_lama" autocomplete="current-password"
                    class="form-control @error('password_lama') is-invalid @enderror">
                @error('password_lama')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Password baru (minimal 8 karakter)</label>
                <input type="password" wire:model="password" autocomplete="new-password"
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
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="simpan">Simpan
                Password</button>
        </form>
    </div>
</div>
