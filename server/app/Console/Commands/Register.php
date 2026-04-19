<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Command;

class Register extends Command
{
    protected $signature = 'client:register {name}';
    protected $description = 'Register a new on-premise client and generate its API token';

    public function handle(): int
    {
        $plainToken = Client::generateToken();

        $client = Client::create([
            'name' => $this->argument('name'),
            'api_token' => hash('sha256', $plainToken),
        ]);

        $this->info("Client registered: [{$client->id}] {$client->name}");
        $this->newLine();
        $this->warn('API Token (copy now!! Will not be shown again):');
        $this->line("  {$plainToken}");
        $this->newLine();
        $this->line('Set as CLIENT_API_TOKEN in the client .env');

        return self::SUCCESS;
    }
}