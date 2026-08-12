<?php

declare(strict_types=1);

final class WWC_Agent_Job_Progress
{
    private static ?string $jobId = null;

    /** @var list<array{from:int,to:int}> */
    private static array $scopes = [];

    /** @var list<array{at:string,message:string,percent:int}> */
    private static array $pendingLog = [];

    private static int $lastSentAt = 0;

    private static int $lastSentPercent = -1;

    private static string $lastSentLabel = '';

    public static function begin(string $jobId): void
    {
        self::$jobId = $jobId !== '' ? $jobId : null;
        self::$scopes = [];
        self::$pendingLog = [];
        self::$lastSentAt = 0;
        self::$lastSentPercent = -1;
        self::$lastSentLabel = '';
    }

    public static function end(): void
    {
        if (self::$jobId !== null && self::$pendingLog !== []) {
            try {
                self::flush(true);
            } catch (Throwable) {
                // ignore
            }
        }
        self::$jobId = null;
        self::$scopes = [];
        self::$pendingLog = [];
    }

    public static function currentJobId(): ?string
    {
        return self::$jobId;
    }

    /**
     * Map nested work (e.g. backup inside staging) into a percent window.
     */
    public static function pushScope(int $from, int $to): void
    {
        self::$scopes[] = ['from' => max(0, min(99, $from)), 'to' => max(0, min(99, $to))];
    }

    public static function popScope(): void
    {
        array_pop(self::$scopes);
    }

    /**
     * @throws WWC_Agent_Cancelled_Exception
     */
    public static function log(string $message, ?int $percent = null, bool $force = false): void
    {
        if (self::$jobId === null) {
            return;
        }
        $mapped = $percent === null
            ? (self::$lastSentPercent >= 0 ? self::$lastSentPercent : 0)
            : self::mapPercent($percent);

        self::$pendingLog[] = [
            'at' => gmdate('c'),
            'message' => mb_substr($message, 0, 300),
            'percent' => $mapped,
        ];

        if (count(self::$pendingLog) > 40) {
            self::$pendingLog = array_slice(self::$pendingLog, -40);
        }

        if ($percent !== null) {
            // report() would duplicate the same log line — send with empty label
            self::report($percent, '', $force);
            self::$lastSentLabel = $message;
        } else {
            self::flush($force);
        }
    }

    /**
     * @throws WWC_Agent_Cancelled_Exception
     */
    public static function report(int $percent, string $label = '', bool $force = false): void
    {
        if (self::$jobId === null) {
            return;
        }

        if (WWC_Agent_Background::is_cancelled(self::$jobId)) {
            throw new WWC_Agent_Cancelled_Exception('Job cancelled');
        }

        $mapped = self::mapPercent($percent);
        if ($label !== '') {
            self::$pendingLog[] = [
                'at' => gmdate('c'),
                'message' => mb_substr($label, 0, 300),
                'percent' => $mapped,
            ];
            if (count(self::$pendingLog) > 40) {
                self::$pendingLog = array_slice(self::$pendingLog, -40);
            }
        }

        $now = time();
        $percentChanged = abs($mapped - self::$lastSentPercent) >= 1;
        $labelChanged = $label !== '' && $label !== self::$lastSentLabel;
        $due = ($now - self::$lastSentAt) >= 2;

        if (! $force && ! $percentChanged && ! ($labelChanged && $due) && ! ($due && self::$pendingLog !== [])) {
            return;
        }
        if (! $force && ! $due && ! $percentChanged && abs($mapped - self::$lastSentPercent) < 2) {
            // still allow label change with small delay
            if (! ($labelChanged && ($now - self::$lastSentAt) >= 1)) {
                return;
            }
        }

        self::send($mapped, $label !== '' ? $label : self::$lastSentLabel);
    }

    /**
     * @throws WWC_Agent_Cancelled_Exception
     */
    public static function flush(bool $force = false): void
    {
        if (self::$jobId === null) {
            return;
        }
        $now = time();
        if (! $force && ($now - self::$lastSentAt) < 2 && self::$pendingLog === []) {
            return;
        }
        if (! $force && self::$pendingLog === [] && ($now - self::$lastSentAt) < 2) {
            return;
        }
        self::send(
            self::$lastSentPercent >= 0 ? self::$lastSentPercent : 1,
            self::$lastSentLabel !== '' ? self::$lastSentLabel : 'Läuft…'
        );
    }

    private static function mapPercent(int $percent): int
    {
        $percent = max(0, min(100, $percent));
        foreach (self::$scopes as $scope) {
            $from = $scope['from'];
            $to = $scope['to'];
            if ($to <= $from) {
                continue;
            }
            $percent = (int) round($from + ($percent / 100) * ($to - $from));
        }

        return max(0, min(99, $percent));
    }

    /**
     * @throws WWC_Agent_Cancelled_Exception
     */
    private static function send(int $percent, string $label): void
    {
        $log = self::$pendingLog;
        self::$pendingLog = [];

        $response = WWC_Agent_Api_Client::request('POST', '/jobs/'.rawurlencode(self::$jobId).'/progress', [
            'progress' => $percent,
            'label' => $label,
            'log' => $log,
        ], 3);

        self::$lastSentAt = time();
        self::$lastSentPercent = $percent;
        if ($label !== '') {
            self::$lastSentLabel = $label;
        }

        if (is_array($response) && ! empty($response['cancelled'])) {
            WWC_Agent_Background::mark_cancelled(self::$jobId);
            throw new WWC_Agent_Cancelled_Exception('Job cancelled by portal');
        }
    }
}

final class WWC_Agent_Cancelled_Exception extends RuntimeException
{
}
