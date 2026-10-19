<?php

declare(strict_types=1);

use Simtabi\Laranail\Package\Tools\Testing\AssertsNoLiteralCredentials;

/*
 * Secret scanning cannot tell a test password from a leaked one, and reports both. Tests take
 * their credentials from TestCredentials at run time; this fails on any literal left behind.
 */
uses(AssertsNoLiteralCredentials::class);

it('keeps literal credentials out of the tests', function (): void {
    $this->assertNoLiteralCredentialsIn([dirname(__DIR__)], minFiles: 13);
    expect(true)->toBeTrue();
});

it('names every generated credential with a single word', function (): void {
    $this->assertCredentialPurposesAreWordsIn([dirname(__DIR__)], minFiles: 13);
    expect(true)->toBeTrue();
});
