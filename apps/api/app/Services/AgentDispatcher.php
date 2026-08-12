<?php

namespace App\Services;

use App\Jobs\PushAgentCommandJob;
use App\Models\AgentJob;
use App\Models\Site;
use App\Models\User;
use InvalidArgumentException;

class AgentDispatcher
{
    public function dispatch(Site $site, string $command, array $payload = [], ?User $user = null): AgentJob
    {
        if (! in_array($command, AgentJob::ALLOWED_COMMANDS, true)) {
            throw new InvalidArgumentException("Command [{$command}] is not allowed.");
        }

        if (! $site->getHmacSecret()) {
            throw new InvalidArgumentException('Site is not paired.');
        }

        if ($command === 'self_update') {
            $meta = app(PluginPackager::class)->releaseMeta();
            $payload = array_merge([
                'package' => $meta['package'],
                'version' => $meta['version'],
                'sha256' => $meta['sha256'] ?? null,
            ], $payload);
        }

        $job = AgentJob::create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'user_id' => $user?->id,
            'command' => $command,
            'payload' => $payload,
            'status' => 'pending',
        ]);

        AuditLogger::log('job.created', $site->organization_id, $user, $site->id, [
            'job_id' => $job->id,
            'command' => $command,
        ]);

        PushAgentCommandJob::dispatch($job->id);

        return $job;
    }
}
