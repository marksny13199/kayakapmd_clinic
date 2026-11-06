<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    {{-- Detailed Comment: Immediate synchronous theme initialization to prevent Flash of Unstyled Content (FOUC) on login screen --}}
    <script>
        (function() {
            try {
                const storedTheme = localStorage.getItem('theme') || 'system';
                const getPreferredTheme = () => {
                    if (storedTheme === 'dark' || storedTheme === 'light') {
                        return storedTheme;
                    }
                    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' :
                        'light';
                };
                document.documentElement.setAttribute('data-bs-theme', getPreferredTheme());
            } catch (e) {
                document.documentElement.setAttribute('data-bs-theme', 'light');
            }
        })();
    </script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }} | Login</title>
    <link rel="icon" type="image/png" href="{{ asset('favicon.ico') }}">

    {{-- Detailed Comment: Dynamic CSS custom variables driven by the active clinic theme configuration for login screen and dark mode --}}
    <style id="login-dynamic-theme-vars">
        :root {
            --app-bg: {{ $activeTheme->app_background ?? '#f8f9fa' }};
            --header-bg: {{ $activeTheme->header_bg ?? '#f4c79f' }};
            --header-text: {{ $activeTheme->header_text_color ?? '#212529' }};
            --header-accent: {{ $activeTheme->header_accent_color ?? '#ffa500' }};
            --primary-btn-bg: {{ $activeTheme->primary_button_bg ?? '#0d6efd' }};
            --primary-btn-text: {{ $activeTheme->primary_button_text ?? '#ffffff' }};
            --secondary-btn-bg: {{ $activeTheme->secondary_button_bg ?? '#6c757d' }};
            --secondary-btn-text: {{ $activeTheme->secondary_button_text ?? '#ffffff' }};
            --body-text: {{ $activeTheme->text_color ?? '#212529' }};
        }

        .login-card {
            background-color: #ffffff;
            color: var(--body-text, #212529);
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-top: 4px solid var(--header-accent, #ffa500);
            transition: background-color 0.2s ease, color 0.2s ease, border-color 0.2s ease;
        }

        .btn-primary {
            background-color: var(--primary-btn-bg) !important;
            border-color: var(--primary-btn-bg) !important;
            color: var(--primary-btn-text) !important;
        }

        .btn-secondary {
            background-color: var(--secondary-btn-bg) !important;
            border-color: var(--secondary-btn-bg) !important;
            color: var(--secondary-btn-text) !important;
        }

        /* Detailed Comment: Dark mode overrides for the login card, overlay, and action links */
        [data-bs-theme="dark"] .login-backdrop-overlay {
            background-color: rgba(10, 12, 16, 0.65) !important;
        }

        [data-bs-theme="dark"] .login-card {
            background-color: #1e1e1e !important;
            color: #e0e0e0 !important;
            border-color: #2d3748 !important;
            border-top: 4px solid var(--header-accent, #ffa500) !important;
        }

        [data-bs-theme="dark"] .login-card .btn-link {
            color: #9ec5fe !important;
        }

        [data-bs-theme="dark"] #bd-theme {
            background-color: #2b3035 !important;
            color: #dee2e6 !important;
            border-color: #495057 !important;
        }
    </style>
    @vite(['resources/js/app.js', 'resources/css/app.css'])
</head>

<body class="overflow-hidden min-vh-100 font-sans">
    <div class="bg-primary vh-100 login-backdrop-overlay position-relative" style="--bs-bg-opacity: .25;">
        {{-- Detailed Comment: Floating top-right theme switcher dropdown allowing Dark, Light, and System mode selection on the login screen --}}
        <div class="position-absolute top-0 end-0 p-3" style="z-index: 20;">
            <div class="dropdown" id="theme_switcher_dropdown">
                <button class="btn btn-light dropdown-toggle d-flex align-items-center gap-1 shadow-sm" id="bd-theme"
                    type="button" aria-expanded="false" data-bs-toggle="dropdown" aria-label="Toggle theme">
                    <i class="fa-solid fa-circle-half-stroke me-1" id="theme-active-icon"></i>
                    <span class="d-none d-md-inline" id="theme-active-text">System</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="bd-theme" id="theme_options_menu">
                    <li>
                        <button type="button" class="dropdown-item d-flex align-items-center justify-content-between"
                            data-bs-theme-value="light" id="theme_option_light">
                            <span><i class="fa-solid fa-sun me-2 text-warning"></i> Light</span>
                            <i class="fa-solid fa-check text-primary theme-check-icon d-none"></i>
                        </button>
                    </li>
                    <li>
                        <button type="button" class="dropdown-item d-flex align-items-center justify-content-between"
                            data-bs-theme-value="dark" id="theme_option_dark">
                            <span><i class="fa-solid fa-moon me-2 text-info"></i> Dark</span>
                            <i class="fa-solid fa-check text-primary theme-check-icon d-none"></i>
                        </button>
                    </li>
                    <li>
                        <button type="button" class="dropdown-item d-flex align-items-center justify-content-between"
                            data-bs-theme-value="system" id="theme_option_system">
                            <span><i class="fa-solid fa-circle-half-stroke me-2 text-secondary"></i> System</span>
                            <i class="fa-solid fa-check text-primary theme-check-icon d-none"></i>
                        </button>
                    </li>
                </ul>
            </div>
        </div>

        <div class="d-flex align-items-center justify-content-center min-vh-100">
            <div class="login-card w-25 d-flex align-items-center justify-content-center rounded-5 shadow">
                <div class="px-0 py-5 w-100 p-0 d-flex flex-column align-items-center gap-3">
                    {{-- Detailed Comment: Dynamically render clinic logo configured in App Theme Settings with fallback to default logo --}}
                    <img class="d-flex" style="width: 20rem; max-height: 10rem; object-fit: contain;"
                        src="{{ asset($activeTheme->logo_path ?? 'images/logo.png') }}" alt="company_logo"
                        id="login_header_logo">

                    <form class="d-flex flex-column gap-3 w-75" method="POST"
                        action="{{ route('login.authenticate') }}">
                        @csrf

                        <div class="">
                            @if ($errors->any())
                                <div class="alert alert-danger py-2" role="alert">
                                    {{ $errors->first() }}
                                </div>
                            @endif

                            <label class="form-label fw-bold m-0" for="username">Username</label>
                            <input class="form-control" type="text" name="username" id="username">
                        </div>

                        <div class="">
                            <label class="form-label fw-bold m-0" for="password">Password</label>
                            <input class="form-control" type="password" name="password" id="password">
                        </div>

                        <div class="d-flex flex-column gap-2">
                            <button type="submit" class="btn btn-primary fw-bold">Login</button>
                            <button type="button" class="btn btn-sm btn-link">Forgot Password?</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <p class="position-absolute bottom-0 end-0 text-white pe-3 pb-2">© DrainWiz Computer Systems</p>
    </div>

    <div class="position-fixed top-0 start-0 w-100 h-100"
        style="background-image: url('{{ asset('images/background/hero.jpg') }}'); background-size: cover; background-position: center; background-repeat: no-repeat; z-index: -1;">
    </div>
</body>

</html>
