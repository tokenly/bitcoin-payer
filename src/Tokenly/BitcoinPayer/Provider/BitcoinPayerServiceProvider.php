<?php

namespace Tokenly\BitcoinPayer\Provider;


use Exception;
use Illuminate\Support\ServiceProvider;
use Nbobtc\Bitcoind\Client;
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

        $this->app->bind('Tokenly\BitcoinPayer\BitcoinPayer', function($app) {
            $xcpd_client = $app->make('Tokenly\XCPDClient\Client');
            $config = $app['config']['bitcoin::bitcoin'];
            $connection_string = "{$config['scheme']}://{$config['rpcUser']}:{$config['rpcPassword']}@{$config['host']}:{$config['port']}";
            $bitcoin_client = new Client($connection_string);
            $sender = new BitcoinPayer($xcpd_client, $bitcoin_client);
            return $sender;
        });
    }

}

