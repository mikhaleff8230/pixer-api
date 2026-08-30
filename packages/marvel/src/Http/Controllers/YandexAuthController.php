<?php

namespace Marvel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;

/**
 * Контроллер для авторизации через Яндекс OAuth 2.0
 * 
 * Использует пакет SocialiteProviders/Yandex для интеграции с Laravel Socialite.
 * 
 * @package Marvel\Http\Controllers
 */
class YandexAuthController extends CoreController
{
    /**
     * Перенаправление пользователя на страницу авторизации Яндекса
     * 
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function redirect(Request $request)
    {
        return Socialite::driver('yandex')
            ->scopes(['login:email'])
            ->stateless()
            ->redirect();
    }

    /**
     * Обработка callback от Яндекса после авторизации
     * 
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function callback(Request $request)
    {
        try {
            Log::info('Yandex OAuth callback: started', [
                'has_code' => $request->has('code'),
                'has_error' => $request->has('error'),
                'request_all' => $request->all(),
            ]);

            // Проверяем наличие ошибки от Яндекса
            if ($request->has('error')) {
                Log::error('Yandex OAuth callback: error from Yandex', [
                    'error' => $request->get('error'),
                    'error_description' => $request->get('error_description'),
                ]);
                return redirect('https://sancan.ru/')->with('error', 'Ошибка авторизации: ' . $request->get('error'));
            }

            // Проверяем наличие кода
            if (!$request->has('code')) {
                Log::error('Yandex OAuth callback: missing code');
                return redirect('https://sancan.ru/')->with('error', 'Отсутствует код авторизации');
            }

            // Получаем данные пользователя от Яндекса (БЕЗ scopes в callback!)
            // Используем stateless() для избежания проблем с сессиями
            try {
                $yUser = Socialite::driver('yandex')->stateless()->user();
            } catch (\Exception $socialiteError) {
                Log::error('Yandex OAuth callback: Socialite user() error', [
                    'message' => $socialiteError->getMessage(),
                    'code' => $socialiteError->getCode(),
                    'file' => $socialiteError->getFile(),
                    'line' => $socialiteError->getLine(),
                    'trace' => $socialiteError->getTraceAsString(),
                ]);
                throw $socialiteError;
            }

            Log::info('Yandex OAuth callback: user data received', [
                'yandex_id' => $yUser->getId(),
                'email' => $yUser->getEmail(),
                'name' => $yUser->getName(),
                'nickname' => $yUser->getNickname(),
            ]);

            // Проверяем обязательные данные
            if (empty($yUser->getId())) {
                Log::error('Yandex OAuth callback: empty yandex_id');
                return redirect('https://sancan.ru/')->with('error', 'Не получен ID пользователя от Яндекса');
            }

            if (empty($yUser->getEmail())) {
                Log::error('Yandex OAuth callback: empty email');
                return redirect('https://sancan.ru/')->with('error', 'Не получен email от Яндекса');
            }

            // Ищем пользователя по yandex_id
            $user = User::where('yandex_id', $yUser->getId())->first();
            
            // Если не найден по yandex_id, ищем по email
            if (!$user) {
                $user = User::where('email', $yUser->getEmail())->first();
                
                // Если найден по email, обновляем yandex_id
                if ($user) {
                    $user->yandex_id = $yUser->getId();
                    $user->save();
                    Log::info('Yandex OAuth callback: existing user updated with yandex_id', [
                        'user_id' => $user->id,
                        'email' => $user->email,
                    ]);
                }
            }
            
            // Если пользователь не найден, создаем нового
            if (!$user) {
                $user = User::create([
                    'name' => $yUser->getName() ?? $yUser->getNickname() ?? 'User',
                    'email' => $yUser->getEmail(),
                    'yandex_id' => $yUser->getId(),
                    'password' => bcrypt(Str::random(40)),
                    'email_verified_at' => now(),
                ]);
                
                // Назначаем роль CUSTOMER для нового пользователя
                if (!$user->hasPermissionTo(Permission::CUSTOMER)) {
                    $user->givePermissionTo(Permission::CUSTOMER);
                    Log::info('Yandex OAuth callback: CUSTOMER permission assigned', [
                        'user_id' => $user->id,
                    ]);
                }
                
                Log::info('Yandex OAuth callback: new user created', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);
            } else {
                // Для существующего пользователя тоже проверяем роль
                if (!$user->hasPermissionTo(Permission::CUSTOMER)) {
                    $user->givePermissionTo(Permission::CUSTOMER);
                    Log::info('Yandex OAuth callback: CUSTOMER permission assigned to existing user', [
                        'user_id' => $user->id,
                    ]);
                }
            }

            Log::info('Yandex OAuth callback: user found/created', [
                'user_id' => $user->id,
                'email' => $user->email,
                'yandex_id' => $user->yandex_id,
                'was_recently_created' => $user->wasRecentlyCreated,
            ]);

            // Авторизуем пользователя через web guard (для бекенда / Blade, если используется)
            Auth::guard('web')->login($user, true);
            // Preserve OAuth itself, but repair an existing seller's missing shop before returning.
            app(\App\Services\SellerOnboardingService::class)->ensure($user);

            // Создаем API-токен для SPA (Marvel shop)
            $token = $user->createToken('auth_token')->plainTextToken;

            Log::info('Yandex OAuth callback: api token created', [
                'user_id' => $user->id,
            ]);

            // Отдаем токен в cookie, который читает фронтенд (shop)
            // Ключ должен совпадать с ConfigValue.AUTH_TOKEN_KEY на фронте: 'pixer-auth-token'
            $cookie = cookie(
                'pixer-auth-token', // имя cookie
                $token,             // значение
                60 * 24,            // срок жизни в минутах (1 день)
                '/',                // path
                '.sancan.ru',       // домен (доступно для sancan.ru и api.sancan.ru)
                true,               // secure
                false,              // httpOnly = false, чтобы JS (js-cookie) мог читать
                false,              // raw
                'Lax'               // sameSite
            );

            Log::info('Yandex OAuth callback: auth cookie set for frontend', [
                'user_id' => $user->id,
                'cookie_name' => 'pixer-auth-token',
            ]);

            // Передаём токен и флаг успешной авторизации в URL,
            // чтобы фронтенд (Next.js) смог вызвать useAuth().authorize(token)
            $redirectUrl = 'https://sancan.ru/auth/success?yandex_auth=success&mode=login&token=' . urlencode($token);

            return redirect($redirectUrl)->withCookie($cookie);
            
        } catch (\Exception $e) {
            Log::error('Yandex OAuth callback error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return redirect('https://sancan.ru/')->with('error', 'Ошибка при авторизации через Яндекс: ' . $e->getMessage());
        }
    }

    /**
     * Получение данных текущего авторизованного пользователя (для тестирования)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function user(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'error' => 'Пользователь не авторизован'
            ], 401);
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'created_at' => $user->created_at,
            ]
        ]);
    }

    /**
     * Получение данных пользователя из сессии после Яндекс авторизации
     * Используется для режима register/seller для автозаполнения полей
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function userData(Request $request)
    {
        // Получаем данные из сессии (сохранены в callback)
        $userData = session('yandex_user_data');

        if (!$userData) {
            return response()->json([
                'error' => 'Данные пользователя не найдены'
            ], 404);
        }

        // Удаляем данные из сессии после получения
        session()->forget('yandex_user_data');

        return response()->json([
            'user' => $userData
        ]);
    }
}
