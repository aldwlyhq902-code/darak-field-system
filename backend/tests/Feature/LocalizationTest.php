<?php

namespace Tests\Feature;

use Tests\DarakTestCase;

class LocalizationTest extends DarakTestCase
{
    public function test_web_ui_defaults_to_arabic_and_exposes_language_switcher(): void
    {
        $this->get(route('panel.login'))
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee(route('locale.switch', 'en'))
            ->assertSee('English');
    }

    public function test_language_choice_persists_and_switches_document_direction(): void
    {
        $this->from(route('panel.login'))
            ->post(route('locale.switch', 'en'))
            ->assertRedirect(route('panel.login'))
            ->assertSessionHas('locale', 'en');

        $this->get(route('panel.login'))
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false)
            ->assertSee('const dictionary =', false)
            ->assertSee('العربية');
    }

    public function test_unsupported_locale_is_not_accepted(): void
    {
        $this->post('/locale/fr')->assertNotFound();
    }

    public function test_api_validation_uses_the_requested_language(): void
    {
        $this->postJson('/api/v1/auth/login', [], ['Accept-Language' => 'ar'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'حقل البريد الإلكتروني مطلوب.');

        $this->postJson('/api/v1/auth/login', [], ['Accept-Language' => 'en'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'The email field is required.');
    }
}
