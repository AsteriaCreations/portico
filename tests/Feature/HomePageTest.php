<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the home page sends visitors to the admin panel', function () {
    $this->get('/')->assertRedirect('/admin');
});

test('a signed-out visitor following it lands on the sign-in page', function () {
    $this->followingRedirects()->get('/')->assertSuccessful()->assertSee('Sign in');
});
