<?php

namespace Tokenly\BitcoinPayer\Provider;


use Exception;
use Illuminate\Support\ServiceProvider;
use Tokenly\BitcoinPayer\BitcoinPayer;

/*
* BitcoinPayerServiceProvider
*/
class BitcoinPayerServiceProvider extends ServiceProvider
{

    public function boot()
    {
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->package('tokenly/bitcoin-payer', 'bitcoin-payer', __DIR__.'/../../');

        $this->app->bind('Nbobtc\Bitcoind\Bitcoind', function($app) {
            $config = $app['config']['bitcoin'];
            $connection_string = "{$config['scheme']}://{$config['rpcUser']}:{$config['rpcPassword']}@{$config['host']}:{$config['port']}";
            $bitcoin_client = new Client($connection_string);
            $bitcoind = new Bitcoind($bitcoin_client);
            return $bitcoind;
        });

        $this->app->bind('Tokenly\BitcoinPayer\BitcoinPayer', function($app) {
            $bitcoind = $app->make('Nbobtc\Bitcoind\Bitcoind');
            $insight_client = $app->make('Tokenly\Insight\Client');
            $sender = new BitcoinPayer($bitcoind, $insight_client);
            return $sender;
        });
    }

}

