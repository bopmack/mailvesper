<?php

it('reports health with the database status', function () {
    $this->getJson('/v1/health')->assertOk()->assertJson(['status' => 'ok', 'database' => 'ok'])->assertJsonStructure(['version', 'time']);
});

it('returns errors in one shape with a request id', function () {
    $response = $this->getJson('/v1/does-not-exist')->assertNotFound()->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Not found.']]);
    expect($response->headers->get('X-Request-Id'))->toMatch('/^[0-9A-Z]{26}$/');
});

it('keeps a valid incoming request id', function () {
    $this->withHeader('X-Request-Id', 'abc12345-trace')->getJson('/v1/health')->assertHeader('X-Request-Id', 'abc12345-trace');
});

it('rejects unauthenticated access in the standard shape', function () {
    $this->getJson('/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
});

it('reports validation errors per field', function () {
    $this->postJson('/v1/auth/lookup', [])->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['message', 'fields' => ['email']]]);
});

it('reports which release is serving', function () {
    $this->getJson('/v1/health')->assertJsonPath('release', basename(base_path()));
});
