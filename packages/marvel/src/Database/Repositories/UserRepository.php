<?php

namespace Marvel\Database\Repositories;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\User;
use Prettus\Validator\Exceptions\ValidatorException;
use Spatie\Permission\Models\Permission;
use Marvel\Enums\Permission as UserPermission;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Marvel\Mail\ForgetPassword;
use Illuminate\Support\Facades\Mail;
use Marvel\Database\Models\Address;
use Marvel\Database\Models\Profile;
use Marvel\Database\Models\Shop;
use Marvel\Exceptions\MarvelException;
use Marvel\Events\UserRegistered;

class UserRepository extends BaseRepository
{
    /**
     * @var array
     */
    protected $fieldSearchable = [
        'name' => 'like',
        'email' => 'like',
    ];

    /**
     * @var array
     */
    protected $dataArray = [
        'name',
        'email',
        'shop_id'
    ];

    /**
     * Configure the Model
     **/
    public function model()
    {
        return User::class;
    }

    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
        }
    }

    public function storeUser($request)
    {
        try {
            $user = $this->create([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => Hash::make($request->password),
            ]);
            $user->givePermissionTo(UserPermission::CUSTOMER);
            if (isset($request['address']) && count($request['address'])) {
                $user->address()->createMany($request['address']);
            }
            if (isset($request['profile'])) {
                // Генерируем seller_id автоматически, игнорируем если был передан в запросе
                $profileData = $request['profile'];
                unset($profileData['seller_id']); // Удаляем seller_id из данных, если был передан
                $profileData['seller_id'] = \Marvel\Services\ArticleGeneratorService::generateSellerId();
                $user->profile()->create($profileData);
            } else {
                // Если профиль не передан, создаем его с seller_id
                $sellerId = \Marvel\Services\ArticleGeneratorService::generateSellerId();
                $user->profile()->create([
                    'seller_id' => $sellerId,
                ]);
            }
            $user->profile = $user->profile;
            $user->address = $user->address;
            $user->shop = $user->shop;
            $user->managed_shop = $user->managed_shop;
            
            // Отправляем событие о регистрации пользователя
            event(new UserRegistered($user, UserPermission::CUSTOMER));
            
            return $user;
        } catch (ValidatorException $e) {
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    public function updateUser($request, $user)
    {
        try {
            // Логируем входящие данные для отладки
            Log::info('UserRepository::updateUser - входящие данные', [
                'user_id' => $user->id,
                'request_data' => $request->all(),
                'profile_data' => $request->get('profile'),
                'name' => $request->get('name'),
            ]);

            if (isset($request['address']) && count($request['address'])) {
                foreach ($request['address'] as $address) {
                    if (isset($address['id'])) {
                        Address::findOrFail($address['id'])->update($address);
                    } else {
                        $address['customer_id'] = $user->id;
                        Address::create($address);
                    }
                }
            }

            if (isset($request['profile'])) {
                $profileData = $request['profile'];
                
                // Обрабатываем аватар - если это массив, берем первый элемент
                if (isset($profileData['avatar']) && is_array($profileData['avatar']) && isset($profileData['avatar'][0])) {
                    $profileData['avatar'] = $profileData['avatar'][0];
                }
                
                // Логируем данные профиля перед обновлением
                Log::info('UserRepository::updateUser - данные профиля', [
                    'profile_id' => $profileData['id'] ?? null,
                    'contact' => $profileData['contact'] ?? null,
                    'bio' => $profileData['bio'] ?? null,
                    'avatar' => $profileData['avatar'] ?? null,
                    'avatar_type' => isset($profileData['avatar']) ? gettype($profileData['avatar']) : null,
                    'avatar_is_array' => isset($profileData['avatar']) && is_array($profileData['avatar']),
                ]);

                if (isset($profileData['id'])) {
                    // Защита: seller_id не может быть изменен через обновление профиля
                    unset($profileData['seller_id']); // Удаляем seller_id из данных обновления
                    
                    // Убеждаемся, что avatar правильно обрабатывается
                    // Если avatar это объект или массив, Laravel автоматически сериализует его в JSON
                    // благодаря cast в модели Profile
                    $profile = Profile::findOrFail($profileData['id']);
                    
                    // Обновляем только переданные поля
                    $updateData = [];
                    if (isset($profileData['contact'])) {
                        $updateData['contact'] = $profileData['contact'];
                    }
                    if (isset($profileData['bio'])) {
                        $updateData['bio'] = $profileData['bio'];
                    }
                    if (isset($profileData['avatar'])) {
                        // Убеждаемся, что avatar - это объект/массив, который будет сериализован в JSON
                        $avatar = $profileData['avatar'];
                        // Если avatar это объект с полями id, thumbnail, original - оставляем как есть
                        // Laravel автоматически сериализует его в JSON благодаря cast
                        $updateData['avatar'] = $avatar;
                        
                        Log::info('UserRepository::updateUser - обработка аватара', [
                            'avatar_data' => $avatar,
                            'avatar_type' => gettype($avatar),
                            'is_array' => is_array($avatar),
                            'is_object' => is_object($avatar),
                        ]);
                    }
                    
                    // Обновляем профиль только если есть данные для обновления
                    if (!empty($updateData)) {
                        $profile->update($updateData);
                        Log::info('UserRepository::updateUser - профиль обновлен', [
                            'profile_id' => $profile->id,
                            'updated_fields' => array_keys($updateData),
                            'avatar_after_update' => $profile->avatar,
                        ]);
                    }
                } else {
                    $profile = $profileData;
                    $profile['customer_id'] = $user->id;
                    // Если seller_id не указан, генерируем его автоматически
                    if (!isset($profile['seller_id']) || empty($profile['seller_id'])) {
                        $profile['seller_id'] = \Marvel\Services\ArticleGeneratorService::generateSellerId();
                    }
                    Profile::create($profile);
                }
            }
            
            // Обновляем имя пользователя, если оно передано
            $userUpdateData = [];
            if ($request->has('name')) {
                $userUpdateData['name'] = $request->get('name');
            }
            if ($request->has('marketing_email_consent')) {
                $userUpdateData['marketing_email_consent_at'] = $request->boolean('marketing_email_consent') ? now() : null;
            }
            if ($request->has('marketing_push_consent')) {
                $userUpdateData['marketing_push_consent_at'] = $request->boolean('marketing_push_consent') ? now() : null;
            }
            if ($request->boolean('marketing_email_consent') || $request->boolean('marketing_push_consent')) {
                $userUpdateData['consent_version'] = '2026-08-20';
                $userUpdateData['consent_ip'] = $request->ip();
                $userUpdateData['consent_user_agent'] = mb_substr((string) $request->userAgent(), 0, 1000);
            }
            if (!empty($userUpdateData)) {
                $user->update($userUpdateData);
                Log::info('UserRepository::updateUser - пользователь обновлен', [
                    'user_id' => $user->id,
                    'updated_fields' => array_keys($userUpdateData),
                ]);
            }
            
            // Обновляем связанные данные для возврата
            $user->profile = $user->profile;
            $user->address = $user->address;
            $user->shop = $user->shop;
            $user->managed_shop = $user->managed_shop;
            
            return $user;
        } catch (ValidationException $e) {
            Log::error('UserRepository::updateUser - ошибка валидации', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'errors' => $e->errors(),
            ]);
            throw new MarvelException(SOMETHING_WENT_WRONG);
        } catch (\Exception $e) {
            Log::error('UserRepository::updateUser - ошибка', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new MarvelException(SOMETHING_WENT_WRONG);
        }
    }

    public function sendResetEmail($email, $token)
    {
        try {
            Mail::to($email)->send(new ForgetPassword($token));
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    /**
     * Update user email and send verification link to the user.
     * @param  $request
     * @return string[]
     */

    public function updateEmail($request): array
    {
        $user =$request->user();
        $user->email = $request->email;
        $user->email_verified_at = null;
        $user->save();
        $user->sendEmailVerificationNotification();
        return ['message' => EMAIL_UPDATED_SUCCESSFULLY, 'status' => 'success'];
    }

}
