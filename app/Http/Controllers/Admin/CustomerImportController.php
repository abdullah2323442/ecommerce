<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CustomerImportRequest;
use App\Services\Import\CustomerImport;
use App\Services\Stock\StockException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Bring in the shops a business already has from one CSV file: a preview of every line first, and nothing is written until the owner says go on. */
class CustomerImportController extends Controller
{
    private const KEEP_MINUTES = 30;

    public function __construct(private CustomerImport $import) {}

    public function index(Request $request): Response
    {
        $this->authorize('manage-customers');

        return Inertia::render('admin/customers/import', ['columns' => CustomerImport::COLUMNS, 'maxRows' => CustomerImport::MAX_ROWS, 'preview' => null, 'token' => null, 'done' => $request->session()->get('import_done')]);
    }

    public function template(Request $request): StreamedResponse
    {
        $this->authorize('manage-customers');

        return response()->streamDownload(fn () => print "\xEF\xBB\xBF".$this->import->template(), 'shops-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function preview(CustomerImportRequest $request): Response|RedirectResponse
    {
        $content = (string) file_get_contents($request->file('file')->getRealPath());

        try {
            $preview = $this->import->preview($content);
        } catch (StockException $e) {
            return back()->withErrors(['file' => __($e->getMessage())]);
        }

        // The file waits for a short while, for this person only, so what is committed is the file that was checked.
        $token = Str::random(40);
        Cache::put($this->key($request, $token), $content, now()->addMinutes(self::KEEP_MINUTES));

        return Inertia::render('admin/customers/import', ['columns' => CustomerImport::COLUMNS, 'maxRows' => CustomerImport::MAX_ROWS, 'preview' => ['lines' => $preview['lines'], 'counts' => $preview['counts']], 'token' => $token, 'done' => null]);
    }

    public function commit(Request $request, string $token): RedirectResponse
    {
        $this->authorize('manage-customers');
        $content = Cache::pull($this->key($request, $token));

        if ($content === null) {
            return to_route('admin.customers.import')->withErrors(['file' => __('That file is no longer kept. Send it again.')]);
        }

        try {
            $done = $this->import->commit($content, $request->user());
        } catch (StockException $e) {
            return to_route('admin.customers.import')->withErrors(['file' => __($e->getMessage())]);
        }

        return to_route('admin.customers.import')->with('import_done', $done);
    }

    private function key(Request $request, string $token): string
    {
        return 'customer-import:'.$request->user()->id.':'.$token;
    }
}
