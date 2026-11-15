<?php

namespace Reach\ResrvPaymentMollie\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use MarcoRieser\Livewire\ServiceProvider as StatamicLivewireServiceProvider;
use Mockery;
use Reach\ResrvPaymentMollie\Http\Payment\MolliePaymentGateway;
use Reach\ResrvPaymentMollie\Tests\Support\FakeMollieApi;
use Reach\StatamicResrv\Enums\ReservationStatus;
use Reach\StatamicResrv\Http\Payment\PaymentGatewayManager;
use Reach\StatamicResrv\Models\Rate;
use Reach\StatamicResrv\Models\Reservation;
use Reach\StatamicResrv\StatamicResrvServiceProvider;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Licensing\Outpost;
use Statamic\Support\Str;
use Statamic\Testing\AddonTestCase;

/**
 * Boots Statamic with Resrv (its migrations, routes, Livewire components) and registers the
 * Mollie gateway under the 'mollie' key, so tests drive the same code paths a site does.
 */
abstract class TestCase extends AddonTestCase
{
    use RefreshDatabase;

    protected string $addonServiceProvider = StatamicResrvServiceProvider::class;

    protected FakeMollieApi $mollie;

    protected function setUp(): void
    {
        (new Filesystem)->deleteDirectory(self::stacheDirectory());

        parent::setUp();

        $this->instance(Outpost::class, Mockery::mock(Outpost::class)->shouldIgnoreMissing());
        $this->withoutExceptionHandling();
        // The return page pauses before checking an open payment again; tests don't wait.
        Sleep::fake();
        Rate::resetEntryCollectionCache();

        Site::setSites(['en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US', 'lang' => 'en']]);

        config([
            'mollie.key' => 'test_dHar4XY7LxsDOtmnkVtjNVWXLSlXsM',
            'resrv-config.currency_isoCode' => 'EUR',
            'resrv-config.payment_gateways' => [
                'mollie' => ['class' => MolliePaymentGateway::class, 'label' => 'Mollie'],
            ],
            'resrv-config.checkout_completed_entry' => $this->makeEntry('Checkout complete')->id(),
            'resrv-config.manual_reservations_payment_entry' => [$this->makeEntry('Pay for your booking')->id()],
        ]);
        $this->app->forgetInstance(PaymentGatewayManager::class);

        $this->mollie = FakeMollieApi::install($this->app);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        (new Filesystem)->deleteDirectory(self::stacheDirectory());
    }

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [
            LivewireServiceProvider::class,
            StatamicLivewireServiceProvider::class,
        ]);
    }

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // Keep Statamic's flat files out of the vendor directory AddonTestCase points them at.
        foreach (['collections', 'entries', 'taxonomies', 'terms', 'navigation', 'globals', 'global-variables', 'asset-containers', 'nav-trees', 'collection-trees', 'form-submissions', 'users'] as $store) {
            $app['config']->set("statamic.stache.stores.{$store}.directory", self::stacheDirectory().'/'.$store);
        }

        $app['config']->set('statamic.search.indexes', ['cp' => ['driver' => 'null']]);
        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('mail.default', 'array');

        // Livewire 4 needs its update route registered before Statamic's catch-all route.
        $app->booted(fn () => Livewire::setUpdateRoute(
            fn ($handle) => Route::post('/livewire/update', $handle)->middleware('web')->name('livewire.update')
        ));
    }

    protected static function stacheDirectory(): string
    {
        return sys_get_temp_dir().'/resrv-payment-mollie-tests';
    }

    protected function makeEntry(string $title)
    {
        if (! Collection::findByHandle('pages')) {
            Collection::make('pages')->routes('/{slug}')->save();
        }

        $entry = Entry::make()->collection('pages')->slug(Str::random(8))->data(['title' => $title]);
        $entry->save();

        return $entry;
    }

    /** A €50 + €5 surcharge reservation paid through Mollie with payment tr_test1. */
    protected function makeReservation(array $attributes = []): Reservation
    {
        return Reservation::factory()->withCustomer()->create(array_merge([
            'status' => ReservationStatus::PENDING->value,
            'item_id' => $this->makeEntry('Sea view room')->id(),
            'payment' => 50,
            'payment_surcharge' => 5,
            'payment_gateway' => 'mollie',
            'payment_id' => 'tr_test1',
            'date_start' => now()->addDays(10)->startOfDay(),
            'date_end' => now()->addDays(12)->startOfDay(),
        ], $attributes));
    }

    protected function gateway(): MolliePaymentGateway
    {
        return app(PaymentGatewayManager::class)->gateway('mollie');
    }
}
