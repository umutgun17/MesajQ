<?php

namespace App\Http\Controllers\Api\Saas;

use App\Http\Controllers\Controller;
use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** Şifremi unuttum → e-posta ile tek kullanımlık token (60 dk) → yeni şifre. */
class PasswordResetController extends Controller
{
    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);
        $email = strtolower(trim($request->email));

        // Bilgi sızdırmamak için her durumda aynı cevap.
        $users = User::where('email', $email)->whereNotNull('tenant_id')->get();
        if ($users->isNotEmpty()) {
            $token = Str::random(64);
            DB::table('password_reset_tokens')->updateOrInsert(['email' => $email], ['token' => Hash::make($token), 'created_at' => now()]);
            $url = rtrim(config('saas.app_base'), '/') . '/sifre-sifirla?token=' . $token . '&email=' . urlencode($email);
            try {
                Mail::to($email)->queue(new ResetPasswordMail($users->first(), $url));
            } catch (\Throwable) {
            }
        }

        return response()->json(['message' => 'Eğer bu e-posta kayıtlıysa şifre sıfırlama bağlantısı gönderildi.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);
        $email = strtolower(trim($request->email));
        $row = DB::table('password_reset_tokens')->where('email', $email)->first();
        if (! $row || ! Hash::check($request->token, $row->token) || now()->diffInMinutes($row->created_at) > 60) {
            throw ValidationException::withMessages(['token' => ['Bağlantı geçersiz veya süresi dolmuş. Yeniden isteyin.']]);
        }

        User::where('email', $email)->whereNotNull('tenant_id')->get()->each(function (User $u) use ($request) {
            $u->forceFill(['password' => $request->password])->save();
            $u->tokens()->delete();
        });
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return response()->json(['message' => 'Şifreniz güncellendi. Giriş yapabilirsiniz.']);
    }
}
