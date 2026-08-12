<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class InvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Invoice $invoice) {}

    public function envelope(): Envelope
    {
        $org = $this->invoice->organization?->name ?? 'WWC';

        return new Envelope(
            subject: "Rechnung {$this->invoice->number} – {$org}",
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: view('emails.invoice', [
                'invoice' => $this->invoice->loadMissing(['client', 'project', 'organization', 'items']),
            ])->render(),
        );
    }

    public function attachments(): array
    {
        if (! $this->invoice->pdf_path || ! Storage::disk('local')->exists($this->invoice->pdf_path)) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('local', $this->invoice->pdf_path)
                ->as($this->invoice->number.'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
