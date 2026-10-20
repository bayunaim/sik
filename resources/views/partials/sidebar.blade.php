@php
    $user = auth()->user();
    $href = fn($route) => $route && Route::has($route) ? route($route) : '#';
    $aktif = fn($route) => $route && Route::has($route) && request()->routeIs($route . '*');
    $boleh = fn($item) => empty($item['can']) || $user->can($item['can']);
    $menu = config('menu');
@endphp

<div class="sidebar sidebar-dark sidebar-fixed border-end" id="sidebar">
    <div class="sidebar-header border-bottom">
        <div class="sidebar-brand">
            <span class="sidebar-brand-full fw-bold fs-5"><i class="cil-institution me-2"></i>SIK Kampus</span>
            <span class="sidebar-brand-narrow fw-bold">SIK</span>
        </div>
    </div>

    <ul class="sidebar-nav" data-coreui="navigation">
        @foreach ($menu as $i => $item)
            @if (isset($item['title']))
                @php
                    // tampilkan judul hanya kalau ada item terlihat setelahnya sebelum judul berikutnya
                    $adaIsi = false;
                    for ($j = $i + 1; $j < count($menu) && !isset($menu[$j]['title']); $j++) {
                        if ($boleh($menu[$j])) {
                            $adaIsi = true;
                            break;
                        }
                    }
                @endphp
                @if ($adaIsi)
                    <li class="nav-title">{{ $item['title'] }}</li>
                @endif
                @continue
            @endif

            @continue(!$boleh($item))

            @if (isset($item['children']))
                @php $terbuka = collect($item['children'])->contains(fn ($c) => $aktif($c['route'] ?? null)); @endphp
                <li class="nav-group {{ $terbuka ? 'show' : '' }}">
                    <a class="nav-link nav-group-toggle" href="#">
                        <i class="nav-icon {{ $item['icon'] }}"></i> {{ $item['label'] }}
                    </a>
                    <ul class="nav-group-items compact">
                        @foreach ($item['children'] as $child)
                            <li class="nav-item">
                                <a class="nav-link {{ $aktif($child['route'] ?? null) ? 'active' : '' }}"
                                    href="{{ $href($child['route'] ?? null) }}">
                                    <span class="nav-icon"><span class="nav-icon-bullet"></span></span>
                                    {{ $child['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </li>
            @else
                <li class="nav-item">
                    <a class="nav-link {{ $aktif($item['route'] ?? null) ? 'active' : '' }}"
                        href="{{ $href($item['route'] ?? null) }}">
                        <i class="nav-icon {{ $item['icon'] }}"></i> {{ $item['label'] }}
                    </a>
                </li>
            @endif
        @endforeach
    </ul>

    <div class="sidebar-footer border-top d-none d-md-flex">
        <button class="sidebar-toggler" type="button"
            onclick="coreui.Sidebar.getInstance(document.querySelector('#sidebar')).toggleNarrow()"></button>
    </div>
</div>
