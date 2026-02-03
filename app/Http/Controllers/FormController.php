<?php

namespace App\Http\Controllers;

use App\Models\Form;
use App\Http\Requests\CreateFormRequest;
use App\Http\Resources\FormResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Concerns\Pagination;
use Inertia\Inertia;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;

class FormController extends Controller
{
    use AuthorizesRequests, Pagination;

    public function index(Request $request, ?int $userId = null)
    {
        $this->authorize('viewAny', Form::class);

        $userId ??= Auth::id();

        $query = Form::query()
            ->ofUser($userId)
            ->withCount('suggestions')
            ->latest();

        return $this->paginateWithResource(
            $query,
            FormResource::class,
            $request,
            ['per_page' => 15, 'max_per_page' => 100]
        );
    }

    public function store(CreateFormRequest $request)
    {
        $this->authorize('store', Form::class);

        $data = $request->validated();
        $data['user_id'] = Auth::id();

        if (!empty($data['img'])) {
            $path = $data['img']->store('forms', 'public');
            $data['img_path'] = "storage/{$path}";
        }

        $form = Form::create($data);

        return response()->json([
            'message' => 'Form created successfully',
            'data' => new FormResource($form),
        ], 201);
    }

    public function show(string $slug)
    {
        $form = Form::where('slug', $slug)
            ->whereRaw('is_active IS TRUE')
            ->firstOrFail();

        $student = Auth::user();

        return Inertia::render('form/SuggestionForm', [
            'form' => [
                'id' => $form->id,
                'title' => $form->title,
                'description' => $form->description,
                'slug' => $form->slug,
                'img_url' => $form->img_url,
                'url' => $form->url,
                'qr_code_url' => $form->qr_code_url,
            ],
            'userEmail' => $student?->email ?? null,
        ]);
    }

    public function qrcode(string $slug)
    {
        $form = Form::where('slug', $slug)
            ->whereRaw('is_active IS TRUE')
            ->firstOrFail();

        $result = new Builder(
            writer: new SvgWriter(),
            data: $form->url,
            size: 300,
            margin: 10,
        );

        $qr = $result->build();

        return response($qr->getString())
            ->header('Content-Type', $qr->getMimeType());
    }
}
