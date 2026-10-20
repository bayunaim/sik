<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>

    <link href="https://cdn.jsdelivr.net/npm/@coreui/coreui@5.2.0/dist/css/coreui.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@coreui/icons@3.0.1/css/free.min.css" rel="stylesheet">
    <style>
        .sidebar-nav .nav-icon[class*="cil-"] {
            font-size: 1.1rem;
            line-height: 1;
        }

        .sidebar-nav .nav-icon-bullet {
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }
    </style>
    @livewireStyles
</head>

<body>
    @include('partials.sidebar')

    <div class="wrapper d-flex flex-column min-vh-100">
        <header class="header header-sticky p-0 mb-4">
            <div class="container-fluid px-4 border-bottom">
                <button class="header-toggler" type="button"
                    onclick="coreui.Sidebar.getInstance(document.querySelector('#sidebar')).toggle()"
                    style="margin-inline-start: -14px;">
                    <i class="cil-menu fs-4"></i>
                </button>

                <ul class="header-nav ms-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link py-0 pe-0" data-coreui-toggle="dropdown" href="#" role="button"
                            aria-expanded="false">
                            <span class="me-2 text-end d-none d-sm-inline-block lh-sm">
                                <span class="d-block fw-semibold">{{ auth()->user()->name }}</span>
                                <small class="text-body-secondary">{{ auth()->user()->getRoleNames()->first() }}</small>
                            </span>
                            <span class="avatar avatar-md bg-primary text-white">
                                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                            </span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end pt-0">
                            <div class="dropdown-header bg-body-tertiary fw-semibold my-2">Akun</div>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="dropdown-item">
                                    <i class="cil-account-logout me-2"></i> Keluar
                                </button>
                            </form>
                        </div>
                    </li>
                </ul>
            </div>
        </header>

        <div class="body flex-grow-1">
            <div class="container-lg px-4">
                {{ $slot }}
            </div>
        </div>

        <footer class="footer px-4">
            <div>{{ config('app.name') }} &copy; {{ date('Y') }}</div>
        </footer>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/@coreui/coreui@5.2.0/dist/js/coreui.bundle.min.js"></script>
    @livewireScripts
</body>

</html>
