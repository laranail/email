# Deduplicate signups

Stop one person registering four times with the same mailbox.

## The problem

```
alice@example.com
Alice@Example.com
alice+newsletter@example.com
alice+2024@example.com
```

Four rows, one inbox. A unique index on the raw column allows all four.

## Store the canonical form beside the address

```php
Schema::table('users', function (Blueprint $table): void {
    $table->string('email_canonical')->nullable()->unique();
});
```

```php
use Simtabi\Laranail\Email\Facades\Mail as EmailAddress;

$user->email_canonical = EmailAddress::of($user->email)->canonical();
```

Keep both. The address the user typed is what you send to and show back to them; the canonical form
is what you compare on. Overwriting the original loses the tag they chose, which they may be filtering
their own inbox by.

## Enforce it at signup

```php
'email' => FluentRule::email()->required()->unique('users', 'email_canonical'),
```

> `unique()` compares the attribute exactly as it arrived, so pointing it at the canonical column
> only works if the value being validated is canonical too. Canonicalise in `prepareForValidation()`,
> or validate a separate field.

## Backfilling

```php
User::query()->whereNull('email_canonical')->chunkById(500, function ($users): void {
    foreach ($users as $user) {
        $user->forceFill([
            'email_canonical' => EmailAddress::of($user->email)->canonical(),
        ])->saveQuietly();
    }
});
```

Expect collisions — that is the point of running it. Report them rather than letting the unique index
abort the backfill halfway:

```php
$canonical = EmailAddress::of($user->email)->canonical();

if ($canonical !== null && User::where('email_canonical', $canonical)->whereKeyNot($user->id)->exists()) {
    logger()->warning('duplicate mailbox', ['id' => $user->id, 'canonical' => $canonical]);

    continue;
}
```

## When not to canonicalise

Subaddressing is a real feature and some users rely on it. If your product routes on the tag, or
lets people run separate accounts per tag deliberately, keep them distinct:

```php
EmailAddress::of($address)->keepSubaddress()->canonical();
```

That still lowercases, which is safe, and leaves the `+tag` alone.

---

[← Docs index](../../README.md#documentation)
