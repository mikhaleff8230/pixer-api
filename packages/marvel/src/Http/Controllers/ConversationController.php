<?php


namespace Marvel\Http\Controllers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Conversation;
use Marvel\Database\Models\Shop;
use Marvel\Database\Repositories\ConversationRepository;
use Marvel\Exceptions\MarvelException;
use Marvel\Http\Requests\ConversationCreateRequest;
use Prettus\Validator\Exceptions\ValidatorException;


class ConversationController extends CoreController
{
    public $repository;

    public function __construct(ConversationRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Collection|Conversation[]
     */
    public function index(Request $request)
    {
        $limit = $request->limit ? $request->limit : 15;
        $conversation = $this->fetchConversations($request);

        return $conversation->paginate($limit);

    }

    public function show($conversation_id)
    {
        $user = Auth::user();
        $conversation = $this->repository->with(['shop', 'user.profile'])->findOrFail($conversation_id);
        abort_unless($user->shop_id === $conversation->shop_id || in_array( $conversation->shop_id, $user->shops->pluck('id')->toArray()) || $user->id === $conversation->user_id, 404, 'Unauthorized');

        // If request is for chat API, also return messages
        if (request()->is('chat/conversations/*') || request()->is('api/chat/conversations/*')) {
            $messages = \Marvel\Database\Models\Message::where('conversation_id', $conversation_id)
                ->with(['user', 'chatAttachments'])
                ->orderBy('created_at', 'asc')
                ->get();

            return response()->json([
                'conversation' => $conversation,
                'messages' => $messages
            ]);
        }

        return $conversation;
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return Query|Conversation[]
     */
    public function fetchConversations(Request $request)
    {
        return $this->repository->where(function($query) {
            $user = Auth::user();
            $query->where('user_id', $user->id);
            $query->orWhereIn('shop_id', $user->shops->pluck('id'));
            $query->orWhere('shop_id', $user->shop_id);
            $query->orderBy('updated_at', 'desc');
        })->with(['user.profile', 'shop']);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param ConversationCreateRequest $request
     * @return mixed
     * @throws ValidatorException
     */
    public function store(ConversationCreateRequest $request)
    {
        $user = $request->user();
        if(empty($user)) {
            throw new MarvelException(NOT_AUTHORIZED);
        }

        $shop = Shop::findOrFail($request->shop_id);
        if($shop->owner_id === $request->user()->id) {
            throw new MarvelException(YOU_CAN_NOT_SEND_MESSAGE_TO_YOUR_OWN_SHOP);
        }
        if($request->shop_id === $request->user()->shop_id) {
            throw new MarvelException(YOU_CAN_NOT_SEND_MESSAGE_TO_YOUR_OWN_SHOP);
        }
        $conversation = $this->repository->firstOrCreate([
            'user_id' => $user->id,
            'shop_id' => $request->shop_id
        ], [
            'type' => 'private',
        ]);
        
        // Загружаем связанные данные для фронтенда
        $conversation->load(['user.profile', 'shop']);
        
        // Явно возвращаем JSON с гарантией наличия ID
        return response()->json([
            'id' => $conversation->id,
            'user_id' => $conversation->user_id,
            'shop_id' => $conversation->shop_id,
            'type' => $conversation->type,
            'title' => $conversation->title,
            'user' => $conversation->user ? [
                'id' => $conversation->user->id,
                'name' => $conversation->user->name,
                'email' => $conversation->user->email,
            ] : null,
            'shop' => $conversation->shop ? [
                'id' => $conversation->shop->id,
                'name' => $conversation->shop->name,
            ] : null,
            'created_at' => $conversation->created_at,
            'updated_at' => $conversation->updated_at,
        ], 201);
    }
}
