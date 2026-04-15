<?php

namespace App\Providers;

use App\Domain\Activity\LedgerPostActivity;
use App\Domain\Activity\MerchantCallbackActivity;
use App\Domain\Activity\ProviderCallActivity;
use App\Domain\Activity\ProviderStatusQueryActivity;
use App\Domain\Activity\PublishDomainEventActivity;
use App\Domain\Activity\UpdatePaymentStatusActivity;
use App\Domain\Activity\UpdateRefundStatusActivity;
use App\Infrastructure\Activity\LedgerPostActivityImpl;
use App\Infrastructure\Activity\MerchantCallbackActivityImpl;
use App\Infrastructure\Activity\ProviderCallActivityImpl;
use App\Infrastructure\Activity\ProviderStatusQueryActivityImpl;
use App\Infrastructure\Activity\PublishDomainEventActivityImpl;
use App\Infrastructure\Activity\UpdatePaymentStatusActivityImpl;
use App\Infrastructure\Activity\UpdateRefundStatusActivityImpl;
use App\Infrastructure\Http\PaymentDomainClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentDomainClient::class);

        $this->app->bind(UpdatePaymentStatusActivity::class, UpdatePaymentStatusActivityImpl::class);
        $this->app->bind(UpdateRefundStatusActivity::class, UpdateRefundStatusActivityImpl::class);
        $this->app->bind(ProviderCallActivity::class, ProviderCallActivityImpl::class);
        $this->app->bind(ProviderStatusQueryActivity::class, ProviderStatusQueryActivityImpl::class);
        $this->app->bind(LedgerPostActivity::class, LedgerPostActivityImpl::class);
        $this->app->bind(MerchantCallbackActivity::class, MerchantCallbackActivityImpl::class);
        $this->app->bind(PublishDomainEventActivity::class, PublishDomainEventActivityImpl::class);
    }

    public function boot(): void
    {
        //
    }
}
