<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Project;
use App\Services\InvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->attributes->get('organization_id');

        return response()->json([
            'data' => Invoice::where('organization_id', $orgId)
                ->with(['client:id,name', 'project:id,name'])
                ->latest('issued_at')
                ->get(),
        ]);
    }

    public function generate(Request $request, InvoiceService $service)
    {
        $orgId = $request->attributes->get('organization_id');
        $data = $request->validate([
            'project_id' => 'required|uuid',
            'month' => 'nullable|date',
        ]);

        $project = Project::where('organization_id', $orgId)->findOrFail($data['project_id']);
        $month = isset($data['month']) ? \Carbon\Carbon::parse($data['month']) : null;
        $invoice = $service->generateMonthly($project, $month);

        return response()->json(['data' => $invoice], 201);
    }

    public function show(Request $request, string $id)
    {
        $orgId = $request->attributes->get('organization_id');
        $invoice = Invoice::where('organization_id', $orgId)
            ->with(['items', 'client', 'project'])
            ->findOrFail($id);

        return response()->json(['data' => $invoice]);
    }

    public function markPaid(Request $request, string $id)
    {
        $orgId = $request->attributes->get('organization_id');
        $invoice = Invoice::where('organization_id', $orgId)->findOrFail($id);
        $invoice->update(['status' => 'paid']);

        return response()->json(['data' => $invoice]);
    }

    public function pdf(Request $request, string $id)
    {
        $orgId = $request->attributes->get('organization_id');
        $invoice = Invoice::where('organization_id', $orgId)->findOrFail($id);
        if (! $invoice->pdf_path || ! Storage::disk('local')->exists($invoice->pdf_path)) {
            return response()->json(['message' => 'PDF not found'], 404);
        }

        return Storage::disk('local')->download($invoice->pdf_path, $invoice->number.'.pdf');
    }

    public function exportCsv(Request $request)
    {
        $orgId = $request->attributes->get('organization_id');
        $invoices = Invoice::where('organization_id', $orgId)->with('client')->get();
        $lines = ["number,client,status,issued_at,total_cents,currency"];
        foreach ($invoices as $inv) {
            $lines[] = implode(',', [
                $inv->number,
                '"'.str_replace('"', '""', $inv->client->name ?? '').'"',
                $inv->status,
                $inv->issued_at?->format('Y-m-d'),
                $inv->total_cents,
                $inv->currency,
            ]);
        }

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="invoices.csv"',
        ]);
    }
}
