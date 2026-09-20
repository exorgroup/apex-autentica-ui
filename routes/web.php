<?php

use Apex\AutenticaUi\Http\Controllers\GroupMemberController;
use Apex\AutenticaUi\Http\Controllers\GroupPermissionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Autentica UI routes
|--------------------------------------------------------------------------
|
| Named `admin.roles.*`, because `EnsureResourcePermission` ignores any route
| whose name does not begin with `admin.` — the name is what the gate reads,
| not the URL. The URLs are left plain so a host can mount them wherever its
| own admin lives.
|
| `precognitive` on the two writes that carry a FormRequest, and only those:
| the middleware answers 204 having validated nothing on a route without one,
| which would tell a client "valid" about a request nobody checked.
|
| Every write returns `back()`, never a named route, so the same endpoints can
| serve a host's own page as well as this package's.
|
*/

Route::middleware(config('autentica-ui.middleware', ['web', 'auth']))
    ->name('admin.')
    ->group(function () {
        Route::get('/roles', [GroupPermissionController::class, 'index'])->name('roles.index');

        Route::post('/roles', [GroupPermissionController::class, 'store'])
            ->middleware('precognitive')
            ->name('roles.store');

        Route::patch('/roles/{group}', [GroupPermissionController::class, 'rename'])
            ->middleware('precognitive')
            ->name('roles.rename');

        Route::put('/roles/{group}', [GroupPermissionController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{group}', [GroupPermissionController::class, 'destroy'])->name('roles.destroy');

        // Membership, listed and edited a page at a time.
        Route::get('/roles/{group}/members', [GroupMemberController::class, 'index'])->name('roles.members.index');
        Route::put('/roles/{group}/members/{user}', [GroupMemberController::class, 'update'])->name('roles.members.update');
    });
