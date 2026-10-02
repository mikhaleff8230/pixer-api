<?php


namespace Marvel\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Database\Models\Category;
use Marvel\Database\Repositories\CategoryRepository;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\CategoryCreateRequest;
use Marvel\Http\Requests\CategoryUpdateRequest;
use Prettus\Validator\Exceptions\ValidatorException;


class CategoryController extends CoreController
{
    public $repository;

    public function __construct(CategoryRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Collection|Category[]
     */
    public function index(Request $request)
    {
        $language = $request->language ?? DEFAULT_LANGUAGE;
        $parent = $request->parent;
        $limit = $request->limit ?   $request->limit : 15;
        if ($parent === 'null') {
            return $this->repository
                ->with(['type', 'parent', 'children'])
                ->where('parent', null)
                ->where('language', $language)
                ->orderBy('sort_order', 'asc')
                ->orderBy('name')
                ->paginate($limit);
        } else {
            return $this->repository
                ->with(['type', 'parent', 'children'])
                ->where('language', $language)
                ->orderBy('sort_order', 'asc')
                ->orderBy('name')
                ->paginate($limit);
        }
    }

    /**
     * Get categories for menu with full hierarchy
     *
     * @param Request $request
     * @return Collection|Category[]
     */
    public function getMenuCategories(Request $request)
    {
        $language = $request->language ?? DEFAULT_LANGUAGE;
        
        // Максимально простой подход - получаем все категории как плоский список
        // Frontend сам построит иерархию
        $categories = $this->repository
            ->where('language', $language)
            ->where('status', 'publish')
            ->whereNotNull('slug')
            ->where('slug', '!=', '')
            ->orderBy('sort_order', 'asc')
            ->orderBy('name')
            ->get([
                'id', 'name', 'slug', 'parent', 'icon', 'image',
                'menu_icon', 'menu_banner', 'details', 'status', 'sort_order'
            ]);
        
        return $categories;
    }

    /**
     * Test endpoint to debug categories data
     */
    public function debugCategories(Request $request)
    {
        $language = $request->language ?? DEFAULT_LANGUAGE;
        
        // Получаем простые данные без relations
        $categories = $this->repository
            ->where('language', $language)
            ->where('status', 'publish')
            ->orderBy('sort_order', 'asc')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'parent', 'status', 'sort_order'])
            ->toArray();
        
        return response()->json([
            'count' => count($categories),
            'sample' => array_slice($categories, 0, 5), // первые 5 записей
            'all' => $categories
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param CategoryCreateRequest $request
     * @return mixed
     * @throws ValidatorException
     */
    public function store(CategoryCreateRequest $request)
    {
        try {
            return $this->repository->saveCategory($request);
        } catch (MarvelException $th) {
            throw new MarvelException(COULD_NOT_CREATE_THE_RESOURCE);
        }
        // $language = $request->language ?? DEFAULT_LANGUAGE;
        // $translation_item_id = $request->translation_item_id ?? null;
        // $category->storeTranslation($translation_item_id, $language);
        // return $category;
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(Request $request, $params)
    {
        try {
            $language = $request->language ?? DEFAULT_LANGUAGE;
            if (is_numeric($params)) {
                $params = (int) $params;
                $category = $this->repository->with(['type', 'parent', 'children'])->where('id', $params)->firstOrFail();
            } else {
                $category = $this->repository->with(['type', 'parent', 'children'])->where('slug', $params)->where('language', $language)->firstOrFail();
            }
            return $category;
        } catch (ModelNotFoundException $e) {
            throw new MarvelException(NOT_FOUND);
        } catch (MarvelException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param CategoryUpdateRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(CategoryUpdateRequest $request, $id)
    {
        try {
            $request->merge(['id' => $id]);
            return $this->categoryUpdate($request);
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }


    public function categoryUpdate(CategoryUpdateRequest $request)
    {
        $category = $this->repository->findOrFail($request->id);
        return $this->repository->updateCategory($request, $category);
    }

    /**
     * Bulk update parent for multiple categories
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function bulkUpdateParent(Request $request)
    {
        try {
            $request->validate([
                'category_ids' => 'required|array|min:1',
                'category_ids.*' => 'integer|exists:categories,id',
                'parent_id' => 'nullable|integer|exists:categories,id',
            ]);

            $categoryIds = $request->category_ids;
            $parentId = $request->parent_id;

            // Проверяем, что родитель не является дочерней категорией
            if ($parentId) {
                $parentCategory = $this->repository->find($parentId);
                if (!$parentCategory) {
                    return response()->json(['error' => 'Parent category not found'], 404);
                }
            }

            // Обновляем родителя для выбранных категорий
            $updatedCount = 0;
            foreach ($categoryIds as $categoryId) {
                // Проверяем, что категория не пытается стать родителем самой себе
                if ($parentId && $categoryId == $parentId) {
                    continue;
                }
                
                $category = $this->repository->find($categoryId);
                if ($category) {
                    $category->update(['parent' => $parentId]);
                    $updatedCount++;
                }
            }

            return response()->json([
                'message' => "Successfully updated parent for {$updatedCount} categories",
                'updated_count' => $updatedCount
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Bulk update status for multiple categories
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function bulkUpdateStatus(Request $request)
    {
        try {
            $request->validate([
                'category_ids' => 'required|array|min:1',
                'category_ids.*' => 'integer|exists:categories,id',
                'status' => 'required|string|in:publish,draft',
            ]);

            $categoryIds = $request->category_ids;
            $status = $request->status;

            $updatedCount = 0;
            foreach ($categoryIds as $categoryId) {
                $category = $this->repository->find($categoryId);
                if ($category) {
                    $category->update(['status' => $status]);
                    $updatedCount++;
                }
            }

            return response()->json([
                'message' => "Successfully updated status for {$updatedCount} categories",
                'updated_count' => $updatedCount
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Reorder categories by updating their sort_order
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function reorder(Request $request)
    {
        try {
            // Поддерживаем два формата:
            // 1) { ids: number[] }
            // 2) { items: [{ id, parent_id, sort_order }] }

            $updatedCount = 0;

            if ($request->has('items')) {
                $request->validate([
                    'items' => 'required|array|min:1',
                    'items.*.id' => 'required|integer|exists:categories,id',
                    'items.*.parent_id' => 'nullable|integer|exists:categories,id',
                    'items.*.sort_order' => 'required|integer|min:0',
                ]);

                foreach ($request->items as $payload) {
                    try {
                        $category = $this->repository->find($payload['id']);
                        if ($category) {
                            $parentId = $payload['parent_id'] ?? $category->parent;
                            if ($parentId === $category->id) {
                                $parentId = $category->parent; // запрещаем делать родителем самого себя
                            }
                            $category->update([
                                'parent' => $parentId,
                                'sort_order' => max(0, (int) $payload['sort_order']),
                            ]);
                            $updatedCount++;
                        }
                    } catch (\Throwable $th) {
                        // продолжаем остальные элементы, а ошибку логируем
                        \Log::warning('Categories reorder skipped item', [
                            'payload' => $payload,
                            'error' => $th->getMessage(),
                        ]);
                    }
                }
            } else {
                $request->validate([
                    'ids' => 'required|array|min:1',
                    'ids.*' => 'integer|exists:categories,id',
                ]);

                foreach ($request->ids as $index => $categoryId) {
                    $category = $this->repository->find($categoryId);
                    if ($category) {
                        $category->update(['sort_order' => $index + 1]);
                        $updatedCount++;
                    }
                }
            }

            return response()->json([
                'message' => "Successfully reordered {$updatedCount} categories",
                'updated_count' => $updatedCount
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        try {
            return $this->repository->findOrFail($id)->delete();
        } catch (MarvelException $e) {
            throw new MarvelException(NOT_FOUND);
        }
    }
}
