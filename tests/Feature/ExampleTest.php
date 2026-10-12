<?php

it('serves the public homepage', function () {
    $this->get('/')->assertOk();
});
