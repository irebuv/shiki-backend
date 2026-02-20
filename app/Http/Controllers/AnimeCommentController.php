<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAnimeCommentRequest;
use App\Http\Requests\UpdateAnimeCommentRequest;
use App\Http\Requests\VoteAnimeCommentRequest;
use App\Models\Anime;
use App\Models\AnimeComment;
use App\Models\User;
use App\Services\AnimeCommentService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AnimeCommentController extends Controller{
    public function __construct(
        private readonly AnimeCommentService $commentService
    ){}

    public function index(Request $request, string $slug){
        $validated = $request->validate([
            'sort' => ['nullable' , 'string', 'in:new,top'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $anime = Anime::query()->where('slug', $slug)->first();
        if(!$anime){
            return response()->json([
                'message' => 'Anime not found.',
                'data' => null,
                'errors' => null,
            ], 404);
        }

        $viewer = auth('api')->user();
        $viewer = $viewer instanceof User ? $viewer : null;

        $data = $this->commentService->paginateForAnime(
            $anime,
            $viewer,
            (string) ($validated['sort'] ?? 'new'),
            (int) ($validated['per_page'] ?? 10),
            (int) ($validated['page'] ?? 1),
        );

        return response()->json([
            'message' => 'Comments loaded.',
            'data' => $data,
            'errors' => null,
        ]);
    }

    public function store(StoreAnimeCommentRequest $request, Anime $anime){
        $user = auth('api')->user();
        if (!$user instanceof User){
            return response()->json([
                'message' => 'Unauthenticated.',
                'data' => null,
                'errors' => null,
            ], 401);
        }

        try {
            $comment = $this->commentService->create($anime, $user, $request->validated());

            return response()->json([
                'message' => 'Comment created.',
                'data' => [
                    'item' => $this->commentService->serializeOne($comment, $user),
                ],
                'errors' => null,
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation error.',
                'data' => null,
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function update(UpdateAnimeCommentRequest $request, AnimeComment $comment){
        $user = auth('api')->user();
        if(!$user instanceof User) {
            return response()->json([
                'message' => 'Unauthenticated.',
                'data' => null,
                'errors' => null,
            ], 401);
        }

        try {
            $updated = $this->commentService->update($comment, $user, $request->validated());

            return response()->json([
                'message' => 'Comment updated.',
                'data' => [
                    'item' => $this->commentService->serializeOne($updated, $user),
                ],
                'errors' => null,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation error.',
                'data' => null,
                'errors' => $e->errors(),
            ], 422);
        } catch (AuthorizationException $e){
            return response()->json([
                'message' => 'Forbidden.',
                'data' => null,
                'errors' => null,
            ], 403);
        }
    }

    public function destroy(AnimeComment $comment){
        $user = auth('api')->user();
        if(!$user instanceof User){
            return response()->json([
                'message' => 'Unauthenticated.',
                'data' => null,
                'errors' => null,
            ], 401);
        }

        try {
            $deleted = $this->commentService->softDelete($comment, $user);

            return response()->json([
                'message' => 'Comment deleted.',
                'data' => [
                    'item' => $this->commentService->serializeOne($deleted, $user),
                ],
                'errors' => null,
            ]);
        } catch (AuthorizationException $e) {
            return response()->json([
                'message' => 'Forbidden.',
                'data' => null,
                'errors' => null,
            ], 403);
        }
    }

    public function vote(VoteAnimeCommentRequest $request, AnimeComment $comment){
        $user = auth('api')->user();
        if(!$user instanceof User){
            return response()->json([
                'message' => 'Unauthenticated.',
                'data' => null,
                'errors' => null,
            ], 401);
        }

        try {
            $updated = $this->commentService->vote(
                $comment,
                $user,
                (int) $request->validated('vote')
            );

            return response()->json([
                'message' => 'Vote updated.',
                'data' => [
                    'item' => $this->commentService->serializeOne($updated, $user),
                ],
                'errors' => null,
            ]);
        } catch (ValidationException $e){
            $message = collect($e->errors())->flatten()->first() ?? 'Validation error.';
            return response()->json([
                'message' => $message,
                'data' => null,
                'errors' => $e->errors(),
            ], 422);
        }
    }
}
