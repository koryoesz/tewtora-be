<?php

namespace App\Domains\Core\Http\Controllers;

use App\Domains\Core\Http\Requests\SendMessageRequest;
use App\Domains\Core\Http\Resources\MessageResource;
use App\Domains\Core\Http\Resources\MessageThreadResource;
use App\Domains\Core\Models\MessageThread;
use App\Domains\Core\Repositories\MessageRepositoryInterface;
use App\Domains\Core\Repositories\MessageThreadRepositoryInterface;
use App\Domains\Core\Services\MessagingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MessageThreadController
{
    public function __construct(
        private readonly MessageThreadRepositoryInterface $threads,
        private readonly MessageRepositoryInterface $messages,
        private readonly MessagingService $messaging,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $account = $request->user();
        $threads = $this->threads->forAccount($account)->load(['plan', 'learnerAccountLink', 'teacherAccountLink']);

        $threads->each(fn (MessageThread $thread) => $thread->unread_count = $this->messaging->unreadCountFor($thread, $account));

        return MessageThreadResource::collection($threads);
    }

    public function show(Request $request, MessageThread $thread): JsonResponse
    {
        $request->user()->can('participate', $thread) || abort(403);

        $thread->load(['plan', 'learnerAccountLink', 'teacherAccountLink']);
        $thread->unread_count = $this->messaging->unreadCountFor($thread, $request->user());

        return response()->json([
            'thread' => new MessageThreadResource($thread),
            'messages' => MessageResource::collection($this->messages->forThread($thread)),
        ]);
    }

    public function store(SendMessageRequest $request, MessageThread $thread): MessageResource
    {
        $message = $this->messaging->sendMessage($thread, $request->user(), $request->validated('text'));

        return new MessageResource($message);
    }

    public function markRead(Request $request, MessageThread $thread): JsonResponse
    {
        $request->user()->can('participate', $thread) || abort(403);

        $this->messaging->markRead($thread, $request->user());

        return response()->json(status: 204);
    }

    public function report(Request $request, MessageThread $thread): JsonResponse
    {
        $request->user()->can('participate', $thread) || abort(403);

        $this->messaging->reportConcern($thread, $request->user());

        return response()->json(status: 204);
    }
}
