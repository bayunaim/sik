<div class="card shadow-sm">
    <div class="card-body p-4">
        <h4 class="mb-1">{{ config('app.name') }}</h4>
        <p class="text-muted small mb-4">Sistem Informasi Keuangan Kampus</p>

        <form wire:submit="login">
            <div class="mb-3">
                <label class="form-label">Email atau NIM</label>
                <input type="text" wire:model="login" autofocus autocomplete="username"
                    class="form-control @error('login') is-invalid @enderror">
                @error('login')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" wire:model="password"
                    class="form-control @error('password') is-invalid @enderror">
                @error('password')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-check mb-3">
                <input type="checkbox" wire:model="remember" class="form-check-input" id="remember">
                <label class="form-check-label" for="remember">Ingat saya</label>
            </div>

            <button type="submit" class="btn btn-primary w-100" wire:loading.attr="disabled">
                <span wire:loading.remove>Masuk</span>
                <span wire:loading>Memproses...</span>
            </button>
        </form>
    </div>
</div>
