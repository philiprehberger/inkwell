<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `_redirect` arrives from the submitter on an unauthenticated endpoint, so an
 * unvalidated value turns the brand domain into an open redirect. It is honoured
 * only for targets the form owner vouched for.
 */
class RedirectValidationTest extends TestCase
{
    use RefreshDatabase;

    private function form(array $overrides = [])
    {
        [$workspace] = $this->freshWorkspace('Redirects');

        return $this->makeForm($workspace, $overrides);
    }

    public function test_an_arbitrary_redirect_is_refused_and_falls_back_to_the_configured_url(): void
    {
        $form = $this->form([
            'success_redirect_url' => 'https://owner.example/thanks',
            'accept_any_origin' => true,
        ]);

        $this->postJson("/v1/forms/{$form->id}/submit", [
            'name' => 'Ada',
            'email' => 'a@b.test',
            'message' => 'hello there',
            '_redirect' => 'https://evil.example/phish',
        ])->assertOk()->assertJsonPath('redirect_url', 'https://owner.example/thanks');
    }

    public function test_a_javascript_scheme_is_never_reflected(): void
    {
        $form = $this->form(['success_redirect_url' => null, 'accept_any_origin' => true]);

        $response = $this->postJson("/v1/forms/{$form->id}/submit", [
            'name' => 'Ada',
            'email' => 'a@b.test',
            'message' => 'hello there',
            '_redirect' => 'javascript:alert(1)',
        ])->assertOk();

        $this->assertNull($response->json('redirect_url'));
        $response->assertDontSee('javascript:', false);
    }

    public function test_a_redirect_matching_the_configured_url_is_allowed(): void
    {
        $form = $this->form([
            'success_redirect_url' => 'https://owner.example/thanks',
            'accept_any_origin' => true,
        ]);

        $this->postJson("/v1/forms/{$form->id}/submit", [
            'name' => 'Ada',
            'email' => 'a@b.test',
            'message' => 'hello there',
            '_redirect' => 'https://owner.example/thanks',
        ])->assertOk()->assertJsonPath('redirect_url', 'https://owner.example/thanks');
    }

    public function test_a_redirect_whose_origin_is_allowlisted_is_allowed(): void
    {
        $form = $this->form([
            'success_redirect_url' => null,
            'accept_any_origin' => true,
            'cors_origins' => ['https://owner.example'],
        ]);

        $this->postJson("/v1/forms/{$form->id}/submit", [
            'name' => 'Ada',
            'email' => 'a@b.test',
            'message' => 'hello there',
            '_redirect' => 'https://owner.example/somewhere-else',
        ])->assertOk()->assertJsonPath('redirect_url', 'https://owner.example/somewhere-else');
    }

    public function test_a_lookalike_host_is_not_treated_as_allowlisted(): void
    {
        $form = $this->form([
            'success_redirect_url' => null,
            'accept_any_origin' => true,
            'cors_origins' => ['https://owner.example'],
        ]);

        $this->postJson("/v1/forms/{$form->id}/submit", [
            'name' => 'Ada',
            'email' => 'a@b.test',
            'message' => 'hello there',
            '_redirect' => 'https://owner.example.evil.test/phish',
        ])->assertOk()->assertJsonPath('redirect_url', null);
    }
}
