<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Email\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\Email\EmailBatch;
use Simtabi\Laranail\Email\EmailManager;
use Simtabi\Laranail\Email\EmailScanner;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Simtabi\Laranail\Email\Enums\ScanLeniency;
use Simtabi\Laranail\Email\Support\EmailMatch;
use Simtabi\Laranail\Email\Http\EmailPresenter;
use Simtabi\Laranail\Email\Support\EmailAuditEntry;

/**
 * The package over HTTP, for the callers that are not PHP.
 *
 * ## Why there is no `FormRequest` here
 *
 * `Illuminate\Foundation\Http\FormRequest` lives in `illuminate/foundation`, which is not published
 * as a standalone Composer package — depending on it means depending on `laravel/framework` in its
 * entirety, from a package that otherwise needs a handful of Illuminate components.
 * `Validator::make()` throws the same {@see ValidationException}, which Laravel's handler renders
 * into the same 422 body, so nothing is given up but the dependency.
 *
 * ## Reachability is the one expensive thing here
 *
 * Every other check is a lookup in an in-memory list. `check_reachability` performs DNS, and over a
 * batch it is grouped per domain rather than per address — but it is still the one parameter that
 * turns a local computation into a network one, so it is opt-in per request and can be refused
 * outright in config.
 */
final readonly class EmailApiController
{
    public function __construct(
        private EmailManager $manager,
        private EmailBatch $batch,
        private EmailScanner $scanner,
        private EmailPresenter $presenter,
    ) {}

    /** One address, everything known about it. */
    public function analyze(Request $request): JsonResponse
    {
        $input = $this->validate($request, [
            'email'              => ['required', 'string', 'max:320'],
            'check_reachability' => ['sometimes', 'boolean'],
            'keep_subaddress'    => ['sometimes', 'boolean'],
        ]);

        $address = $input['email'];

        $email = $this->manager->of(is_string($address) ? $address : null)
            ->keepSubaddress((bool) ($input['keep_subaddress'] ?? false));

        return new JsonResponse([
            'data' => $this->presenter->address($email, $this->wantsReachability($input)),
        ]);
    }

    /** Many addresses, one result each, plus the report. */
    public function batch(Request $request): JsonResponse
    {
        $input = $this->validateBatch($request);

        $audit = $this->batch->audit(
            $input['emails'],
            $this->wantsReachability($input),
            (bool) ($input['keep_subaddress'] ?? false),
        );

        return new JsonResponse([
            'data' => array_map(
                $this->presenter->entry(...),
                $audit->entries(),
            ),
            'meta' => $audit->report(),
        ]);
    }

    /**
     * Many addresses, the report only.
     *
     * For the caller asking "is this list worth importing", which is a question about the list.
     */
    public function audit(Request $request): JsonResponse
    {
        $input = $this->validateBatch($request);

        $audit = $this->batch->audit(
            $input['emails'],
            $this->wantsReachability($input),
            (bool) ($input['keep_subaddress'] ?? false),
        );

        return new JsonResponse([
            'data' => [
                ...$audit->report(),
                'unusable' => array_map(
                    static fn (EmailAuditEntry $entry): array => [
                        'index'    => $entry->index,
                        'input'    => $entry->input,
                        'problems' => $entry->problems,
                    ],
                    $audit->unusable(),
                ),
            ],
        ]);
    }

    /**
     * Free text in, the addresses it contains out.
     *
     * `leniency` is the only interesting parameter. `DELIVERABLE` performs MX lookups — once per
     * distinct domain — so it is refused when `allow_reachability` is off, exactly as the per-address
     * check is.
     */
    public function scan(Request $request): JsonResponse
    {
        $input = $this->validate($request, [
            'text'     => ['required', 'string', 'max:100000'],
            'leniency' => ['nullable', Rule::in(array_column(ScanLeniency::cases(), 'value'))],
        ]);

        $leniency = is_string($input['leniency'] ?? null)
            ? ScanLeniency::tryFrom($input['leniency'])
            : null;

        if ($leniency?->requiresDns() === true && config('laranail.email.api.allow_reachability', true) !== true) {
            $leniency = ScanLeniency::Valid;
        }

        $matches = $this->scanner->scan(is_string($input['text']) ? $input['text'] : null, $leniency);

        return new JsonResponse([
            'data' => array_map(static fn (EmailMatch $match): array => $match->toArray(), $matches),
            'meta' => ['count' => count($matches)],
        ]);
    }

    /**
     * @param array<string, mixed> $rules
     *
     * @return array<array-key, mixed>
     *
     * @throws ValidationException
     */
    private function validate(Request $request, array $rules): array
    {
        return Validator::make($request->all(), $rules)->validate();
    }

    /**
     * @return array{emails: list<string|null>, check_reachability: bool|null, keep_subaddress: bool|null}
     *
     * @throws ValidationException
     */
    private function validateBatch(Request $request): array
    {
        $configured = config('laranail.email.api.max_batch', 1000);
        $max = max(1, is_numeric($configured) ? (int) $configured : 1000);

        /** @var array{emails: list<string|null>, check_reachability: bool|null, keep_subaddress: bool|null} $validated */
        $validated = $this->validate($request, [
            // Enforced rather than applied: a caller that sent more than the cap gets a 422 naming
            // the field, never a truncated answer it has no way to notice.
            'emails'             => ['required', 'array', 'min:1', "max:{$max}"],
            'emails.*'           => ['nullable', 'string', 'max:320'],
            'check_reachability' => ['sometimes', 'boolean'],
            'keep_subaddress'    => ['sometimes', 'boolean'],
        ]);

        return $validated;
    }

    /**
     * @param array<array-key, mixed> $input
     */
    private function wantsReachability(array $input): bool
    {
        if (config('laranail.email.api.allow_reachability', true) !== true) {
            return false;
        }

        return (bool) ($input['check_reachability'] ?? false);
    }
}
