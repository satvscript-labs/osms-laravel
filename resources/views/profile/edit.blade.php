@extends('layouts.app')
@section('title', 'Settings')

@php
    $isStoreAdmin = auth()->user()->isStoreAdmin() && $tenant;
@endphp

@section('content')
<div class="p-4 p-md-5" x-data="settingsHub({ storeAdmin: {{ $isStoreAdmin ? 'true' : 'false' }} })" x-init="init()">
    {{-- Header --}}
    <div class="mb-4">
        <p class="section-label mb-1">Account &amp; store</p>
        <h1 class="h3 fw-semibold font-display mb-1">Settings</h1>
        <p class="text-muted-foreground mb-0 text-md">
            Manage your account, security, and store details in one place.
        </p>
    </div>

    <div class="row g-4">
        {{-- ============ Section nav (sticky rail) ============ --}}
        <div class="col-lg-3">
            <nav class="settings-nav" aria-label="Settings sections">
                <button type="button" class="settings-nav-item" :class="{ active: section==='profile' }" @click="go('profile')">
                    <i class="bi bi-person-circle"></i> <span>Profile</span>
                </button>
                <button type="button" class="settings-nav-item" :class="{ active: section==='password' }" @click="go('password')">
                    <i class="bi bi-shield-lock"></i> <span>Password</span>
                </button>
                @if ($isStoreAdmin)
                    <button type="button" class="settings-nav-item" :class="{ active: section==='store' }" @click="go('store')">
                        <i class="bi bi-shop"></i> <span>Store</span>
                    </button>
                @endif
                <button type="button" class="settings-nav-item settings-nav-item-danger" :class="{ active: section==='danger' }" @click="go('danger')">
                    <i class="bi bi-exclamation-octagon"></i> <span>Danger zone</span>
                </button>
            </nav>
        </div>

        {{-- ============ Panels ============ --}}
        <div class="col-lg-9">
            {{-- Profile --}}
            <div x-show="section==='profile'" x-transition.opacity.duration.250ms>
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-body p-4">
                        <h2 class="section-label mb-1">Profile information</h2>
                        <p class="text-muted-foreground text-sm mb-4">Your name and sign-in email.</p>
                        <form method="POST" action="{{ route('profile.update') }}" class="d-flex flex-column gap-3" style="max-width:34rem;">
                            @csrf
                            @method('patch')
                            <div>
                                <label for="name" class="form-label">Name</label>
                                <input id="name" name="name" type="text" value="{{ old('name', $user->name) }}"
                                       class="form-control @error('name') is-invalid @enderror" required autocomplete="name">
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label for="email" class="form-label">Email</label>
                                <input id="email" name="email" type="email" value="{{ old('email', $user->email) }}"
                                       class="form-control @error('email') is-invalid @enderror" required autocomplete="email">
                                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="pt-1">
                                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i>Save changes</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            {{-- Password --}}
            <div x-show="section==='password'" x-transition.opacity.duration.250ms x-cloak>
                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-body p-4">
                        <h2 class="section-label mb-1">Update password</h2>
                        <p class="text-muted-foreground text-sm mb-4">Use a long, unique password to keep your account secure.</p>
                        <form method="POST" action="{{ route('password.update') }}" class="d-flex flex-column gap-3" style="max-width:34rem;">
                            @csrf
                            @method('put')
                            <div>
                                <label for="current_password" class="form-label">Current password</label>
                                <input id="current_password" name="current_password" type="password"
                                       class="form-control @error('current_password', 'updatePassword') is-invalid @enderror" autocomplete="current-password">
                                @error('current_password', 'updatePassword')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label for="password" class="form-label">New password</label>
                                <input id="password" name="password" type="password"
                                       class="form-control @error('password', 'updatePassword') is-invalid @enderror" autocomplete="new-password">
                                @error('password', 'updatePassword')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div>
                                <label for="password_confirmation" class="form-label">Confirm new password</label>
                                <input id="password_confirmation" name="password_confirmation" type="password"
                                       class="form-control" autocomplete="new-password">
                            </div>
                            <div class="pt-1">
                                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i>Update password</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            @if ($isStoreAdmin)
                {{-- Store --}}
                <div x-show="section==='store'" x-transition.opacity.duration.250ms x-cloak>
                    <form method="POST" action="{{ route('tenant.settings.update') }}" enctype="multipart/form-data"
                          class="d-flex flex-column gap-4">
                        @csrf
                        @method('PUT')

                        {{-- Store identity --}}
                        <div class="card border-0 shadow-sm rounded-4">
                            <div class="card-body p-4">
                                <h2 class="section-label mb-1">Store identity</h2>
                                <p class="text-muted-foreground text-sm mb-4">These details appear on printed receipts and across your workspace.</p>
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label for="store_name" class="form-label">Store name *</label>
                                        <input id="store_name" type="text" name="store_name"
                                               value="{{ old('store_name', $tenant->store_name) }}"
                                               class="form-control @error('store_name') is-invalid @enderror"
                                               required placeholder="Sahaj Optical">
                                        @error('store_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-sm-6">
                                        <label for="tax_id" class="form-label">GST / Tax ID</label>
                                        <input id="tax_id" type="text" name="tax_id" value="{{ old('tax_id', $tenant->tax_id) }}"
                                               class="form-control @error('tax_id') is-invalid @enderror" placeholder="22AAAAA0000A1Z5">
                                        @error('tax_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="col-sm-6">
                                        <label for="address" class="form-label">Address</label>
                                        <input id="address" type="text" name="address" value="{{ old('address', $tenant->address) }}"
                                               class="form-control @error('address') is-invalid @enderror" placeholder="123 Main Street, Mumbai">
                                        @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Store logo --}}
                        <div class="card border-0 shadow-sm rounded-4">
                            <div class="card-body p-4">
                                <h2 class="section-label mb-3">Store logo</h2>
                                <div class="d-flex align-items-center gap-4 flex-wrap">
                                    <div class="flex-shrink-0">
                                        @if ($tenant->logo_url)
                                            <img id="logoPreview" src="{{ $tenant->logo_url }}" alt="{{ $tenant->store_name }}"
                                                 class="rounded-4 object-fit-cover border" style="width:5rem;height:5rem;">
                                        @else
                                            <span id="logoPreview"
                                                  class="d-inline-flex align-items-center justify-content-center bg-primary text-white rounded-4"
                                                  style="width:5rem;height:5rem;font-size:1.75rem;">
                                                <i class="bi bi-shop"></i>
                                            </span>
                                        @endif
                                    </div>
                                    <div class="flex-grow-1" style="min-width:14rem;">
                                        <label for="logo" class="form-label">Replace logo</label>
                                        <input id="logo" type="file" name="logo" accept="image/*"
                                               class="form-control @error('logo') is-invalid @enderror">
                                        @error('logo')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                        <div class="form-text text-xs">
                                            Used on printed receipts. PNG or JPG, ideally square. Max 2MB.
                                        </div>
                                        @if ($tenant->logo_url)
                                            <div class="form-check mt-2">
                                                <input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="remove_logo">
                                                <label class="form-check-label text-sm text-muted-foreground" for="remove_logo">
                                                    Remove current logo
                                                </label>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i>Save store settings</button>
                        </div>
                    </form>
                </div>
            @endif

            {{-- Danger zone --}}
            <div x-show="section==='danger'" x-transition.opacity.duration.250ms x-cloak>
                <div class="card border-0 shadow-sm rounded-4" style="border:1px solid var(--tone-red-bg) !important;">
                    <div class="card-body p-4">
                        <h2 class="h6 fw-semibold mb-1 text-danger"><i class="bi bi-exclamation-octagon me-2"></i>Delete account</h2>
                        <p class="text-muted-foreground text-sm mb-4" style="max-width:38rem;">
                            Once your account is deleted, all of its resources and data are permanently removed.
                            Before deleting, please download any data you wish to retain.
                        </p>
                        <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">
                            <i class="bi bi-trash me-2"></i>Delete account
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Delete confirmation modal --}}
<div class="modal fade" id="deleteAccountModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('profile.destroy') }}" class="modal-content border-0">
            @csrf
            @method('delete')
            <div class="modal-body p-4 text-center">
                <span class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3"
                      style="width:3rem;height:3rem;background:var(--tone-red-bg);color:var(--tone-red);">
                    <i class="bi bi-exclamation-triangle fs-4"></i>
                </span>
                <h2 class="h5 fw-semibold font-display mb-2">Delete account?</h2>
                <p class="text-muted-foreground mb-4">Enter your password to confirm permanent deletion. This cannot be undone.</p>
                <input type="password" name="password" class="form-control mb-2 @error('password', 'userDeletion') is-invalid @enderror"
                       placeholder="Password" required autocomplete="current-password">
                @error('password', 'userDeletion')<div class="invalid-feedback d-block text-start mb-2">{{ $message }}</div>@enderror
                <div class="d-flex gap-2 justify-content-center mt-3">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete account</button>
                </div>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    // Which section opens by default: remember the last one (per browser); if a
    // form had validation errors, jump to its section so the user sees them.
    function settingsHub(cfg) {
        const allowed = ['profile', 'password', 'danger'].concat(cfg.storeAdmin ? ['store'] : []);
        return {
            section: 'profile',
            go(s) {
                this.section = s;
                try { localStorage.setItem('osms.settings.section', s); } catch (e) {}
            },
            init() {
                @if ($errors->updatePassword->isNotEmpty())
                    this.section = 'password'; return;
                @elseif ($errors->userDeletion->isNotEmpty())
                    this.section = 'danger'; return;
                @elseif ($errors->getBag('default')->isNotEmpty())
                    // Store form uses the default bag (store_name/tax_id/address/logo).
                    @if ($isStoreAdmin)
                        this.section = 'store'; return;
                    @endif
                @endif
                let saved = null;
                try { saved = localStorage.getItem('osms.settings.section'); } catch (e) {}
                this.section = allowed.includes(saved) ? saved : 'profile';
            },
        };
    }

    // Live preview of a newly chosen store logo.
    (function () {
        function init() {
            const input = document.getElementById('logo');
            const preview = document.getElementById('logoPreview');
            if (!input || !preview) return;
            input.addEventListener('change', () => {
                const file = input.files && input.files[0];
                if (!file) return;
                const url = URL.createObjectURL(file);
                if (preview.tagName === 'IMG') {
                    preview.src = url;
                } else {
                    const img = document.createElement('img');
                    img.id = 'logoPreview';
                    img.src = url;
                    img.alt = 'Logo preview';
                    img.className = 'rounded-4 object-fit-cover border';
                    img.style.width = '5rem';
                    img.style.height = '5rem';
                    preview.replaceWith(img);
                }
                const remove = document.getElementById('remove_logo');
                if (remove) remove.checked = false;
            });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', init);
        } else {
            init();
        }
    })();
</script>
@endpush
@endsection
