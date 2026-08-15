<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AlertMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public string $alertSubject,
        public array $lines,
        public ?string $actionUrl = null,
        public string $severity = 'warning',
    ) {}

    public function envelope(): Envelope
    {
        $prefix = match ($this->severity) {
            'critical' => '[KRITISCH] ',
            'error' => '[FEHLER] ',
            'info' => '',
            default => '[Warnung] ',
        };

        return new Envelope(subject: $prefix.$this->alertSubject);
    }

    public function content(): Content
    {
        $color = match ($this->severity) {
            'critical', 'error' => '#b91c1c',
            'info' => '#1d4ed8',
            default => '#b45309',
        };

        $body = '<div style="font-family:system-ui,-apple-system,sans-serif;max-width:600px;margin:0 auto;padding:16px">';
        $body .= '<h2 style="color:'.$color.';font-size:18px">'.e($this->alertSubject).'</h2>';
        foreach ($this->lines as $line) {
            $body .= '<p style="margin:6px 0;color:#111827;font-size:14px">'.e($line).'</p>';
        }
        if ($this->actionUrl) {
            $body .= '<p style="margin:18px 0"><a href="'.e($this->actionUrl).'" style="background:'.$color.';color:#fff;padding:9px 16px;border-radius:6px;text-decoration:none;font-size:14px">Im Portal öffnen</a></p>';
        }
        $body .= '<p style="color:#6b7280;font-size:12px;margin-top:22px">Diese Nachricht wurde automatisch von der WWC-Wartungsplattform gesendet.</p>';
        $body .= '</div>';

        return new Content(htmlString: $body);
    }
}
