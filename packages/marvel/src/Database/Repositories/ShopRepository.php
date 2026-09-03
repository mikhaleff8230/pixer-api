<?php


namespace Marvel\Database\Repositories;

use Exception;
use Marvel\Database\Models\Balance;
use Marvel\Database\Models\Shop;
use Marvel\Enums\Permission;
use Marvel\Events\ShopCreated;
use Prettus\Repository\Criteria\RequestCriteria;
use Prettus\Repository\Exceptions\RepositoryException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ShopRepository extends BaseRepository
{

    /**
     * @var array
     */
    protected $fieldSearchable = [
        'name'        => 'like',
        'is_active',
        'categories.slug',
        'users.name'
    ];

    /**
     * @var array
     */
    protected $dataArray = [
        'name',
        'slug',
        'description',
        'cover_image',
        'logo',
        'is_active',
        'address',
        'settings',
        'notifications',
    ];


    public function boot()
    {
        try {
            $this->pushCriteria(app(RequestCriteria::class));
        } catch (RepositoryException $e) {
            //
        }
    }

    /**
     * Configure the Model
     **/
    public function model()
    {
        return Shop::class;
    }

    public function storeShop($request)
    {
        try {
            $data = $request->only($this->dataArray);
            $data = $this->sanitizeSocials($data);
            $data['slug'] = $this->makeSlug($request);
            $data['owner_id'] = $request->user()->id;
            $shop = $this->create($data);
            if (isset($request['categories'])) {
                $shop->categories()->attach($request['categories']);
            }
            if (isset($request['balance']['payment_info'])) {
                $shop->balance()->create($request['balance']);
            }
            $shop->categories = $shop->categories;
            $shop->staffs = $shop->staffs;
            
            // Отправляем событие о создании магазина
            event(new ShopCreated($shop));
            
            return $shop;
        } catch (Exception $e) {
            throw new HttpException(400, COULD_NOT_CREATE_THE_RESOURCE);
        }
    }

    public function updateShop($request, $id)
    {
        try {
            $shop = $this->findOrFail($id);
            if (isset($request['categories'])) {
                $shop->categories()->sync($request['categories']);
            }
            if (isset($request['balance'])) {
                if (isset($request['balance']['admin_commission_rate']) && $shop->balance->admin_commission_rate !== $request['balance']['admin_commission_rate']) {
                    if ($request->user()->hasPermissionTo(Permission::SUPER_ADMIN)) {
                        $this->updateBalance($request['balance'], $id);
                    }
                } else {
                    $this->updateBalance($request['balance'], $id);
                }
            }
            $data = $request->only($this->dataArray);
            $data = $this->sanitizeSocials($data);
            if (!empty($request->slug) &&  $request->slug != $shop['slug']) {
                $data['slug'] = $this->makeSlug($request);
            }
            $shop->update($data);
            $shop->categories = $shop->categories;
            $shop->staffs = $shop->staffs;
            $shop->balance = $shop->balance;
            return $shop;
        } catch (Exception $e) {
            throw new HttpException(400, COULD_NOT_UPDATE_THE_RESOURCE);
        }
    }

    public function updateBalance($balance, $shop_id)
    {
        if (isset($balance['id'])) {
            Balance::findOrFail($balance['id'])->update($balance);
        } else {
            $balance['shop_id'] = $shop_id;
            Balance::create($balance);
        }
    }

    private function sanitizeSocials(array $data): array
    {
        if (
            !isset($data['settings']) ||
            !is_array($data['settings']) ||
            !array_key_exists('socials', $data['settings'])
        ) {
            return $data;
        }

        $socials = is_array($data['settings']['socials'])
            ? $data['settings']['socials']
            : [];

        $data['settings']['socials'] = array_values(array_filter(array_map(
            static function ($social) {
                if (!is_array($social)) {
                    return null;
                }

                $icon = trim((string) ($social['icon'] ?? ''));
                $url = trim((string) ($social['url'] ?? ''));

                if ($icon === '' || $url === '') {
                    return null;
                }

                return [
                    'icon' => $icon,
                    'url' => $url,
                ];
            },
            $socials
        )));

        return $data;
    }
}
