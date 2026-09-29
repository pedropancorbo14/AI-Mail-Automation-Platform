<?php

it('throttles the api after 60 requests per minute', function () {
    foreach (range(1, 60) as $i) {
        $this->getJson('/api/v1/health')->assertOk();
    }

    $this->getJson('/api/v1/health')->assertStatus(429);
});
