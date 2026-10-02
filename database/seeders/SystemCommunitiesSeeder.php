<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Marvel\Enums\Permission as UserPermission;
use Marvel\Database\Models\Community;
use Marvel\Database\Models\Profile;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;

class SystemCommunitiesSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['interior', 'Интерьер', 'Пространства, материалы, мебель и идеи для дома.'],
            ['art', 'Искусство', 'Живопись, графика, скульптура и новые визуальные практики.'],
            ['fashion', 'Мода', 'Одежда, стиль, детали и авторские коллекции.'],
            ['handmade', 'Handmade', 'Вещи, созданные вручную: от идеи до готовой работы.'],
            ['furniture', 'Мебель', 'Авторская мебель, материалы, формы и процессы.'],
            ['ceramics', 'Керамика', 'Посуда, объекты и работа с глиной.'],
            ['home-renovation', 'Ремонт и дом', 'Ремонт, отделка, планировки и реальные проекты.'],
            ['design', 'Дизайн', 'Предметный, графический и интерьерный дизайн.'],
            ['architecture', 'Архитектура', 'Дома, пространства и архитектурные идеи.'],
            ['photography', 'Фотография', 'Люди, места, предметы и визуальные истории.'],
            ['beauty', 'Красота', 'Стиль, уход, макияж и эстетика.'],
            ['nature', 'Природа', 'Сады, растения, ландшафт и жизнь вне города.'],
            ['diy', 'DIY', 'Самостоятельные проекты, процессы и эксперименты.'],
            ['jewelry', 'Украшения', 'Авторские украшения и ювелирные работы.'],
            ['textile', 'Текстиль', 'Ткани, вязание, одежда и предметы из текстиля.'],
        ];

        Permission::firstOrCreate(['name' => UserPermission::SUPER_ADMIN, 'guard_name' => 'api']);
        $owner = User::permission(UserPermission::SUPER_ADMIN)->first();
        if (!$owner) {
            $owner = User::firstOrCreate(
                ['email' => 'sancan@sancan.ru'],
                [
                    'name' => 'SANCAN',
                    'password' => Hash::make(Str::random(64)),
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );
            $owner->forceFill(['email_verified_at' => now()])->save();
            $owner->givePermissionTo(UserPermission::SUPER_ADMIN);
        }

        $ownerProfile = Profile::updateOrCreate(
            ['customer_id' => $owner->id],
            [
                'username' => 'sancan',
                'display_name' => 'SANCAN',
                'bio' => 'Официальный профиль SANCAN',
            ]
        );

        foreach ($items as $index => [$slug, $name, $description]) {
            $community = Community::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $name,
                    'description' => $description,
                    'category' => $slug,
                    'owner_profile_id' => $ownerProfile?->id,
                    'is_system' => true,
                    'created_by_admin' => true,
                    'visibility' => 'public',
                    'posting_policy' => 'members_only',
                    'status' => 'active',
                    'sort_order' => $index + 1,
                ]
            );

            if ($ownerProfile) {
                $community->members()->syncWithoutDetaching([
                    $ownerProfile->id => ['role' => 'owner', 'status' => 'active', 'joined_at' => now()],
                ]);
                $community->update(['members_count' => $community->members()->wherePivot('status', 'active')->count()]);
            }
        }
    }
}
