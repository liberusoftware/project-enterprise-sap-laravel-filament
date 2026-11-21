<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('uses configured credentials in production without printing them', function () {
    $this->app->instance('env', 'production');
    config(['app.seed_admin_password' => 'private-configured-seed-password']);
    ob_start();
    try {
        $exitCode = Artisan::call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
        $output = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    $this->assertSame(0, $exitCode);
    $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

    $this->assertTrue(Hash::check('private-configured-seed-password', $admin->password));
    $this->assertStringNotContainsString('private-configured-seed-password', $output.Artisan::output());
    $this->assertStringNotContainsString('Admin password:', $output.Artisan::output());
});

it('rotates the seeded admin password only when a changed password is configured', function () {
    config(['app.seed_admin_password' => null]);
    $this->seed(DatabaseSeeder::class);
    $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();

    config(['app.seed_admin_password' => 'rotated-configured-seed-password']);
    $this->seed(UserSeeder::class);
    $rotatedHash = $admin->refresh()->password;

    $this->assertTrue(Hash::check('rotated-configured-seed-password', $rotatedHash));

    $this->seed(UserSeeder::class);
    $this->assertSame($rotatedHash, $admin->refresh()->password);

    config(['app.seed_admin_password' => null]);
    $this->seed(UserSeeder::class);
    $this->assertSame($rotatedHash, $admin->refresh()->password);
});
