<?php

namespace Marvel\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Events\UserRegistered;
use Marvel\Notifications\UserRegisteredNotification;

class SendUserRegisteredNotification implements ShouldQueue
{
    /**
     * Handle the event.
     *
     * @param UserRegistered $event
     * @return void
     */
    public function handle(UserRegistered $event)
    {
        $user = $event->user;
        $permission = $event->permission;
        
        // Отправка уведомления админу
        $admins = $this->getAdminUsers();
        foreach ($admins as $admin) {
            $admin->notify(new UserRegisteredNotification($user, 'admin'));
        }
        
        // Отправка уведомления пользователю
        if (filter_var($user->email, FILTER_VALIDATE_EMAIL) && !str_ends_with($user->email, '@phone.auth')) {
            $user->notify(new UserRegisteredNotification($user, 'customer'));
        }
    }
    
    /**
     * Get admin users
     */
    private function getAdminUsers()
    {
        return \Marvel\Database\Models\User::whereHas('permissions', function($query) {
            $query->where('name', \Marvel\Enums\Permission::SUPER_ADMIN);
        })->get();
    }
}
