<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;

class ImpersonationController extends Controller
{
    public function store(Request $request, User $user): JsonResponse
    {
        $admin = $request->user();

        abort_unless($admin && $admin->hasPermissionTo(Permission::SUPER_ADMIN), 403);
        abort_if((int) $admin->id === (int) $user->id, 422, 'Нельзя авторизоваться от своего имени.');
        abort_if($user->hasPermissionTo(Permission::SUPER_ADMIN), 403, 'Имперсонация другого супер-администратора запрещена.');
        abort_unless((bool) $user->is_active, 422, 'Пользователь заблокирован.');

        $token = $user->createToken('admin-impersonation', ['impersonated'])->plainTextToken;

        Log::notice('Admin started user impersonation', [
            'admin_id' => $admin->id,
            'target_user_id' => $user->id,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'token' => $token,
            'permissions' => $user->getPermissionNames(),
            'impersonated_user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }
}
