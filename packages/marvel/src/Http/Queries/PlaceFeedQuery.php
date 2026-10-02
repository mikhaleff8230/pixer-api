<?php

namespace Marvel\Http\Queries;

use Illuminate\Database\Eloquent\Builder;
use Marvel\Database\Models\Place;

class PlaceFeedQuery
{
    /**
     * Создает оптимизированный запрос для фида places
     *
     * @param array $params
     * @return Builder
     */
    public static function query(array $params = []): Builder
    {
        $query = Place::query()
            // Выбираем только легкие поля
            ->select([
                'id',
                'title',
                'slug',
                'user_id',
                'community_id',
                'created_at',
            ])
            // Сортировка для cursor-based pagination: created_at DESC, id DESC
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc');

        // Cursor-based pagination
        if (isset($params['cursor'])) {
            $cursor = self::parseCursor($params['cursor']);
            if ($cursor) {
                $query->where(function ($q) use ($cursor) {
                    $q->where('created_at', '<', $cursor['created_at'])
                      ->orWhere(function ($subQ) use ($cursor) {
                          $subQ->where('created_at', '=', $cursor['created_at'])
                               ->where('id', '<', $cursor['id']);
                      });
                });
            }
        }

        // Фильтрация по slug хэштега
        if (isset($params['hashtag_slug'])) {
            $hashtagSlug = $params['hashtag_slug'];

            \Log::info('PlaceFeedQuery - фильтрация по hashtag', [
                'hashtag_slug' => $hashtagSlug,
            ]);

            $query->whereHas('hashtags', function ($q) use ($hashtagSlug) {
                $q->where('slug', $hashtagSlug);
            });
        }

        // Ограничение на количество записей
        $limit = $params['limit'] ?? 20;
        $query->limit(min($limit, 100)); // Максимум 100 записей за раз

        // Загружаем связи для фида (только необходимые)
        $query->with([
            // Загружаем все изображения для совместимости с единым контрактом
            'images' => function ($q) {
                $q->select(['id', 'place_id', 'url'])
                  ->orderBy('id', 'asc'); // Все изображения в правильном порядке
            },
            // Минимальные данные пользователя
            'user' => function ($q) {
                $q->select(['id', 'name'])
                  ->with(['profile' => function ($profileQuery) {
                      $profileQuery->select(['id', 'customer_id', 'avatar']);
                  }]);
            },
            // Хэштеги (нужны для страницы хэштегов) - используем alias для избежания конфликтов
            'hashtags' => function ($q) {
                $q->select(['hashtags.id', 'hashtags.name', 'hashtags.slug']);
            },
            'community:id,name,slug',
        ])
        // Подсчитываем likes и comments без загрузки самих записей
        ->withCount([
            'likes',
            'comments'
        ]);

        return $query;
    }

    /**
     * Парсит курсор формата "created_at|id"
     *
     * @param string $cursor
     * @return array|null
     */
    private static function parseCursor(string $cursor): ?array
    {
        $parts = explode('|', $cursor);
        if (count($parts) !== 2) {
            return null;
        }

        $createdAt = $parts[0];
        $id = $parts[1];

        // Валидация формата created_at (ISO 8601)
        if (!strtotime($createdAt) || !is_numeric($id)) {
            return null;
        }

        return [
            'created_at' => $createdAt,
            'id' => (int) $id,
        ];
    }

    /**
     * Создает курсор из модели Place
     *
     * @param Place $place
     * @return string
     */
    public static function createCursor(Place $place): string
    {
        return $place->created_at->format('Y-m-d\TH:i:s.u\Z') . '|' . $place->id;
    }
}
