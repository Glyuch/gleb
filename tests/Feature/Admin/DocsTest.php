<?php

use App\Models\User;

function docsAdmin(): User
{
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    return $admin;
}

it('redirects guests to login', function () {
    $this->get('/admin/docs')->assertRedirect('/login');
});

it('forbids non-admins', function () {
    $this->actingAs(User::factory()->create())->get('/admin/docs')->assertForbidden();
});

it('lists documents grouped by folder', function () {
    $this->actingAs(docsAdmin())->get('/admin/docs')
        ->assertOk()
        ->assertSee('gleb.finance — Project Map')
        ->assertSee('specs')
        ->assertSee('/admin/docs/PROJECT_MAP', false);
});

it('renders a document as html', function () {
    $this->actingAs(docsAdmin())->get('/admin/docs/PROJECT_MAP')
        ->assertOk()
        ->assertSee('<h1>gleb.finance — Project Map</h1>', false)
        ->assertSee('все документы');
});

it('renders a document from a subfolder', function () {
    $this->actingAs(docsAdmin())
        ->get('/admin/docs/specs/2026-08-08-shtab-v11-tasks-ai-design')
        ->assertOk()
        ->assertSee('Задачи территорий', false);
});

it('404s for a missing document', function () {
    $this->actingAs(docsAdmin())->get('/admin/docs/nope-not-here')->assertNotFound();
});

it('refuses to escape the docs directory', function () {
    $this->actingAs(docsAdmin())->get('/admin/docs/../AGENTS')->assertNotFound();
    $this->actingAs(docsAdmin())->get('/admin/docs/..%2FAGENTS')->assertNotFound();
});

it('strips raw html from markdown', function () {
    $path = base_path('docs/tmp-docs-test.md');
    file_put_contents($path, "# Проверка\n\n<script>alert(1)</script>\n\nтекст\n");

    try {
        $this->actingAs(docsAdmin())->get('/admin/docs/tmp-docs-test')
            ->assertOk()
            ->assertSee('<h1>Проверка</h1>', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    } finally {
        @unlink($path);
    }
});
