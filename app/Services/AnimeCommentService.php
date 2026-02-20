<?php

namespace App\Services;

use App\Models\Anime;
use App\Models\AnimeComment;
use App\Models\AnimeCommentVote;
use App\Models\User;
use App\Support\CommentBodySanitizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AnimeCommentService
{
    public function __construct(
        private readonly CommentBodySanitizer $sanitizer
    ) {}

    public function paginateForAnime(Anime $anime, ?User $viewer, string $sort = 'new', int $perPage = 10, int $page = 1): array
    {
        $sort = $this->normalizeSort($sort);

        $query = AnimeComment::query()
            ->where('anime_id', $anime->id)
            ->whereNull('parent_id')
            ->whereIn('status', ['active', 'deleted'])
            ->with([
                'user:id,name,avatar_path',
                'replyToUser:id,name',
                'replies' => fn($q) => $q
                    ->whereIn('status', ['active', 'deleted'])
                    ->with([
                        'user:id,name,avatar_path',
                        'replyToUser:id,name',
                    ])
                    ->orderBy('created_at'),
            ]);

        if ($sort === 'top') {
            $query->orderByRaw('(likes_count - dislikes_count) DESC')->orderByDesc('created_at');
        } else {
            $query->orderByDesc('created_at');
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $ids = [];
        foreach ($paginator->items() as $root) {
            $ids[] = (int) $root->id;
            foreach ($root->replies as $reply) {
                $ids[] = (int) $reply->id;
            }
        }

        $voteMap = $this->buildVoteMap($ids, $viewer);

        $items = collect($paginator->items())
            ->map(fn(AnimeComment $root) => $this->serializeComment($root, $viewer, $voteMap, true))
            ->values()
            ->all();

        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
            'sort' => $sort,
        ];
    }

    public function create(Anime $anime, User $author, array $payload): AnimeComment
    {
        $body = $this->sanitizer->sanitize((string) ($payload['body'] ?? ''));
        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => 'Comment is empty after sanitization.',
            ]);
        }

        $parentId = null;
        $replyToUserId = isset($payload['reply_to_user_id']) ? (int) $payload['reply_to_user_id'] : null;
        $targetCommentId = isset($payload['parent_id']) ? (int) $payload['parent_id'] : 0;
        $targetComment = null;

        if ($targetCommentId > 0) {
            $targetComment = AnimeComment::query()->with('parent')->find($targetCommentId);

            if (!$targetComment || (int) $targetComment->anime_id !== (int) $anime->id) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Parent comment does not belong to this anime.',
                ]);
            }

            $rootParent = $targetComment->parent_id ? $targetComment->parent : $targetComment;

            if (!$rootParent) {
                throw ValidationException::withMessages([
                    'parent_id' => 'Cannot reply to this comment.',
                ]);
            }

            // Enforce one-level nesting
            $parentId = (int) $rootParent->id;

            // If reply target is not passed, default to clicked comment author.
            if ($replyToUserId ===  null) {
                $replyToUserId = (int) $targetComment->user_id;
            }

            if ($replyToUserId === (int) $author->id) {
                $replyToUserId = null;
            }

            // Ensure reply_to_user_id is inside the same root thread.
            if ($replyToUserId !== null) {
                $belongsToThread = AnimeComment::query()
                    ->where('anime_id', $anime->id)
                    ->where(function ($q) use ($parentId) {
                        $q->where('id', $parentId)->orWhere('parent_id', $parentId);
                    })
                    ->where('user_id', $replyToUserId)
                    ->exists();
                if (!$belongsToThread) {
                    $replyToUserId = null;
                }
            }
        }

        return DB::transaction(function () use ($anime, $author, $body, $payload, $parentId, $replyToUserId) {
            $comment = AnimeComment::query()->create([
                'anime_id' => $anime->id,
                'user_id' => $author->id,
                'parent_id' => $parentId,
                'reply_to_user_id' => $replyToUserId,
                'body' => $body,
                'has_spoiler' => (bool) ($payload['has_spoiler'] ?? false),
                'status' => 'active',
            ]);

            if ($parentId !== null) {
                AnimeComment::query()
                    ->where('id', $parentId)
                    ->increment('replies_count');
            }

            return $comment->fresh([
                'user:id,name,avatar_path',
                'replyToUser:id,name',
            ]);
        });
    }

    public function update(AnimeComment $comment, User $actor, array $payload): AnimeComment
    {
        if (!$this->canManageComment($actor, $comment)) {
            throw new AuthorizationException('You cannot edit this comment.');
        }

        if ((string) $comment->status !== 'active') {
            throw ValidationException::withMessages([
                'comment' => 'Only active comments can be edited.',
            ]);
        }

        $body = $this->sanitizer->sanitize((string) ($payload['body'] ?? ''));
        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => 'Comment is empty after sanitization.',
            ]);
        }

        $comment->fill([
            'body' => $body,
            'has_spoiler' => (bool) ($payload['has_spoiler'] ?? false),
            'is_edited' => true,
            'edited_at' => now(),
        ])->save();

        return $comment->fresh([
            'user:id,name,avatar_path',
            'replyToUser:id,name',
        ]);
    }

    public function softDelete(AnimeComment $comment, User $actor): AnimeComment
    {
        if (!$this->canManageComment($actor, $comment)) {
            throw new AuthorizationException('You cannot delete this comment.');
        }

        if ((string) $comment->status === 'deleted') {
            return $comment->fresh([
                'user:id,name,avatar_path',
                'replyToUser:id,name',
            ]);
        }

        DB::transaction(function () use ($comment) {
            AnimeCommentVote::query()->where('comment_id', $comment->id)->delete();

            $comment->fill([
                'status' => 'deleted',
                'body' => null,
                'has_spoiler' => false,
                'is_edited' => false,
                'edited_at' => null,
                'likes_count' => 0,
                'dislikes_count' => 0,
            ])->save();
        });

        return $comment->fresh([
            'user:id,name,avatar_path',
            'replyToUser:id,name',
        ]);
    }

    public function vote(AnimeComment $comment, User $actor, int $vote): AnimeComment
    {
        if ((string) $comment->status !== 'active') {
            throw ValidationException::withMessages([
                'comment' => 'You can vote only on active comments.',
            ]);
        }

        if ((int) $comment->user_id === (int) $actor->id) {
            throw ValidationException::withMessages([
                'vote' => 'You cannot vote for your own comment.',
            ]);
        }

        DB::transaction(function () use ($comment, $actor, $vote) {
            $existing = AnimeCommentVote::query()
                ->where('comment_id', $comment->id)
                ->where('user_id', $actor->id)
                ->first();

            if ($vote === 0) {
                if ($existing) {
                    $this->applyVoteCounter((int) $comment->id, (int) $existing->vote, -1);
                    $existing->delete();
                }
                return;
            }

            if (!$existing) {
                AnimeCommentVote::query()->create([
                    'comment_id' => $comment->id,
                    'user_id' => $actor->id,
                    'vote' => $vote,
                ]);
                $this->applyVoteCounter((int) $comment->id, $vote, 1);
                return;
            }

            if ((int) $existing->vote === $vote) {
                //Toggle off when same vote clicked again
                $this->applyVoteCounter((int) $comment->id, (int) $existing->vote, -1);
                $existing->delete();
                return;
            }

            $this->applyVoteCounter((int) $comment->id, (int) $existing->vote, -1);
            $existing->update(['vote' => $vote]);
            $this->applyVoteCounter((int) $comment->id, $vote, 1);
        });

        return $comment->fresh([
            'user:id,name,avatar_path',
            'replyToUser:id,name',
        ]);
    }

    public function serializeOne(AnimeComment $comment, ?User $viewer): array
    {
        $comment->loadMissing([
            'user:id,name,avatar_path',
            'replyToUser:id,name',
        ]);

        $voteMap = $this->buildVoteMap([(int) $comment->id], $viewer);

        return $this->serializeComment($comment, $viewer, $voteMap, false);
    }

    private function normalizeSort(string $sort): string{
        $value = strtolower(trim($sort));
        return in_array($value, ['new', 'top'], true) ? $value : 'new';
    }

    private function buildVoteMap(array $commentIds, ?User $viewer): array
    {
        if (!$viewer || empty($commentIds)) {
            return [];
        }

        return AnimeCommentVote::query()
            ->where('user_id', $viewer->id)
            ->whereIn('comment_id', array_unique($commentIds))
            ->pluck('vote', 'comment_id')
            ->map(fn($v) => (int) $v)
            ->all();
    }

    private function canManageComment(User $user, AnimeComment $comment): bool
    {
        if ((int) $user->id === (int) $comment->user_id) {
            return true;
        }

        return in_array((string) $user->role, ['admin', 'manager'], true);
    }

    private function applyVoteCounter(int $commentId, int $vote, int $delta): void
    {
        if ($vote === 1) {
            if ($delta > 0) {
                AnimeComment::query()->where('id', $commentId)->increment('likes_count');
            } else {
                AnimeComment::query()->where('id', $commentId)->update([
                    'likes_count' => DB::raw('GREATEST(likes_count - 1, 0)'),
                ]);
            }
            return;
        }

        if ($vote === -1) {
            if ($delta > 0) {
                AnimeComment::query()->where('id', $commentId)->increment('dislikes_count');
            } else {
                AnimeComment::query()->where('id', $commentId)->update([
                    'dislikes_count' => DB::raw('GREATEST(dislikes_count - 1, 0)'),
                ]);
            }
        }
    }

    private function serializeComment(AnimeComment $comment, ?User $viewer, array $voteMap, bool $includeReplies): array
    {
        $isDeleted = (string) $comment->status === 'deleted';
        $canManage = $viewer ? $this->canManageComment($viewer, $comment) : false;
        $user = $comment->user;

        $avatarPath = (string) ($user?->avatar_path ?? '');
        $avatarUrl = $avatarPath !== '' ? Storage::url($avatarPath) : null;

        $result = [
            'id' => (int) $comment->id,
            'anime_id' => (int) $comment->anime_id,
            'parent_id' => $comment->parent_id ? (int) $comment->parent_id : null,
            'reply_to_user' => $comment->replyToUser ? [
                'id' => (int) $comment->replyToUser->id,
                'name' => (string) $comment->replyToUser->name,
            ] : null,
            'body' => $isDeleted ? null : (string) ($comment->body ?? ''),
            'has_spoiler' => $isDeleted ? false : (bool) $comment->has_spoiler,
            'is_deleted' => $isDeleted,
            'is_edited' => (bool) $comment->is_edited,
            'edited_at' => optional($comment->edited_at)?->toIso8601String(),
            'created_at' => optional($comment->created_at)?->toIso8601String(),
            'likes_count' => (int) $comment->likes_count,
            'dislikes_count' => (int) $comment->dislikes_count,
            'replies_count' => (int) $comment->replies_count,
            'my_vote' => (int) ($voteMap[(int) $comment->id] ?? 0),
            'can_edit' => $canManage && !$isDeleted,
            'can_delete' => $canManage,
            'user' => [
                'id' => (int) ($user?->id ?? 0),
                'name' => (string) ($user?->name ?? 'Unknown'),
                'avatar_path' => $user?->avatar_path,
                'avatar_url' => $avatarUrl,
            ],
            'replies' => [],
        ];

        if($includeReplies){
            $result['replies'] = $comment->replies
                ->map(fn(AnimeComment $reply) => $this->serializeComment($reply, $viewer, $voteMap, false))
                ->values()
                ->all();
        }

        return $result;
    }
}
