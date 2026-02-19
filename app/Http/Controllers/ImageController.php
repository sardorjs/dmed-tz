<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Image\UploadImageRequest;
use App\Http\Resources\ImageResource;
use App\Jobs\Image\DeleteImageJob;
use App\Jobs\Image\ProcessImageUploadJob;
use App\Models\User;
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

        ProcessImageUploadJob::dispatch($image->id);

        return (new ImageResource($image))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function destroy(Request $request, int $id, ShowImageUseCase $useCase): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $useCase->execute(userId: $user->id, imageId: $id);

        DeleteImageJob::dispatch($user->id, $id);

        return response()->json(
            ['message' => 'Image is being deleted.'],
            Response::HTTP_ACCEPTED,
        );
    }
}
