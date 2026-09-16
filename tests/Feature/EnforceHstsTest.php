<?php

test('a request served over https gets a Strict-Transport-Security header', function () {
    $host = parse_url(config('app.url'), PHP_URL_HOST);

    $response = $this->get("https://{$host}/");

    $response->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

test('a plain http request gets no Strict-Transport-Security header', function () {
    $response = $this->get('/');

    $response->assertHeaderMissing('Strict-Transport-Security');
});
