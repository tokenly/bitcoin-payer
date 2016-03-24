<?php

namespace Tokenly\BitcoinPayer\Provider;


use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Nbobtc\Bitcoind\Bitcoind;
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
        $this->bindConfig();

        $this->app->bind('Nbobtc\Bitcoind\Bitcoind', function($app) {
            $bitcoind_client = $app->make('Nbobtc\Bitcoind\Client');
            $bitcoind = new Bitcoind($bitcoind_client);
            return $bitcoind;
        });

        $this->app->bind('Nbobtc\Bitcoind\Client', function($app) {
            $url_pieces = parse_url(Config::get('bitcoin-payer.connection_string'));
            $rpc_user = Config::get('bitcoin-payer.rpc_user');
            $rpc_password = Config::get('bitcoin-payer.rpc_password');

            $connection_string = "{$url_pieces['scheme']}://{$rpc_user}:{$rpc_password}@{$url_pieces['host']}:{$url_pieces['port']}";
            $bitcoind_client = new Client($connection_string);
            return $bitcoind_client;
        });

        $this->app->bind('Tokenly\BitcoinPayer\BitcoinPayer', function($app) {
            $bitcoind_client = $app->make('Nbobtc\Bitcoind\Client');
            $bitcoind = $app->make('Nbobtc\Bitcoind\Bitcoind');
            $cache_table_provider = function() {
                return DB::table('address_txos_cache');
            };
            $sender = new BitcoinPayer($bitcoind, $bitcoind_client, $cache_table_provider);
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


