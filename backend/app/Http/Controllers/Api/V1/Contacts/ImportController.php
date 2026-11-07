<?php

namespace App\Http\Controllers\Api\V1\Contacts;

use App\Exceptions\ApiException;
use App\Http\Controllers\Concerns\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\ContactImport;
use App\Models\Workspace;
use App\Services\Audit;
use App\Services\Contacts\Importer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Contacts → Import: upload a CSV, confirm the column matching, follow progress. */
class ImportController extends Controller
{
    use WorkspaceAccess;

    public function __construct(private Importer $importer) {}

    public function index(Workspace $workspace): JsonResponse
    {
        return response()->json(['imports' => ContactImport::where('workspace_id', $workspace->id)->orderByDesc('id')->limit(20)->get()->map(fn ($i) => self::present($i))]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->requireEditor($request, 'import contacts');
        $request->validate(['file' => ['required', 'file', 'max:25600', 'mimes:csv,txt']], ['file.mimes' => 'Upload a .csv file.', 'file.max' => 'Files can be up to 25 MB.']);
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            throw new ApiException('validation_failed', 'Upload a .csv file.', 422, ['file' => 'Upload a .csv file.']);
        }
        $import = $this->importer->create($workspace, $this->currentUser($request), $file);

        return response()->json(['import' => self::present($import)], 201);
    }

    public function show(Workspace $workspace, string $id): JsonResponse
    {
        return response()->json(['import' => self::present($this->find($workspace, $id))]);
    }

    public function start(Request $request, Workspace $workspace, string $id): JsonResponse
    {
        $this->requireEditor($request, 'import contacts');
        $import = $this->importer->start($this->find($workspace, $id), $workspace, (array) $request->input('mapping', []), (array) $request->input('options', []));
        Audit::log('contacts.import_started', $this->currentUser($request), $workspace->id, ['file' => $import->filename, 'rows' => $import->total_rows]);

        return response()->json(['import' => self::present($import)]);
    }

    /** CSV of the rows that weren't imported, with the reason (and a suggested fix for typos). */
    public function skipped(Workspace $workspace, string $id): BinaryFileResponse
    {
        $import = $this->find($workspace, $id);
        $path = Importer::rejectedPath($import);
        if (! Storage::disk('local')->exists($path)) {
            throw new ApiException('not_found', 'Every row was imported.', 404);
        }

        return response()->download(Storage::disk('local')->path($path), 'skipped-rows-'.pathinfo($import->filename, PATHINFO_FILENAME).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function find(Workspace $workspace, string $id): ContactImport
    {
        return ContactImport::where('workspace_id', $workspace->id)->find($id) ?? throw new ApiException('not_found', 'Import not found.', 404);
    }

    /** @return array<string, mixed> */
    public static function present(ContactImport $i): array
    {
        $opt = $i->options ?? [];

        return [
            'id' => $i->id, 'filename' => $i->filename, 'status' => $i->status, 'total_rows' => $i->total_rows, 'processed_rows' => $i->processed_rows,
            'columns' => $i->columns, 'mapping' => $i->mapping, 'sample' => $i->status === 'uploaded' ? ($opt['sample'] ?? []) : null,
            'stats' => $i->stats, 'error' => $i->error, 'has_skipped' => Storage::disk('local')->exists(Importer::rejectedPath($i)),
            'created_at' => $i->created_at?->toIso8601ZuluString(), 'finished_at' => $i->finished_at?->toIso8601ZuluString(),
        ];
    }
}
