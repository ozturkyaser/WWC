<?php

declare(strict_types=1);

final class WWC_Agent_Event_Queue
{
    private const OPTION = 'wwc_agent_event_queue';

    public static function push(string $type, string $title, string $severity = 'info', array $payload = []): void
    {
        $queue = get_option(self::OPTION, []);
        $queue[] = [
            'type' => $type,
            'title' => $title,
            'severity' => $severity,
            'payload' => $payload,
            'occurred_at' => gmdate('c'),
        ];
        // Keep last 200
        if (count($queue) > 200) {
            $queue = array_slice($queue, -200);
        }
        update_option(self::OPTION, $queue, false);
    }

    public static function drain(): array
    {
        $queue = get_option(self::OPTION, []);
        delete_option(self::OPTION);

        return is_array($queue) ? $queue : [];
    }
}
