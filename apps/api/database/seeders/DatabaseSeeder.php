<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\VulnerabilityScanner;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::create([
            'name' => 'WWC',
            'slug' => 'wwc',
            'billing_day' => 1,
            'billing_profile' => [
                'company' => 'WWC Web Care GmbH',
                'address' => "Musterstraße 1\n10115 Berlin",
                'vat_id' => 'DE123456789',
                'tax_rate' => 19,
                'small_business' => false,
            ],
        ]);

        $user = User::create([
            'name' => 'WWC Admin',
            'email' => 'admin@wwc.local',
            'password' => 'password',
            'current_organization_id' => $org->id,
        ]);

        Membership::create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        $client = Client::create([
            'organization_id' => $org->id,
            'name' => 'Demo Kunde',
            'email' => 'kunde@example.com',
            'company' => 'Demo GmbH',
            'address' => "Beispielweg 2\n80331 München",
        ]);

        Project::create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'name' => 'Website Wartung Standard',
            'scope' => Project::DEFAULT_SCOPE,
            'monthly_budget_cents' => 14900,
            'currency' => 'EUR',
            'active' => true,
        ]);

        app(VulnerabilityScanner::class)->syncWordPressOrgAdvisories();
    }
}
