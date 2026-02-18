<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Image\UploadImageRequest;
use App\Http\Resources\ImageResource;
use App\Models\User;
use App\UseCases\Image\DeleteImageUseCase;
use App\UseCases\Image\ListImagesUseCase;
use App\UseCases\Image\ShowImageUseCase;
use App\UseCases\Image\UploadImageUseCase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ImageController extends Controller
{
    public function index(Request $request, ListImagesUseCase $useCase): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $images = $useCase->execute($user->id);

        return ImageResource::collection($images)->response();
    }

    public function show(Request $request, int $id, ShowImageUseCase $useCase): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $image = $useCase->execute(userId: $user->id, imageId: $id);

        return (new ImageResource($image))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    public function store(UploadImageRequest $request, UploadImageUseCase $useCase): JsonResponse
    {
        $image = $useCase->execute($request->toDto());

        return (new ImageResource($image))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function destroy(Request $request, int $id, DeleteImageUseCase $useCase): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $useCase->execute(userId: $user->id, imageId: $id);

        return response()->json(['message' => 'Image deleted successfully.'], Response::HTTP_OK);
    }
}
