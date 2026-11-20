<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->session()->get('admin.id');
        $hash = $request->session()->get('admin.password_hash');
        $admin = $id ? DB::table('admin_accounts')->where('id', $id)->first() : null;

        if (! $admin || ! is_string($hash) || ! hash_equals($admin->password, $hash)) {
            $request->session()->forget('admin');

            return redirect()->away(route('admin.login', absolute: false));
        }

        return $next($request)->header('Cache-Control', 'no-store, private');
    }
}
