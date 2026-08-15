<?php

namespace App\Services;

use App\Mail\InvoiceMail;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceSequence;
use App\Models\Project;
use App\Models\SiteEvent;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class InvoiceService
{
    public function generateMonthly(Project $project, ?\Carbon\Carbon $forMonth = null, bool $sendEmail = true): Invoice
    {
        $forMonth = $forMonth?->copy()->startOfMonth() ?? now()->subMonth()->startOfMonth();
        $periodStart = $forMonth->copy()->startOfMonth();
        $periodEnd = $forMonth->copy()->endOfMonth();

        $existing = Invoice::where('project_id', $project->id)
            ->whereDate('period_start', $periodStart)
            ->whereDate('period_end', $periodEnd)
            ->first();
        if ($existing) {
            if ($sendEmail) {
                $this->sendToClient($existing);
            }

            return $existing->load(['items', 'client', 'project']);
        }

        $invoice = DB::transaction(function () use ($project, $periodStart, $periodEnd) {
            $number = $this->nextNumber($project->organization_id);
            $profile = $project->organization->billing_profile ?? [];
            $smallBusiness = (bool) ($profile['small_business'] ?? false);
            $taxRate = $smallBusiness ? 0 : (float) ($profile['tax_rate'] ?? 19);

            $subtotal = (int) $project->monthly_budget_cents;
            $tierLabel = match ($project->maintenance_tier) {
                '1' => '1. Stufe',
                '2' => '2. Stufe',
                '3' => '3. Stufe',
                'custom' => 'Custom',
                default => 'Wartung',
            };
            $events = SiteEvent::whereIn('site_id', $project->sites()->pluck('id'))
                ->whereBetween('occurred_at', [$periodStart, $periodEnd])
                ->count();

            $items = [
                [
                    'description' => "WordPress-Wartung „{$project->name}“ – {$tierLabel} ({$periodStart->format('m/Y')})",
                    'quantity' => 1,
                    'unit_price_cents' => $subtotal,
                ],
            ];

            $scope = array_merge(Project::DEFAULT_SCOPE, $project->scope ?? []);
            if ($events > 0) {
                $items[] = [
                    'description' => "Incident-/Event-Monitoring ({$events} Events)",
                    'quantity' => 1,
                    'unit_price_cents' => 0,
                ];
            }
            $included = (float) ($scope['hours_included'] ?? 0);
            $minutes = (int) \App\Models\TimeEntry::where('project_id', $project->id)
                ->where('billable', true)
                ->whereBetween('occurred_at', [$periodStart, $periodEnd])
                ->sum('minutes');
            $usedHours = round($minutes / 60, 2);
            if ($included > 0) {
                $items[] = [
                    'description' => "Enthaltene Support-Stunden: {$included} (verbraucht: {$usedHours})",
                    'quantity' => 1,
                    'unit_price_cents' => 0,
                ];
            }
            $overage = max(0, $usedHours - $included);
            if ($overage > 0) {
                $rate = (int) (($project->organization->billing_profile['overage_rate_cents'] ?? 15000));
                $items[] = [
                    'description' => "Mehrarbeit {$overage} Std. à ".number_format($rate / 100, 2, ',', '.').' €',
                    'quantity' => 1,
                    'unit_price_cents' => (int) round($overage * $rate),
                ];
            }

            $subtotalCents = collect($items)->sum(fn ($i) => $i['unit_price_cents'] * $i['quantity']);
            $taxCents = (int) round($subtotalCents * ($taxRate / 100));
            $totalCents = $subtotalCents + $taxCents;

            $invoice = Invoice::create([
                'organization_id' => $project->organization_id,
                'client_id' => $project->client_id,
                'project_id' => $project->id,
                'number' => $number,
                'status' => 'draft',
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'issued_at' => now(),
                'due_at' => now()->addDays(14),
                'subtotal_cents' => $subtotalCents,
                'tax_cents' => $taxCents,
                'total_cents' => $totalCents,
                'tax_rate' => $taxRate,
                'small_business' => $smallBusiness,
                'currency' => $project->currency,
            ]);

            foreach ($items as $item) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit_price_cents' => $item['unit_price_cents'],
                    'total_cents' => $item['unit_price_cents'] * $item['quantity'],
                ]);
            }

            $invoice->load(['items', 'client', 'project', 'organization']);
            $pdfPath = $this->renderPdf($invoice);
            $invoice->update(['pdf_path' => $pdfPath, 'status' => 'draft']);

            return $invoice->fresh(['items', 'client', 'project', 'organization']);
        });

        if ($sendEmail) {
            $this->sendToClient($invoice);
        }

        return $invoice;
    }

    public function send(Invoice $invoice): Invoice
    {
        $this->sendToClient($invoice);
        $invoice->update(['status' => 'sent', 'issued_at' => $invoice->issued_at ?? now()]);

        return $invoice->fresh(['items', 'client', 'project']);
    }

    public function sendToClient(Invoice $invoice): bool
    {
        $invoice->loadMissing(['client', 'organization', 'project', 'items']);
        $email = $invoice->client?->email;
        if (! $email) {
            Log::info('Invoice email skipped – client has no email', ['invoice' => $invoice->number]);

            return false;
        }

        try {
            Mail::to($email)->send(new InvoiceMail($invoice));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Invoice email failed', [
                'invoice' => $invoice->number,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function nextNumber(string $organizationId): string
    {
        $year = (int) now()->format('Y');
        $seq = InvoiceSequence::firstOrCreate(
            ['organization_id' => $organizationId, 'year' => $year],
            ['last_number' => 0]
        );
        $seq->last_number++;
        $seq->save();

        return sprintf('WWC-%d-%04d', $year, $seq->last_number);
    }

    private function renderPdf(Invoice $invoice): string
    {
        $org = $invoice->organization;
        $profile = $org->billing_profile ?? [];
        $html = view('invoices.pdf', [
            'invoice' => $invoice,
            'profile' => $profile,
        ])->render();

        $path = 'invoices/'.$invoice->number.'.pdf';
        $pdf = Pdf::loadHTML($html);
        Storage::disk('local')->put($path, $pdf->output());

        return $path;
    }
}
