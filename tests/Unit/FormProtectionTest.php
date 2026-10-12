<?php

namespace Nevela\Laravel\Tests\Unit;

use InvalidArgumentException;
use Nevela\Laravel\Console\GenerateCommand;
use Nevela\Laravel\Forms\Disposable;
use Nevela\Laravel\Forms\FormGuard;
use Nevela\Laravel\Generator\ResourceGenerator;
use Nevela\Laravel\Support\Descriptor;
use Nevela\Laravel\Support\PublicAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** "protect" and "confirm" on a public create: how they are written, and what is generated from them. */
final class FormProtectionTest extends TestCase
{
    /** @param array<string, mixed> $create */
    private function subscriber(array $create): Descriptor
    {
        return Descriptor::fromArray([
            'name' => 'Subscriber',
            'fields' => ['email' => ['kind' => 'string', 'format' => 'email', 'unique' => true], 'name' => ['kind' => 'string', 'required' => false], 'age' => ['kind' => 'int', 'required' => false]],
            'public' => ['create' => $create + ['fields' => ['email', 'name', 'age']]],
        ]);
    }

    public function test_protect_and_confirm_are_read_and_written_back_as_they_were(): void
    {
        $create = ['fields' => ['email', 'name', 'age'], 'protect' => ['turnstile' => true, 'honeypot' => true, 'minSeconds' => 3, 'email' => 'email'], 'confirm' => 'email'];
        $subscriber = $this->subscriber($create);

        $this->assertTrue($subscriber->public->protected());
        $this->assertSame(['turnstile' => true, 'honeypot' => true, 'minSeconds' => 3, 'email' => 'email'], $subscriber->public->protect);
        $this->assertSame('email', $subscriber->public->confirm);
        $this->assertSame($create, $subscriber->toArray()['public']['create']);
        $this->assertEquals($subscriber, Descriptor::fromJson($subscriber->toJson()));
    }

    public function test_only_what_is_on_is_written(): void
    {
        $subscriber = $this->subscriber(['protect' => ['honeypot' => true, 'turnstile' => false]]);

        // Every check has an answer to work from, and the descriptor keeps only the ones that are on.
        $this->assertSame(['turnstile' => false, 'honeypot' => true, 'minSeconds' => 0, 'email' => null], $subscriber->public->protect);
        $this->assertSame(['honeypot' => true], $subscriber->toArray()['public']['create']['protect']);
    }

    public function test_a_create_without_either_is_as_it_was(): void
    {
        $subscriber = $this->subscriber([]);

        $this->assertFalse($subscriber->public->protected());
        $this->assertNull($subscriber->public->confirm);
        $this->assertSame(['fields' => ['email', 'name', 'age']], $subscriber->toArray()['public']['create']);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function mistakes(): array
    {
        return [
            'a check that does not exist' => [['protect' => ['captcha' => true]], 'no check called "captcha"'],
            'nothing turned on' => [['protect' => ['turnstile' => false]], 'turns nothing on'],
            'not a list of checks' => [['protect' => true], 'lists the checks to make'],
            'seconds that are not a number' => [['protect' => ['minSeconds' => 'three']], 'whole number of seconds'],
            'longer than anyone waits' => [['protect' => ['minSeconds' => 4000]], 'between 0 and 3600'],
            'an address field the form does not have' => [['protect' => ['email' => 'mail']], 'protect.email names "mail"'],
            'an address field that is not text' => [['protect' => ['email' => 'age']], 'protect.email names "age"'],
            'confirming a field that is not text' => [['confirm' => 'age'], 'confirm names "age"'],
        ];
    }

    /** @param array<string, mixed> $create */
    #[DataProvider('mistakes')]
    public function test_a_mistake_is_said_plainly(array $create, string $says): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($says);

        $this->subscriber($create);
    }

    public function test_they_are_about_a_create_and_need_one(): void
    {
        $this->expectExceptionMessage('are about a public create');

        // "confirm" where only reading is open: Descriptor reads it from "create", so it can only be said in code.
        (new PublicAccess(read: ['email'], confirm: 'email'))->check('Subscriber', Descriptor::fromArray(['name' => 'Subscriber', 'fields' => ['email' => ['kind' => 'string']]])->fields);
    }

    public function test_the_routes_are_only_there_for_a_form_that_asks(): void
    {
        $generator = new ResourceGenerator;
        $routes = fn (array $create) => $generator->publicRoutes([$this->subscriber($create)])->contents;

        $plain = $routes([]);
        $this->assertStringNotContainsString('_nevela/form/', $plain);
        $this->assertStringNotContainsString('_nevela/confirm/', $plain);

        $protected = $routes(['protect' => ['honeypot' => true]]);
        $this->assertStringContainsString("Route::get('_nevela/form/subscribers', [\\Nevela\\Laravel\\Http\\PublicController::class, 'form'])->defaults('resource', 'Subscriber')->middleware(['nevela.throttle:60,1,subscribers.form:'])", $protected);
        $this->assertStringNotContainsString('_nevela/confirm/', $protected);

        $confirmed = $routes(['confirm' => 'email']);
        $this->assertStringContainsString("Route::post('_nevela/confirm/subscribers/{id}/{key}', [\\Nevela\\Laravel\\Http\\PublicController::class, 'confirm'])", $confirmed);
        $this->assertStringNotContainsString('_nevela/form/', $confirmed);
    }

    public function test_a_resource_that_confirms_has_somewhere_to_record_it(): void
    {
        $generator = new ResourceGenerator;
        $plain = $this->subscriber(['protect' => ['honeypot' => true]]);
        $confirming = $this->subscriber(['confirm' => 'email']);

        foreach (['model', 'migration', 'resource'] as $part) {
            $this->assertStringNotContainsStringIgnoringCase('confirm', $generator->{$part}($plain), $part);
        }
        $this->assertStringContainsString('use \\Nevela\\Laravel\\Concerns\\Confirmable;', $generator->model($confirming));
        $this->assertStringContainsString("\$table->timestamp('confirmed_at')->nullable()->index();", $generator->migration($confirming));
        $this->assertStringContainsString("'confirmedAt' => \$this->confirmed_at?->toJSON(),", $generator->resource($confirming));

        // For a table made before: a migration of its own, written once, that keeps whoever is on the list.
        $file = $generator->confirmMigrationFile($confirming, '2026_10_12_000000');
        $this->assertSame('database/migrations/2026_10_12_000000_add_confirmation_to_subscribers_table.php', $file->path);
        $this->assertStringContainsString("DB::table('subscribers')->update(['confirmed_at' => now()]);", $file->contents);
        $this->assertFalse(GenerateCommand::needsConfirmMigration([]), 'a new resource: its own migration has the column');
        $this->assertFalse(GenerateCommand::needsConfirmMigration([$generator->migration($confirming)]));
        $this->assertTrue(GenerateCommand::needsConfirmMigration([$generator->migration($plain)]));
        $this->assertTrue(GenerateCommand::needsConfirmMigration([], adopted: true));
    }

    public function test_the_dashboard_is_told_what_a_form_checks(): void
    {
        $generator = new ResourceGenerator;

        $this->assertStringContainsString('export const extras: Record<string, unknown> = {};', $generator->registry([$this->subscriber([])])->contents);

        $registry = $generator->registry([$this->subscriber(['protect' => ['turnstile' => true, 'email' => 'email'], 'confirm' => 'email'])])->contents;
        $extras = json_decode(substr($registry, $at = strpos($registry, '= {') + 2, strrpos($registry, '};') - $at + 1), true);
        $this->assertSame(['Subscriber' => ['form' => ['protect' => ['turnstile' => true, 'email' => 'email'], 'confirm' => 'email']]], $extras);
    }

    public function test_a_throwaway_domain_is_known_and_so_are_its_subdomains(): void
    {
        $this->assertGreaterThan(5000, Disposable::count());
        $this->assertTrue(Disposable::is('mailinator.com'));
        $this->assertTrue(Disposable::is('MAIL.Mailinator.com.'));
        $this->assertFalse(Disposable::is('gmail.com'));
        $this->assertFalse(Disposable::is('example.org'));
        // Not every domain that ends the same way.
        $this->assertFalse(Disposable::is('safemailinator.org'));
        $this->assertFalse(Disposable::is('com'));
        $this->assertFalse(Disposable::is(''));
    }

    public function test_every_form_has_a_name_of_its_own_for_the_robot_check(): void
    {
        $named = fn (string $name) => FormGuard::action(Descriptor::fromArray(['name' => $name, 'fields' => ['email' => ['kind' => 'string']]]));

        // The slug, where it fits what Cloudflare allows: letters, digits, _ and -, up to 32.
        $this->assertSame('subscribers', $named('Subscriber'));
        $this->assertSame('internship-applications', $named('InternshipApplication'));

        // Two long ones that begin alike are cut to the length, and are still two names.
        $spring = $named('InternationalScholarshipApplicationSpring');
        $autumn = $named('InternationalScholarshipApplicationAutumn');
        $this->assertNotSame($spring, $autumn);
        foreach ([$spring, $autumn] as $action) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,32}$/', $action);
            $this->assertStringStartsWith('international-scholarsh', $action);
        }
        // And the same one every time.
        $this->assertSame($spring, $named('InternationalScholarshipApplicationSpring'));
    }
}
