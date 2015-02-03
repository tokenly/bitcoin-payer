<?php

namespace Tokenly\BitcoinPayer\Provider;


use Exception;
use Illuminate\Support\Facades\Config;
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
        $this->bindConfig();

        $this->app->bind('Nbobtc\Bitcoind\Bitcoind', function($app) {

            $url_pieces = parse_url(Config::get('bitcoin-payer.connection_string'));
            $rpc_user = Config::get('bitcoin-payer.rpc_user');
            $rpc_password = Config::get('bitcoin-payer.rpc_password');
            $url_pieces = parse_url(Config::get('bitcoin-payer.connection_string'));
            $connection_string = "{$url_pieces['scheme']}://{$rpc_user}:{$rpc_password}@{$url_pieces['host']}:{$url_pieces['port']}";
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

    protected function bindConfig()
    {


        // simple config
        $config = [
            'bitcoin-payer.connection_string' => env('NATIVE_CONNECTION_STRING', 'http://localhost:8332'),
            'bitcoin-payer.rpc_user'          => env('NATIVE_RPC_USER', null),
            'bitcoin-payer.rpc_password'      => env('NATIVE_RPC_PASSWORD', null),
        ];

        // set the laravel config
        Config::set($config);
    }

}


