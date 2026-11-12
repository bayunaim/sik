<li class="nav-item dropdown" x-data="{ open: false }" @click.outside="open = false" wire:poll.30s>
    <a class="nav-link position-relative" href="#" role="button" aria-label="Notifikasi"
        :aria-expanded="open" @click.prevent="open = !open">
        <i class="cil-bell fs-5"></i>
        @if ($jumlah > 0)
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                style="margin-left: -0.6rem; margin-top: 0.6rem;">
                {{ $jumlah > 99 ? '99+' : $jumlah }}
                <span class="visually-hidden">belum dibaca</span>
            </span>
        @endif
    </a>

    <div class="dropdown-menu dropdown-menu-end p-0" :class="{ 'show': open }"
        style="position: absolute; top: 100%; right: 0; left: auto; width: 24rem; max-width: 92vw;">
        <div class="d-flex align-items-center justify-content-between bg-body-tertiary px-3 py-2 border-bottom">
            <span class="fw-semibold">Notifikasi</span>
            @if ($jumlah > 0)
                <button type="button" class="btn btn-link btn-sm p-0" wire:click="tandaiSemua">Tandai semua dibaca</button>
            @endif
        </div>

        <div style="max-height: 24rem; overflow-y: auto;">
            @forelse ($daftar as $n)
                <button type="button" wire:key="notif-{{ $n->id }}" wire:click="buka('{{ $n->id }}')"
                    class="dropdown-item d-block text-wrap py-2 border-bottom {{ $n->read_at ? '' : 'bg-body-tertiary' }}">
                    <div class="{{ $n->read_at ? '' : 'fw-semibold' }}">{{ $n->data['judul'] ?? 'Notifikasi' }}</div>
                    <div class="small text-body-secondary">{{ $n->data['pesan'] ?? '' }}</div>
                    <div class="small text-body-secondary">{{ $n->created_at->diffForHumans() }}</div>
                </button>
            @empty
                <div class="px-3 py-4 text-center text-body-secondary small">Belum ada notifikasi.</div>
            @endforelse
        </div>
    </div>
</li>
