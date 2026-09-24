<?php

it('returns ok status from the health endpoint', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJson(['status' => 'ok']);
});